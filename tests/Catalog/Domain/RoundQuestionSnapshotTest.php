<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Domain;

use App\Catalog\Domain\Model\ChoicePairType;
use App\Catalog\Domain\Model\RoundQuestionSnapshot;
use App\Game\Domain\Exception\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class RoundQuestionSnapshotTest extends TestCase
{
    public function testStepTwentyMustBeTheFinalDoor(): void
    {
        $this->expectException(DomainRuleViolation::class);

        new RoundQuestionSnapshot(
            20,
            'PAIR-1',
            'mare-montagna',
            'Mare',
            'Montagna',
            'Natura',
            ChoicePairType::Regular,
        );
    }

    public function testTheFinalDoorCannotAppearBeforeStepTwenty(): void
    {
        $this->expectException(DomainRuleViolation::class);

        new RoundQuestionSnapshot(
            19,
            'FINAL-DOOR',
            'porta-finale',
            'Porta rossa',
            'Porta blu',
            'Finale',
            ChoicePairType::FinalDoor,
        );
    }
}
