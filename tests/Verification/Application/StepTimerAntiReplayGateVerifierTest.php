<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\StepTimerAntiReplayGateVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StepTimerAntiReplayGateVerifierTest extends KernelTestCase
{
    public function testGateScenarioPassesAndRollsBackEveryVerificationMutation(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $before = [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'steps' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step'),
            'sessions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'),
        ];

        $report = self::getContainer()->get(StepTimerAntiReplayGateVerifier::class)->verify();

        self::assertSame('ok', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertNotEmpty($report['checks']);
        foreach ($report['checks'] as $check) {
            self::assertSame('ok', $check['status'], $check['name'].': '.$check['value']);
        }

        self::assertSame($before, [
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'plays' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play'),
            'steps' => (int) $connection->fetchOne('SELECT COUNT(*) FROM play_step'),
            'sessions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM player_session'),
        ]);
    }
}
