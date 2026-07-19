<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\PlayStartAccountingGateVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PlayStartAccountingGateVerifierTest extends KernelTestCase
{
    public function testGatePassesAndRollsBackSessionsPlaysAndAccounting(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $before = [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'sessions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'),
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
            'audit' => (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event'),
        ];

        $report = self::getContainer()->get(PlayStartAccountingGateVerifier::class)->verify();

        self::assertSame('ok', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(9, $report['checks']);
        self::assertSame($before['rounds'], (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'));
        self::assertSame($before['sessions'], (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'));
        self::assertSame($before['plays'], (int) $connection->fetchOne('SELECT COUNT(*) FROM play'));
        self::assertSame($before['ledger'], (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'));
        self::assertSame($before['audit'], (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event'));
    }
}
