<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain;

use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use PHPUnit\Framework\TestCase;

final class RoundCommitmentTest extends TestCase
{
    public function testItVerifiesTheOriginalReveal(): void
    {
        $path = WinningPath::fromBitString('10110001101001011100');
        $nonce = str_repeat("\x7A", 32);
        $questionSetHash = hash('sha256', 'questions-v1');
        $commitment = RoundCommitment::create('R-000001', $questionSetHash, $path, $nonce);

        self::assertTrue($commitment->verifies('R-000001', $questionSetHash, $path, $nonce));
        self::assertFalse($commitment->verifies(
            'R-000001',
            $questionSetHash,
            WinningPath::fromInt($path->value + 1),
            $nonce,
        ));
    }

    /** @dataProvider tamperedInputProvider */
    public function testEveryBoundInputIndependentlyChangesTheCommitment(string $tamperedInput): void
    {
        $roundCode = 'R-20260719-ABCDEF123456';
        $questionSetHash = hash('sha256', 'question-set');
        $path = WinningPath::fromBitString('10110001101001011100');
        $nonce = str_repeat("\x5A", 32);
        $original = RoundCommitment::create($roundCode, $questionSetHash, $path, $nonce)->hash;

        $candidateRoundCode = $roundCode;
        $candidateQuestionHash = $questionSetHash;
        $candidatePath = $path;
        $candidateNonce = $nonce;

        if ($tamperedInput === 'path') {
            $bits = $path->toBitString();
            $candidatePath = WinningPath::fromBitString(($bits[0] === '0' ? '1' : '0').substr($bits, 1));
        } elseif ($tamperedInput === 'nonce') {
            $candidateNonce[0] = chr(ord($candidateNonce[0]) ^ 1);
        } elseif ($tamperedInput === 'round-code') {
            $candidateRoundCode = 'R-20260719-ABCDEF123457';
        } elseif ($tamperedInput === 'question-hash') {
            $candidateQuestionHash = ($questionSetHash[0] === '0' ? '1' : '0').substr($questionSetHash, 1);
        }

        $tampered = RoundCommitment::create(
            $candidateRoundCode,
            $candidateQuestionHash,
            $candidatePath,
            $candidateNonce,
        )->hash;

        self::assertNotSame($original, $tampered);
    }

    /** @return iterable<string, array{string}> */
    public static function tamperedInputProvider(): iterable
    {
        yield 'one path bit' => ['path'];
        yield 'one nonce byte' => ['nonce'];
        yield 'round public code' => ['round-code'];
        yield 'question-set hash' => ['question-hash'];
    }
}
