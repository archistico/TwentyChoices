<?php

declare(strict_types=1);

namespace App\Game\Application;

use App\Audit\Application\AuditLogger;
use App\Game\Domain\Exception\ChoiceTooEarly;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\Choice;
use App\Game\Domain\ValueObject\StepTiming;
use App\Shared\Security\SecureTokenGenerator;
use App\Shared\Time\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Throwable;

final readonly class SubmitChoice
{
    public function __construct(
        private Connection $connection,
        private SecureTokenGenerator $tokens,
        private Clock $clock,
        private AuditLogger $audit,
        private ResolveCompletedPlay $resolveCompletedPlay,
    ) {
    }

    public function submit(
        string $playPublicCode,
        string $playerSessionId,
        string $challengeToken,
        string $selectedOption,
        string $requestId,
        ?int $clientElapsedMilliseconds,
    ): ChoiceSubmissionResult {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $requestId) !== 1) {
            throw new DomainRuleViolation('L’identificativo della richiesta non è valido.');
        }
        if ($clientElapsedMilliseconds !== null && $clientElapsedMilliseconds < 0) {
            throw new DomainRuleViolation('Il tempo client non può essere negativo.');
        }

        $choice = Choice::tryFrom($selectedOption)
            ?? throw new DomainRuleViolation('La scelta deve essere A oppure B.');
        $clientElapsedMilliseconds = $clientElapsedMilliseconds === null
            ? null
            : min($clientElapsedMilliseconds, 3_600_000);

        $transactionCompleted = false;
        $this->connection->beginTransaction();
        try {
            $play = $this->loadOwnedPlay($playPublicCode, $playerSessionId);

            $duplicate = $this->connection->fetchAssociative(<<<'SQL'
SELECT step_number
FROM play_step
WHERE play_id = :playId
  AND request_id = :requestId
LIMIT 1
SQL, [
                'playId' => $play['id'],
                'requestId' => $requestId,
            ]);
            if ($duplicate !== false) {
                $acceptedStep = (int) $duplicate['step_number'];
                $this->audit->append('CHOICE_REPLAY_IDEMPOTENT', [
                    'stepNumber' => $acceptedStep,
                ], (string) $play['round_id'], (string) $play['id'], $requestId);
                $this->connection->commit();
                $transactionCompleted = true;

                return new ChoiceSubmissionResult(
                    (string) $play['public_code'],
                    $acceptedStep,
                    $acceptedStep === 20,
                    true,
                );
            }

            if ((string) $play['status'] !== 'IN_PROGRESS') {
                throw new DomainRuleViolation('La giocata non è più in corso.');
            }
            if ((string) $play['round_status'] !== 'ACTIVE') {
                throw new DomainRuleViolation('Il round non è più attivo.');
            }

            $currentStep = (int) $play['current_step'];
            if ($currentStep >= 20) {
                throw new DomainRuleViolation('La giocata ha già ricevuto tutte le venti scelte.');
            }
            $expectedStep = $currentStep + 1;
            $step = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     id
    ,step_number
    ,challenge_token_hash
    ,shown_at
    ,available_at
    ,answered_at
FROM play_step
WHERE play_id = :playId
  AND step_number = :stepNumber
LIMIT 1
SQL, [
                'playId' => $play['id'],
                'stepNumber' => $expectedStep,
            ]);
            if ($step === false || $step['answered_at'] !== null) {
                throw new DomainRuleViolation('Lo step corrente non è disponibile.');
            }

            $receivedHash = $this->tokens->hash($challengeToken);
            if (!hash_equals((string) $step['challenge_token_hash'], $receivedHash)) {
                $this->audit->append('CHOICE_REJECTED_INVALID_TOKEN', [
                    'stepNumber' => $expectedStep,
                ], (string) $play['round_id'], (string) $play['id'], $requestId);
                $this->connection->commit();
                $transactionCompleted = true;

                throw new DomainRuleViolation('Il token della scelta non è più valido. Ricarica la pagina.');
            }

            $timing = StepTiming::reconstitute(
                self::parseDate((string) $step['shown_at']),
                self::parseDate((string) $step['available_at']),
            );
            $now = $this->clock->now();
            if (!$timing->isAvailableAt($now)) {
                $remaining = $timing->remainingMillisecondsAt($now);
                $this->audit->append('CHOICE_REJECTED_TOO_EARLY', [
                    'stepNumber' => $expectedStep,
                    'shownAt' => $timing->shownAt->format(DATE_ATOM),
                    'availableAt' => $timing->availableAt->format(DATE_ATOM),
                    'receivedAt' => $now->format(DATE_ATOM),
                    'remainingMilliseconds' => $remaining,
                    'clientElapsedMilliseconds' => $clientElapsedMilliseconds,
                ], (string) $play['round_id'], (string) $play['id'], $requestId);
                $this->connection->commit();
                $transactionCompleted = true;

                throw new ChoiceTooEarly($timing->availableAt, $remaining);
            }

            $answeredAt = self::formatDate($now);
            $stepUpdated = $this->connection->executeStatement(<<<'SQL'
UPDATE play_step
SET
     answered_at = :answeredAt
    ,selected_option = :selectedOption
    ,request_id = :requestId
    ,client_elapsed_ms = :clientElapsedMilliseconds
WHERE id = :stepId
  AND answered_at IS NULL
  AND challenge_token_hash = :challengeHash
SQL, [
                'answeredAt' => $answeredAt,
                'selectedOption' => $choice->value,
                'requestId' => $requestId,
                'clientElapsedMilliseconds' => $clientElapsedMilliseconds,
                'stepId' => $step['id'],
                'challengeHash' => $receivedHash,
            ]);
            if ($stepUpdated !== 1) {
                throw new DomainRuleViolation('La scelta è già stata elaborata da un’altra richiesta.');
            }

            $completed = $expectedStep === 20;
            $playUpdated = $this->connection->executeStatement(<<<'SQL'
UPDATE play
SET
     current_step = current_step + 1
    ,chosen_path_bits = chosen_path_bits || :choiceBit
    ,completed_at = :completedAt
    ,version = version + 1
WHERE id = :playId
  AND status = 'IN_PROGRESS'
  AND current_step = :previousStep
SQL, [
                'choiceBit' => (string) $choice->bit(),
                'completedAt' => $completed ? $answeredAt : null,
                'playId' => $play['id'],
                'previousStep' => $currentStep,
            ]);
            if ($playUpdated !== 1) {
                throw new DomainRuleViolation('La progressione della giocata è stata modificata da un’altra richiesta.');
            }

            $this->audit->append('CHOICE_ACCEPTED', [
                'stepNumber' => $expectedStep,
                'selectedOption' => $choice->value,
                'choiceBit' => $choice->bit(),
                'shownAt' => $timing->shownAt->format(DATE_ATOM),
                'availableAt' => $timing->availableAt->format(DATE_ATOM),
                'answeredAt' => $now->format(DATE_ATOM),
                'serverElapsedMilliseconds' => self::elapsedMilliseconds($timing->shownAt, $now),
                'clientElapsedMilliseconds' => $clientElapsedMilliseconds,
            ], (string) $play['round_id'], (string) $play['id'], $requestId);

            $resolution = null;
            if ($completed) {
                $resolution = $this->resolveCompletedPlay->resolve((string) $play['id'], $requestId);
            }

            $this->connection->commit();
            $transactionCompleted = true;

            return new ChoiceSubmissionResult(
                (string) $play['public_code'],
                $expectedStep,
                $completed,
                false,
                $resolution?->outcome,
                $resolution?->frozenJackpotCents,
                $resolution?->nextRoundPublicCode,
                $resolution?->interruptedPlayCount ?? 0,
            );
        } catch (Throwable $exception) {
            if (!$transactionCompleted && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function loadOwnedPlay(string $playPublicCode, string $playerSessionId): array
    {
        $play = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     p.id
    ,p.public_code
    ,p.round_id
    ,p.status
    ,p.current_step
    ,r.status AS round_status
FROM play p
INNER JOIN game_round r ON r.id = p.round_id
WHERE p.public_code = :playPublicCode
  AND p.player_session_id = :playerSessionId
LIMIT 1
SQL, [
            'playPublicCode' => $playPublicCode,
            'playerSessionId' => $playerSessionId,
        ]);

        if ($play === false) {
            throw new DomainRuleViolation('La giocata richiesta non esiste o appartiene a un’altra sessione.');
        }

        return $play;
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.u',
            $value,
            new DateTimeZone('UTC'),
        );
        if ($date === false) {
            throw new DomainRuleViolation('Una data dello step non è valida.');
        }

        return $date;
    }

    private static function elapsedMilliseconds(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $fromMicros = ((int) $from->format('U')) * 1_000_000 + (int) $from->format('u');
        $toMicros = ((int) $to->format('U')) * 1_000_000 + (int) $to->format('u');

        return max(0, (int) round(($toMicros - $fromMicros) / 1_000));
    }
}
