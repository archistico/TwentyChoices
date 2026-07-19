<?php

declare(strict_types=1);

namespace App\Tests\Game\Application;

use App\Audit\Application\AuditIntegrityVerifier;
use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Exception\ChoiceTooEarly;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PlayFlowTest extends KernelTestCase
{
    private Connection $connection;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-07-18 12:00:00.000000 UTC'));
        self::getContainer()->set(SystemClock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testStartingTwiceResumesTheSamePlayWithoutChargingTwice(): void
    {
        [$sessionId, $play] = $this->startPlay();
        $resumed = self::getContainer()->get(StartPlay::class)->start($sessionId);

        self::assertFalse($play->resumed);
        self::assertTrue($resumed->resumed);
        self::assertSame($play->publicCode, $resumed->publicCode);
        self::assertSame(80, (int) $this->connection->fetchOne(
            'SELECT entry_contribution_cents FROM game_round WHERE id = :id',
            ['id' => $play->roundId],
        ));
        self::assertSame(3, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ledger_entry WHERE play_id = :id',
            ['id' => $play->id],
        ));
    }

    public function testTheServerRejectsAnEarlyChoiceAndLogsIt(): void
    {
        [$sessionId, $play] = $this->startPlay();
        $screen = self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);

        try {
            self::getContainer()->get(SubmitChoice::class)->submit(
                $play->publicCode,
                $sessionId,
                (string) $screen->challengeToken,
                'A',
                (string) $screen->requestId,
                50,
            );
            self::fail('The early choice should have been rejected.');
        } catch (ChoiceTooEarly $exception) {
            self::assertSame(2_000, $exception->remainingMilliseconds);
        }

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT current_step FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_event WHERE play_id = :id AND event_type = 'CHOICE_REJECTED_TOO_EARLY'",
            ['id' => $play->id],
        ));
    }

    public function testRefreshKeepsTheTimerAndInvalidatesTheOldChallenge(): void
    {
        [$sessionId, $play] = $this->startPlay();
        $first = self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);
        self::assertSame(2_000, $first->waitRemainingMilliseconds);
        self::assertSame(0, $first->elapsedSinceShownMilliseconds);

        $this->clock->advance('+1 second');
        $refreshed = self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);

        self::assertSame(1_000, $refreshed->waitRemainingMilliseconds);
        self::assertSame(1_000, $refreshed->elapsedSinceShownMilliseconds);
        self::assertEquals($first->shownAt, $refreshed->shownAt);
        self::assertEquals($first->availableAt, $refreshed->availableAt);
        self::assertNotSame($first->challengeToken, $refreshed->challengeToken);

        $this->clock->advance('+1 second');
        $this->expectException(DomainRuleViolation::class);
        self::getContainer()->get(SubmitChoice::class)->submit(
            $play->publicCode,
            $sessionId,
            (string) $first->challengeToken,
            'A',
            (string) Uuid::v7(),
            2_000,
        );
    }


    public function testSubmitChoiceRejectsAt1999MillisecondsAndAcceptsAtExactly2000(): void
    {
        [$sessionId, $play] = $this->startPlay();
        $screen = self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);
        $submit = self::getContainer()->get(SubmitChoice::class);

        $this->clock->advance('+1999 milliseconds');
        try {
            $submit->submit(
                $play->publicCode,
                $sessionId,
                (string) $screen->challengeToken,
                'A',
                (string) $screen->requestId,
                999_999,
            );
            self::fail('A choice at 1,999 ms must be rejected.');
        } catch (ChoiceTooEarly $exception) {
            self::assertSame(1, $exception->remainingMilliseconds);
        }

        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT current_step FROM play WHERE id = :id',
            ['id' => $play->id],
        ));

        $this->clock->advance('+1 millisecond');
        $accepted = $submit->submit(
            $play->publicCode,
            $sessionId,
            (string) $screen->challengeToken,
            'A',
            (string) $screen->requestId,
            0,
        );

        self::assertSame(1, $accepted->acceptedStep);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT current_step FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
        self::assertSame('0', (string) $this->connection->fetchOne(
            'SELECT chosen_path_bits FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
    }

    public function testRelativeBrowserTimingIsClampedAfterTheServerWaitHasExpired(): void
    {
        [$sessionId, $play] = $this->startPlay();
        self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);

        $this->clock->advance('+3 seconds');
        $refreshed = self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);

        self::assertSame(0, $refreshed->waitRemainingMilliseconds);
        self::assertSame(3_000, $refreshed->elapsedSinceShownMilliseconds);
    }

    public function testAValidChoiceAdvancesExactlyOneStepAndReplayIsIdempotent(): void
    {
        [$sessionId, $play] = $this->startPlay();
        $screen = self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);
        $this->clock->advance('+2 seconds');
        $submit = self::getContainer()->get(SubmitChoice::class);

        $accepted = $submit->submit(
            $play->publicCode,
            $sessionId,
            (string) $screen->challengeToken,
            'B',
            (string) $screen->requestId,
            2_000,
        );
        $replayed = $submit->submit(
            $play->publicCode,
            $sessionId,
            (string) $screen->challengeToken,
            'B',
            (string) $screen->requestId,
            2_000,
        );

        self::assertSame(1, $accepted->acceptedStep);
        self::assertTrue($replayed->idempotentReplay);
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT current_step FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
        self::assertSame('1', (string) $this->connection->fetchOne(
            'SELECT chosen_path_bits FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
    }

    public function testTheSameRequestIdIsScopedPerPlayAndDoesNotCollideAcrossParticipants(): void
    {
        self::getContainer()->get(OpenRound::class)->open();
        $sessions = self::getContainer()->get(PlayerSessionRegistry::class);
        $start = self::getContainer()->get(StartPlay::class);
        $open = self::getContainer()->get(OpenPlayStep::class);
        $submit = self::getContainer()->get(SubmitChoice::class);
        $requestId = (string) Uuid::v7();

        $firstSession = $sessions->resolve(null);
        $firstPlay = $start->start($firstSession->id);
        $firstScreen = $open->open($firstPlay->publicCode, $firstSession->id);
        $this->clock->advance('+2 seconds');
        $firstResult = $submit->submit(
            $firstPlay->publicCode,
            $firstSession->id,
            (string) $firstScreen->challengeToken,
            'A',
            $requestId,
            2_000,
        );

        $secondSession = $sessions->resolve(null);
        $secondPlay = $start->start($secondSession->id);
        $secondScreen = $open->open($secondPlay->publicCode, $secondSession->id);
        $this->clock->advance('+2 seconds');
        $secondResult = $submit->submit(
            $secondPlay->publicCode,
            $secondSession->id,
            (string) $secondScreen->challengeToken,
            'B',
            $requestId,
            2_000,
        );

        self::assertSame(1, $firstResult->acceptedStep);
        self::assertSame(1, $secondResult->acceptedStep);
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM play_step WHERE request_id = :requestId',
            ['requestId' => $requestId],
        ));
    }

    public function testItRecordsAllTwentyChoicesWithoutCreatingTheNextStepEarly(): void
    {
        [$sessionId, $play] = $this->startPlay();
        $open = self::getContainer()->get(OpenPlayStep::class);
        $submit = self::getContainer()->get(SubmitChoice::class);

        for ($step = 1; $step <= 20; ++$step) {
            $screen = $open->open($play->publicCode, $sessionId);
            self::assertSame($step, $screen->displayedStep);
            $this->clock->advance('+2 seconds');
            $result = $submit->submit(
                $play->publicCode,
                $sessionId,
                (string) $screen->challengeToken,
                $step % 2 === 0 ? 'B' : 'A',
                (string) $screen->requestId,
                2_000,
            );
            self::assertSame($step, $result->acceptedStep);
        }

        $completed = $open->open($play->publicCode, $sessionId);
        self::assertTrue($completed->completed);
        self::assertSame(20, $completed->currentStep);
        self::assertNull($completed->availableAt);
        self::assertNotNull($completed->verificationCode);
        self::assertSame(20, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM play_step WHERE play_id = :id AND answered_at IS NOT NULL',
            ['id' => $play->id],
        ));
        self::assertNotNull($this->connection->fetchOne(
            'SELECT completed_at FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
    }


    public function testAuditEventsAreChainedAndImmutable(): void
    {
        [$sessionId, $play] = $this->startPlay();
        self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $sessionId);

        $events = $this->connection->fetchAllAssociative(
            'SELECT id, previous_hash, event_hash FROM audit_event ORDER BY sequence_number',
        );
        self::assertGreaterThanOrEqual(3, count($events));
        self::assertSame(str_repeat('0', 64), $events[0]['previous_hash']);
        for ($index = 1; $index < count($events); ++$index) {
            self::assertSame($events[$index - 1]['event_hash'], $events[$index]['previous_hash']);
        }
        self::assertTrue(self::getContainer()->get(AuditIntegrityVerifier::class)->verify()->valid);

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->connection->executeStatement(
            'UPDATE audit_event SET payload_json = :payload WHERE id = :id',
            ['payload' => '{}', 'id' => $events[0]['id']],
        );
    }

    public function testAnotherAnonymousSessionCannotOpenThePlay(): void
    {
        [, $play] = $this->startPlay();
        $other = self::getContainer()->get(PlayerSessionRegistry::class)->resolve(null);

        $this->expectException(DomainRuleViolation::class);
        self::getContainer()->get(OpenPlayStep::class)->open($play->publicCode, $other->id);
    }

    /** @return array{0: string, 1: \App\Game\Application\StartedPlay} */
    private function startPlay(): array
    {
        self::getContainer()->get(OpenRound::class)->open();
        $session = self::getContainer()->get(PlayerSessionRegistry::class)->resolve(null);
        $play = self::getContainer()->get(StartPlay::class)->start($session->id);

        return [$session->id, $play];
    }
}
