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
use App\Catalog\Domain\Service\CryptographicChoicePairSelector;
use App\Catalog\Domain\Service\QuestionSetFactory;
use App\Game\Domain\Exception\ChoiceTooEarly;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Model\GameRound;
use App\Game\Domain\Model\RoundStatus;
use App\Game\Domain\ValueObject\Choice;
use App\Game\Domain\ValueObject\PlayPath;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\StepTiming;
use App\Game\Domain\ValueObject\VirtualMoney;
use App\Game\Domain\ValueObject\WinningPath;
use App\Game\Infrastructure\Security\AuthenticatedRoundSecretCipher;
use App\Shared\Security\SecureTokenGenerator;
use App\Security\Application\SecurityEventLogger;
use App\Security\Admin\AdminAccessPolicy;
use App\Security\Admin\AdminIdentity;
use App\Security\Admin\AdminPasswordHasher;
use App\Security\Admin\AdminRole;
use App\Shared\Time\Clock;
use App\Simulation\Domain\SimulationEngine;
use App\Simulation\Domain\SimulationProfile;
use App\Simulation\Domain\SimulationRequest;
use App\Verification\Application\PlayReceiptHasher;
use App\Verification\Application\RoundVerifier;

/** @var array<string, Closure(): void> $tests */
$tests = [];


$tests['choice-too-early is a specialized domain violation'] = static function (): void {
    $availableAt = new DateTimeImmutable('2026-07-18 12:00:02.000000 UTC');
    $exception = new ChoiceTooEarly($availableAt, 1_250);

    if (!$exception instanceof DomainRuleViolation
        || $exception->availableAt != $availableAt
        || $exception->remainingMilliseconds !== 1_250
    ) {
        throw new RuntimeException('ChoiceTooEarly exception hierarchy is invalid.');
    }
};

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

$tests['authenticated encryption rejects tampering'] = static function (): void {
    $cipher = new AuthenticatedRoundSecretCipher('domain-test-secret-with-enough-entropy');
    $context = 'round:TEST:winning-path';
    $encrypted = $cipher->encrypt('10110001101001011100', $context);

    if ($cipher->decrypt($encrypted, $context) !== '10110001101001011100') {
        throw new RuntimeException('The encrypted secret did not round-trip.');
    }

    $last = strlen($encrypted) - 1;
    $encrypted[$last] = chr(ord($encrypted[$last]) ^ 1);

    try {
        $cipher->decrypt($encrypted, $context);
        throw new RuntimeException('A tampered ciphertext was accepted.');
    } catch (DomainRuleViolation) {
    }
};

$tests['cryptographic selector returns nineteen distinct pairs'] = static function (): void {
    $now = new DateTimeImmutable('2026-07-18 10:00:00 UTC');
    $pairs = [];
    for ($index = 1; $index <= 25; ++$index) {
        $pairs[] = ChoicePair::createRegular(
            'SELECT-'.$index,
            'select-'.$index,
            'A '.$index,
            'B '.$index,
            'Test',
            $index * 10,
            $now,
        );
    }

    $selected = (new CryptographicChoicePairSelector())->select($pairs);
    $ids = array_map(static fn (ChoicePair $pair): string => $pair->id(), $selected);
    if (count($selected) !== 19 || count(array_unique($ids)) !== 19) {
        throw new RuntimeException('The selected question pairs are not nineteen distinct items.');
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


$tests['step timer enforces two full seconds'] = static function (): void {
    $shown = new DateTimeImmutable('2026-07-18 12:00:00.250000 UTC');
    $timing = StepTiming::start($shown);
    $almost = $shown->modify('+1 second')->modify('+999000 microseconds');

    if ($timing->isAvailableAt($almost) || !$timing->isAvailableAt($shown->modify('+2 seconds'))) {
        throw new RuntimeException('The two-second server timer is not exact.');
    }
};

$tests['security tokens are random URL-safe and hashed'] = static function (): void {
    $tokens = new SecureTokenGenerator();
    $first = $tokens->generate();
    $second = $tokens->generate();

    if ($first === $second || !$tokens->isWellFormed($first) || strlen($tokens->hash($first)) !== 64) {
        throw new RuntimeException('Secure token generation is invalid.');
    }
};


$tests['published round material recomputes commitment'] = static function (): void {
    $path = WinningPath::fromInt(321);
    $nonce = str_repeat("\x31", 32);
    $questions = hash('sha256', 'verification-questions');
    $commitment = RoundCommitment::create('R-VERIFY', $questions, $path, $nonce);
    $result = (new RoundVerifier())->verify('R-VERIFY', $questions, $commitment->hash, $path->toBitString(), bin2hex($nonce));

    if (!$result->available || !$result->commitmentMatches) {
        throw new RuntimeException('Published round material did not verify.');
    }
};

$tests['receipt hash binds terminal play snapshot'] = static function (): void {
    $first = PlayReceiptHasher::hash('V-1', 'G-1', 'R-1', 7, 'STANDARD', 'LOST', 20, str_repeat('0', 20), '2026-07-18 15:00:00.000000');
    $second = PlayReceiptHasher::hash('V-1', 'G-1', 'R-1', 7, 'STANDARD', 'WON', 20, str_repeat('0', 20), '2026-07-18 15:00:00.000000');

    if ($first === $second || strlen($first) !== 64) {
        throw new RuntimeException('Receipt hashing does not bind the receipt snapshot.');
    }
};

$tests['simulation is deterministic with a fixed seed'] = static function (): void {
    $engine = new SimulationEngine();
    $request = new SimulationRequest(2_000, SimulationProfile::UNIFORM, 5_000, 1234);
    $first = $engine->run($request);
    $second = $engine->run($request);

    if ($first->uniquePaths !== $second->uniquePaths || $first->choiceStats !== $second->choiceStats) {
        throw new RuntimeException('Simulation with the same seed is not deterministic.');
    }
};

$tests['strong synthetic bias increases duplicate concentration'] = static function (): void {
    $engine = new SimulationEngine();
    $uniform = $engine->run(new SimulationRequest(10_000, SimulationProfile::UNIFORM, 5_000, 99));
    $biased = $engine->run(new SimulationRequest(10_000, SimulationProfile::FIXED_A_BIAS, 8_500, 99));

    if ($biased->duplicatePlays <= $uniform->duplicatePlays || $biased->effectivePathCount >= $uniform->effectivePathCount) {
        throw new RuntimeException('Synthetic bias does not concentrate path distribution as expected.');
    }
};



$tests['security logger redacts sensitive fields'] = static function (): void {
    $root = sys_get_temp_dir().'/twentychoices-domain-log-'.bin2hex(random_bytes(5));
    $clock = new class implements Clock {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-07-18 20:00:00 UTC');
        }
    };
    $logger = new SecurityEventLogger($clock, $root);
    $logger->log('DOMAIN_TEST', [
        'requestId' => 'req-safe',
        'challengeToken' => 'never-log-this-token',
        'nested' => ['secretNonce' => 'never-log-this-nonce'],
    ]);

    $path = $root.'/var/log/security.jsonl';
    $contents = is_file($path) ? (string) file_get_contents($path) : '';
    if (!str_contains($contents, '[REDACTED]') || str_contains($contents, 'never-log-this-token') || str_contains($contents, 'never-log-this-nonce')) {
        throw new RuntimeException('Security log redaction leaked a sensitive value.');
    }

    @unlink($path);
    @rmdir($root.'/var/log');
    @rmdir($root.'/var');
    @rmdir($root);
};




$tests['admin password hashing rejects plaintext and weak credentials'] = static function (): void {
    $hasher = new AdminPasswordHasher();
    $hash = $hasher->hash('TwentyChoices2026!');
    if ($hash === 'TwentyChoices2026!' || !$hasher->verify('TwentyChoices2026!', $hash)) {
        throw new RuntimeException('Admin password hashing is invalid.');
    }
    try {
        $hasher->hash('short');
        throw new RuntimeException('A weak admin password was accepted.');
    } catch (InvalidArgumentException) {
    }
};

$tests['admin roles are deny-by-default'] = static function (): void {
    $policy = new AdminAccessPolicy();
    $operator = new AdminIdentity('1', 'operator', AdminRole::OPERATOR, 1);
    $auditor = new AdminIdentity('2', 'auditor', AdminRole::AUDITOR, 1);
    if (!$policy->allows($operator, 'admin_round_open')
        || $policy->allows($operator, 'admin_diagnostics_index')
        || !$policy->allows($auditor, 'admin_diagnostics_index')
        || $policy->allows($auditor, 'admin_round_open')
        || $policy->allows($auditor, 'admin_unknown_route')) {
        throw new RuntimeException('Admin authorization policy is invalid.');
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
