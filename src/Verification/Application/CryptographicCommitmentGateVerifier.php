<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Application\OpenRound;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Throwable;

final readonly class CryptographicCommitmentGateVerifier
{
    public function __construct(
        private Connection $connection,
        private OpenRound $openRound,
        private RoundSecretCipher $secretCipher,
        private RoundVerifier $roundVerifier,
        private string $projectDir,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $logOffsets = $this->captureLogOffsets();
        $this->connection->beginTransaction();

        try {
            $opened = $this->openRound->open();
            $round = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     id
    ,public_code
    ,status
    ,question_set_hash
    ,secret_commitment
    ,encrypted_winning_path
    ,encrypted_secret_nonce
    ,revealed_winning_path
    ,revealed_secret_nonce_hex
    ,verification_published_at
FROM game_round
WHERE id = :roundId
SQL, ['roundId' => $opened->id]);

            if ($round === false) {
                throw new DomainRuleViolation('The cryptographic verification round was not persisted.');
            }

            $pathCiphertext = self::blobToString($round['encrypted_winning_path']);
            $nonceCiphertext = self::blobToString($round['encrypted_secret_nonce']);
            $pathBits = $this->secretCipher->decrypt($pathCiphertext, OpenRound::pathContext($opened->id));
            $nonce = $this->secretCipher->decrypt($nonceCiphertext, OpenRound::nonceContext($opened->id));
            $path = WinningPath::fromBitString($pathBits);
            $nonceHex = bin2hex($nonce);

            $this->verifyPersistedMaterial($checks, $round, $pathBits, $nonce);
            $this->verifyOriginalCommitment($checks, $round, $path, $nonce);
            $this->verifyIndependentTampering($checks, $round, $path, $nonce);
            $this->verifyAuthenticatedEncryption($checks, $opened->id, $pathCiphertext, $nonceCiphertext, $pathBits, $nonce);
            $this->verifyCryptographicImmutability($checks, $opened->id, $round, $pathCiphertext);
            $this->verifyActiveRoundSecrecy($checks, $opened->id, $round, $pathBits, $nonceHex, $logOffsets);
        } catch (Throwable $exception) {
            $checks[] = $this->check(
                'Verification scenario',
                false,
                $exception::class,
                $exception->getMessage(),
            );
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }

        return [
            'status' => in_array('error', array_column($checks, 'status'), true) ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks
     *  @param array<string,mixed> $round
     */
    private function verifyPersistedMaterial(array &$checks, array $round, string $pathBits, string $nonce): void
    {
        $ok = $round['status'] === 'ACTIVE'
            && preg_match('/^[01]{20}$/D', $pathBits) === 1
            && strlen($nonce) === 32
            && preg_match('/^[a-f0-9]{64}$/D', (string) $round['question_set_hash']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $round['secret_commitment']) === 1
            && $round['revealed_winning_path'] === null
            && $round['revealed_secret_nonce_hex'] === null
            && $round['verification_published_at'] === null;

        $checks[] = $this->check(
            'ACTIVE cryptographic material',
            $ok,
            sprintf('status=%s, path=%d bits, nonce=%d bytes, reveal=%s', (string) $round['status'], strlen($pathBits), strlen($nonce), $round['revealed_winning_path'] === null ? 'hidden' : 'public'),
            'Il round ACTIVE deve contenere commitment e ciphertext validi, mentre percorso, nonce e timestamp di pubblicazione restano non pubblicati.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks
     *  @param array<string,mixed> $round
     */
    private function verifyOriginalCommitment(array &$checks, array $round, WinningPath $path, string $nonce): void
    {
        $commitment = RoundCommitment::fromHash((string) $round['secret_commitment']);
        $domainMatches = $commitment->verifies(
            (string) $round['public_code'],
            (string) $round['question_set_hash'],
            $path,
            $nonce,
        );
        $publicVerifier = $this->roundVerifier->verify(
            (string) $round['public_code'],
            (string) $round['question_set_hash'],
            (string) $round['secret_commitment'],
            $path->toBitString(),
            bin2hex($nonce),
        );

        $checks[] = $this->check(
            'Commitment reproduction',
            $domainMatches && $publicVerifier->available && $publicVerifier->commitmentMatches
                && hash_equals((string) $round['secret_commitment'], (string) $publicVerifier->calculatedCommitment),
            $domainMatches && $publicVerifier->commitmentMatches ? 'exact SHA-256 match' : 'mismatch',
            'Codice round, hash del question set, percorso e nonce originali devono ricostruire esattamente il commitment pubblicato.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks
     *  @param array<string,mixed> $round
     */
    private function verifyIndependentTampering(array &$checks, array $round, WinningPath $path, string $nonce): void
    {
        $original = (string) $round['secret_commitment'];
        $roundCode = (string) $round['public_code'];
        $questionHash = (string) $round['question_set_hash'];
        $bits = $path->toBitString();

        $tamperedBits = ($bits[0] === '0' ? '1' : '0').substr($bits, 1);
        $tamperedNonce = $nonce;
        $tamperedNonce[0] = chr(ord($tamperedNonce[0]) ^ 1);
        $tamperedRoundCode = substr($roundCode, 0, -1).(str_ends_with($roundCode, 'A') ? 'B' : 'A');
        $tamperedQuestionHash = ($questionHash[0] === '0' ? '1' : '0').substr($questionHash, 1);

        $candidates = [
            'path bit' => RoundCommitment::create($roundCode, $questionHash, WinningPath::fromBitString($tamperedBits), $nonce)->hash,
            'nonce byte' => RoundCommitment::create($roundCode, $questionHash, $path, $tamperedNonce)->hash,
            'round code' => RoundCommitment::create($tamperedRoundCode, $questionHash, $path, $nonce)->hash,
            'question hash' => RoundCommitment::create($roundCode, $tamperedQuestionHash, $path, $nonce)->hash,
        ];

        foreach ($candidates as $name => $candidate) {
            $checks[] = $this->check(
                'Tamper detection: '.$name,
                !hash_equals($original, $candidate),
                hash_equals($original, $candidate) ? 'collision/match' : 'different commitment',
                'Ogni singola modifica di un input vincolato deve produrre un commitment differente dall’originale.',
            );
        }
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyAuthenticatedEncryption(
        array &$checks,
        string $roundId,
        string $pathCiphertext,
        string $nonceCiphertext,
        string $pathBits,
        string $nonce,
    ): void {
        $wrongContextBlocked = $this->decryptMustFail($pathCiphertext, OpenRound::nonceContext($roundId))
            && $this->decryptMustFail($nonceCiphertext, OpenRound::pathContext($roundId))
            && $this->decryptMustFail($pathCiphertext, OpenRound::pathContext($roundId.'X'));

        $tampered = $pathCiphertext;
        $last = strlen($tampered) - 1;
        if ($last >= 0) {
            $tampered[$last] = chr(ord($tampered[$last]) ^ 1);
        }
        $tamperBlocked = $this->decryptMustFail($tampered, OpenRound::pathContext($roundId));

        $plaintextNotStored = !str_contains($pathCiphertext, $pathBits)
            && !str_contains($nonceCiphertext, $nonce);

        $checks[] = $this->check(
            'Authenticated encryption binding',
            $wrongContextBlocked && $tamperBlocked && $plaintextNotStored,
            sprintf('context=%s, tamper=%s, plaintext=%s', $wrongContextBlocked ? 'bound' : 'reusable', $tamperBlocked ? 'rejected' : 'accepted', $plaintextNotStored ? 'absent' : 'visible'),
            'I ciphertext devono essere autenticati, legati a round/tipo di segreto e non contenere il plaintext in chiaro.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks
     *  @param array<string,mixed> $round
     */
    private function verifyCryptographicImmutability(array &$checks, string $roundId, array $round, string $pathCiphertext): void
    {
        $modifiedCiphertext = $pathCiphertext;
        $last = strlen($modifiedCiphertext) - 1;
        if ($last >= 0) {
            $modifiedCiphertext[$last] = chr(ord($modifiedCiphertext[$last]) ^ 1);
        }

        $cipherBlocked = $this->updateMustFail(
            'UPDATE game_round SET encrypted_winning_path = :value WHERE id = :id',
            ['value' => $modifiedCiphertext, 'id' => $roundId],
            ['value' => ParameterType::BINARY],
        );
        $commitmentBlocked = $this->updateMustFail(
            'UPDATE game_round SET secret_commitment = :value WHERE id = :id',
            ['value' => str_repeat('0', 64), 'id' => $roundId],
        );
        $questionHashBlocked = $this->updateMustFail(
            'UPDATE game_round SET question_set_hash = :value WHERE id = :id',
            ['value' => str_repeat('1', 64), 'id' => $roundId],
        );
        $roundCodeBlocked = $this->updateMustFail(
            'UPDATE game_round SET public_code = :value WHERE id = :id',
            ['value' => ((string) $round['public_code']).'-TAMPER', 'id' => $roundId],
        );

        $checks[] = $this->check(
            'Persisted cryptographic immutability',
            $cipherBlocked && $commitmentBlocked && $questionHashBlocked && $roundCodeBlocked,
            sprintf('cipher=%s, commitment=%s, questionHash=%s, roundCode=%s', $cipherBlocked ? 'blocked' : 'mutable', $commitmentBlocked ? 'blocked' : 'mutable', $questionHashBlocked ? 'blocked' : 'mutable', $roundCodeBlocked ? 'blocked' : 'mutable'),
            'Dopo la creazione del round, ciphertext, commitment, hash del question set e codice pubblico non devono essere modificabili retroattivamente.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks
     *  @param array<string,mixed> $round
     */
    private function verifyActiveRoundSecrecy(
        array &$checks,
        string $roundId,
        array $round,
        string $pathBits,
        string $nonceHex,
        array $logOffsets,
    ): void {
        $auditRows = $this->connection->fetchFirstColumn(
            'SELECT payload_json FROM audit_event WHERE round_id = :roundId',
            ['roundId' => $roundId],
        );
        $auditPayload = implode("\n", array_map(static fn (mixed $value): string => (string) $value, $auditRows));
        $auditClean = !str_contains($auditPayload, $nonceHex)
            && preg_match('/"(?:winningPath|winning_path|revealedWinningPath|secretNonce|nonceHex)"\s*:\s*"'.preg_quote($pathBits, '/').'"/i', $auditPayload) !== 1;

        $filesystemLogsClean = true;
        $logDirectory = rtrim($this->projectDir, '/\\').DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log';
        if (is_dir($logDirectory)) {
            foreach ($this->logFiles($logDirectory) as $logFile) {
                $contents = $this->logContentSince($logFile, $logOffsets[$logFile] ?? 0);
                if ($contents === '') {
                    continue;
                }
                if (str_contains($contents, $nonceHex)
                    || preg_match('/"(?:winningPath|winning_path|revealedWinningPath|secretNonce|nonceHex)"\s*:\s*"'.preg_quote($pathBits, '/').'"/i', $contents) === 1
                ) {
                    $filesystemLogsClean = false;
                    break;
                }
            }
        }

        $dbHidden = $round['revealed_winning_path'] === null
            && $round['revealed_secret_nonce_hex'] === null
            && $round['verification_published_at'] === null;

        $checks[] = $this->check(
            'ACTIVE secret non-disclosure',
            $dbHidden && $auditClean && $filesystemLogsClean,
            sprintf('publicColumns=%s, audit=%s, newLogs=%s', $dbHidden ? 'hidden' : 'revealed', $auditClean ? 'clean' : 'leak', $filesystemLogsClean ? 'clean' : 'leak'),
            'Durante ACTIVE percorso e nonce non devono essere pubblicati nelle colonne di reveal, nell’audit o nelle nuove righe di log prodotte dallo scenario; gli endpoint/DOM sono coperti dal test HTTP dedicato.',
        );
    }

    private function decryptMustFail(string $ciphertext, string $context): bool
    {
        try {
            $this->secretCipher->decrypt($ciphertext, $context);
        } catch (DomainRuleViolation) {
            return true;
        }

        return false;
    }

    /** @param array<string,mixed> $params
     *  @param array<string,ParameterType> $types
     */
    private function updateMustFail(string $sql, array $params, array $types = []): bool
    {
        try {
            $this->connection->executeStatement($sql, $params, $types);
        } catch (Throwable) {
            return true;
        }

        return false;
    }

    /** @return array<string,int> */
    private function captureLogOffsets(): array
    {
        $directory = rtrim($this->projectDir, '/\\').DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'log';
        if (!is_dir($directory)) {
            return [];
        }

        $offsets = [];
        foreach ($this->logFiles($directory) as $file) {
            $size = filesize($file);
            $offsets[$file] = $size === false ? 0 : $size;
        }

        return $offsets;
    }

    private function logContentSince(string $file, int $offset): string
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                return '';
            }
            $contents = stream_get_contents($handle);

            return $contents === false ? '' : $contents;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function logFiles(string $directory): array
    {
        $files = [];
        $stack = [$directory];

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_string($current)) {
                continue;
            }
            $entries = scandir($current);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $current.DIRECTORY_SEPARATOR.$entry;
                if (is_dir($path)) {
                    $stack[] = $path;
                } elseif (is_file($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }

    /** @return array{name:string,status:string,value:string,detail:string} */
    private function check(string $name, bool $ok, string $value, string $detail): array
    {
        return [
            'name' => $name,
            'status' => $ok ? 'ok' : 'error',
            'value' => $value,
            'detail' => $detail,
        ];
    }
}
