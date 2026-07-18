<?php

declare(strict_types=1);

namespace App\Audit\Application;

use Doctrine\DBAL\Connection;

final readonly class AuditIntegrityVerifier
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private Connection $connection)
    {
    }

    public function verify(): AuditIntegrityReport
    {
        $events = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
     sequence_number
    ,event_type
    ,round_id
    ,play_id
    ,request_id
    ,occurred_at
    ,payload_json
    ,previous_hash
    ,event_hash
FROM audit_event
ORDER BY sequence_number
SQL);

        $previousHash = self::GENESIS_HASH;
        $expectedSequence = 1;

        foreach ($events as $event) {
            $sequence = (int) $event['sequence_number'];
            if ($sequence !== $expectedSequence || !hash_equals($previousHash, (string) $event['previous_hash'])) {
                return new AuditIntegrityReport(false, count($events), $sequence);
            }

            $calculated = hash('sha256', implode('|', [
                (string) $sequence,
                (string) $event['previous_hash'],
                (string) $event['event_type'],
                (string) ($event['round_id'] ?? ''),
                (string) ($event['play_id'] ?? ''),
                (string) ($event['request_id'] ?? ''),
                (string) $event['occurred_at'],
                (string) $event['payload_json'],
            ]));

            if (!hash_equals((string) $event['event_hash'], $calculated)) {
                return new AuditIntegrityReport(false, count($events), $sequence);
            }

            $previousHash = (string) $event['event_hash'];
            ++$expectedSequence;
        }

        return new AuditIntegrityReport(true, count($events));
    }
}
