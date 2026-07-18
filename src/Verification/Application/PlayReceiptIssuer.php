<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class PlayReceiptIssuer
{
    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    public function ensureForTerminalPlay(string $playId): ?string
    {
        $existing = $this->connection->fetchOne(
            'SELECT public_code FROM play_receipt WHERE play_id = :playId LIMIT 1',
            ['playId' => $playId],
        );
        if ($existing !== false) {
            return (string) $existing;
        }

        $play = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     p.id
    ,p.public_code
    ,p.round_id
    ,p.status
    ,p.participation_number
    ,p.entry_kind
    ,p.current_step
    ,p.chosen_path_bits
    ,p.completed_at
    ,p.interrupted_at
    ,r.public_code AS round_public_code
FROM play p
INNER JOIN game_round r ON r.id = p.round_id
WHERE p.id = :playId
LIMIT 1
SQL, ['playId' => $playId]);
        if ($play === false) {
            throw new DomainRuleViolation('Cannot issue a receipt for a missing play.');
        }

        $outcome = match ((string) $play['status']) {
            'COMPLETED_WON' => 'WON',
            'COMPLETED_LOST' => 'LOST',
            'INTERRUPTED', 'CREDITED' => 'INTERRUPTED',
            default => null,
        };
        if ($outcome === null) {
            return null;
        }

        $issuedAt = $this->clock->now()->format('Y-m-d H:i:s.u');
        $verificationCode = $this->generateVerificationCode();
        $receiptHash = PlayReceiptHasher::hash(
            $verificationCode,
            (string) $play['public_code'],
            (string) $play['round_public_code'],
            (int) $play['participation_number'],
            (string) $play['entry_kind'],
            $outcome,
            (int) $play['current_step'],
            (string) $play['chosen_path_bits'],
            (string) $issuedAt,
        );

        $this->connection->insert('play_receipt', [
            'id' => (string) new Ulid(),
            'public_code' => $verificationCode,
            'play_id' => (string) $play['id'],
            'round_id' => (string) $play['round_id'],
            'play_public_code' => (string) $play['public_code'],
            'round_public_code' => (string) $play['round_public_code'],
            'participation_number' => (int) $play['participation_number'],
            'entry_kind' => (string) $play['entry_kind'],
            'outcome' => $outcome,
            'completed_steps' => (int) $play['current_step'],
            'chosen_path_bits' => (string) $play['chosen_path_bits'],
            'issued_at' => (string) $issuedAt,
            'receipt_hash' => $receiptHash,
        ]);

        return $verificationCode;
    }

    private function generateVerificationCode(): string
    {
        return 'V-'.strtoupper(bin2hex(random_bytes(12)));
    }
}
