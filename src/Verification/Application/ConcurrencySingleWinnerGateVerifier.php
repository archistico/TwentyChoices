<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Application\OpenPlayStep;
use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Security\SecureTokenGenerator;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class ConcurrencySingleWinnerGateVerifier
{
    private const RACE_COUNT = 3;
    private const INITIAL_JACKPOT_CENTS = 1_000_000;

    public function __construct(
        private Connection $connection,
        private OpenRound $openRound,
        private PlayerSessionRegistry $sessions,
        private StartPlay $startPlay,
        private OpenPlayStep $openPlayStep,
        private SubmitChoice $submitChoice,
        private SecureTokenGenerator $tokens,
        private RoundSecretCipher $secretCipher,
        private Clock $clock,
        private string $projectDir,
        private string $kernelEnvironment,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $databasePath = $this->databasePath();
        $backupPath = $databasePath.'.m198-backup-'.bin2hex(random_bytes(6));
        $runtimeDir = $this->projectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'m1.9.8-'.bin2hex(random_bytes(6));
        $snapshotCreated = false;

        try {
            if ($this->kernelEnvironment !== 'test') {
                throw new DomainRuleViolation('Il gate M1.9.8 può essere eseguito esclusivamente con --env=test.');
            }
            if (!function_exists('proc_open')) {
                throw new DomainRuleViolation('proc_open è necessario per il gate multiprocesso M1.9.8.');
            }

            $this->snapshotDatabase($backupPath);
            $snapshotCreated = true;
            if (!mkdir($runtimeDir, 0777, true) && !is_dir($runtimeDir)) {
                throw new RuntimeException('Impossibile creare la directory runtime M1.9.8.');
            }

            $journalMode = strtolower((string) $this->connection->fetchOne('PRAGMA journal_mode = WAL'));
            $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
            $this->connection->executeStatement('PRAGMA synchronous = FULL');
            $this->connection->executeStatement('PRAGMA wal_autocheckpoint = 1000');

            $checks[] = $this->check(
                'SQLite concurrency runtime',
                $journalMode === 'wal' && (int) $this->connection->fetchOne('PRAGMA busy_timeout') >= 5000,
                sprintf('journal=%s, busy_timeout=%dms', $journalMode, (int) $this->connection->fetchOne('PRAGMA busy_timeout')),
                'La race M1.9.8 deve usare lo stesso modello operativo previsto per il runtime concorrente: WAL, busy timeout e transazioni separate in processi PHP distinti.',
            );

            $checks[] = $this->verifySchemaGuards();

            $round = $this->ensureCleanActiveRound();
            $raceSummaries = [];
            $allRacesOk = true;
            $allStaleOk = true;
            $allImmutable = true;

            for ($iteration = 1; $iteration <= self::RACE_COUNT; ++$iteration) {
                $race = $this->runRace($round['id'], $iteration, $runtimeDir);
                $raceSummaries[] = sprintf(
                    '#%d winner=%s loser=%s payout=%d active=%d',
                    $iteration,
                    $race['winnerCode'],
                    $race['loserDisposition'],
                    $race['payoutCount'],
                    $race['activeRoundCount'],
                );
                $allRacesOk = $allRacesOk && $race['raceOk'];
                $allStaleOk = $allStaleOk && $race['staleOk'];
                $allImmutable = $allImmutable && $race['winnerImmutable'];
                $round = ['id' => $race['nextRoundId']];
            }

            $checks[] = $this->check(
                'Three real multi-process winning races',
                $allRacesOk,
                implode('; ', $raceSummaries),
                'Ogni race avvia due processi PHP sincronizzati sulla stessa scelta 20: deve emergere un solo COMPLETED_WON, un solo winner_play_id, un solo JACKPOT_PAYOUT e un solo nuovo round ACTIVE; il concorrente perdente deve essere gestito come stato ormai chiuso, non come secondo vincitore.',
            );

            $checks[] = $this->check(
                'Stale pre-win requests are harmless',
                $allStaleOk,
                sprintf('%d/%d stale requests rejected without mutation', $allStaleOk ? self::RACE_COUNT : 0, self::RACE_COUNT),
                'Una challenge aperta prima della vittoria ma inviata dopo il settlement deve essere respinta usando lo stato autorevole persistito e non deve creare step, ledger, payout, round o audit aggiuntivi.',
            );

            $checks[] = $this->check(
                'Winner cannot be overwritten',
                $allImmutable,
                sprintf('%d/%d overwrite attempts blocked', $allImmutable ? self::RACE_COUNT : 0, self::RACE_COUNT),
                'Dopo il first-winner claim nessuna richiesta o UPDATE successivo deve poter sostituire winner_play_id o produrre un secondo payout.',
            );
        } catch (Throwable $exception) {
            $checks[] = $this->check('Verification scenario', false, $exception::class, $exception->getMessage());
        } finally {
            try {
                if ($snapshotCreated) {
                    $this->restoreDatabase($backupPath, $databasePath);
                }
            } catch (Throwable $restoreException) {
                $checks[] = $this->check('Test database restore', false, $restoreException::class, $restoreException->getMessage());
            }
            $this->removeTree($runtimeDir);
            if (is_file($backupPath)) {
                @unlink($backupPath);
            }
        }

        return [
            'status' => in_array('error', array_column($checks, 'status'), true) ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /** @return array{name:string,status:string,value:string,detail:string} */
    private function verifySchemaGuards(): array
    {
        $required = [
            ['index', 'uniq_single_active_round'],
            ['index', 'uniq_round_winner_play'],
            ['index', 'uniq_round_jackpot_payout'],
            ['trigger', 'trg_round_protect_win_fields'],
            ['trigger', 'trg_round_validate_win'],
        ];
        $missing = [];
        foreach ($required as [$type, $name]) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM sqlite_master WHERE type = :type AND name = :name',
                ['type' => $type, 'name' => $name],
            ) === 1;
            if (!$exists) {
                $missing[] = $name;
            }
        }

        return $this->check(
            'Database single-winner guards',
            $missing === [],
            $missing === [] ? '3 unique indexes + 2 winner triggers present' : 'missing: '.implode(', ', $missing),
            'L’unicità del vincitore non deve dipendere dal timing applicativo: active round, winner, payout e immutabilità post-claim devono essere protetti direttamente dallo schema SQLite.',
        );
    }

    /** @return array{id:string} */
    private function ensureCleanActiveRound(): array
    {
        $active = $this->connection->fetchAssociative("SELECT id FROM game_round WHERE status = 'ACTIVE' LIMIT 1");
        if ($active === false) {
            $opened = $this->openRound->open();

            return ['id' => $opened->id];
        }

        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM play WHERE round_id = :id', ['id' => $active['id']]) !== 0) {
            throw new DomainRuleViolation('Il gate M1.9.8 richiede un database di test senza play preesistenti nel round ACTIVE.');
        }

        return ['id' => (string) $active['id']];
    }

    /**
     * @return array{raceOk:bool,staleOk:bool,winnerImmutable:bool,winnerCode:string,loserDisposition:string,payoutCount:int,activeRoundCount:int,nextRoundId:string}
     */
    private function runRace(string $roundId, int $iteration, string $runtimeDir): array
    {
        $winningPath = $this->winningPath($roundId);
        $sessionA = $this->sessions->resolve(null);
        $sessionB = $this->sessions->resolve(null);
        $staleSession = $this->sessions->resolve(null);
        $playA = $this->startPlay->start($sessionA->id);
        $playB = $this->startPlay->start($sessionB->id);
        $stalePlay = $this->startPlay->start($staleSession->id);

        $contribution = (int) $this->connection->fetchOne('SELECT entry_contribution_cents FROM game_round WHERE id = :id', ['id' => $roundId]);
        if ($contribution !== 240) {
            throw new DomainRuleViolation(sprintf('La race M1.9.8 #%d richiede esattamente tre nuove quote STANDARD (contribution=%d).', $iteration, $contribution));
        }

        $this->advanceToNineteen($roundId, $playA->id, $playA->publicCode, $sessionA->id, $winningPath);
        $this->advanceToNineteen($roundId, $playB->id, $playB->publicCode, $sessionB->id, $winningPath);
        $staleScreen = $this->openPlayStep->open($stalePlay->publicCode, $staleSession->id);
        if ($staleScreen->challengeToken === null || $staleScreen->requestId === null) {
            throw new DomainRuleViolation('La richiesta stale M1.9.8 non ha ricevuto una challenge pre-win.');
        }

        $finalChallengeA = $this->seedAvailableStep($playA->id, $roundId, 20);
        $finalChallengeB = $this->seedAvailableStep($playB->id, $roundId, 20);
        $roundCountBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round');

        $barrier = $runtimeDir.DIRECTORY_SEPARATOR.sprintf('race-%d.barrier', $iteration);
        $workers = [
            $this->startWorker($runtimeDir, $iteration, 'a', $barrier, [
                'playCode' => $playA->publicCode,
                'sessionId' => $sessionA->id,
                'challengeToken' => $finalChallengeA,
                'selectedOption' => $winningPath[19] === '0' ? 'A' : 'B',
                'requestId' => (string) Uuid::v7(),
            ]),
            $this->startWorker($runtimeDir, $iteration, 'b', $barrier, [
                'playCode' => $playB->publicCode,
                'sessionId' => $sessionB->id,
                'challengeToken' => $finalChallengeB,
                'selectedOption' => $winningPath[19] === '0' ? 'A' : 'B',
                'requestId' => (string) Uuid::v7(),
            ]),
        ];

        try {
            $this->waitForWorkersReady($workers);
            if (file_put_contents($barrier, 'go', LOCK_EX) === false) {
                throw new RuntimeException('Impossibile rilasciare la barriera M1.9.8.');
            }
            $results = $this->waitForWorkers($workers);
        } finally {
            foreach ($workers as $worker) {
                if (!is_resource($worker['process'])) {
                    continue;
                }
                $status = proc_get_status($worker['process']);
                if (($status['running'] ?? false) === true) {
                    proc_terminate($worker['process']);
                }
            }
        }

        $successes = array_values(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'success' && ($result['outcome'] ?? null) === 'WON'));
        $errors = array_values(array_filter($results, static fn (array $result): bool => ($result['status'] ?? null) === 'error'));
        $loserHandledAsDomainState = count($errors) === 1
            && str_contains((string) ($errors[0]['exception'] ?? ''), 'DomainRuleViolation')
            && !str_contains(strtolower((string) ($errors[0]['message'] ?? '')), 'database is locked');

        $round = $this->connection->fetchAssociative(
            'SELECT status, winner_play_id, frozen_jackpot_cents FROM game_round WHERE id = :id',
            ['id' => $roundId],
        );
        if ($round === false) {
            throw new DomainRuleViolation('Il round concorrente M1.9.8 non è più leggibile.');
        }
        $winnerId = (string) $round['winner_play_id'];
        $winnerCode = $winnerId === $playA->id ? $playA->publicCode : ($winnerId === $playB->id ? $playB->publicCode : 'unknown');
        $loserId = $winnerId === $playA->id ? $playB->id : $playA->id;
        $payoutCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'", ['id' => $roundId]);
        $payoutAmount = (int) $this->connection->fetchOne("SELECT COALESCE(SUM(amount_cents), 0) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'", ['id' => $roundId]);
        $activeRoundCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'");
        $nextRound = $this->connection->fetchAssociative("SELECT id FROM game_round WHERE status = 'ACTIVE' LIMIT 1");
        if ($nextRound === false) {
            throw new DomainRuleViolation('La race M1.9.8 non ha lasciato un round ACTIVE successivo.');
        }

        $raceOk = count($successes) === 1
            && $loserHandledAsDomainState
            && (string) $round['status'] === 'SETTLED'
            && in_array($winnerId, [$playA->id, $playB->id], true)
            && (int) $round['frozen_jackpot_cents'] === self::INITIAL_JACKPOT_CENTS + 240
            && (string) $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $winnerId]) === 'COMPLETED_WON'
            && (string) $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $loserId]) === 'CREDITED'
            && (string) $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $stalePlay->id]) === 'CREDITED'
            && $payoutCount === 1
            && $payoutAmount === self::INITIAL_JACKPOT_CENTS + 240
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :id', ['id' => $roundId]) === 2
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt WHERE round_id = :id', ['id' => $roundId]) === 3
            && $activeRoundCount === 1
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round') === $roundCountBefore + 1;

        $beforeStale = $this->stateFingerprint($roundId);
        $staleFailure = null;
        try {
            $this->submitChoice->submit(
                $stalePlay->publicCode,
                $staleSession->id,
                $staleScreen->challengeToken,
                $winningPath[0] === '0' ? 'A' : 'B',
                $staleScreen->requestId,
                60_000,
            );
        } catch (Throwable $exception) {
            $staleFailure = $exception;
        }
        $afterStale = $this->stateFingerprint($roundId);
        $staleOk = $staleFailure instanceof DomainRuleViolation && $beforeStale === $afterStale;

        $overwriteBlocked = false;
        try {
            $this->connection->executeStatement(
                'UPDATE game_round SET winner_play_id = :loser WHERE id = :id',
                ['loser' => $loserId, 'id' => $roundId],
            );
        } catch (Throwable) {
            $overwriteBlocked = true;
        }
        $winnerImmutable = $overwriteBlocked
            && (string) $this->connection->fetchOne('SELECT winner_play_id FROM game_round WHERE id = :id', ['id' => $roundId]) === $winnerId
            && (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'", ['id' => $roundId]) === 1;

        return [
            'raceOk' => $raceOk,
            'staleOk' => $staleOk,
            'winnerImmutable' => $winnerImmutable,
            'winnerCode' => $winnerCode,
            'loserDisposition' => $loserHandledAsDomainState ? 'domain-closed' : 'invalid',
            'payoutCount' => $payoutCount,
            'activeRoundCount' => $activeRoundCount,
            'nextRoundId' => (string) $nextRound['id'],
        ];
    }

    private function advanceToNineteen(string $roundId, string $playId, string $playCode, string $sessionId, string $winningPath): void
    {
        for ($index = 0; $index < 19; ++$index) {
            $challenge = $this->seedAvailableStep($playId, $roundId, $index + 1);
            $result = $this->submitChoice->submit(
                $playCode,
                $sessionId,
                $challenge,
                $winningPath[$index] === '0' ? 'A' : 'B',
                (string) Uuid::v7(),
                2_000,
            );
            if ($result->acceptedStep !== $index + 1 || $result->completed) {
                throw new DomainRuleViolation('La preparazione 19/20 M1.9.8 non è deterministica.');
            }
        }
    }

    private function seedAvailableStep(string $playId, string $roundId, int $stepNumber): string
    {
        $questionId = (string) $this->connection->fetchOne(
            'SELECT id FROM round_question WHERE round_id = :roundId AND step_number = :stepNumber',
            ['roundId' => $roundId, 'stepNumber' => $stepNumber],
        );
        if ($questionId === '') {
            throw new DomainRuleViolation(sprintf('Domanda %d mancante durante la preparazione M1.9.8.', $stepNumber));
        }

        $challenge = $this->tokens->generate();
        $now = $this->clock->now();
        $shownAt = $now->modify('-3 seconds')->format('Y-m-d H:i:s.u');
        $availableAt = $now->modify('-1 second')->format('Y-m-d H:i:s.u');
        $this->connection->insert('play_step', [
            'id' => (string) new Ulid(),
            'play_id' => $playId,
            'round_question_id' => $questionId,
            'step_number' => $stepNumber,
            'option_a_is_left' => 1,
            'challenge_token_hash' => $this->tokens->hash($challenge),
            'shown_at' => $shownAt,
            'available_at' => $availableAt,
            'answered_at' => null,
            'selected_option' => null,
            'request_id' => null,
            'client_elapsed_ms' => null,
            'created_at' => $shownAt,
        ]);

        return $challenge;
    }

    private function winningPath(string $roundId): string
    {
        $ciphertext = $this->connection->fetchOne('SELECT encrypted_winning_path FROM game_round WHERE id = :id', ['id' => $roundId]);
        if ($ciphertext === false) {
            throw new DomainRuleViolation('Percorso vincente cifrato non disponibile per M1.9.8.');
        }

        return $this->secretCipher->decrypt(self::blobToString($ciphertext), OpenRound::pathContext($roundId));
    }

    /** @param array{playCode:string,sessionId:string,challengeToken:string,selectedOption:string,requestId:string} $submission
     *  @return array{process:resource,readyPath:string,resultPath:string,stdoutPath:string,stderrPath:string}
     */
    private function startWorker(string $runtimeDir, int $iteration, string $label, string $barrierPath, array $submission): array
    {
        $prefix = sprintf('race-%d-%s', $iteration, $label);
        $payloadPath = $runtimeDir.DIRECTORY_SEPARATOR.$prefix.'.json';
        $readyPath = $runtimeDir.DIRECTORY_SEPARATOR.$prefix.'.ready';
        $resultPath = $runtimeDir.DIRECTORY_SEPARATOR.$prefix.'.result.json';
        $stdoutPath = $runtimeDir.DIRECTORY_SEPARATOR.$prefix.'.stdout.log';
        $stderrPath = $runtimeDir.DIRECTORY_SEPARATOR.$prefix.'.stderr.log';
        $payload = $submission + [
            'readyPath' => $readyPath,
            'barrierPath' => $barrierPath,
            'resultPath' => $resultPath,
        ];
        file_put_contents($payloadPath, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $command = [
            PHP_BINARY,
            $this->projectDir.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'console',
            'app:verification:concurrency-worker',
            '--payload='.$payloadPath,
            '--env=test',
            '--no-debug',
        ];
        $descriptorSpec = [
            0 => ['file', $this->nullDevice(), 'r'],
            1 => ['file', $stdoutPath, 'w'],
            2 => ['file', $stderrPath, 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $this->projectDir);
        if (!is_resource($process)) {
            throw new RuntimeException('Impossibile avviare un worker PHP M1.9.8.');
        }

        return compact('process', 'readyPath', 'resultPath', 'stdoutPath', 'stderrPath');
    }

    /** @param list<array{process:resource,readyPath:string,resultPath:string,stdoutPath:string,stderrPath:string}> $workers */
    private function waitForWorkersReady(array $workers): void
    {
        $deadline = microtime(true) + 15.0;
        while (true) {
            $allReady = true;
            foreach ($workers as $worker) {
                if (!is_file($worker['readyPath'])) {
                    $allReady = false;
                    $status = proc_get_status($worker['process']);
                    if (($status['running'] ?? false) !== true) {
                        throw new RuntimeException('Un worker M1.9.8 è terminato prima della barriera: '.$this->workerDiagnostics($worker));
                    }
                }
            }
            if ($allReady) {
                return;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timeout readiness worker M1.9.8.');
            }
            usleep(20_000);
        }
    }

    /** @param list<array{process:resource,readyPath:string,resultPath:string,stdoutPath:string,stderrPath:string}> $workers
     *  @return list<array<string, mixed>>
     */
    private function waitForWorkers(array $workers): array
    {
        $deadline = microtime(true) + 30.0;
        while (true) {
            $allStopped = true;
            foreach ($workers as $worker) {
                $status = proc_get_status($worker['process']);
                if (($status['running'] ?? false) === true) {
                    $allStopped = false;
                }
            }
            if ($allStopped) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Timeout esecuzione worker concorrenti M1.9.8.');
            }
            usleep(20_000);
        }

        $results = [];
        foreach ($workers as $worker) {
            proc_close($worker['process']);
            if (!is_file($worker['resultPath'])) {
                throw new RuntimeException('Risultato worker M1.9.8 mancante: '.$this->workerDiagnostics($worker));
            }
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($worker['resultPath']), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new RuntimeException('Risultato worker M1.9.8 non valido.');
            }
            $results[] = $decoded;
        }

        return $results;
    }

    /** @param array{stdoutPath:string,stderrPath:string} $worker */
    private function workerDiagnostics(array $worker): string
    {
        return trim((string) @file_get_contents($worker['stdoutPath']).' '.(string) @file_get_contents($worker['stderrPath']));
    }

    /** @return array<string, int|string|null> */
    private function stateFingerprint(string $roundId): array
    {
        return [
            'roundStatus' => (string) $this->connection->fetchOne('SELECT status FROM game_round WHERE id = :id', ['id' => $roundId]),
            'winner' => $this->connection->fetchOne('SELECT winner_play_id FROM game_round WHERE id = :id', ['id' => $roundId]) ?: null,
            'payouts' => (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'", ['id' => $roundId]),
            'rounds' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'ledger' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry'),
            'audit' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_event'),
            'steps' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_step'),
            'credits' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit'),
            'receipts' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt'),
        ];
    }

    private function snapshotDatabase(string $backupPath): void
    {
        if (is_file($backupPath)) {
            @unlink($backupPath);
        }
        $this->connection->executeStatement("VACUUM INTO '".str_replace("'", "''", $backupPath)."'");
        if (!is_file($backupPath) || filesize($backupPath) === 0) {
            throw new RuntimeException('Snapshot pre-gate M1.9.8 non creata.');
        }
    }

    private function restoreDatabase(string $backupPath, string $databasePath): void
    {
        $this->connection->close();
        foreach ([$databasePath, $databasePath.'-wal', $databasePath.'-shm', $databasePath.'-journal'] as $path) {
            if (is_file($path) && !@unlink($path)) {
                throw new RuntimeException('Impossibile rimuovere il file SQLite runtime durante il restore M1.9.8: '.$path);
            }
        }
        if (!copy($backupPath, $databasePath)) {
            throw new RuntimeException('Impossibile ripristinare var/test.db dopo M1.9.8.');
        }
        @unlink($backupPath);

        // SqliteRuntimeConfigurator is intentionally once-per-service; after the explicit reconnect
        // performed by this isolated gate we restore the connection-scoped test pragmas ourselves.
        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
        $this->connection->executeStatement('PRAGMA synchronous = FULL');
    }

    private function databasePath(): string
    {
        $rows = $this->connection->fetchAllAssociative('PRAGMA database_list');
        foreach ($rows as $row) {
            if (($row['name'] ?? null) === 'main' && is_string($row['file'] ?? null) && $row['file'] !== '') {
                return (string) $row['file'];
            }
        }

        return $this->projectDir.DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'test.db';
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $absolute = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($absolute) ? $this->removeTree($absolute) : @unlink($absolute);
        }
        @rmdir($path);
    }

    private function nullDevice(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
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
