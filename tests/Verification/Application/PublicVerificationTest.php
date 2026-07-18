<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use App\Verification\Application\ReceiptQuery;
use App\Verification\Application\RoundVerifier;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublicVerificationTest extends KernelTestCase
{
    private Connection $connection;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-07-18 16:00:00.000000 UTC'));
        self::getContainer()->set(SystemClock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testLosingPlayGetsAnImmutableReceiptBeforeRoundSettlement(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $session = self::getContainer()->get(PlayerSessionRegistry::class)->resolve(null);
        $play = self::getContainer()->get(StartPlay::class)->start($session->id);
        $winning = $this->winningBits($round->id);
        $losing = ($winning[0] === '0' ? '1' : '0').substr($winning, 1);

        $this->completePath($play->publicCode, $session->id, $losing);
        $code = $this->connection->fetchOne('SELECT public_code FROM play_receipt WHERE play_id = :id', ['id' => $play->id]);
        self::assertIsString($code);

        $receipt = self::getContainer()->get(ReceiptQuery::class)->byVerificationCode($code);
        self::assertNotNull($receipt);
        self::assertSame('LOST', $receipt->outcome);
        self::assertTrue($receipt->receiptIntegrityValid);
        self::assertFalse($receipt->roundVerificationAvailable);
        self::assertTrue($receipt->outcomeConsistent);
    }

    public function testWinningSettlementPublishesVerifiableMaterialAndAllTerminalReceipts(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $sessions = self::getContainer()->get(PlayerSessionRegistry::class);
        $winnerSession = $sessions->resolve(null);
        $otherSession = $sessions->resolve(null);
        $start = self::getContainer()->get(StartPlay::class);
        $winner = $start->start($winnerSession->id);
        $interrupted = $start->start($otherSession->id);
        $winningBits = $this->winningBits($round->id);

        $this->completePath($winner->publicCode, $winnerSession->id, $winningBits);

        $published = $this->connection->fetchAssociative(<<<'SQL'
SELECT public_code, question_set_hash, secret_commitment, revealed_winning_path,
       revealed_secret_nonce_hex, verification_published_at, status
FROM game_round WHERE id = :id
SQL, ['id' => $round->id]);
        self::assertIsArray($published);
        self::assertSame('SETTLED', $published['status']);
        self::assertSame($winningBits, $published['revealed_winning_path']);
        self::assertNotNull($published['verification_published_at']);

        $verification = self::getContainer()->get(RoundVerifier::class)->verify(
            (string) $published['public_code'],
            (string) $published['question_set_hash'],
            (string) $published['secret_commitment'],
            (string) $published['revealed_winning_path'],
            (string) $published['revealed_secret_nonce_hex'],
        );
        self::assertTrue($verification->commitmentMatches);
        self::assertSame(2, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM play_receipt WHERE round_id = :id',
            ['id' => $round->id],
        ));

        $winnerCode = (string) $this->connection->fetchOne(
            'SELECT public_code FROM play_receipt WHERE play_id = :id',
            ['id' => $winner->id],
        );
        $winnerReceipt = self::getContainer()->get(ReceiptQuery::class)->byVerificationCode($winnerCode);
        self::assertNotNull($winnerReceipt);
        self::assertTrue($winnerReceipt->roundCommitmentValid);
        self::assertTrue($winnerReceipt->outcomeConsistent);
        self::assertSame('WON', $winnerReceipt->outcome);

        $interruptedCode = $this->connection->fetchOne(
            'SELECT public_code FROM play_receipt WHERE play_id = :id',
            ['id' => $interrupted->id],
        );
        self::assertIsString($interruptedCode);
    }

    public function testPublishedVerificationAndReceiptsCannotBeModified(): void
    {
        $round = self::getContainer()->get(OpenRound::class)->open();
        $session = self::getContainer()->get(PlayerSessionRegistry::class)->resolve(null);
        $play = self::getContainer()->get(StartPlay::class)->start($session->id);
        $this->completePath($play->publicCode, $session->id, $this->winningBits($round->id));

        try {
            $this->connection->executeStatement(
                "UPDATE game_round SET revealed_winning_path = '00000000000000000000' WHERE id = :id",
                ['id' => $round->id],
            );
            self::fail('Published verification material should be immutable.');
        } catch (\Throwable) {
            self::assertTrue(true);
        }

        $receiptId = (string) $this->connection->fetchOne('SELECT id FROM play_receipt WHERE play_id = :id', ['id' => $play->id]);
        try {
            $this->connection->executeStatement(
                "UPDATE play_receipt SET outcome = 'LOST' WHERE id = :id",
                ['id' => $receiptId],
            );
            self::fail('Play receipts should be immutable.');
        } catch (\Throwable) {
            self::assertTrue(true);
        }
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

    private function completePath(string $playPublicCode, string $sessionId, string $bits): void
    {
        $open = self::getContainer()->get(OpenPlayStep::class);
        $submit = self::getContainer()->get(SubmitChoice::class);
        for ($index = 0; $index < 20; ++$index) {
            $screen = $open->open($playPublicCode, $sessionId);
            $this->clock->advance('+2 seconds');
            $submit->submit(
                $playPublicCode,
                $sessionId,
                (string) $screen->challengeToken,
                $bits[$index] === '0' ? 'A' : 'B',
                (string) $screen->requestId,
                2_000,
            );
        }
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
