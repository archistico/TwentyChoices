<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain;

use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use PHPUnit\Framework\TestCase;

final class RoundCommitmentTest extends TestCase
{
    public function testItVerifiesTheOriginalReveal(): void
    {
        $path = WinningPath::fromBitString('10110001101001011100');
        $nonce = str_repeat("\x7A", 32);
        $questionSetHash = hash('sha256', 'questions-v1');
        $commitment = RoundCommitment::create('R-000001', $questionSetHash, $path, $nonce);

        self::assertTrue($commitment->verifies('R-000001', $questionSetHash, $path, $nonce));
        self::assertFalse($commitment->verifies(
            'R-000001',
            $questionSetHash,
            WinningPath::fromInt($path->value + 1),
            $nonce,
        ));
    }
}
