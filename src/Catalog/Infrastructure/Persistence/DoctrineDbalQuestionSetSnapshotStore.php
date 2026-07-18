<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Domain\Model\QuestionSetSnapshot;
use App\Catalog\Domain\Repository\QuestionSetSnapshotStore;
use App\Game\Domain\Exception\DomainRuleViolation;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class DoctrineDbalQuestionSetSnapshotStore implements QuestionSetSnapshotStore
{
    public function __construct(private Connection $connection)
    {
    }

    public function saveForRound(string $roundId, QuestionSetSnapshot $snapshot): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->persist($roundId, $snapshot);

            return;
        }

        $this->connection->transactional(
            fn (): null => $this->persist($roundId, $snapshot),
        );
    }

    private function persist(string $roundId, QuestionSetSnapshot $snapshot): null
    {
        $existing = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM round_question WHERE round_id = :roundId',
            ['roundId' => $roundId],
        );

        if ($existing !== 0) {
            throw new DomainRuleViolation('A round question snapshot is immutable and can be written only once.');
        }

        foreach ($snapshot->questions() as $question) {
            $this->connection->insert('round_question', [
                'id' => (string) new Ulid(),
                'round_id' => $roundId,
                'choice_pair_id' => $question->choicePairId,
                'step_number' => $question->stepNumber,
                'option_a_text_snapshot' => $question->optionAText,
                'option_b_text_snapshot' => $question->optionBText,
                'option_a_image_snapshot' => null,
                'option_b_image_snapshot' => null,
                'choice_pair_code_snapshot' => $question->choicePairCode,
                'category_snapshot' => $question->category,
                'pair_type_snapshot' => $question->type->value,
            ]);
        }

        $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE game_round
SET question_set_hash = :questionSetHash
WHERE id = :roundId
  AND status = 'PREPARING'
SQL, [
            'questionSetHash' => $snapshot->hash(),
            'roundId' => $roundId,
        ]);

        if ($updated !== 1) {
            throw new DomainRuleViolation('Question snapshots can be attached only to a preparing round.');
        }

        return null;
    }
}
