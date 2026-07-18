<?php

declare(strict_types=1);

namespace App\Verification\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class ReceiptQuery
{
    public function __construct(
        private Connection $connection,
        private RoundVerifier $roundVerifier,
    ) {
    }

    public function byVerificationCode(string $verificationCode): ?ReceiptView
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     pr.public_code AS verification_code
    ,pr.receipt_hash
    ,pr.play_public_code
    ,pr.round_public_code
    ,pr.participation_number
    ,pr.entry_kind
    ,pr.outcome
    ,pr.completed_steps
    ,pr.chosen_path_bits
    ,pr.issued_at
    ,r.status AS round_status
    ,r.secret_commitment
    ,r.question_set_hash
    ,r.revealed_winning_path
    ,r.revealed_secret_nonce_hex
    ,r.frozen_jackpot_cents
    ,winner.public_code AS winner_play_public_code
FROM play_receipt pr
INNER JOIN game_round r ON r.id = pr.round_id
LEFT JOIN play winner ON winner.id = r.winner_play_id
WHERE pr.public_code = :verificationCode
LIMIT 1
SQL, ['verificationCode' => $verificationCode]);
        if ($row === false) {
            return null;
        }

        $issuedAtRaw = (string) $row['issued_at'];
        $expectedReceiptHash = PlayReceiptHasher::hash(
            (string) $row['verification_code'],
            (string) $row['play_public_code'],
            (string) $row['round_public_code'],
            (int) $row['participation_number'],
            (string) $row['entry_kind'],
            (string) $row['outcome'],
            (int) $row['completed_steps'],
            (string) $row['chosen_path_bits'],
            $issuedAtRaw,
        );
        $roundVerification = $this->roundVerifier->verify(
            (string) $row['round_public_code'],
            (string) $row['question_set_hash'],
            (string) $row['secret_commitment'],
            $row['revealed_winning_path'] === null ? null : (string) $row['revealed_winning_path'],
            $row['revealed_secret_nonce_hex'] === null ? null : (string) $row['revealed_secret_nonce_hex'],
        );

        $outcomeConsistent = match ((string) $row['outcome']) {
            'WON' => $roundVerification->available
                && $roundVerification->winningPath === (string) $row['chosen_path_bits']
                && (string) $row['winner_play_public_code'] === (string) $row['play_public_code'],
            'LOST' => !$roundVerification->available
                || $roundVerification->winningPath !== (string) $row['chosen_path_bits'],
            'INTERRUPTED' => (string) $row['winner_play_public_code'] !== (string) $row['play_public_code'],
            default => false,
        };

        return new ReceiptView(
            (string) $row['verification_code'],
            (string) $row['receipt_hash'],
            hash_equals((string) $row['receipt_hash'], $expectedReceiptHash),
            (string) $row['play_public_code'],
            (string) $row['round_public_code'],
            (int) $row['participation_number'],
            (string) $row['entry_kind'],
            (string) $row['outcome'],
            (int) $row['completed_steps'],
            (string) $row['chosen_path_bits'],
            self::parseDate($issuedAtRaw),
            (string) $row['round_status'],
            (string) $row['secret_commitment'],
            (string) $row['question_set_hash'],
            $roundVerification->winningPath,
            $roundVerification->nonceHex,
            $roundVerification->available,
            $roundVerification->commitmentMatches,
            $row['winner_play_public_code'] === null ? null : (string) $row['winner_play_public_code'],
            $row['frozen_jackpot_cents'] === null ? null : (int) $row['frozen_jackpot_cents'],
            $outcomeConsistent,
        );
    }

    private static function parseDate(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
