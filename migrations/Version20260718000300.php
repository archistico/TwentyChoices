<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.2 round opening invariants, immutable encrypted secrets and append-only virtual ledger.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql('CREATE INDEX idx_round_status_started ON game_round (status, started_at)');
        $this->addSql("CREATE UNIQUE INDEX uniq_round_bank_seed ON ledger_entry (round_id) WHERE entry_type = 'BANK_SEED'");

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_validate_insert
BEFORE INSERT ON game_round
WHEN length(NEW.question_set_hash) <> 64
  OR NEW.question_set_hash GLOB '*[^0-9a-f]*'
  OR length(NEW.secret_commitment) <> 64
  OR NEW.secret_commitment GLOB '*[^0-9a-f]*'
  OR length(NEW.encrypted_winning_path) < 32
  OR length(NEW.encrypted_secret_nonce) < 32
BEGIN
    SELECT RAISE(ABORT, 'Invalid round cryptographic material');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_protect_cryptographic_material
BEFORE UPDATE ON game_round
WHEN NEW.public_code <> OLD.public_code
  OR NEW.question_set_hash <> OLD.question_set_hash
  OR NEW.secret_commitment <> OLD.secret_commitment
  OR NEW.encrypted_winning_path <> OLD.encrypted_winning_path
  OR NEW.encrypted_secret_nonce <> OLD.encrypted_secret_nonce
  OR NEW.initial_jackpot_cents <> OLD.initial_jackpot_cents
BEGIN
    SELECT RAISE(ABORT, 'Round cryptographic material is immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_validate_activation
BEFORE UPDATE OF status, started_at ON game_round
WHEN NEW.status = 'ACTIVE'
  AND (
       OLD.status <> 'PREPARING'
       OR NEW.started_at IS NULL
       OR (SELECT COUNT(*) FROM round_question WHERE round_id = NEW.id) <> 20
       OR (SELECT COUNT(*) FROM ledger_entry WHERE round_id = NEW.id AND entry_type = 'BANK_SEED') <> 1
  )
BEGIN
    SELECT RAISE(ABORT, 'A round can be activated only after questions and bank seed are complete');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_validate_bank_seed
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type = 'BANK_SEED'
  AND (NEW.amount_cents <> 1000000 OR NEW.play_id IS NOT NULL)
BEGIN
    SELECT RAISE(ABORT, 'A bank seed must be exactly 10,000.00 virtual euros and cannot reference a play');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_immutable_update
BEFORE UPDATE ON ledger_entry
BEGIN
    SELECT RAISE(ABORT, 'Virtual ledger entries are append-only');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_immutable_delete
BEFORE DELETE ON ledger_entry
BEGIN
    SELECT RAISE(ABORT, 'Virtual ledger entries are append-only');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_ledger_immutable_delete');
        $this->addSql('DROP TRIGGER trg_ledger_immutable_update');
        $this->addSql('DROP TRIGGER trg_ledger_validate_bank_seed');
        $this->addSql('DROP TRIGGER trg_round_validate_activation');
        $this->addSql('DROP TRIGGER trg_round_protect_cryptographic_material');
        $this->addSql('DROP TRIGGER trg_round_validate_insert');
        $this->addSql('DROP INDEX uniq_round_bank_seed');
        $this->addSql('DROP INDEX idx_round_status_started');
    }
}
