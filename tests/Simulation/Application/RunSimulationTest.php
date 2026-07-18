<?php

declare(strict_types=1);

namespace App\Tests\Simulation\Application;

use App\Simulation\Application\RunSimulation;
use App\Simulation\Application\SimulationQuery;
use App\Simulation\Domain\SimulationProfile;
use App\Simulation\Domain\SimulationRequest;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RunSimulationTest extends KernelTestCase
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

    public function testSimulationPersistsStatisticsWithoutTouchingGameState(): void
    {
        $roundsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round');
        $playsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play');
        $ledgerBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry');

        $runner = self::getContainer()->get(RunSimulation::class);
        $code = $runner->run(new SimulationRequest(2_000, SimulationProfile::FIXED_A_BIAS, 7_000, 42));

        self::assertMatchesRegularExpression('/^S-[0-9]{8}-[A-F0-9]{12}$/D', $code);
        self::assertSame($roundsBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'));
        self::assertSame($playsBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play'));
        self::assertSame($ledgerBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'));
        self::assertSame(20, (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*) FROM simulation_choice_stat s
INNER JOIN simulation_run r ON r.id = s.run_id
WHERE r.public_code = :code
SQL, ['code' => $code]));

        $view = self::getContainer()->get(SimulationQuery::class)->byPublicCode($code);
        self::assertNotNull($view);
        self::assertSame(2_000, $view->plays);
        self::assertCount(20, $view->choiceStats);
        self::assertSame($view->plays - $view->uniquePaths, $view->duplicatePlays);
    }

    public function testPersistedSimulationSummaryIsImmutable(): void
    {
        $code = self::getContainer()->get(RunSimulation::class)->run(
            new SimulationRequest(100, SimulationProfile::UNIFORM, 5_000, 11),
        );

        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->connection->executeStatement(
            'UPDATE simulation_run SET completed_plays = completed_plays + 1 WHERE public_code = :code',
            ['code' => $code],
        );
    }
}
