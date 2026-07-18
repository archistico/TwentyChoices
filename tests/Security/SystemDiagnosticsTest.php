<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Application\SystemDiagnostics;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SystemDiagnosticsTest extends KernelTestCase
{
    public function testDiagnosticsReportCriticalChecksAsHealthyInTestEnvironment(): void
    {
        self::bootKernel();
        $report = self::getContainer()->get(SystemDiagnostics::class)->inspect();

        self::assertNotSame('error', $report['status']);
        $byName = [];
        foreach ($report['checks'] as $check) {
            $byName[$check['name']] = $check;
        }

        self::assertSame('ok', $byName['SQLite quick_check']['status']);
        self::assertSame('ok', $byName['Foreign keys']['status']);
        self::assertSame('ok', $byName['Busy timeout']['status']);
        self::assertSame('ok', $byName['Catena audit']['status']);
    }
}
