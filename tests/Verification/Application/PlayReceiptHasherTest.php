<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Verification\Application\PlayReceiptHasher;
use PHPUnit\Framework\TestCase;

final class PlayReceiptHasherTest extends TestCase
{
    public function testReceiptHashIsDeterministicAndBindsTheOutcome(): void
    {
        $args = [
            'V-ABCDEF0123456789ABCDEF01',
            'G-ABCDEF0123456789ABCDEF01',
            'R-20260718-ABCDEF123456',
            42,
            'STANDARD',
            'LOST',
            20,
            '10110001101001011100',
            '2026-07-18 15:00:00.000000',
        ];

        $hash = PlayReceiptHasher::hash(...$args);
        self::assertSame($hash, PlayReceiptHasher::hash(...$args));
        self::assertNotSame($hash, PlayReceiptHasher::hash(
            $args[0], $args[1], $args[2], $args[3], $args[4], 'WON', $args[6], $args[7], $args[8],
        ));
    }
}
