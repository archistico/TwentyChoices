<?php

declare(strict_types=1);

namespace App\Tests\Verification\Application;

use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use App\Verification\Application\RoundVerifier;
use PHPUnit\Framework\TestCase;

final class RoundVerifierTest extends TestCase
{
    public function testItRecomputesThePublishedCommitment(): void
    {
        $path = WinningPath::fromBitString('10110001101001011100');
        $nonce = str_repeat("\x5a", 32);
        $questionHash = hash('sha256', 'questions');
        $commitment = RoundCommitment::create('R-20260718-ABCDEF123456', $questionHash, $path, $nonce);

        $result = (new RoundVerifier())->verify(
            'R-20260718-ABCDEF123456',
            $questionHash,
            $commitment->hash,
            $path->toBitString(),
            bin2hex($nonce),
        );

        self::assertTrue($result->available);
        self::assertTrue($result->commitmentMatches);
        self::assertSame($commitment->hash, $result->calculatedCommitment);
    }

    public function testItDetectsTamperedPublishedMaterial(): void
    {
        $path = WinningPath::fromInt(123);
        $nonce = str_repeat("\x4a", 32);
        $questionHash = hash('sha256', 'questions');
        $commitment = RoundCommitment::create('R-20260718-ABCDEF123456', $questionHash, $path, $nonce);

        $result = (new RoundVerifier())->verify(
            'R-20260718-ABCDEF123456',
            $questionHash,
            $commitment->hash,
            WinningPath::fromInt(124)->toBitString(),
            bin2hex($nonce),
        );

        self::assertTrue($result->available);
        self::assertFalse($result->commitmentMatches);
    }
}
