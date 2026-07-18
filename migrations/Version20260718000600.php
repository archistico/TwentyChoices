<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.5 public verification disclosure, immutable play receipts and verification indexes.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql('ALTER TABLE game_round ADD COLUMN revealed_winning_path VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_round ADD COLUMN revealed_secret_nonce_hex CHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_round ADD COLUMN verification_published_at DATETIME_IMMUTABLE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_round_verification_publication ON game_round (status, verification_published_at)');

        $this->addSql(<<<'SQL'
CREATE TABLE play_receipt (
    id CHAR(26) NOT NULL PRIMARY KEY,
    public_code VARCHAR(40) NOT NULL,
    play_id CHAR(26) NOT NULL,
    round_id CHAR(26) NOT NULL,
    play_public_code VARCHAR(40) NOT NULL,
    round_public_code VARCHAR(40) NOT NULL,
    participation_number INTEGER NOT NULL,
    entry_kind VARCHAR(20) NOT NULL,
    outcome VARCHAR(16) NOT NULL,
    completed_steps INTEGER NOT NULL,
    chosen_path_bits VARCHAR(20) NOT NULL,
    issued_at DATETIME_IMMUTABLE NOT NULL,
    receipt_hash CHAR(64) NOT NULL,
    FOREIGN KEY (play_id) REFERENCES play (id) ON DELETE RESTRICT,
    FOREIGN KEY (round_id) REFERENCES game_round (id) ON DELETE RESTRICT,
    CONSTRAINT chk_receipt_public_code CHECK (length(public_code) = 26 AND substr(public_code, 1, 2) = 'V-' AND substr(public_code, 3) NOT GLOB '*[^0-9A-F]*'),
    CONSTRAINT chk_receipt_outcome CHECK (outcome IN ('WON', 'LOST', 'INTERRUPTED')),
    CONSTRAINT chk_receipt_steps CHECK (completed_steps BETWEEN 0 AND 20),
    CONSTRAINT chk_receipt_path_length CHECK (length(chosen_path_bits) = completed_steps),
    CONSTRAINT chk_receipt_path_binary CHECK (chosen_path_bits NOT GLOB '*[^01]*'),
    CONSTRAINT chk_receipt_hash CHECK (length(receipt_hash) = 64 AND receipt_hash NOT GLOB '*[^a-f0-9]*')
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_public_code ON play_receipt (public_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_play ON play_receipt (play_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_receipt_hash ON play_receipt (receipt_hash)');
        $this->addSql('CREATE INDEX idx_receipt_round_participation ON play_receipt (round_id, participation_number)');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_verification_publication_shape
BEFORE UPDATE OF revealed_winning_path, revealed_secret_nonce_hex, verification_published_at ON game_round
WHEN NEW.revealed_winning_path IS NOT NULL
  OR NEW.revealed_secret_nonce_hex IS NOT NULL
  OR NEW.verification_published_at IS NOT NULL
BEGIN
    SELECT CASE
        WHEN NEW.status <> 'SETTLED' THEN RAISE(ABORT, 'Verification material can only be public on a settled round')
        WHEN NEW.revealed_winning_path IS NULL
          OR length(NEW.revealed_winning_path) <> 20
          OR NEW.revealed_winning_path GLOB '*[^01]*'
        THEN RAISE(ABORT, 'Published winning path must contain exactly twenty bits')
        WHEN NEW.revealed_secret_nonce_hex IS NULL
          OR length(NEW.revealed_secret_nonce_hex) <> 64
          OR NEW.revealed_secret_nonce_hex GLOB '*[^a-f0-9]*'
        THEN RAISE(ABORT, 'Published nonce must be a lowercase 32-byte hex value')
        WHEN NEW.verification_published_at IS NULL
        THEN RAISE(ABORT, 'Verification publication timestamp is required')
    END;
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_verification_immutable
BEFORE UPDATE OF revealed_winning_path, revealed_secret_nonce_hex, verification_published_at ON game_round
WHEN OLD.revealed_winning_path IS NOT NULL
  OR OLD.revealed_secret_nonce_hex IS NOT NULL
  OR OLD.verification_published_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'Published round verification material is immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_settlement_requires_publication
BEFORE UPDATE OF status ON game_round
WHEN NEW.status = 'SETTLED'
  AND OLD.status = 'WON'
  AND (
      NEW.revealed_winning_path IS NULL
      OR NEW.revealed_secret_nonce_hex IS NULL
      OR NEW.verification_published_at IS NULL
  )
BEGIN
    SELECT RAISE(ABORT, 'A newly settled round must publish verification material atomically');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_receipt_insert_snapshot
BEFORE INSERT ON play_receipt
BEGIN
    SELECT CASE WHEN NOT EXISTS (
        SELECT 1
        FROM play p
        INNER JOIN game_round r ON r.id = p.round_id
        WHERE p.id = NEW.play_id
          AND p.round_id = NEW.round_id
          AND p.public_code = NEW.play_public_code
          AND r.public_code = NEW.round_public_code
          AND p.participation_number = NEW.participation_number
          AND p.entry_kind = NEW.entry_kind
          AND p.current_step = NEW.completed_steps
          AND p.chosen_path_bits = NEW.chosen_path_bits
          AND (
              (NEW.outcome = 'WON' AND p.status = 'COMPLETED_WON')
              OR (NEW.outcome = 'LOST' AND p.status = 'COMPLETED_LOST')
              OR (NEW.outcome = 'INTERRUPTED' AND p.status IN ('INTERRUPTED', 'CREDITED'))
          )
    ) THEN RAISE(ABORT, 'Receipt must snapshot a matching terminal play') END;
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_receipt_immutable_update
BEFORE UPDATE ON play_receipt
BEGIN
    SELECT RAISE(ABORT, 'Play receipts are immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_receipt_immutable_delete
BEFORE DELETE ON play_receipt
BEGIN
    SELECT RAISE(ABORT, 'Play receipts are immutable');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            true,
            'M1.5 is intentionally irreversible on SQLite because removing verification columns requires rebuilding game_round.',
        );
    }

}
