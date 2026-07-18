<?php

declare(strict_types=1);

namespace App\Tests\Game\Application;

use App\Game\Application\OpenRound;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenRoundTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItOpensACompleteVerifiableRoundAtomically(): void
    {
        $service = self::getContainer()->get(OpenRound::class);
        $cipher = self::getContainer()->get(RoundSecretCipher::class);
        $opened = $service->open();

        $row = $this->connection->fetchAssociative(
            'SELECT * FROM game_round WHERE id = :id',
            ['id' => $opened->id],
        );
        self::assertIsArray($row);
        self::assertSame('ACTIVE', $row['status']);
        self::assertSame(1_000_000, (int) $row['initial_jackpot_cents']);
        self::assertSame(20, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM round_question WHERE round_id = :id',
            ['id' => $opened->id],
        ));
        self::assertSame(1, (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM ledger_entry
WHERE round_id = :id
  AND entry_type = 'BANK_SEED'
  AND amount_cents = 1000000
SQL, ['id' => $opened->id]));

        $encryptedPath = self::blobToString($row['encrypted_winning_path']);
        $encryptedNonce = self::blobToString($row['encrypted_secret_nonce']);
        $pathBits = $cipher->decrypt($encryptedPath, OpenRound::pathContext($opened->id));
        $nonce = $cipher->decrypt($encryptedNonce, OpenRound::nonceContext($opened->id));

        self::assertMatchesRegularExpression('/^[01]{20}$/D', $pathBits);
        self::assertSame(32, strlen($nonce));
        self::assertStringNotContainsString($pathBits, $encryptedPath);
        self::assertTrue(RoundCommitment::fromHash((string) $row['secret_commitment'])->verifies(
            (string) $row['public_code'],
            (string) $row['question_set_hash'],
            WinningPath::fromBitString($pathBits),
            $nonce,
        ));
    }

    public function testItRefusesASecondActiveRound(): void
    {
        $service = self::getContainer()->get(OpenRound::class);
        $service->open();

        $this->expectException(DomainRuleViolation::class);
        $service->open();
    }

    public function testAnIncompleteRoundCannotBeActivatedDirectly(): void
    {
        $roundId = '01DIRECTROUND0000000000000';
        $this->connection->insert('game_round', [
            'id' => $roundId,
            'public_code' => 'R-20260718-ABCDEF123456',
            'status' => 'PREPARING',
            'question_set_hash' => str_repeat('a', 64),
            'secret_commitment' => str_repeat('b', 64),
            'encrypted_winning_path' => 'TC1S'.str_repeat('x', 60),
            'encrypted_secret_nonce' => 'TC1S'.str_repeat('y', 72),
            'initial_jackpot_cents' => 1_000_000,
            'entry_contribution_cents' => 0,
            'version' => 1,
        ]);

        $this->expectException(Exception::class);
        $this->connection->executeStatement(
            "UPDATE game_round SET status = 'ACTIVE', started_at = :startedAt WHERE id = :id",
            ['startedAt' => '2026-07-18 12:00:00.000000', 'id' => $roundId],
        );
    }

    public function testRoundCryptographicMaterialCannotBeRewritten(): void
    {
        $opened = self::getContainer()->get(OpenRound::class)->open();

        $this->expectException(Exception::class);
        $this->connection->executeStatement(
            'UPDATE game_round SET secret_commitment = :commitment WHERE id = :id',
            ['commitment' => str_repeat('f', 64), 'id' => $opened->id],
        );
    }

    public function testTheLedgerCannotBeRewritten(): void
    {
        $opened = self::getContainer()->get(OpenRound::class)->open();

        $this->expectException(Exception::class);
        $this->connection->executeStatement(
            "UPDATE ledger_entry SET amount_cents = 1 WHERE round_id = :id AND entry_type = 'BANK_SEED'",
            ['id' => $opened->id],
        );
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
