<?php

declare(strict_types=1);

namespace App\Game\Domain\Model;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\VirtualMoney;
use App\Game\Domain\ValueObject\WinningPath;

final class GameRound
{
    private RoundStatus $status = RoundStatus::Preparing;
    private VirtualMoney $contributions;
    private ?string $winnerPlayCode = null;
    private ?VirtualMoney $frozenJackpot = null;

    private function __construct(
        private readonly string $publicCode,
        private readonly string $questionSetHash,
        private readonly WinningPath $winningPath,
        private readonly string $secretNonce,
        private readonly RoundCommitment $commitment,
        private readonly VirtualMoney $initialJackpot,
    ) {
        $this->contributions = VirtualMoney::fromCents(0);
    }

    public static function prepare(
        string $publicCode,
        string $questionSetHash,
        WinningPath $winningPath,
        string $secretNonce,
        VirtualMoney $initialJackpot,
    ): self {
        if ($initialJackpot->cents !== 1_000_000) {
            throw new DomainRuleViolation('A round must start with exactly 10,000.00 virtual euros.');
        }

        return new self(
            $publicCode,
            $questionSetHash,
            $winningPath,
            $secretNonce,
            RoundCommitment::create($publicCode, $questionSetHash, $winningPath, $secretNonce),
            $initialJackpot,
        );
    }

    public function activate(): void
    {
        $this->requireStatus(RoundStatus::Preparing);
        $this->status = RoundStatus::Active;
    }

    public function addStandardEntry(): void
    {
        $this->requireStatus(RoundStatus::Active);
        $this->contributions = $this->contributions->add(VirtualMoney::fromCents(80));
    }

    public function closeAsWon(string $winnerPlayCode, WinningPath $submittedPath): VirtualMoney
    {
        $this->requireStatus(RoundStatus::Active);

        if ($winnerPlayCode === '') {
            throw new DomainRuleViolation('The winner play code is required.');
        }

        if (!$this->winningPath->equals($submittedPath)) {
            throw new DomainRuleViolation('A losing path cannot close the round.');
        }

        $this->winnerPlayCode = $winnerPlayCode;
        $this->frozenJackpot = $this->currentJackpot();
        $this->status = RoundStatus::Won;

        return $this->frozenJackpot;
    }

    public function settle(): void
    {
        $this->requireStatus(RoundStatus::Won);
        $this->status = RoundStatus::Settled;
    }

    public function currentJackpot(): VirtualMoney
    {
        return $this->initialJackpot->add($this->contributions);
    }

    public function status(): RoundStatus
    {
        return $this->status;
    }

    public function commitment(): RoundCommitment
    {
        return $this->commitment;
    }

    public function winnerPlayCode(): ?string
    {
        return $this->winnerPlayCode;
    }

    public function frozenJackpot(): ?VirtualMoney
    {
        return $this->frozenJackpot;
    }

    /** @return array{roundCode: string, questionSetHash: string, winningPath: string, secretNonceHex: string, commitment: string} */
    public function reveal(): array
    {
        if (!in_array($this->status, [RoundStatus::Won, RoundStatus::Settled], true)) {
            throw new DomainRuleViolation('The winning path can only be revealed after the round has been won.');
        }

        return [
            'roundCode' => $this->publicCode,
            'questionSetHash' => $this->questionSetHash,
            'winningPath' => $this->winningPath->toBitString(),
            'secretNonceHex' => bin2hex($this->secretNonce),
            'commitment' => $this->commitment->hash,
        ];
    }

    private function requireStatus(RoundStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new DomainRuleViolation(sprintf(
                'Expected round status %s, got %s.',
                $expected->value,
                $this->status->value,
            ));
        }
    }
}
