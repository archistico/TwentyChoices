<?php

declare(strict_types=1);

$root = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = $root.'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Model\ChoicePairType;
use App\Catalog\Domain\Service\QuestionSetFactory;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Model\GameRound;
use App\Game\Domain\Model\RoundStatus;
use App\Game\Domain\ValueObject\Choice;
use App\Game\Domain\ValueObject\PlayPath;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\VirtualMoney;
use App\Game\Domain\ValueObject\WinningPath;

/** @var array<string, Closure(): void> $tests */
$tests = [];

$tests['2^20 combinations'] = static function (): void {
    if (WinningPath::COMBINATIONS !== 1_048_576 || WinningPath::MAX_VALUE !== 1_048_575) {
        throw new RuntimeException('Invalid number of path combinations.');
    }
};

$tests['winning path round-trip'] = static function (): void {
    $bits = '10110001101001011100';
    $path = WinningPath::fromBitString($bits);

    if ($path->toBitString() !== $bits || $path->choiceAt(1) !== Choice::B || $path->choiceAt(20) !== Choice::A) {
        throw new RuntimeException('The winning path does not round-trip correctly.');
    }
};

$tests['commitment detects tampering'] = static function (): void {
    $path = WinningPath::fromInt(42);
    $nonce = str_repeat("\x55", 32);
    $questions = hash('sha256', 'questions');
    $commitment = RoundCommitment::create('R-1', $questions, $path, $nonce);

    if (!$commitment->verifies('R-1', $questions, $path, $nonce)) {
        throw new RuntimeException('The original commitment does not verify.');
    }

    if ($commitment->verifies('R-1', $questions, WinningPath::fromInt(43), $nonce)) {
        throw new RuntimeException('A modified path incorrectly verifies.');
    }
};

$tests['play path stops at twenty choices'] = static function (): void {
    $path = PlayPath::fromChoices(array_fill(0, 20, Choice::A));

    if (!$path->isComplete() || !$path->matches(WinningPath::fromInt(0))) {
        throw new RuntimeException('The complete play path is invalid.');
    }

    try {
        $path->append(Choice::B);
        throw new RuntimeException('Expected DomainRuleViolation was not thrown.');
    } catch (DomainRuleViolation) {
    }
};

$tests['round freezes 80 cents per standard entry'] = static function (): void {
    $winning = WinningPath::fromInt(77);
    $round = GameRound::prepare(
        'R-1',
        hash('sha256', 'questions'),
        $winning,
        str_repeat("\x66", 32),
        VirtualMoney::euros(10_000),
    );
    $round->activate();
    $round->addStandardEntry();
    $round->addStandardEntry();
    $payout = $round->closeAsWon('G-1', $winning);

    if ($round->status() !== RoundStatus::Won || $payout->cents !== 1_000_160) {
        throw new RuntimeException('The round payout was not frozen correctly.');
    }
};


$tests['regular catalog pairs can be deactivated'] = static function (): void {
    $pair = ChoicePair::createRegular('PAIR-1', 'mare-montagna', 'Mare', 'Montagna', 'Natura', 10);
    $pair->deactivate();

    if ($pair->isActive()) {
        throw new RuntimeException('A regular pair was not deactivated.');
    }
};

$tests['final door is protected'] = static function (): void {
    $now = new DateTimeImmutable('2026-07-18 10:00:00');
    $door = ChoicePair::reconstitute(
        'FINAL',
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

    try {
        $door->deactivate();
        throw new RuntimeException('The final door was incorrectly deactivated.');
    } catch (DomainRuleViolation) {
    }
};

$tests['question snapshot contains nineteen pairs plus final door'] = static function (): void {
    $now = new DateTimeImmutable('2026-07-18 10:00:00');
    $pairs = [];
    for ($index = 1; $index <= 19; ++$index) {
        $pairs[] = ChoicePair::createRegular(
            'PAIR-'.$index,
            'pair-'.$index,
            'A '.$index,
            'B '.$index,
            'Test',
            $index * 10,
            $now,
        );
    }

    $door = ChoicePair::reconstitute(
        'FINAL',
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
    $snapshot = (new QuestionSetFactory())->create($pairs, $door);

    if (count($snapshot->questions()) !== 20 || $snapshot->questions()[19]->type !== ChoicePairType::FinalDoor) {
        throw new RuntimeException('The question snapshot is not valid.');
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "[PASS] {$name}\n";
    } catch (Throwable $exception) {
        ++$failed;
        fwrite(STDERR, "[FAIL] {$name}: {$exception->getMessage()}\n");
    }
}

echo sprintf("\n%d test(s), %d failure(s).\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);
