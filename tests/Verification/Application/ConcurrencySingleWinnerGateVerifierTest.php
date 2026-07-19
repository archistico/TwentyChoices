<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\ConcurrencySingleWinnerGateVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ConcurrencySingleWinnerGateVerifierTest extends KernelTestCase
{
    public function testThreeProcessRacesHaveExactlyOneWinnerAndRestoreTheTestDatabase(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $before = $this->databaseFingerprint($connection);

        $report = self::getContainer()->get(ConcurrencySingleWinnerGateVerifier::class)->verify();

        self::assertSame('ok', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertCount(5, $report['checks']);
        foreach ($report['checks'] as $check) {
            self::assertSame('ok', $check['status'], $check['name'].': '.$check['value']);
        }
        self::assertSame($before, $this->databaseFingerprint($connection));
    }

    /** @return array<string, int> */
    private function databaseFingerprint(Connection $connection): array
    {
        return [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'sessions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'),
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'steps' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step'),
            'credits' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_credit'),
            'receipts' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_receipt'),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
            'audit' => (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_event'),
        ];
    }
}
