<?php

declare(strict_types=1);

namespace App\Audit\Application;

use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class AuditLogger
{
    private const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function append(
        string $eventType,
        array $payload = [],
        ?string $roundId = null,
        ?string $playId = null,
        ?string $requestId = null,
    ): void {
        $last = $this->connection->fetchAssociative(
            'SELECT sequence_number, event_hash FROM audit_event ORDER BY sequence_number DESC LIMIT 1',
        );
        $sequence = $last === false ? 1 : (int) $last['sequence_number'] + 1;
        $previousHash = $last === false ? self::GENESIS_HASH : (string) $last['event_hash'];
        $occurredAt = $this->clock->now()->format('Y-m-d H:i:s.u');
        $payloadJson = json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
        $eventHash = hash('sha256', implode('|', [
            (string) $sequence,
            $previousHash,
            $eventType,
            $roundId ?? '',
            $playId ?? '',
            $requestId ?? '',
            $occurredAt,
            $payloadJson,
        ]));

        $this->connection->insert('audit_event', [
            'id' => (string) new Ulid(),
            'sequence_number' => $sequence,
            'round_id' => $roundId,
            'play_id' => $playId,
            'event_type' => $eventType,
            'payload_json' => $payloadJson,
            'request_id' => $requestId,
            'occurred_at' => $occurredAt,
            'previous_hash' => $previousHash,
            'event_hash' => $eventHash,
        ]);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
