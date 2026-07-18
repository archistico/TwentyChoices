<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Model\ChoicePairType;
use App\Catalog\Domain\Model\QuestionSetSnapshot;
use App\Catalog\Domain\Model\RoundQuestionSnapshot;
use App\Game\Domain\Exception\DomainRuleViolation;

final class QuestionSetFactory
{
    /** @param list<ChoicePair> $regularPairs */
    public function create(array $regularPairs, ChoicePair $finalDoor): QuestionSetSnapshot
    {
        if (count($regularPairs) !== 19) {
            throw new DomainRuleViolation('Exactly nineteen regular pairs are required.');
        }

        if ($finalDoor->type() !== ChoicePairType::FinalDoor || !$finalDoor->isActive()) {
            throw new DomainRuleViolation('The twentieth pair must be the active mandatory final door.');
        }

        $questions = [];
        foreach ($regularPairs as $index => $pair) {
            if (!$pair instanceof ChoicePair) {
                throw new DomainRuleViolation('Every regular item must be a ChoicePair.');
            }

            if ($pair->type() !== ChoicePairType::Regular || !$pair->isActive()) {
                throw new DomainRuleViolation('Question sets can use active regular pairs only.');
            }

            $questions[] = $this->snapshot($index + 1, $pair);
        }

        $questions[] = $this->snapshot(20, $finalDoor);

        return new QuestionSetSnapshot($questions);
    }

    private function snapshot(int $step, ChoicePair $pair): RoundQuestionSnapshot
    {
        return new RoundQuestionSnapshot(
            $step,
            $pair->id(),
            $pair->code(),
            $pair->optionAText(),
            $pair->optionBText(),
            $pair->category(),
            $pair->type(),
        );
    }
}
