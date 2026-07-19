<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StandardAccountingSchemaTest extends KernelTestCase
{
    public function testStandardAccountingHardeningObjectsExist(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        foreach ([
            ['index', 'uniq_standard_player_entry_per_play'],
            ['index', 'uniq_standard_jackpot_contribution_per_play'],
            ['index', 'uniq_standard_organizer_share_per_play'],
            ['trigger', 'trg_ledger_standard_entry_play_binding'],
        ] as [$type, $name]) {
            self::assertSame(1, (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM sqlite_master WHERE type = :type AND name = :name',
                ['type' => $type, 'name' => $name],
            ), sprintf('%s %s must exist in the test database.', ucfirst($type), $name));
        }
    }
}
