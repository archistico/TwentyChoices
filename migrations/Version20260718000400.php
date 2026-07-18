<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.3 anonymous sessions, guarded play progression, two-second server timer and append-only audit chain.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql('CREATE INDEX idx_play_session_status ON play (player_session_id, status, round_id)');
        $this->addSql('CREATE INDEX idx_play_step_open ON play_step (play_id, step_number, answered_at)');
        $this->addSql('CREATE INDEX idx_audit_play_sequence ON audit_event (play_id, sequence_number)');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_player_session_validate_insert
BEFORE INSERT ON player_session
WHEN length(NEW.public_token_hash) <> 64
  OR NEW.public_token_hash GLOB '*[^0-9a-f]*'
BEGIN
    SELECT RAISE(ABORT, 'Invalid anonymous player token hash');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_player_session_protect_identity
BEFORE UPDATE ON player_session
WHEN NEW.id <> OLD.id
  OR NEW.public_token_hash <> OLD.public_token_hash
  OR NEW.created_at <> OLD.created_at
BEGIN
    SELECT RAISE(ABORT, 'Anonymous player identity is immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_validate_insert
BEFORE INSERT ON play
WHEN NEW.current_step <> 0
  OR NEW.chosen_path_bits <> ''
  OR NEW.completed_at IS NOT NULL
  OR NEW.status NOT IN ('CREATED', 'IN_PROGRESS')
BEGIN
    SELECT RAISE(ABORT, 'A play must start at step zero');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_protect_identity
BEFORE UPDATE ON play
WHEN NEW.id <> OLD.id
  OR NEW.public_code <> OLD.public_code
  OR NEW.round_id <> OLD.round_id
  OR NEW.player_session_id <> OLD.player_session_id
  OR NEW.participation_number <> OLD.participation_number
  OR NEW.entry_kind <> OLD.entry_kind
  OR NEW.started_at <> OLD.started_at
  OR NEW.created_at <> OLD.created_at
BEGIN
    SELECT RAISE(ABORT, 'Play identity is immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_validate_progress
BEFORE UPDATE OF current_step, chosen_path_bits ON play
WHEN NEW.current_step <> OLD.current_step + 1
  OR length(NEW.chosen_path_bits) <> length(OLD.chosen_path_bits) + 1
  OR substr(NEW.chosen_path_bits, 1, length(OLD.chosen_path_bits)) <> OLD.chosen_path_bits
BEGIN
    SELECT RAISE(ABORT, 'Play progression must advance by exactly one choice');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_validate_completion
BEFORE UPDATE ON play
WHEN (NEW.current_step = 20 AND NEW.completed_at IS NULL)
   OR (NEW.current_step < 20 AND NEW.completed_at IS NOT NULL)
BEGIN
    SELECT RAISE(ABORT, 'Play completion timestamp is inconsistent');
END
SQL);

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

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_step_protect_structure
BEFORE UPDATE ON play_step
WHEN NEW.id <> OLD.id
  OR NEW.play_id <> OLD.play_id
  OR NEW.round_question_id <> OLD.round_question_id
  OR NEW.step_number <> OLD.step_number
  OR NEW.option_a_is_left <> OLD.option_a_is_left
  OR NEW.shown_at <> OLD.shown_at
  OR NEW.available_at <> OLD.available_at
  OR NEW.created_at <> OLD.created_at
BEGIN
    SELECT RAISE(ABORT, 'Play step structure and timing are immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_step_validate_answer
BEFORE UPDATE OF answered_at, selected_option, request_id, client_elapsed_ms ON play_step
WHEN NEW.answered_at IS NULL
  OR NEW.selected_option NOT IN ('A', 'B')
  OR NEW.request_id IS NULL
  OR NEW.answered_at < OLD.available_at
  OR (NEW.client_elapsed_ms IS NOT NULL AND NEW.client_elapsed_ms < 0)
BEGIN
    SELECT RAISE(ABORT, 'Invalid or premature play step answer');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_step_answer_once
BEFORE UPDATE ON play_step
WHEN OLD.answered_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'A play step answer is immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_step_rotate_only_open
BEFORE UPDATE OF challenge_token_hash ON play_step
WHEN OLD.answered_at IS NOT NULL
   OR length(NEW.challenge_token_hash) <> 64
   OR NEW.challenge_token_hash GLOB '*[^0-9a-f]*'
BEGIN
    SELECT RAISE(ABORT, 'Only an unanswered step token can be rotated');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_validate_standard_entry
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')
  AND (
       NEW.play_id IS NULL
       OR (NEW.entry_type = 'PLAYER_ENTRY' AND NEW.amount_cents <> 100)
       OR (NEW.entry_type = 'JACKPOT_CONTRIBUTION' AND NEW.amount_cents <> 80)
       OR (NEW.entry_type = 'ORGANIZER_SHARE' AND NEW.amount_cents <> 20)
  )
BEGIN
    SELECT RAISE(ABORT, 'Invalid standard virtual entry ledger movement');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_validate_contribution_progress
BEFORE UPDATE OF entry_contribution_cents ON game_round
WHEN NEW.entry_contribution_cents < OLD.entry_contribution_cents
   OR ((NEW.entry_contribution_cents - OLD.entry_contribution_cents) % 80) <> 0
BEGIN
    SELECT RAISE(ABORT, 'Round contribution must grow in complete standard-entry units');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_audit_validate_insert
BEFORE INSERT ON audit_event
WHEN NEW.sequence_number <> COALESCE((SELECT MAX(sequence_number) FROM audit_event), 0) + 1
  OR NEW.previous_hash <> COALESCE(
        (SELECT event_hash FROM audit_event ORDER BY sequence_number DESC LIMIT 1),
        '0000000000000000000000000000000000000000000000000000000000000000'
     )
  OR length(NEW.event_hash) <> 64
  OR NEW.event_hash GLOB '*[^0-9a-f]*'
BEGIN
    SELECT RAISE(ABORT, 'Invalid audit chain link');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_audit_immutable_update
BEFORE UPDATE ON audit_event
BEGIN
    SELECT RAISE(ABORT, 'Audit events are append-only');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_audit_immutable_delete
BEFORE DELETE ON audit_event
BEGIN
    SELECT RAISE(ABORT, 'Audit events are append-only');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_audit_immutable_delete');
        $this->addSql('DROP TRIGGER trg_audit_immutable_update');
        $this->addSql('DROP TRIGGER trg_audit_validate_insert');
        $this->addSql('DROP TRIGGER trg_round_validate_contribution_progress');
        $this->addSql('DROP TRIGGER trg_ledger_validate_standard_entry');
        $this->addSql('DROP TRIGGER trg_play_step_rotate_only_open');
        $this->addSql('DROP TRIGGER trg_play_step_answer_once');
        $this->addSql('DROP TRIGGER trg_play_step_validate_answer');
        $this->addSql('DROP TRIGGER trg_play_step_protect_structure');
        $this->addSql('DROP TRIGGER trg_play_step_validate_insert');
        $this->addSql('DROP TRIGGER trg_play_validate_completion');
        $this->addSql('DROP TRIGGER trg_play_validate_progress');
        $this->addSql('DROP TRIGGER trg_play_protect_identity');
        $this->addSql('DROP TRIGGER trg_play_validate_insert');
        $this->addSql('DROP TRIGGER trg_player_session_protect_identity');
        $this->addSql('DROP TRIGGER trg_player_session_validate_insert');
        $this->addSql('DROP INDEX idx_audit_play_sequence');
        $this->addSql('DROP INDEX idx_play_step_open');
        $this->addSql('DROP INDEX idx_play_session_status');
    }
}
