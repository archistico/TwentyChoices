<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Domain;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Model\ChoicePairType;
use App\Catalog\Domain\Service\QuestionSetFactory;
use App\Game\Domain\Exception\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class QuestionSetSnapshotTest extends TestCase
{
    public function testItCreatesNineteenRegularSnapshotsAndTheFinalDoor(): void
    {
        $snapshot = (new QuestionSetFactory())->create($this->regularPairs(), $this->finalDoor());
        $questions = $snapshot->questions();

        self::assertCount(20, $questions);
        self::assertSame(1, $questions[0]->stepNumber);
        self::assertSame(ChoicePairType::Regular, $questions[0]->type);
        self::assertSame(20, $questions[19]->stepNumber);
        self::assertSame(ChoicePairType::FinalDoor, $questions[19]->type);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot->hash());
    }

    public function testItsHashIsDeterministic(): void
    {
        $factory = new QuestionSetFactory();
        $first = $factory->create($this->regularPairs(), $this->finalDoor());
        $second = $factory->create($this->regularPairs(), $this->finalDoor());

        self::assertSame($first->hash(), $second->hash());
    }

    public function testItRejectsFewerThanNineteenRegularPairs(): void
    {
        $this->expectException(DomainRuleViolation::class);

        (new QuestionSetFactory())->create(array_slice($this->regularPairs(), 0, 18), $this->finalDoor());
    }

    public function testItRejectsAnInactiveRegularPair(): void
    {
        $pairs = $this->regularPairs();
        $pairs[4]->deactivate();

        $this->expectException(DomainRuleViolation::class);
        (new QuestionSetFactory())->create($pairs, $this->finalDoor());
    }

    public function testItRejectsDuplicateSourcePairs(): void
    {
        $pairs = $this->regularPairs();
        $pairs[18] = $pairs[0];

        $this->expectException(DomainRuleViolation::class);
        (new QuestionSetFactory())->create($pairs, $this->finalDoor());
    }

    /** @return list<ChoicePair> */
    private function regularPairs(): array
    {
        $pairs = [];
        for ($index = 1; $index <= 19; ++$index) {
            $pairs[] = ChoicePair::createRegular(
                'PAIR-'.$index,
                'pair-'.$index,
                'Opzione A '.$index,
                'Opzione B '.$index,
                'Categoria',
                $index * 10,
                new DateTimeImmutable('2026-07-18 10:00:00'),
            );
        }

        return $pairs;
    }

    private function finalDoor(): ChoicePair
    {
        $now = new DateTimeImmutable('2026-07-18 10:00:00');

        return ChoicePair::reconstitute(
            'FINAL-DOOR',
            'porta-finale',
            'Porta rossa',
            'Porta blu',
            'Finale',
            ChoicePairType::FinalDoor,
            true,
            true,
            10_000,
            $now,
            $now,
        );
    }
}
