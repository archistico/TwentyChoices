<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.9.5 enforces an exact two-second SQLite step boundary and scopes choice request idempotency keys per play.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql('DROP TRIGGER IF EXISTS trg_play_step_validate_insert');
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_step_validate_insert
BEFORE INSERT ON play_step
WHEN length(NEW.challenge_token_hash) <> 64
  OR NEW.challenge_token_hash GLOB '*[^0-9a-f]*'
  OR NEW.answered_at IS NOT NULL
  OR NEW.selected_option IS NOT NULL
  OR NEW.request_id IS NOT NULL
  OR length(NEW.shown_at) <> 26
  OR length(NEW.available_at) <> 26
  OR substr(NEW.shown_at, 21, 6) GLOB '*[^0-9]*'
  OR substr(NEW.available_at, 21, 6) GLOB '*[^0-9]*'
  OR strftime('%s', substr(NEW.shown_at, 1, 19)) IS NULL
  OR strftime('%s', substr(NEW.available_at, 1, 19)) IS NULL
  OR (
       (
           CAST(strftime('%s', substr(NEW.available_at, 1, 19)) AS INTEGER) * 1000000
           + CAST(substr(NEW.available_at, 21, 6) AS INTEGER)
       )
       -
       (
           CAST(strftime('%s', substr(NEW.shown_at, 1, 19)) AS INTEGER) * 1000000
           + CAST(substr(NEW.shown_at, 21, 6) AS INTEGER)
       )
     ) < 2000000
BEGIN
    SELECT RAISE(ABORT, 'Invalid initial play step state');
END
SQL);

        $this->addSql('DROP INDEX IF EXISTS uniq_play_step_request');
        $this->addSql('CREATE UNIQUE INDEX uniq_play_step_request ON play_step (play_id, request_id) WHERE request_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql('DROP INDEX IF EXISTS uniq_play_step_request');
        $this->addSql('CREATE UNIQUE INDEX uniq_play_step_request ON play_step (request_id) WHERE request_id IS NOT NULL');

        $this->addSql('DROP TRIGGER IF EXISTS trg_play_step_validate_insert');
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_step_validate_insert
BEFORE INSERT ON play_step
WHEN length(NEW.challenge_token_hash) <> 64
  OR NEW.challenge_token_hash GLOB '*[^0-9a-f]*'
  OR NEW.answered_at IS NOT NULL
  OR NEW.selected_option IS NOT NULL
  OR NEW.request_id IS NOT NULL
  OR ((julianday(NEW.available_at) - julianday(NEW.shown_at)) * 86400.0) < 1.999
BEGIN
    SELECT RAISE(ABORT, 'Invalid initial play step state');
END
SQL);
    }
}
