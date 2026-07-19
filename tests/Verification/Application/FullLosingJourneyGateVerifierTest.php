<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\FullLosingJourneyGateVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FullLosingJourneyGateVerifierTest extends KernelTestCase
{
    public function testGatePassesAndRollsBackTheEntireLosingJourney(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $before = [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'sessions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'),
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'steps' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step'),
            'receipts' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_receipt'),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
            'audit' => (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event'),
        ];

        $report = self::getContainer()->get(FullLosingJourneyGateVerifier::class)->verify();

        self::assertSame('ok', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(6, $report['checks']);
        foreach ($report['checks'] as $check) {
            self::assertSame('ok', $check['status'], $check['name'].': '.$check['value']);
        }

        self::assertSame($before, [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'sessions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'),
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'steps' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step'),
            'receipts' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_receipt'),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
            'audit' => (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event'),
        ]);
    }
}
