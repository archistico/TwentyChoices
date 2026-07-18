<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Game\Domain\Exception\DomainRuleViolation;

final readonly class RoundQuestionSnapshot
{
    public function __construct(
        public int $stepNumber,
        public string $choicePairId,
        public string $choicePairCode,
        public string $optionAText,
        public string $optionBText,
        public string $category,
        public ChoicePairType $type,
    ) {
        if ($stepNumber < 1 || $stepNumber > 20) {
            throw new DomainRuleViolation('A snapshot step must be between 1 and 20.');
        }

        if ($choicePairId === '' || $choicePairCode === '') {
            throw new DomainRuleViolation('A snapshot must retain its source pair identity.');
        }

        if ($optionAText === '' || $optionBText === '' || $category === '') {
            throw new DomainRuleViolation('A snapshot must contain complete display data.');
        }

        if ($stepNumber === 20 && $type !== ChoicePairType::FinalDoor) {
            throw new DomainRuleViolation('Step 20 must contain the mandatory final door.');
        }

        if ($stepNumber < 20 && $type !== ChoicePairType::Regular) {
            throw new DomainRuleViolation('Only regular pairs are allowed before step 20.');
        }
    }

    /** @return array{step: int, sourceId: string, code: string, optionA: string, optionB: string, category: string, type: string} */
    public function canonicalData(): array
    {
        return [
            'step' => $this->stepNumber,
            'sourceId' => $this->choicePairId,
            'code' => $this->choicePairCode,
            'optionA' => $this->optionAText,
            'optionB' => $this->optionBText,
            'category' => $this->category,
            'type' => $this->type->value,
        ];
    }
}
