<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Bootstrap;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/tools/BootstrapPreflight.php';

final class BootstrapPreflightTest extends TestCase
{
    public function testRequiredRuntimeCapabilitiesAreAvailable(): void
    {
        $verifier = new \BootstrapPreflight();
        $checks = $verifier->inspect(dirname(__DIR__, 3));
        $errors = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'error',
        ));

        self::assertSame([], $errors, $this->formatErrors($errors));
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $errors */
    private function formatErrors(array $errors): string
    {
        if ($errors === []) {
            return '';
        }

        return implode(PHP_EOL, array_map(
            static fn (array $check): string => $check['name'].': '.$check['value'].' — '.$check['detail'],
            $errors,
        ));
    }
}
