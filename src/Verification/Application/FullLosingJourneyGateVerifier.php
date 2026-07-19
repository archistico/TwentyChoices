<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Security\SecureTokenGenerator;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class FullLosingJourneyGateVerifier
{
    public function __construct(
        private Connection $connection,
        private OpenRound $openRound,
        private PlayerSessionRegistry $sessions,
        private StartPlay $startPlay,
        private SubmitChoice $submitChoice,
        private OpenPlayStep $openPlayStep,
        private SecureTokenGenerator $tokens,
        private RoundSecretCipher $secretCipher,
        private ReceiptQuery $receipts,
        private Clock $clock,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $this->connection->beginTransaction();

        try {
            $round = $this->openRound->open();
            $session = $this->sessions->resolve(null);
            $play = $this->startPlay->start($session->id);
            $winningPath = $this->winningPath($round->id);
            $losingPath = ($winningPath[0] === '0' ? '1' : '0').substr($winningPath, 1);

            $this->verifyJourneyProgression($checks, $round->id, $play->id, $play->publicCode, $session->id, $losingPath);
            $this->verifyTerminalLosingState($checks, $round->id, $play->id, $losingPath);
            $verificationCode = $this->verifyReceiptBeforeReveal($checks, $round->id, $play->id, $losingPath);
            $this->verifyNoPrematureRevealOrSettlement($checks, $round->id, $play->id, $winningPath);
            $this->verifyTerminalScreen($checks, $play->publicCode, $session->id, $verificationCode);
            $this->verifyNewParticipationSameRound($checks, $round->id, $play->id, $session->id);
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
    private function verifyJourneyProgression(
        array &$checks,
        string $roundId,
        string $playId,
        string $playPublicCode,
        string $sessionId,
        string $losingPath,
    ): void {
        $intermediateStable = true;
        $lastResult = null;

        for ($index = 0; $index < 20; ++$index) {
            $stepNumber = $index + 1;
            $challenge = $this->seedAvailableStep($playId, $roundId, $stepNumber);
            $lastResult = $this->submitChoice->submit(
                $playPublicCode,
                $sessionId,
                $challenge,
                $losingPath[$index] === '0' ? 'A' : 'B',
                (string) Uuid::v7(),
                2_000,
            );

            $play = $this->connection->fetchAssociative(
                'SELECT status, current_step, chosen_path_bits, completed_at FROM play WHERE id = :id',
                ['id' => $playId],
            );
            if ($play === false) {
                throw new DomainRuleViolation('La giocata è scomparsa durante il gate M1.9.6.');
            }

            if ($stepNumber < 20) {
                $roundStatus = (string) $this->connection->fetchOne('SELECT status FROM game_round WHERE id = :id', ['id' => $roundId]);
                $receiptCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt WHERE play_id = :id', ['id' => $playId]);
                $intermediateStable = $intermediateStable
                    && (string) $play['status'] === 'IN_PROGRESS'
                    && (int) $play['current_step'] === $stepNumber
                    && strlen((string) $play['chosen_path_bits']) === $stepNumber
                    && $play['completed_at'] === null
                    && $roundStatus === 'ACTIVE'
                    && $receiptCount === 0
                    && $lastResult->outcome === null;
            }
        }

        $checks[] = $this->check(
            'Full 1/20 → 20/20 progression',
            $intermediateStable
                && $lastResult !== null
                && $lastResult->completed
                && $lastResult->outcome === 'LOST',
            sprintf('steps=20, intermediate=%s, outcome=%s', $intermediateStable ? 'stable' : 'invalid', $lastResult?->outcome ?? 'null'),
            'Una scelta già diversa dal percorso segreto non deve chiudere anticipatamente la giocata: tutti i 20 step devono essere acquisiti prima dell’esito perdente.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyTerminalLosingState(array &$checks, string $roundId, string $playId, string $losingPath): void
    {
        $play = $this->connection->fetchAssociative(<<<'SQL'
SELECT status, current_step, chosen_path_bits, completed_at
FROM play
WHERE id = :id
SQL, ['id' => $playId]);
        if ($play === false) {
            throw new DomainRuleViolation('La giocata terminale non è disponibile.');
        }

        $stepStats = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     COUNT(*) AS rows_count
    ,SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) AS answered_count
    ,COUNT(DISTINCT step_number) AS distinct_steps
    ,MIN(step_number) AS min_step
    ,MAX(step_number) AS max_step
FROM play_step
WHERE play_id = :playId
SQL, ['playId' => $playId]);
        if ($stepStats === false) {
            throw new DomainRuleViolation('Le statistiche degli step non sono disponibili.');
        }

        $roundStatus = (string) $this->connection->fetchOne('SELECT status FROM game_round WHERE id = :id', ['id' => $roundId]);
        $ok = (string) $play['status'] === 'COMPLETED_LOST'
            && (int) $play['current_step'] === 20
            && (string) $play['chosen_path_bits'] === $losingPath
            && strlen((string) $play['chosen_path_bits']) === 20
            && $play['completed_at'] !== null
            && (int) $stepStats['rows_count'] === 20
            && (int) $stepStats['answered_count'] === 20
            && (int) $stepStats['distinct_steps'] === 20
            && (int) $stepStats['min_step'] === 1
            && (int) $stepStats['max_step'] === 20
            && $roundStatus === 'ACTIVE';

        $checks[] = $this->check(
            'Losing terminal state',
            $ok,
            sprintf('status=%s, step=%d, path=%d bits, answered=%d, round=%s', (string) $play['status'], (int) $play['current_step'], strlen((string) $play['chosen_path_bits']), (int) $stepStats['answered_count'], $roundStatus),
            'Dopo la ventesima scelta perdente la sola play deve essere COMPLETED_LOST, con completed_at valorizzato, esattamente 20 risposte persistite e percorso di 20 bit; il round resta ACTIVE.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyReceiptBeforeReveal(array &$checks, string $roundId, string $playId, string $losingPath): string
    {
        $receiptRows = $this->connection->fetchAllAssociative('SELECT public_code FROM play_receipt WHERE play_id = :playId', ['playId' => $playId]);
        $verificationCode = count($receiptRows) === 1 ? (string) $receiptRows[0]['public_code'] : '';
        $receipt = $verificationCode === '' ? null : $this->receipts->byVerificationCode($verificationCode);

        $ok = count($receiptRows) === 1
            && $receipt !== null
            && $receipt->outcome === 'LOST'
            && $receipt->completedSteps === 20
            && $receipt->chosenPathBits === $losingPath
            && $receipt->roundStatus === 'ACTIVE'
            && $receipt->receiptIntegrityValid
            && $receipt->outcomeConsistent
            && !$receipt->roundVerificationAvailable
            && $receipt->winningPath === null
            && $receipt->nonceHex === null
            && $receipt->roundPublicCode === (string) $this->connection->fetchOne('SELECT public_code FROM game_round WHERE id = :id', ['id' => $roundId]);

        $checks[] = $this->check(
            'Immutable losing receipt before reveal',
            $ok,
            $verificationCode === '' ? 'receipt missing' : sprintf('%s, integrity=%s, reveal=%s', $verificationCode, $receipt?->receiptIntegrityValid ? 'valid' : 'invalid', $receipt?->roundVerificationAvailable ? 'available' : 'withheld'),
            'La perdita deve emettere una sola ricevuta immutabile e verificabile subito, senza pubblicare percorso vincente o nonce finché il round resta attivo.',
        );

        return $verificationCode;
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyNoPrematureRevealOrSettlement(array &$checks, string $roundId, string $playId, string $winningPath): void
    {
        $round = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     status
    ,winner_play_id
    ,frozen_jackpot_cents
    ,won_at
    ,settled_at
    ,revealed_winning_path
    ,revealed_secret_nonce_hex
    ,verification_published_at
FROM game_round
WHERE id = :id
SQL, ['id' => $roundId]);
        if ($round === false) {
            throw new DomainRuleViolation('Il round non è disponibile dopo la perdita.');
        }

        $payouts = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :roundId AND entry_type = 'JACKPOT_PAYOUT'",
            ['roundId' => $roundId],
        );
        $credits = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM play_credit WHERE source_round_id = :roundId",
            ['roundId' => $roundId],
        );
        $rounds = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round');
        $lostAudit = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_event WHERE play_id = :playId AND event_type = 'PLAY_COMPLETED_LOST'",
            ['playId' => $playId],
        );
        $auditLeak = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM audit_event WHERE round_id = :roundId AND instr(payload_json, :winningPath) > 0',
            ['roundId' => $roundId, 'winningPath' => $winningPath],
        );

        $ok = (string) $round['status'] === 'ACTIVE'
            && $round['winner_play_id'] === null
            && $round['frozen_jackpot_cents'] === null
            && $round['won_at'] === null
            && $round['settled_at'] === null
            && $round['revealed_winning_path'] === null
            && $round['revealed_secret_nonce_hex'] === null
            && $round['verification_published_at'] === null
            && $payouts === 0
            && $credits === 0
            && $rounds === 1
            && $lostAudit === 1
            && $auditLeak === 0;

        $checks[] = $this->check(
            'Loss does not settle or reveal the round',
            $ok,
            sprintf('round=%s, winner=%s, payout=%d, credits=%d, reveal=%s, lost-audit=%d', (string) $round['status'], $round['winner_play_id'] === null ? 'none' : 'set', $payouts, $credits, $round['revealed_winning_path'] === null ? 'withheld' : 'published', $lostAudit),
            'Una perdita non deve eleggere un vincitore, congelare/pagare il jackpot, emettere crediti, creare un nuovo round o pubblicare materiale segreto; deve produrre un solo evento PLAY_COMPLETED_LOST.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyTerminalScreen(array &$checks, string $playPublicCode, string $sessionId, string $verificationCode): void
    {
        $screen = $this->openPlayStep->open($playPublicCode, $sessionId);
        $ok = $screen->completed
            && $screen->status === 'COMPLETED_LOST'
            && $screen->currentStep === 20
            && $screen->displayedStep === null
            && $screen->challengeToken === null
            && $screen->requestId === null
            && $screen->verificationCode === $verificationCode
            && $screen->activeRoundPublicCode === $screen->roundPublicCode;

        $checks[] = $this->check(
            'Terminal play reopen',
            $ok,
            sprintf('completed=%s, status=%s, verification=%s', $screen->completed ? 'yes' : 'no', $screen->status, $screen->verificationCode ?? 'none'),
            'Riaprire una play perdente deve mostrare solo lo stato terminale e la stessa ricevuta, senza creare un nuovo step/challenge o modificare il round.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyNewParticipationSameRound(array &$checks, string $roundId, string $lostPlayId, string $sessionId): void
    {
        $beforeContribution = (int) $this->connection->fetchOne('SELECT entry_contribution_cents FROM game_round WHERE id = :id', ['id' => $roundId]);
        $newPlay = $this->startPlay->start($sessionId);
        $newState = $this->connection->fetchAssociative(
            'SELECT status, current_step, chosen_path_bits, entry_kind FROM play WHERE id = :id',
            ['id' => $newPlay->id],
        );
        $afterContribution = (int) $this->connection->fetchOne('SELECT entry_contribution_cents FROM game_round WHERE id = :id', ['id' => $roundId]);
        $lostStatus = (string) $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $lostPlayId]);
        $activeOpenForSession = (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM play
WHERE round_id = :roundId
  AND player_session_id = :sessionId
  AND status IN ('CREATED', 'IN_PROGRESS')
SQL, ['roundId' => $roundId, 'sessionId' => $sessionId]);

        $ok = !$newPlay->resumed
            && $newPlay->roundId === $roundId
            && $newPlay->id !== $lostPlayId
            && $newPlay->participationNumber === 2
            && $newState !== false
            && (string) $newState['status'] === 'IN_PROGRESS'
            && (int) $newState['current_step'] === 0
            && (string) $newState['chosen_path_bits'] === ''
            && (string) $newState['entry_kind'] === 'STANDARD'
            && $lostStatus === 'COMPLETED_LOST'
            && $activeOpenForSession === 1
            && $afterContribution === $beforeContribution + 80
            && (string) $this->connection->fetchOne('SELECT status FROM game_round WHERE id = :id', ['id' => $roundId]) === 'ACTIVE';

        $checks[] = $this->check(
            'New participation after loss',
            $ok,
            sprintf('#%d, same-round=%s, resumed=%s, contribution=%d→%d', $newPlay->participationNumber, $newPlay->roundId === $roundId ? 'yes' : 'no', $newPlay->resumed ? 'yes' : 'no', $beforeContribution, $afterContribution),
            'Dopo una perdita la stessa sessione deve poter iniziare una nuova partecipazione STANDARD nello stesso round, mentre la precedente resta definitivamente COMPLETED_LOST.',
        );
    }

    private function seedAvailableStep(string $playId, string $roundId, int $stepNumber): string
    {
        $questionId = (string) $this->connection->fetchOne(
            'SELECT id FROM round_question WHERE round_id = :roundId AND step_number = :stepNumber',
            ['roundId' => $roundId, 'stepNumber' => $stepNumber],
        );
        if ($questionId === '') {
            throw new DomainRuleViolation(sprintf('La domanda %d non è disponibile per il gate M1.9.6.', $stepNumber));
        }

        $rawChallenge = $this->tokens->generate();
        $now = $this->clock->now();
        $shownAt = $now->modify('-3 seconds')->format('Y-m-d H:i:s.u');
        $availableAt = $now->modify('-1 second')->format('Y-m-d H:i:s.u');

        $this->connection->insert('play_step', [
            'id' => (string) new Ulid(),
            'play_id' => $playId,
            'round_question_id' => $questionId,
            'step_number' => $stepNumber,
            'option_a_is_left' => 1,
            'challenge_token_hash' => $this->tokens->hash($rawChallenge),
            'shown_at' => $shownAt,
            'available_at' => $availableAt,
            'answered_at' => null,
            'selected_option' => null,
            'request_id' => null,
            'client_elapsed_ms' => null,
            'created_at' => $shownAt,
        ]);

        return $rawChallenge;
    }

    private function winningPath(string $roundId): string
    {
        $ciphertext = $this->connection->fetchOne('SELECT encrypted_winning_path FROM game_round WHERE id = :id', ['id' => $roundId]);
        if ($ciphertext === false) {
            throw new DomainRuleViolation('Il percorso cifrato del round non è disponibile.');
        }

        return $this->secretCipher->decrypt(self::blobToString($ciphertext), OpenRound::pathContext($roundId));
    }

    private static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
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
