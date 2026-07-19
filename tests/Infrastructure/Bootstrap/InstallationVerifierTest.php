<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Bootstrap;

use App\Infrastructure\Bootstrap\InstallationVerifier;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InstallationVerifierTest extends KernelTestCase
{
    public function testCleanTestDatabaseHasCompleteMigrationsSeedAndRequiredPragmas(): void
    {
        self::bootKernel();
        $report = self::getContainer()->get(InstallationVerifier::class)->inspect();
        $byName = [];
        foreach ($report['checks'] as $check) {
            $byName[$check['name']] = $check;
        }

        self::assertSame('ok', $report['status'], $this->formatErrors($report['checks']));
        self::assertSame('ok', $byName['Database path']['status']);
        self::assertStringEndsWith('/var/test.db', str_replace('\\', '/', $byName['Database path']['value']));
        self::assertSame('ok', $byName['Dev/test separation']['status']);
        self::assertSame('ok', $byName['Migrations complete']['status']);
        self::assertSame('ok', $byName['Catalog seed']['status']);
        self::assertSame('ok', $byName['Foreign keys']['status']);
        self::assertSame('ok', $byName['Busy timeout']['status']);
        self::assertSame('ok', $byName['Synchronous']['status']);
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function formatErrors(array $checks): string
    {
        $errors = array_filter($checks, static fn (array $check): bool => $check['status'] === 'error');

        return implode(PHP_EOL, array_map(
            static fn (array $check): string => $check['name'].': '.$check['value'].' — '.$check['detail'],
            $errors,
        ));
    }
}
