<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\CryptographicCommitmentGateVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CryptographicCommitmentGateVerifierTest extends KernelTestCase
{
    public function testGatePassesAndRollsBackItsVerificationRound(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $before = [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'questions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM round_question'),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
        ];

        $report = self::getContainer()->get(CryptographicCommitmentGateVerifier::class)->verify();

        self::assertSame('ok', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(9, $report['checks']);
        self::assertSame($before['rounds'], (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'));
        self::assertSame($before['questions'], (int) $connection->fetchOne('SELECT COUNT(*) FROM round_question'));
        self::assertSame($before['ledger'], (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'));
    }
}
