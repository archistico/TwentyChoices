<?php

declare(strict_types=1);

namespace App\Tests\Game\Application;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RoundSettlementTest extends KernelTestCase
{
    private Connection $connection;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-07-18 14:00:00.000000 UTC'));
        self::getContainer()->set(SystemClock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testLosingPathClosesOnlyThePlayAndKeepsTheRoundActive(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $session = self::getContainer()->get(PlayerSessionRegistry::class)->resolve(null);
        $play = self::getContainer()->get(StartPlay::class)->start($session->id);
        $winningBits = $this->winningBits($round->id);
        $losingBits = ($winningBits[0] === '0' ? '1' : '0').substr($winningBits, 1);

        $result = $this->completePath($play->publicCode, $session->id, $losingBits);

        self::assertSame('LOST', $result->outcome);
        self::assertSame('COMPLETED_LOST', $this->connection->fetchOne(
            'SELECT status FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
        self::assertSame('ACTIVE', $this->connection->fetchOne(
            'SELECT status FROM game_round WHERE id = :id',
            ['id' => $round->id],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'",
            ['id' => $round->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'));
    }

    public function testFirstValidatedWinnerAtomicallySettlesAndStartsTheNextRound(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $sessions = self::getContainer()->get(PlayerSessionRegistry::class);
        $winnerSession = $sessions->resolve(null);
        $interruptedSession = $sessions->resolve(null);
        $start = self::getContainer()->get(StartPlay::class);
        $winnerPlay = $start->start($winnerSession->id);
        $interruptedPlay = $start->start($interruptedSession->id);

        $result = $this->completePath(
            $winnerPlay->publicCode,
            $winnerSession->id,
            $this->winningBits($round->id),
        );

        self::assertSame('WON', $result->outcome);
        self::assertSame(1_000_160, $result->frozenJackpotCents);
        self::assertNotNull($result->nextRoundPublicCode);
        self::assertSame(1, $result->interruptedPlayCount);

        $oldRound = $this->connection->fetchAssociative(
            'SELECT status, winner_play_id, frozen_jackpot_cents, won_at, settled_at FROM game_round WHERE id = :id',
            ['id' => $round->id],
        );
        self::assertIsArray($oldRound);
        self::assertSame('SETTLED', $oldRound['status']);
        self::assertSame($winnerPlay->id, $oldRound['winner_play_id']);
        self::assertSame(1_000_160, (int) $oldRound['frozen_jackpot_cents']);
        self::assertNotNull($oldRound['won_at']);
        self::assertNotNull($oldRound['settled_at']);

        self::assertSame('COMPLETED_WON', $this->connection->fetchOne(
            'SELECT status FROM play WHERE id = :id',
            ['id' => $winnerPlay->id],
        ));
        self::assertSame('CREDITED', $this->connection->fetchOne(
            'SELECT status FROM play WHERE id = :id',
            ['id' => $interruptedPlay->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM play_credit WHERE source_play_id = :id AND status = 'AVAILABLE'",
            ['id' => $interruptedPlay->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT' AND amount_cents = 1000160",
            ['id' => $round->id],
        ));

        $active = $this->connection->fetchAssociative(
            "SELECT id, public_code, initial_jackpot_cents, entry_contribution_cents FROM game_round WHERE status = 'ACTIVE'",
        );
        self::assertIsArray($active);
        self::assertNotSame($round->id, $active['id']);
        self::assertSame($result->nextRoundPublicCode, $active['public_code']);
        self::assertSame(1_000_000, (int) $active['initial_jackpot_cents']);
        self::assertSame(0, (int) $active['entry_contribution_cents']);
        self::assertSame(20, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM round_question WHERE round_id = :id',
            ['id' => $active['id']],
        ));
    }

    public function testInterruptedPlayerRedeemsCreditWithoutIncreasingTheNewJackpot(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $sessions = self::getContainer()->get(PlayerSessionRegistry::class);
        $winnerSession = $sessions->resolve(null);
        $interruptedSession = $sessions->resolve(null);
        $start = self::getContainer()->get(StartPlay::class);
        $winnerPlay = $start->start($winnerSession->id);
        $interruptedPlay = $start->start($interruptedSession->id);

        $this->completePath($winnerPlay->publicCode, $winnerSession->id, $this->winningBits($round->id));
        $restarted = $start->start($interruptedSession->id);

        self::assertSame('RESTART_CREDIT', $this->connection->fetchOne(
            'SELECT entry_kind FROM play WHERE id = :id',
            ['id' => $restarted->id],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT entry_contribution_cents FROM game_round WHERE id = :id',
            ['id' => $restarted->roundId],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM play_credit
WHERE source_play_id = :sourcePlayId
  AND status = 'REDEEMED'
  AND redeemed_play_id = :redeemedPlayId
SQL, [
            'sourcePlayId' => $interruptedPlay->id,
            'redeemedPlayId' => $restarted->id,
        ]));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE play_id = :id AND entry_type = 'RESTART_CREDIT_REDEEMED' AND amount_cents = 100",
            ['id' => $restarted->id],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE play_id = :id AND entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')",
            ['id' => $restarted->id],
        ));
    }


    public function testFaultDuringNextRoundCreationRollsBackTheEntireWinningChoice(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $session = self::getContainer()->get(PlayerSessionRegistry::class)->resolve(null);
        $play = self::getContainer()->get(StartPlay::class)->start($session->id);
        $winningBits = $this->winningBits($round->id);
        $open = self::getContainer()->get(OpenPlayStep::class);
        $submit = self::getContainer()->get(SubmitChoice::class);

        for ($index = 0; $index < 19; ++$index) {
            $screen = $open->open($play->publicCode, $session->id);
            $this->clock->advance('+2 seconds');
            $submit->submit(
                $play->publicCode,
                $session->id,
                (string) $screen->challengeToken,
                $winningBits[$index] === '0' ? 'A' : 'B',
                (string) $screen->requestId,
                2_000,
            );
        }

        $this->connection->executeStatement(<<<'SQL'
CREATE TRIGGER trg_test_fault_next_round
BEFORE INSERT ON game_round
WHEN (SELECT COUNT(*) FROM game_round) >= 1
BEGIN
    SELECT RAISE(ABORT, 'injected next round failure');
END
SQL);

        try {
            $screen = $open->open($play->publicCode, $session->id);
            $this->clock->advance('+2 seconds');
            $failure = null;
            try {
                $submit->submit(
                    $play->publicCode,
                    $session->id,
                    (string) $screen->challengeToken,
                    $winningBits[19] === '0' ? 'A' : 'B',
                    (string) $screen->requestId,
                    2_000,
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, 'La fault injection avrebbe dovuto annullare il settlement.');
        } finally {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS trg_test_fault_next_round');
        }

        self::assertSame('ACTIVE', $this->connection->fetchOne(
            'SELECT status FROM game_round WHERE id = :id',
            ['id' => $round->id],
        ));
        self::assertSame(19, (int) $this->connection->fetchOne(
            'SELECT current_step FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
        self::assertSame('IN_PROGRESS', $this->connection->fetchOne(
            'SELECT status FROM play WHERE id = :id',
            ['id' => $play->id],
        ));
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'",
            ['id' => $round->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'));
    }

    public function testLateFaultBeforeSettledRollsBackWinnerPayoutCreditsReceiptsAndNextRound(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $sessions = self::getContainer()->get(PlayerSessionRegistry::class);
        $winnerSession = $sessions->resolve(null);
        $pendingSession = $sessions->resolve(null);
        $start = self::getContainer()->get(StartPlay::class);
        $winnerPlay = $start->start($winnerSession->id);
        $pendingPlay = $start->start($pendingSession->id);
        $winningBits = $this->winningBits($round->id);
        $open = self::getContainer()->get(OpenPlayStep::class);
        $submit = self::getContainer()->get(SubmitChoice::class);

        for ($index = 0; $index < 19; ++$index) {
            $screen = $open->open($winnerPlay->publicCode, $winnerSession->id);
            $this->clock->advance('+2 seconds');
            $submit->submit(
                $winnerPlay->publicCode,
                $winnerSession->id,
                (string) $screen->challengeToken,
                $winningBits[$index] === '0' ? 'A' : 'B',
                (string) $screen->requestId,
                2_000,
            );
        }

        // Opening step 20 is intentionally outside the final choice transaction: it persists the
        // STEP_SHOWN audit event and the challenge before the user can submit the winning choice.
        // Snapshot the rollback baseline only after that legitimate pre-submit work has completed.
        $screen = $open->open($winnerPlay->publicCode, $winnerSession->id);
        $beforeRounds = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round');
        $beforeLedger = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry');
        $beforeAudit = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_event');

        $this->connection->executeStatement(<<<'SQL'
CREATE TRIGGER trg_test_fault_before_settled
BEFORE UPDATE OF status ON game_round
WHEN NEW.status = 'SETTLED'
BEGIN
    SELECT RAISE(ABORT, 'injected late settlement failure');
END
SQL);

        try {
            $this->clock->advance('+2 seconds');
            $failure = null;
            try {
                $submit->submit(
                    $winnerPlay->publicCode,
                    $winnerSession->id,
                    (string) $screen->challengeToken,
                    $winningBits[19] === '0' ? 'A' : 'B',
                    (string) $screen->requestId,
                    2_000,
                );
            } catch (\Throwable $exception) {
                $failure = $exception;
            }
            self::assertNotNull($failure, 'La fault injection tardiva deve annullare l’intero settlement.');
        } finally {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS trg_test_fault_before_settled');
        }

        $oldRound = $this->connection->fetchAssociative(<<<'SQL'
SELECT status, winner_play_id, frozen_jackpot_cents, won_at, settled_at,
       revealed_winning_path, revealed_secret_nonce_hex, verification_published_at
FROM game_round
WHERE id = :id
SQL, ['id' => $round->id]);
        self::assertIsArray($oldRound);
        self::assertSame('ACTIVE', $oldRound['status']);
        self::assertNull($oldRound['winner_play_id']);
        self::assertNull($oldRound['frozen_jackpot_cents']);
        self::assertNull($oldRound['won_at']);
        self::assertNull($oldRound['settled_at']);
        self::assertNull($oldRound['revealed_winning_path']);
        self::assertNull($oldRound['revealed_secret_nonce_hex']);
        self::assertNull($oldRound['verification_published_at']);

        self::assertSame('IN_PROGRESS', $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $winnerPlay->id]));
        self::assertSame(19, (int) $this->connection->fetchOne('SELECT current_step FROM play WHERE id = :id', ['id' => $winnerPlay->id]));
        self::assertSame('IN_PROGRESS', $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $pendingPlay->id]));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'", ['id' => $round->id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :id', ['id' => $round->id]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt WHERE round_id = :id', ['id' => $round->id]));
        self::assertSame($beforeRounds, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'));
        self::assertSame($beforeLedger, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'));
        self::assertSame($beforeAudit, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_event'));
    }

    public function testStaleConcurrentChoiceCannotOverwriteTheAlreadyValidatedWinner(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $sessions = self::getContainer()->get(PlayerSessionRegistry::class);
        $winnerSession = $sessions->resolve(null);
        $contenderSession = $sessions->resolve(null);
        $start = self::getContainer()->get(StartPlay::class);
        $winnerPlay = $start->start($winnerSession->id);
        $contenderPlay = $start->start($contenderSession->id);
        $staleScreen = self::getContainer()->get(OpenPlayStep::class)->open($contenderPlay->publicCode, $contenderSession->id);

        $this->completePath($winnerPlay->publicCode, $winnerSession->id, $this->winningBits($round->id));
        $this->clock->advance('+2 seconds');

        try {
            self::getContainer()->get(SubmitChoice::class)->submit(
                $contenderPlay->publicCode,
                $contenderSession->id,
                (string) $staleScreen->challengeToken,
                'A',
                (string) $staleScreen->requestId,
                2_000,
            );
            self::fail('Una richiesta stale non deve essere accettata dopo il settlement.');
        } catch (\App\Game\Domain\Exception\DomainRuleViolation) {
        }

        self::assertSame($winnerPlay->id, $this->connection->fetchOne(
            'SELECT winner_play_id FROM game_round WHERE id = :id',
            ['id' => $round->id],
        ));
        self::assertSame('CREDITED', $this->connection->fetchOne(
            'SELECT status FROM play WHERE id = :id',
            ['id' => $contenderPlay->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'",
            ['id' => $round->id],
        ));
    }

    private function winningBits(string $roundId): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT encrypted_winning_path FROM game_round WHERE id = :id',
            ['id' => $roundId],
        );
        self::assertIsArray($row);

        return self::getContainer()->get(RoundSecretCipher::class)->decrypt(
            self::blobToString($row['encrypted_winning_path']),
            OpenRound::pathContext($roundId),
        );
    }

    private function completePath(string $playPublicCode, string $sessionId, string $bits): \App\Game\Application\ChoiceSubmissionResult
    {
        $open = self::getContainer()->get(OpenPlayStep::class);
        $submit = self::getContainer()->get(SubmitChoice::class);
        $result = null;

        for ($index = 0; $index < 20; ++$index) {
            $screen = $open->open($playPublicCode, $sessionId);
            $this->clock->advance('+2 seconds');
            $result = $submit->submit(
                $playPublicCode,
                $sessionId,
                (string) $screen->challengeToken,
                $bits[$index] === '0' ? 'A' : 'B',
                (string) $screen->requestId,
                2_000,
            );
        }

        self::assertNotNull($result);

        return $result;
    }

    private static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }
}
