<?php

declare(strict_types=1);

namespace App\Verification\Application;

final class PlayReceiptHasher
{
    public static function hash(
        string $verificationCode,
        string $playPublicCode,
        string $roundPublicCode,
        int $participationNumber,
        string $entryKind,
        string $outcome,
        int $completedSteps,
        string $chosenPathBits,
        string $issuedAt,
    ): string {
        return hash('sha256', implode('|', [
            'twenty-choices-receipt-v1',
            $verificationCode,
            $playPublicCode,
            $roundPublicCode,
            (string) $participationNumber,
            $entryKind,
            $outcome,
            (string) $completedSteps,
            $chosenPathBits,
            $issuedAt,
        ]));
    }
}
