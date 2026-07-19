<?php

declare(strict_types=1);

namespace App\Tests\Game\Application;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/tools/ClientTimingPolicy.php';

final class ClientTimingPolicyTest extends TestCase
{
    public function testBrowserCountdownUsesOnlyRelativeServerDurationsAndMonotonicTime(): void
    {
        $policy = new \ClientTimingPolicy();
        $checks = $policy->inspect(dirname(__DIR__, 3));
        $errors = array_values(array_filter(
            $checks,
            static fn (array $check): bool => $check['status'] === 'error',
        ));

        self::assertSame([], $errors, json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
