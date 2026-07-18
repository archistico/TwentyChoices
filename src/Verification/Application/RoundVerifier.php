<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;

final class RoundVerifier
{
    public function verify(
        string $roundPublicCode,
        string $questionSetHash,
        string $commitmentHash,
        ?string $revealedWinningPath,
        ?string $revealedNonceHex,
    ): RoundVerification {
        if ($revealedWinningPath === null || $revealedNonceHex === null) {
            return new RoundVerification(false, false, null, null, null);
        }

        if (preg_match('/^[01]{20}$/D', $revealedWinningPath) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $revealedNonceHex) !== 1
        ) {
            return new RoundVerification(true, false, $revealedWinningPath, $revealedNonceHex, null);
        }

        $nonce = hex2bin($revealedNonceHex);
        if ($nonce === false) {
            return new RoundVerification(true, false, $revealedWinningPath, $revealedNonceHex, null);
        }

        $path = WinningPath::fromBitString($revealedWinningPath);
        $calculated = RoundCommitment::create($roundPublicCode, $questionSetHash, $path, $nonce)->hash;

        return new RoundVerification(
            true,
            hash_equals($commitmentHash, $calculated),
            $revealedWinningPath,
            $revealedNonceHex,
            $calculated,
        );
    }
}
