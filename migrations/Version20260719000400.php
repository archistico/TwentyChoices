<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.9.4.1 reasserts STANDARD ledger duplicate protection and recreates the required SQLite schema objects.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_ledger_standard_entry_play_binding');
        $this->addSql('DROP INDEX IF EXISTS uniq_standard_organizer_share_per_play');
        $this->addSql('DROP INDEX IF EXISTS uniq_standard_jackpot_contribution_per_play');
        $this->addSql('DROP INDEX IF EXISTS uniq_standard_player_entry_per_play');

        $this->addSql("CREATE UNIQUE INDEX uniq_standard_player_entry_per_play ON ledger_entry (play_id) WHERE entry_type = 'PLAYER_ENTRY'");
        $this->addSql("CREATE UNIQUE INDEX uniq_standard_jackpot_contribution_per_play ON ledger_entry (play_id) WHERE entry_type = 'JACKPOT_CONTRIBUTION'");
        $this->addSql("CREATE UNIQUE INDEX uniq_standard_organizer_share_per_play ON ledger_entry (play_id) WHERE entry_type = 'ORGANIZER_SHARE'");

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_standard_entry_play_binding
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')
 AND (
      NOT EXISTS (
          SELECT 1
          FROM play p
          WHERE p.id = NEW.play_id
            AND p.round_id = NEW.round_id
            AND p.entry_kind = 'STANDARD'
      )
   OR EXISTS (
          SELECT 1
          FROM ledger_entry existing
          WHERE existing.play_id = NEW.play_id
            AND existing.entry_type = NEW.entry_type
      )
   OR EXISTS (
          SELECT 1
          FROM ledger_entry existing
          WHERE existing.play_id = NEW.play_id
            AND existing.entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')
            AND existing.correlation_id <> NEW.correlation_id
      )
 )
BEGIN
    SELECT RAISE(ABORT, 'Standard-entry ledger rows must be unique per play and share one STANDARD play/correlation');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_ledger_standard_entry_play_binding');
        $this->addSql('DROP INDEX IF EXISTS uniq_standard_organizer_share_per_play');
        $this->addSql('DROP INDEX IF EXISTS uniq_standard_jackpot_contribution_per_play');
        $this->addSql('DROP INDEX IF EXISTS uniq_standard_player_entry_per_play');

        $this->addSql("CREATE UNIQUE INDEX uniq_standard_player_entry_per_play ON ledger_entry (play_id) WHERE entry_type = 'PLAYER_ENTRY'");
        $this->addSql("CREATE UNIQUE INDEX uniq_standard_jackpot_contribution_per_play ON ledger_entry (play_id) WHERE entry_type = 'JACKPOT_CONTRIBUTION'");
        $this->addSql("CREATE UNIQUE INDEX uniq_standard_organizer_share_per_play ON ledger_entry (play_id) WHERE entry_type = 'ORGANIZER_SHARE'");

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_standard_entry_play_binding
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')
 AND (
      NOT EXISTS (
          SELECT 1
          FROM play p
          WHERE p.id = NEW.play_id
            AND p.round_id = NEW.round_id
            AND p.entry_kind = 'STANDARD'
      )
   OR EXISTS (
          SELECT 1
          FROM ledger_entry existing
          WHERE existing.play_id = NEW.play_id
            AND existing.entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')
            AND existing.correlation_id <> NEW.correlation_id
      )
 )
BEGIN
    SELECT RAISE(ABORT, 'Standard-entry ledger rows must belong to one STANDARD play and one correlation');
END
SQL);
    }
}
