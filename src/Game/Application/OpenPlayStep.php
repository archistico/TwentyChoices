<?php

declare(strict_types=1);

namespace App\Game\Application;

use App\Audit\Application\AuditLogger;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\StepTiming;
use App\Shared\Security\SecureTokenGenerator;
use App\Shared\Time\Clock;
use App\Verification\Application\PlayReceiptIssuer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class OpenPlayStep
{
    public function __construct(
        private Connection $connection,
        private SecureTokenGenerator $tokens,
        private Clock $clock,
        private AuditLogger $audit,
        private ResolveCompletedPlay $resolveCompletedPlay,
        private PlayReceiptIssuer $receipts,
    ) {
    }

    public function open(string $playPublicCode, string $playerSessionId): PlayScreen
    {
        $this->connection->beginTransaction();
        try {
            $play = $this->loadOwnedPlay($playPublicCode, $playerSessionId);

            // Compatibility with M1.3 databases: a 20/20 play could still be IN_PROGRESS
            // because settlement did not exist yet. Resolve it once on the first reopen.
            if ((int) $play['current_step'] === 20
                && (string) $play['status'] === 'IN_PROGRESS'
                && (string) $play['round_status'] === 'ACTIVE'
            ) {
                $this->resolveCompletedPlay->resolve((string) $play['id'], (string) Uuid::v7());
                $play = $this->loadOwnedPlay($playPublicCode, $playerSessionId);
            }

            $currentStep = (int) $play['current_step'];
            $jackpot = $play['frozen_jackpot_cents'] === null
                ? (int) $play['initial_jackpot_cents'] + (int) $play['entry_contribution_cents']
                : (int) $play['frozen_jackpot_cents'];
            $status = (string) $play['status'];
            $terminal = $currentStep === 20 || $status !== 'IN_PROGRESS';

            if ($terminal) {
                $verificationCode = $this->receipts->ensureForTerminalPlay((string) $play['id']);
                $this->connection->commit();

                return new PlayScreen(
                    playId: (string) $play['id'],
                    playPublicCode: (string) $play['public_code'],
                    roundId: (string) $play['round_id'],
                    roundPublicCode: (string) $play['round_public_code'],
                    participationNumber: (int) $play['participation_number'],
                    currentStep: $currentStep,
                    status: $status,
                    currentJackpotCents: $jackpot,
                    completed: true,
                    restartCreditAvailable: (bool) $play['restart_credit_available'],
                    activeRoundPublicCode: $play['active_round_public_code'] === null ? null : (string) $play['active_round_public_code'],
                    displayedStep: null,
                    category: null,
                    leftLabel: null,
                    leftValue: null,
                    rightLabel: null,
                    rightValue: null,
                    challengeToken: null,
                    requestId: null,
                    shownAt: null,
                    availableAt: null,
                    waitRemainingMilliseconds: null,
                    elapsedSinceShownMilliseconds: null,
                    verificationCode: $verificationCode,
                );
            }

            if ((string) $play['status'] !== 'IN_PROGRESS') {
                throw new DomainRuleViolation('La giocata non è più in corso.');
            }
            if ((string) $play['round_status'] !== 'ACTIVE') {
                throw new DomainRuleViolation('Il round della giocata non è più attivo.');
            }

            $stepNumber = $currentStep + 1;
            $question = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     id
    ,category_snapshot
    ,option_a_text_snapshot
    ,option_b_text_snapshot
FROM round_question
WHERE round_id = :roundId
  AND step_number = :stepNumber
LIMIT 1
SQL, [
                'roundId' => $play['round_id'],
                'stepNumber' => $stepNumber,
            ]);
            if ($question === false) {
                throw new DomainRuleViolation('La domanda prevista non è disponibile.');
            }

            $existingStep = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     id
    ,option_a_is_left
    ,shown_at
    ,available_at
    ,answered_at
FROM play_step
WHERE play_id = :playId
  AND step_number = :stepNumber
LIMIT 1
SQL, [
                'playId' => $play['id'],
                'stepNumber' => $stepNumber,
            ]);

            $rawChallenge = $this->tokens->generate();
            $challengeHash = $this->tokens->hash($rawChallenge);
            $eventType = 'STEP_TOKEN_ROTATED';

            if ($existingStep === false) {
                $timing = StepTiming::start($this->clock->now());
                $optionAIsLeft = random_int(0, 1) === 0;
                $this->connection->insert('play_step', [
                    'id' => (string) new Ulid(),
                    'play_id' => $play['id'],
                    'round_question_id' => $question['id'],
                    'step_number' => $stepNumber,
                    'option_a_is_left' => $optionAIsLeft ? 1 : 0,
                    'challenge_token_hash' => $challengeHash,
                    'shown_at' => self::formatDate($timing->shownAt),
                    'available_at' => self::formatDate($timing->availableAt),
                    'answered_at' => null,
                    'selected_option' => null,
                    'request_id' => null,
                    'client_elapsed_ms' => null,
                    'created_at' => self::formatDate($timing->shownAt),
                ]);
                $shownAt = $timing->shownAt;
                $availableAt = $timing->availableAt;
                $eventType = 'STEP_SHOWN';
            } else {
                if ($existingStep['answered_at'] !== null) {
                    throw new DomainRuleViolation('Lo stato della giocata non è coerente con lo step corrente.');
                }

                $optionAIsLeft = (bool) $existingStep['option_a_is_left'];
                $shownAt = self::parseDate((string) $existingStep['shown_at']);
                $availableAt = self::parseDate((string) $existingStep['available_at']);
                $this->connection->update('play_step', [
                    'challenge_token_hash' => $challengeHash,
                ], ['id' => $existingStep['id']]);
            }

            $requestId = (string) Uuid::v7();
            $this->audit->append($eventType, [
                'stepNumber' => $stepNumber,
                'shownAt' => $shownAt->format(DATE_ATOM),
                'availableAt' => $availableAt->format(DATE_ATOM),
            ], (string) $play['round_id'], (string) $play['id'], $requestId);
            $renderNow = $this->clock->now();
            $waitRemainingMilliseconds = self::millisecondsBetween($renderNow, $availableAt);
            $elapsedSinceShownMilliseconds = self::millisecondsBetween($shownAt, $renderNow);

            $this->connection->commit();

            $optionA = (string) $question['option_a_text_snapshot'];
            $optionB = (string) $question['option_b_text_snapshot'];

            return new PlayScreen(
                playId: (string) $play['id'],
                playPublicCode: (string) $play['public_code'],
                roundId: (string) $play['round_id'],
                roundPublicCode: (string) $play['round_public_code'],
                participationNumber: (int) $play['participation_number'],
                currentStep: $currentStep,
                status: $status,
                currentJackpotCents: $jackpot,
                completed: false,
                restartCreditAvailable: false,
                activeRoundPublicCode: (string) $play['round_public_code'],
                displayedStep: $stepNumber,
                category: (string) $question['category_snapshot'],
                leftLabel: $optionAIsLeft ? $optionA : $optionB,
                leftValue: $optionAIsLeft ? 'A' : 'B',
                rightLabel: $optionAIsLeft ? $optionB : $optionA,
                rightValue: $optionAIsLeft ? 'B' : 'A',
                challengeToken: $rawChallenge,
                requestId: $requestId,
                shownAt: $shownAt,
                availableAt: $availableAt,
                waitRemainingMilliseconds: $waitRemainingMilliseconds,
                elapsedSinceShownMilliseconds: $elapsedSinceShownMilliseconds,
                verificationCode: null,
            );
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
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
    ,p.participation_number
    ,p.current_step
    ,p.completed_at
    ,r.public_code AS round_public_code
    ,r.status AS round_status
    ,r.initial_jackpot_cents
    ,r.entry_contribution_cents
    ,r.frozen_jackpot_cents
    ,(SELECT public_code FROM game_round WHERE status = 'ACTIVE' LIMIT 1) AS active_round_public_code
    ,EXISTS(
        SELECT 1
        FROM play_credit c
        WHERE c.source_play_id = p.id
          AND c.status = 'AVAILABLE'
    ) AS restart_credit_available
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

    private static function millisecondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return max(0, self::epochMilliseconds($to) - self::epochMilliseconds($from));
    }

    private static function epochMilliseconds(DateTimeImmutable $date): int
    {
        return ((int) $date->format('U')) * 1_000 + intdiv((int) $date->format('u'), 1_000);
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
}
