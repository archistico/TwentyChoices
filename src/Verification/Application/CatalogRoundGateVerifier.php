<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Catalog\Application\ChoicePairCatalog;
use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Repository\ChoicePairRepository;
use App\Game\Application\OpenRound;
use App\Game\Domain\Exception\DomainRuleViolation;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Throwable;

final readonly class CatalogRoundGateVerifier
{
    public function __construct(
        private Connection $connection,
        private ChoicePairCatalog $catalog,
        private ChoicePairRepository $choicePairs,
        private OpenRound $openRound,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $this->connection->beginTransaction();

        try {
            $this->verifyCatalogBaseline($checks);
            $this->verifyCatalogCrud($checks);
            $this->verifyMinimumActiveRegulars($checks);
            $this->verifyFinalDoorProtection($checks);
            $this->verifyRoundAndSnapshots($checks);
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

        $status = in_array('error', array_column($checks, 'status'), true) ? 'error' : 'ok';

        return ['status' => $status, 'checks' => $checks];
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyCatalogBaseline(array &$checks): void
    {
        $regularCount = count($this->choicePairs->findAllActiveRegular());
        $finalDoorCount = (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM choice_pair
WHERE pair_type = 'FINAL_DOOR'
  AND is_active = 1
  AND is_system = 1
SQL);

        $checks[] = $this->check(
            'Catalog baseline',
            $regularCount >= 19 && $finalDoorCount === 1,
            sprintf('%d regular active, %d final door', $regularCount, $finalDoorCount),
            'Servono almeno 19 coppie regolari attive e una sola porta finale attiva di sistema.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyCatalogCrud(array &$checks): void
    {
        $suffix = strtolower(bin2hex(random_bytes(6)));
        $pair = $this->catalog->create(
            'm192-'.$suffix,
            'Creazione A',
            'Creazione B',
            'M1.9.2 Create',
            9990,
        );
        $created = $this->choicePairs->find($pair->id());

        $this->catalog->update(
            $pair->id(),
            'm192-updated-'.$suffix,
            'Modifica A',
            'Modifica B',
            'M1.9.2 Update',
            9991,
        );
        $updated = $this->choicePairs->find($pair->id());

        $deactivated = $this->catalog->toggle($pair->id());
        $reactivated = $this->catalog->toggle($pair->id());
        $this->catalog->delete($pair->id());
        $deleted = $this->choicePairs->find($pair->id()) === null;

        $ok = $created instanceof ChoicePair
            && $updated instanceof ChoicePair
            && $updated->code() === 'm192-updated-'.$suffix
            && $updated->optionAText() === 'Modifica A'
            && $updated->optionBText() === 'Modifica B'
            && $updated->category() === 'M1.9.2 Update'
            && $updated->sortOrder() === 9991
            && !$deactivated->isActive()
            && $reactivated->isActive()
            && $deleted;

        $checks[] = $this->check(
            'Catalog CRUD',
            $ok,
            $ok ? 'create/update/toggle/delete OK' : 'inconsistent',
            'Creazione, modifica, categoria, ordinamento, disattivazione, riattivazione ed eliminazione devono essere coerenti.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyMinimumActiveRegulars(array &$checks): void
    {
        $active = $this->choicePairs->findAllActiveRegular();
        if (count($active) < 19) {
            $checks[] = $this->check(
                'Minimum active regular pairs',
                false,
                (string) count($active),
                'Il catalogo contiene già meno delle 19 coppie regolari attive richieste.',
            );

            return;
        }

        $deactivateCount = count($active) - 19;
        $deactivated = [];

        for ($index = 0; $index < $deactivateCount; ++$index) {
            $this->catalog->toggle($active[$index]->id());
            $deactivated[] = $active[$index]->id();
        }

        $remaining = count($this->choicePairs->findAllActiveRegular());
        $blockedAtNineteen = false;
        try {
            $this->catalog->toggle($active[$deactivateCount]->id());
        } catch (DomainRuleViolation) {
            $blockedAtNineteen = true;
        }

        foreach ($deactivated as $id) {
            $this->catalog->toggle($id);
        }

        $checks[] = $this->check(
            'Minimum active regular pairs',
            $remaining === 19 && $blockedAtNineteen,
            sprintf('%d remaining; twentieth removal %s', $remaining, $blockedAtNineteen ? 'blocked' : 'allowed'),
            'Il catalogo deve impedire di scendere sotto 19 coppie regolari attive.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyFinalDoorProtection(array &$checks): void
    {
        $finalDoor = $this->choicePairs->findFinalDoor();
        if (!$finalDoor instanceof ChoicePair) {
            $checks[] = $this->check('Final door protection', false, 'missing', 'La porta finale obbligatoria non esiste.');

            return;
        }

        $deactivateBlocked = false;
        try {
            $this->catalog->toggle($finalDoor->id());
        } catch (DomainRuleViolation) {
            $deactivateBlocked = true;
        }

        $deleteBlocked = false;
        try {
            $this->catalog->delete($finalDoor->id());
        } catch (DomainRuleViolation) {
            $deleteBlocked = true;
        }

        $stillPresent = $this->choicePairs->findFinalDoor()?->id() === $finalDoor->id();
        $checks[] = $this->check(
            'Final door protection',
            $deactivateBlocked && $deleteBlocked && $stillPresent,
            sprintf('deactivate=%s, delete=%s', $deactivateBlocked ? 'blocked' : 'allowed', $deleteBlocked ? 'blocked' : 'allowed'),
            'La porta finale deve restare unica, attiva, non disattivabile e non eliminabile.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyRoundAndSnapshots(array &$checks): void
    {
        $beforeRoundCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round');
        $opened = $this->openRound->open();
        $round = $this->connection->fetchAssociative(
            'SELECT * FROM game_round WHERE id = :id',
            ['id' => $opened->id],
        );
        if ($round === false) {
            throw new DomainRuleViolation('The verification round was not persisted.');
        }

        $questions = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
     id
    ,choice_pair_id
    ,choice_pair_source_id_snapshot
    ,step_number
    ,option_a_text_snapshot
    ,option_b_text_snapshot
    ,choice_pair_code_snapshot
    ,category_snapshot
    ,pair_type_snapshot
FROM round_question
WHERE round_id = :roundId
ORDER BY step_number
SQL, ['roundId' => $opened->id]);

        $regular = array_values(array_filter(
            $questions,
            static fn (array $question): bool => $question['pair_type_snapshot'] === 'REGULAR',
        ));
        $regularSourceIds = array_map(
            static fn (array $question): string => (string) $question['choice_pair_source_id_snapshot'],
            $regular,
        );
        $last = $questions[19] ?? null;
        $bankSeedCount = (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM ledger_entry
WHERE round_id = :roundId
  AND entry_type = 'BANK_SEED'
  AND amount_cents = 1000000
  AND play_id IS NULL
SQL, ['roundId' => $opened->id]);
        $activeCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'");

        $roundOk = $round['status'] === 'ACTIVE'
            && preg_match('/^R-[0-9]{8}-[A-F0-9]{12}$/D', (string) $round['public_code']) === 1
            && (int) $round['initial_jackpot_cents'] === 1_000_000
            && preg_match('/^[a-f0-9]{64}$/D', (string) $round['question_set_hash']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) $round['secret_commitment']) === 1
            && self::blobLength($round['encrypted_winning_path']) > 0
            && self::blobLength($round['encrypted_secret_nonce']) > 0
            && count($questions) === 20
            && count($regular) === 19
            && count(array_unique($regularSourceIds)) === 19
            && is_array($last)
            && (int) $last['step_number'] === 20
            && $last['pair_type_snapshot'] === 'FINAL_DOOR'
            && $bankSeedCount === 1
            && $activeCount === 1;

        $checks[] = $this->check(
            'Round opening',
            $roundOk,
            sprintf('%d snapshots, %d distinct regular, %d active round', count($questions), count(array_unique($regularSourceIds)), $activeCount),
            'Il round deve essere ACTIVE con 19 coppie regolari distinte, porta finale allo step 20, BANK_SEED e materiale crittografico.',
        );

        if ($regular === []) {
            throw new DomainRuleViolation('No regular snapshot is available for the immutability verification.');
        }

        $snapshot = $regular[0];
        $sourceId = (string) $snapshot['choice_pair_source_id_snapshot'];
        $source = $this->catalog->get($sourceId);
        $originalSnapshot = $this->snapshotImmutableData($snapshot);
        $originalHash = (string) $round['question_set_hash'];
        $updatedCode = strlen($source->code()) <= 54 ? $source->code().'-m192' : $source->code();

        $this->catalog->update(
            $sourceId,
            $updatedCode,
            'Catalogo modificato A',
            'Catalogo modificato B',
            'Catalogo modificato M1.9.2',
            $source->sortOrder() + 1,
        );
        $afterCatalogUpdate = $this->connection->fetchAssociative(
            'SELECT * FROM round_question WHERE id = :id',
            ['id' => $snapshot['id']],
        );
        $hashAfterUpdate = (string) $this->connection->fetchOne(
            'SELECT question_set_hash FROM game_round WHERE id = :id',
            ['id' => $opened->id],
        );
        $snapshotUnchangedAfterEdit = is_array($afterCatalogUpdate)
            && $this->snapshotImmutableData($afterCatalogUpdate) === $originalSnapshot
            && $hashAfterUpdate === $originalHash;

        $this->catalog->delete($sourceId);
        $afterCatalogDelete = $this->connection->fetchAssociative(
            'SELECT * FROM round_question WHERE id = :id',
            ['id' => $snapshot['id']],
        );
        $hashAfterDelete = (string) $this->connection->fetchOne(
            'SELECT question_set_hash FROM game_round WHERE id = :id',
            ['id' => $opened->id],
        );
        $snapshotSurvivesDelete = is_array($afterCatalogDelete)
            && $afterCatalogDelete['choice_pair_id'] === null
            && (string) $afterCatalogDelete['choice_pair_source_id_snapshot'] === $sourceId
            && $this->snapshotImmutableData($afterCatalogDelete) === $originalSnapshot
            && $hashAfterDelete === $originalHash;

        $directMutationBlocked = false;
        try {
            $this->connection->executeStatement(
                'UPDATE round_question SET option_a_text_snapshot = :value WHERE id = :id',
                ['value' => 'TAMPER', 'id' => $snapshot['id']],
            );
        } catch (DbalException) {
            $directMutationBlocked = true;
        }

        $checks[] = $this->check(
            'Snapshot immutability',
            $snapshotUnchangedAfterEdit && $snapshotSurvivesDelete && $directMutationBlocked,
            sprintf(
                'edit=%s, delete=%s, direct tamper=%s',
                $snapshotUnchangedAfterEdit ? 'stable' : 'changed',
                $snapshotSurvivesDelete ? 'stable' : 'changed',
                $directMutationBlocked ? 'blocked' : 'allowed',
            ),
            'Modifica/eliminazione del catalogo non devono cambiare identità, contenuti o hash dello snapshot; il tamper diretto deve fallire.',
        );

        $secondRoundBlocked = false;
        try {
            $this->openRound->open();
        } catch (DomainRuleViolation) {
            $secondRoundBlocked = true;
        }
        $roundCountAfterSecondAttempt = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round');
        $activeCountAfterSecondAttempt = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'");

        $checks[] = $this->check(
            'Single ACTIVE round',
            $secondRoundBlocked
                && $roundCountAfterSecondAttempt === $beforeRoundCount + 1
                && $activeCountAfterSecondAttempt === 1,
            sprintf('%d total added, %d ACTIVE', $roundCountAfterSecondAttempt - $beforeRoundCount, $activeCountAfterSecondAttempt),
            'Un secondo tentativo di apertura deve essere rifiutato senza lasciare un PREPARING orfano.',
        );
    }

    /** @param array<string,mixed> $row
     *  @return array<string,string>
     */
    private function snapshotImmutableData(array $row): array
    {
        return [
            'sourceId' => (string) $row['choice_pair_source_id_snapshot'],
            'step' => (string) $row['step_number'],
            'optionA' => (string) $row['option_a_text_snapshot'],
            'optionB' => (string) $row['option_b_text_snapshot'],
            'optionAImage' => (string) ($row['option_a_image_snapshot'] ?? ''),
            'optionBImage' => (string) ($row['option_b_image_snapshot'] ?? ''),
            'code' => (string) $row['choice_pair_code_snapshot'],
            'category' => (string) $row['category_snapshot'],
            'type' => (string) $row['pair_type_snapshot'],
        ];
    }

    private static function blobLength(mixed $value): int
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? 0 : strlen($contents);
        }

        return strlen((string) $value);
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
