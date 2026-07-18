<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Game\Domain\Exception\DomainRuleViolation;

final readonly class QuestionSetSnapshot
{
    /** @var list<RoundQuestionSnapshot> */
    private array $questions;
    private string $hash;

    /** @param list<RoundQuestionSnapshot> $questions */
    public function __construct(array $questions)
    {
        if (count($questions) !== 20) {
            throw new DomainRuleViolation('A round question set must contain exactly twenty snapshots.');
        }

        $sourceIds = [];
        foreach ($questions as $index => $question) {
            if (!$question instanceof RoundQuestionSnapshot) {
                throw new DomainRuleViolation('Every question must be a RoundQuestionSnapshot.');
            }

            $expectedStep = $index + 1;
            if ($question->stepNumber !== $expectedStep) {
                throw new DomainRuleViolation('Snapshot steps must be consecutive and ordered from 1 to 20.');
            }

            if (isset($sourceIds[$question->choicePairId])) {
                throw new DomainRuleViolation('The same choice pair cannot appear twice in one round.');
            }

            $sourceIds[$question->choicePairId] = true;
        }

        $this->questions = array_values($questions);
        $this->hash = hash('sha256', json_encode(
            array_map(
                static fn (RoundQuestionSnapshot $question): array => $question->canonicalData(),
                $this->questions,
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return list<RoundQuestionSnapshot> */
    public function questions(): array
    {
        return $this->questions;
    }

    public function hash(): string
    {
        return $this->hash;
    }
}
