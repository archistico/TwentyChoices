<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Bootstrap;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/tools/RuntimeBaselinePolicy.php';

final class RuntimeBaselinePolicyTest extends TestCase
{
    public function testPhp83IsRejectedAndPhp84IsAccepted(): void
    {
        self::assertFalse(\RuntimeBaselinePolicy::supportsPhpVersion('8.3.99'));
        self::assertTrue(\RuntimeBaselinePolicy::supportsPhpVersion('8.4.0'));
        self::assertTrue(\RuntimeBaselinePolicy::supportsPhpVersion('8.5.0'));
    }

    public function testDistributedRuntimeDeclarationsAreConsistent(): void
    {
        $policy = new \RuntimeBaselinePolicy();
        $checks = $policy->inspect(dirname(__DIR__, 3));
        $errors = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'error',
        ));

        self::assertSame([], $errors, json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
