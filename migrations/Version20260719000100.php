<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.9.2 preserve immutable source identity when a snapshotted catalog pair is deleted.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $missingSource = (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM round_question
WHERE choice_pair_id IS NULL
SQL);
        $this->abortIf(
            $missingSource !== 0,
            'Cannot preserve immutable source identities: at least one existing round snapshot has no source pair id.',
        );

        $this->addSql('DROP TRIGGER trg_round_question_immutable');
        $this->addSql('DROP TRIGGER trg_round_question_validate_insert');
        $this->addSql("ALTER TABLE round_question ADD COLUMN choice_pair_source_id_snapshot CHAR(26) NOT NULL DEFAULT ''");
        $this->addSql(<<<'SQL'
UPDATE round_question
SET choice_pair_source_id_snapshot = choice_pair_id
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_question_validate_insert
BEFORE INSERT ON round_question
WHEN (NEW.step_number = 20 AND NEW.pair_type_snapshot <> 'FINAL_DOOR')
  OR (NEW.step_number < 20 AND NEW.pair_type_snapshot <> 'REGULAR')
  OR NEW.choice_pair_id IS NULL
  OR NEW.choice_pair_source_id_snapshot = ''
  OR NEW.choice_pair_source_id_snapshot <> NEW.choice_pair_id
BEGIN
    SELECT RAISE(ABORT, 'Invalid round-question snapshot');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_question_immutable
BEFORE UPDATE ON round_question
WHEN NEW.id IS NOT OLD.id
  OR NEW.round_id IS NOT OLD.round_id
  OR NEW.choice_pair_source_id_snapshot IS NOT OLD.choice_pair_source_id_snapshot
  OR NEW.step_number IS NOT OLD.step_number
  OR NEW.option_a_text_snapshot IS NOT OLD.option_a_text_snapshot
  OR NEW.option_b_text_snapshot IS NOT OLD.option_b_text_snapshot
  OR NEW.option_a_image_snapshot IS NOT OLD.option_a_image_snapshot
  OR NEW.option_b_image_snapshot IS NOT OLD.option_b_image_snapshot
  OR NEW.choice_pair_code_snapshot IS NOT OLD.choice_pair_code_snapshot
  OR NEW.category_snapshot IS NOT OLD.category_snapshot
  OR NEW.pair_type_snapshot IS NOT OLD.pair_type_snapshot
  OR NOT (
       NEW.choice_pair_id IS OLD.choice_pair_id
       OR (OLD.choice_pair_id IS NOT NULL AND NEW.choice_pair_id IS NULL)
  )
BEGIN
    SELECT RAISE(ABORT, 'Round-question snapshots are immutable');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $missingLiveReference = (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM round_question
WHERE choice_pair_id IS NULL
SQL);
        $this->abortIf(
            $missingLiveReference !== 0,
            'Cannot safely downgrade: at least one round snapshot refers to a catalog pair that has been deleted.',
        );

        $this->addSql('DROP TRIGGER trg_round_question_immutable');
        $this->addSql('DROP TRIGGER trg_round_question_validate_insert');
        $this->addSql('ALTER TABLE round_question DROP COLUMN choice_pair_source_id_snapshot');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_question_validate_insert
BEFORE INSERT ON round_question
WHEN (NEW.step_number = 20 AND NEW.pair_type_snapshot <> 'FINAL_DOOR')
  OR (NEW.step_number < 20 AND NEW.pair_type_snapshot <> 'REGULAR')
BEGIN
    SELECT RAISE(ABORT, 'Invalid round-question position');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_question_immutable
BEFORE UPDATE ON round_question
BEGIN
    SELECT RAISE(ABORT, 'Round-question snapshots are immutable');
END
SQL);
    }
}
