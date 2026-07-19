<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Exception\ChoiceTooEarly;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\StepTiming;
use App\Player\Application\PlayerSessionRegistry;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class StepTimerAntiReplayGateVerifier
{
    public function __construct(
        private Connection $connection,
        private OpenRound $openRound,
        private PlayerSessionRegistry $sessions,
        private StartPlay $startPlay,
        private OpenPlayStep $openPlayStep,
        private SubmitChoice $submitChoice,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $this->connection->beginTransaction();

        try {
            $this->verifyExactBoundaryPolicy($checks);

            $round = $this->openRound->open();
            $session = $this->sessions->resolve(null);
            $play = $this->startPlay->start($session->id);

            $this->verifyDatabaseTimingBoundary($checks, $play->id, $round->id);
            $this->verifyRefreshReplayAndDoubleTab($checks, $play->id, $play->publicCode, $session->id);
            $this->verifyDirectStepSkipBlocked($checks, $play->id);
            $this->verifyInvalidClientInputsCannotAdvance($checks, $play->id, $play->publicCode, $session->id);
            $this->verifyPlayOwnershipCannotBeSwapped($checks);
            $this->verifyRequestIdScope($checks);
        } catch (Throwable $exception) {
            $checks[] = $this->check('Verification scenario', false, $exception::class, $exception->getMessage());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }

        return [
            'status' => in_array('error', array_column($checks, 'status'), true) ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyExactBoundaryPolicy(array &$checks): void
    {
        $shown = new DateTimeImmutable('2030-01-01 12:00:00.000000 UTC');
        $timing = StepTiming::start($shown);
        $at1999 = $shown->modify('+1999 milliseconds');
        $at2000 = $shown->modify('+2000 milliseconds');

        $rejected1999 = !$timing->isAvailableAt($at1999) && $timing->remainingMillisecondsAt($at1999) === 1;
        $accepted2000 = $timing->isAvailableAt($at2000) && $timing->remainingMillisecondsAt($at2000) === 0;

        $checks[] = $this->check(
            'Exact server timer boundary',
            $rejected1999 && $accepted2000,
            sprintf('1,999s=%s, 2,000s=%s', $rejected1999 ? 'blocked' : 'accepted', $accepted2000 ? 'accepted' : 'blocked'),
            'La policy server-side deve rifiutare 1.999 ms trascorsi e accettare esattamente 2.000 ms, senza dipendere dal timer del browser.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyDatabaseTimingBoundary(array &$checks, string $playId, string $roundId): void
    {
        $questionId = (string) $this->connection->fetchOne(
            'SELECT id FROM round_question WHERE round_id = :roundId AND step_number = 1',
            ['roundId' => $roundId],
        );
        if ($questionId === '') {
            throw new DomainRuleViolation('La domanda 1 non è disponibile per il gate M1.9.5.');
        }

        $shown = '2030-01-01 12:00:00.000000';
        $blocked1999 = false;
        try {
            $this->insertProbeStep($playId, $questionId, $shown, '2030-01-01 12:00:01.999000', hash('sha256', 'm195-1999'));
        } catch (Throwable) {
            $blocked1999 = true;
        }

        $accepted2000 = false;
        try {
            $this->insertProbeStep($playId, $questionId, $shown, '2030-01-01 12:00:02.000000', hash('sha256', 'm195-2000'));
            $accepted2000 = true;
            $this->connection->executeStatement(
                'DELETE FROM play_step WHERE play_id = :playId AND step_number = 1',
                ['playId' => $playId],
            );
        } catch (Throwable) {
            $accepted2000 = false;
        }

        $checks[] = $this->check(
            'SQLite exact timer invariant',
            $blocked1999 && $accepted2000,
            sprintf('1,999s=%s, 2,000s=%s', $blocked1999 ? 'blocked' : 'accepted', $accepted2000 ? 'accepted' : 'blocked'),
            'Anche lo schema SQLite deve imporre il confine esatto dei due secondi: nessuna tolleranza floating-point può rendere valido uno step da 1,999 secondi.',
        );
    }

    private function insertProbeStep(string $playId, string $questionId, string $shownAt, string $availableAt, string $challengeHash): void
    {
        $this->connection->insert('play_step', [
            'id' => (string) new Ulid(),
            'play_id' => $playId,
            'round_question_id' => $questionId,
            'step_number' => 1,
            'option_a_is_left' => 1,
            'challenge_token_hash' => $challengeHash,
            'shown_at' => $shownAt,
            'available_at' => $availableAt,
            'answered_at' => null,
            'selected_option' => null,
            'request_id' => null,
            'client_elapsed_ms' => null,
            'created_at' => $shownAt,
        ]);
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyRefreshReplayAndDoubleTab(array &$checks, string $playId, string $playPublicCode, string $sessionId): void
    {
        $firstTab = $this->openPlayStep->open($playPublicCode, $sessionId);
        $secondTab = $this->openPlayStep->open($playPublicCode, $sessionId);

        $sameTimer = $firstTab->shownAt == $secondTab->shownAt
            && $firstTab->availableAt == $secondTab->availableAt
            && $firstTab->displayedStep === 1
            && $secondTab->displayedStep === 1;
        $rotated = $firstTab->challengeToken !== $secondTab->challengeToken;
        $oneStepRow = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM play_step WHERE play_id = :playId AND step_number = 1',
            ['playId' => $playId],
        ) === 1;

        $tooEarly = false;
        try {
            $this->submitChoice->submit(
                $playPublicCode,
                $sessionId,
                (string) $secondTab->challengeToken,
                'A',
                (string) $secondTab->requestId,
                3_600_000,
            );
        } catch (ChoiceTooEarly) {
            $tooEarly = true;
        }
        $unchangedEarly = $this->currentStep($playId) === 0 && $this->answeredStepCount($playId) === 0;

        $this->waitUntil($secondTab->availableAt ?? throw new DomainRuleViolation('Lo step non espone available_at.'));

        $staleBlocked = false;
        try {
            $this->submitChoice->submit(
                $playPublicCode,
                $sessionId,
                (string) $firstTab->challengeToken,
                'A',
                (string) $firstTab->requestId,
                2_000,
            );
        } catch (DomainRuleViolation) {
            $staleBlocked = true;
        }
        $unchangedStale = $this->currentStep($playId) === 0 && $this->answeredStepCount($playId) === 0;

        $accepted = $this->submitChoice->submit(
            $playPublicCode,
            $sessionId,
            (string) $secondTab->challengeToken,
            'A',
            (string) $secondTab->requestId,
            0,
        );
        $replayed = $this->submitChoice->submit(
            $playPublicCode,
            $sessionId,
            (string) $secondTab->challengeToken,
            'B',
            (string) $secondTab->requestId,
            999_999,
        );

        $row = $this->connection->fetchAssociative(
            'SELECT current_step, chosen_path_bits FROM play WHERE id = :id',
            ['id' => $playId],
        );
        $step = $this->connection->fetchAssociative(
            'SELECT selected_option, request_id, client_elapsed_ms FROM play_step WHERE play_id = :playId AND step_number = 1',
            ['playId' => $playId],
        );
        $idempotent = $accepted->acceptedStep === 1
            && !$accepted->idempotentReplay
            && $replayed->acceptedStep === 1
            && $replayed->idempotentReplay
            && $row !== false
            && (int) $row['current_step'] === 1
            && (string) $row['chosen_path_bits'] === '0'
            && $step !== false
            && (string) $step['selected_option'] === 'A'
            && (string) $step['request_id'] === (string) $secondTab->requestId
            && (int) $step['client_elapsed_ms'] === 0
            && $this->answeredStepCount($playId) === 1;

        $checks[] = $this->check(
            'Refresh, token rotation and double-tab',
            $sameTimer && $rotated && $oneStepRow && $tooEarly && $unchangedEarly && $staleBlocked && $unchangedStale,
            sprintf('timer=%s, token=%s, early=%s, stale=%s', $sameTimer ? 'stable' : 'reset', $rotated ? 'rotated' : 'reused', $tooEarly ? 'blocked' : 'accepted', $staleBlocked ? 'blocked' : 'accepted'),
            'Ricaricare o aprire una seconda scheda non deve azzerare il timer: il token precedente viene invalidato e nessuna richiesta stale può avanzare lo stato.',
        );
        $checks[] = $this->check(
            'Replay idempotency',
            $idempotent,
            sprintf('step=%d, path=%s, answered=%d, replay=%s', $row === false ? -1 : (int) $row['current_step'], $row === false ? '?' : (string) $row['chosen_path_bits'], $this->answeredStepCount($playId), $replayed->idempotentReplay ? 'idempotent' : 'mutating'),
            'Il replay dello stesso request_id deve restituire il risultato già acquisito senza aggiungere un bit, cambiare opzione o duplicare lo step; i tempi client restano sola telemetria.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyDirectStepSkipBlocked(array &$checks, string $playId): void
    {
        $before = $this->stateFingerprint($playId);
        $blocked = false;
        try {
            $this->connection->executeStatement(
                'UPDATE play SET current_step = 3, chosen_path_bits = :path, version = version + 1 WHERE id = :id',
                ['path' => '011', 'id' => $playId],
            );
        } catch (Throwable) {
            $blocked = true;
        }
        $after = $this->stateFingerprint($playId);

        $checks[] = $this->check(
            'Step skip database guard',
            $blocked && $before === $after,
            sprintf('skip=%s, step=%d, path=%s', $blocked ? 'blocked' : 'accepted', $after['current_step'], $after['path']),
            'Lo schema deve rifiutare qualunque avanzamento che non sia esattamente +1 step e +1 bit, anche se una regressione applicativa tentasse un salto diretto.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyInvalidClientInputsCannotAdvance(array &$checks, string $playId, string $playPublicCode, string $sessionId): void
    {
        $screen = $this->openPlayStep->open($playPublicCode, $sessionId);
        $before = $this->stateFingerprint($playId);

        $invalidOption = $this->isDomainRejected(fn () => $this->submitChoice->submit(
            $playPublicCode,
            $sessionId,
            (string) $screen->challengeToken,
            'C',
            (string) $screen->requestId,
            2_000,
        ));
        $invalidRequest = $this->isDomainRejected(fn () => $this->submitChoice->submit(
            $playPublicCode,
            $sessionId,
            (string) $screen->challengeToken,
            'A',
            'not-a-uuid',
            2_000,
        ));
        $negativeElapsed = $this->isDomainRejected(fn () => $this->submitChoice->submit(
            $playPublicCode,
            $sessionId,
            (string) $screen->challengeToken,
            'A',
            (string) Uuid::v7(),
            -1,
        ));

        $after = $this->stateFingerprint($playId);
        $unchanged = $before === $after && $this->currentStep($playId) === 1;

        $checks[] = $this->check(
            'Invalid client input rejection',
            $invalidOption && $invalidRequest && $negativeElapsed && $unchanged,
            sprintf('option=%s, request=%s, elapsed=%s, state=%s', $invalidOption ? 'blocked' : 'accepted', $invalidRequest ? 'blocked' : 'accepted', $negativeElapsed ? 'blocked' : 'accepted', $unchanged ? 'unchanged' : 'changed'),
            'Opzioni non valide, request_id malformati e tempi client impossibili devono fallire prima di poter alterare step, percorso o risposta persistita.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyPlayOwnershipCannotBeSwapped(array &$checks): void
    {
        $owner = $this->sessions->resolve(null);
        $other = $this->sessions->resolve(null);
        $ownedPlay = $this->startPlay->start($owner->id);
        $screen = $this->openPlayStep->open($ownedPlay->publicCode, $owner->id);

        $blocked = $this->isDomainRejected(fn () => $this->submitChoice->submit(
            $ownedPlay->publicCode,
            $other->id,
            (string) $screen->challengeToken,
            'A',
            (string) $screen->requestId,
            999_999,
        ));
        $unchanged = $this->currentStep($ownedPlay->id) === 0 && $this->answeredStepCount($ownedPlay->id) === 0;

        $checks[] = $this->check(
            'Play/session ownership binding',
            $blocked && $unchanged,
            sprintf('foreign-session=%s, step=%d', $blocked ? 'blocked' : 'accepted', $this->currentStep($ownedPlay->id)),
            'Cambiare il codice play o la sessione non può trasferire il controllo della macchina a stati: la play viene sempre risolta insieme alla sua identità anonima proprietaria.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyRequestIdScope(array &$checks): void
    {
        $indexSql = (string) $this->connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'uniq_play_step_request'",
        );
        $scoped = str_contains(strtolower($indexSql), '(play_id, request_id)');

        $checks[] = $this->check(
            'Idempotency key scope',
            $scoped,
            $scoped ? 'unique per (play_id, request_id)' : ($indexSql === '' ? 'index missing' : $indexSql),
            'Il request_id è un’idempotency key della singola giocata: un UUID riutilizzato volontariamente in un altro round/play non deve causare una collisione globale o controllare una giocata diversa.',
        );
    }

    /** @return array{current_step:int,path:string,answered:int,rows:int} */
    private function stateFingerprint(string $playId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT current_step, chosen_path_bits FROM play WHERE id = :id',
            ['id' => $playId],
        );

        return [
            'current_step' => $row === false ? -1 : (int) $row['current_step'],
            'path' => $row === false ? '?' : (string) $row['chosen_path_bits'],
            'answered' => $this->answeredStepCount($playId),
            'rows' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_step WHERE play_id = :id', ['id' => $playId]),
        ];
    }

    private function currentStep(string $playId): int
    {
        return (int) $this->connection->fetchOne('SELECT current_step FROM play WHERE id = :id', ['id' => $playId]);
    }

    private function answeredStepCount(string $playId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM play_step WHERE play_id = :id AND answered_at IS NOT NULL',
            ['id' => $playId],
        );
    }

    private function waitUntil(DateTimeImmutable $instant): void
    {
        $targetMicros = ((int) $instant->format('U')) * 1_000_000 + (int) $instant->format('u');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowMicros = ((int) $now->format('U')) * 1_000_000 + (int) $now->format('u');
        $remaining = $targetMicros - $nowMicros;
        if ($remaining > 0) {
            usleep($remaining + 20_000);
        }
    }

    /** @param callable():mixed $operation */
    private function isDomainRejected(callable $operation): bool
    {
        try {
            $operation();

            return false;
        } catch (DomainRuleViolation) {
            return true;
        }
    }

    /** @return array{name:string,status:string,value:string,detail:string} */
    private function check(string $name, bool $ok, string $value, string $detail): array
    {
        return [
            'name' => $name,
            'status' => $ok ? 'ok' : 'error',
            'value' => $value,
            'detail' => $detail,
        ];
    }
}
