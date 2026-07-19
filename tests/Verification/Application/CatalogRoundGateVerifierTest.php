<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\CatalogRoundGateVerifier;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogRoundGateVerifierTest extends KernelTestCase
{
    public function testGateScenarioPassesAndRollsBackEveryVerificationMutation(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $before = [
            'pairs' => (int) $connection->fetchOne('SELECT COUNT(*) FROM choice_pair'),
            'rounds' => (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'questions' => (int) $connection->fetchOne('SELECT COUNT(*) FROM round_question'),
            'ledger' => (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
        ];

        $report = self::getContainer()->get(CatalogRoundGateVerifier::class)->verify();

        self::assertSame('ok', $report['status'], json_encode($report, JSON_THROW_ON_ERROR));
        self::assertNotEmpty($report['checks']);
        self::assertSame($before['pairs'], (int) $connection->fetchOne('SELECT COUNT(*) FROM choice_pair'));
        self::assertSame($before['rounds'], (int) $connection->fetchOne('SELECT COUNT(*) FROM game_round'));
        self::assertSame($before['questions'], (int) $connection->fetchOne('SELECT COUNT(*) FROM round_question'));
        self::assertSame($before['ledger'], (int) $connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'));
    }
}
