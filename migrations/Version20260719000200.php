<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.9.2.1.3 detach live catalog references atomically before deleting snapshotted regular pairs.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        // Repair any dangling live references produced by an older connection that ran with
        // PRAGMA foreign_keys disabled. Historical identity remains in the immutable source snapshot.
        $this->addSql(<<<'SQL'
UPDATE round_question
SET choice_pair_id = NULL
WHERE choice_pair_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM choice_pair
      WHERE choice_pair.id = round_question.choice_pair_id
  )
SQL);

        // Keep snapshot detachment atomic and independent from the connection-local
        // PRAGMA foreign_keys setting. ON DELETE SET NULL remains defense in depth.
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_choice_pair_detach_round_snapshot_reference
BEFORE DELETE ON choice_pair
WHEN OLD.pair_type = 'REGULAR'
BEGIN
    UPDATE round_question
    SET choice_pair_id = NULL
    WHERE choice_pair_id = OLD.id;
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_choice_pair_detach_round_snapshot_reference');
    }
}
