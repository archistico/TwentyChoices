<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial SQLite schema for rounds, plays, virtual ledger and tamper-evident audit.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This initial prototype migration targets SQLite only.');
        }

        $this->addSql(<<<'SQL'
CREATE TABLE game_round (
    id CHAR(26) NOT NULL PRIMARY KEY,
    public_code VARCHAR(40) NOT NULL,
    status VARCHAR(16) NOT NULL,
    question_set_hash CHAR(64) NOT NULL,
    secret_commitment CHAR(64) NOT NULL,
    encrypted_winning_path BLOB NOT NULL,
    encrypted_secret_nonce BLOB NOT NULL,
    initial_jackpot_cents INTEGER NOT NULL,
    entry_contribution_cents INTEGER NOT NULL DEFAULT 0,
    frozen_jackpot_cents INTEGER DEFAULT NULL,
    winner_play_id CHAR(26) DEFAULT NULL,
    started_at DATETIME_IMMUTABLE DEFAULT NULL,
    won_at DATETIME_IMMUTABLE DEFAULT NULL,
    settled_at DATETIME_IMMUTABLE DEFAULT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    CONSTRAINT chk_round_status CHECK (status IN ('PREPARING', 'ACTIVE', 'WON', 'SETTLED', 'CANCELLED')),
    CONSTRAINT chk_round_initial_jackpot CHECK (initial_jackpot_cents = 1000000),
    CONSTRAINT chk_round_contribution CHECK (entry_contribution_cents >= 0),
    CONSTRAINT chk_round_frozen_jackpot CHECK (frozen_jackpot_cents IS NULL OR frozen_jackpot_cents >= 0)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_round_public_code ON game_round (public_code)');
        $this->addSql("CREATE UNIQUE INDEX uniq_single_active_round ON game_round (status) WHERE status = 'ACTIVE'");

        $this->addSql(<<<'SQL'
CREATE TABLE choice_pair (
    id CHAR(26) NOT NULL PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    option_a_text VARCHAR(120) NOT NULL,
    option_b_text VARCHAR(120) NOT NULL,
    category VARCHAR(60) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT 1,
    created_at DATETIME_IMMUTABLE NOT NULL,
    updated_at DATETIME_IMMUTABLE NOT NULL
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_choice_pair_code ON choice_pair (code)');

        $this->addSql(<<<'SQL'
CREATE TABLE round_question (
    id CHAR(26) NOT NULL PRIMARY KEY,
    round_id CHAR(26) NOT NULL,
    choice_pair_id CHAR(26) DEFAULT NULL,
    step_number INTEGER NOT NULL,
    option_a_text_snapshot VARCHAR(120) NOT NULL,
    option_b_text_snapshot VARCHAR(120) NOT NULL,
    option_a_image_snapshot VARCHAR(255) DEFAULT NULL,
    option_b_image_snapshot VARCHAR(255) DEFAULT NULL,
    FOREIGN KEY (round_id) REFERENCES game_round (id) ON DELETE CASCADE,
    FOREIGN KEY (choice_pair_id) REFERENCES choice_pair (id) ON DELETE SET NULL,
    CONSTRAINT chk_round_question_step CHECK (step_number BETWEEN 1 AND 20)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_round_question_step ON round_question (round_id, step_number)');

        $this->addSql(<<<'SQL'
CREATE TABLE player_session (
    id CHAR(26) NOT NULL PRIMARY KEY,
    public_token_hash CHAR(64) NOT NULL,
    created_at DATETIME_IMMUTABLE NOT NULL,
    last_seen_at DATETIME_IMMUTABLE NOT NULL,
    blocked_at DATETIME_IMMUTABLE DEFAULT NULL
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_player_session_token_hash ON player_session (public_token_hash)');

        $this->addSql(<<<'SQL'
CREATE TABLE play (
    id CHAR(26) NOT NULL PRIMARY KEY,
    public_code VARCHAR(40) NOT NULL,
    round_id CHAR(26) NOT NULL,
    player_session_id CHAR(26) NOT NULL,
    status VARCHAR(24) NOT NULL,
    participation_number INTEGER NOT NULL,
    current_step INTEGER NOT NULL DEFAULT 0,
    chosen_path_bits VARCHAR(20) NOT NULL DEFAULT '',
    entry_kind VARCHAR(20) NOT NULL,
    started_at DATETIME_IMMUTABLE NOT NULL,
    completed_at DATETIME_IMMUTABLE DEFAULT NULL,
    interrupted_at DATETIME_IMMUTABLE DEFAULT NULL,
    created_at DATETIME_IMMUTABLE NOT NULL,
    version INTEGER NOT NULL DEFAULT 1,
    FOREIGN KEY (round_id) REFERENCES game_round (id) ON DELETE RESTRICT,
    FOREIGN KEY (player_session_id) REFERENCES player_session (id) ON DELETE RESTRICT,
    CONSTRAINT chk_play_status CHECK (status IN ('CREATED', 'IN_PROGRESS', 'COMPLETED_LOST', 'COMPLETED_WON', 'INTERRUPTED', 'CREDITED', 'EXPIRED', 'CANCELLED')),
    CONSTRAINT chk_play_entry_kind CHECK (entry_kind IN ('STANDARD', 'RESTART_CREDIT', 'ADMIN_TEST')),
    CONSTRAINT chk_play_step CHECK (current_step BETWEEN 0 AND 20),
    CONSTRAINT chk_play_path_length CHECK (length(chosen_path_bits) = current_step),
    CONSTRAINT chk_play_path_binary CHECK (chosen_path_bits NOT GLOB '*[^01]*')
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_play_public_code ON play (public_code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_play_round_participation ON play (round_id, participation_number)');
        $this->addSql("CREATE UNIQUE INDEX uniq_one_active_play_per_session ON play (round_id, player_session_id) WHERE status IN ('CREATED', 'IN_PROGRESS')");

        $this->addSql(<<<'SQL'
CREATE TABLE play_step (
    id CHAR(26) NOT NULL PRIMARY KEY,
    play_id CHAR(26) NOT NULL,
    round_question_id CHAR(26) NOT NULL,
    step_number INTEGER NOT NULL,
    option_a_is_left BOOLEAN NOT NULL,
    challenge_token_hash CHAR(64) NOT NULL,
    shown_at DATETIME_IMMUTABLE NOT NULL,
    available_at DATETIME_IMMUTABLE NOT NULL,
    answered_at DATETIME_IMMUTABLE DEFAULT NULL,
    selected_option CHAR(1) DEFAULT NULL,
    request_id CHAR(36) DEFAULT NULL,
    client_elapsed_ms INTEGER DEFAULT NULL,
    created_at DATETIME_IMMUTABLE NOT NULL,
    FOREIGN KEY (play_id) REFERENCES play (id) ON DELETE CASCADE,
    FOREIGN KEY (round_question_id) REFERENCES round_question (id) ON DELETE RESTRICT,
    CONSTRAINT chk_play_step_number CHECK (step_number BETWEEN 1 AND 20),
    CONSTRAINT chk_play_step_option CHECK (selected_option IS NULL OR selected_option IN ('A', 'B')),
    CONSTRAINT chk_play_step_elapsed CHECK (client_elapsed_ms IS NULL OR client_elapsed_ms >= 0)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_play_step ON play_step (play_id, step_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_play_step_challenge ON play_step (challenge_token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_play_step_request ON play_step (request_id) WHERE request_id IS NOT NULL');

        $this->addSql(<<<'SQL'
CREATE TABLE play_credit (
    id CHAR(26) NOT NULL PRIMARY KEY,
    player_session_id CHAR(26) NOT NULL,
    source_round_id CHAR(26) NOT NULL,
    source_play_id CHAR(26) NOT NULL,
    status VARCHAR(16) NOT NULL,
    issued_at DATETIME_IMMUTABLE NOT NULL,
    redeemed_at DATETIME_IMMUTABLE DEFAULT NULL,
    redeemed_play_id CHAR(26) DEFAULT NULL,
    FOREIGN KEY (player_session_id) REFERENCES player_session (id) ON DELETE RESTRICT,
    FOREIGN KEY (source_round_id) REFERENCES game_round (id) ON DELETE RESTRICT,
    FOREIGN KEY (source_play_id) REFERENCES play (id) ON DELETE RESTRICT,
    FOREIGN KEY (redeemed_play_id) REFERENCES play (id) ON DELETE RESTRICT,
    CONSTRAINT chk_play_credit_status CHECK (status IN ('AVAILABLE', 'REDEEMED', 'EXPIRED', 'CANCELLED'))
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_credit_source_play ON play_credit (source_play_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_credit_redeemed_play ON play_credit (redeemed_play_id) WHERE redeemed_play_id IS NOT NULL');

        $this->addSql(<<<'SQL'
CREATE TABLE ledger_entry (
    id CHAR(26) NOT NULL PRIMARY KEY,
    round_id CHAR(26) NOT NULL,
    play_id CHAR(26) DEFAULT NULL,
    entry_type VARCHAR(32) NOT NULL,
    amount_cents INTEGER NOT NULL,
    correlation_id CHAR(36) NOT NULL,
    created_at DATETIME_IMMUTABLE NOT NULL,
    FOREIGN KEY (round_id) REFERENCES game_round (id) ON DELETE RESTRICT,
    FOREIGN KEY (play_id) REFERENCES play (id) ON DELETE RESTRICT,
    CONSTRAINT chk_ledger_type CHECK (entry_type IN ('BANK_SEED', 'PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE', 'RESTART_CREDIT_ISSUED', 'RESTART_CREDIT_REDEEMED', 'JACKPOT_PAYOUT', 'ROUND_ADJUSTMENT'))
)
SQL);
        $this->addSql('CREATE INDEX idx_ledger_round_created ON ledger_entry (round_id, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_ledger_correlation_type ON ledger_entry (correlation_id, entry_type)');

        $this->addSql(<<<'SQL'
CREATE TABLE audit_event (
    id CHAR(26) NOT NULL PRIMARY KEY,
    sequence_number INTEGER NOT NULL,
    round_id CHAR(26) DEFAULT NULL,
    play_id CHAR(26) DEFAULT NULL,
    event_type VARCHAR(48) NOT NULL,
    payload_json TEXT NOT NULL,
    request_id CHAR(36) DEFAULT NULL,
    occurred_at DATETIME_IMMUTABLE NOT NULL,
    previous_hash CHAR(64) NOT NULL,
    event_hash CHAR(64) NOT NULL,
    FOREIGN KEY (round_id) REFERENCES game_round (id) ON DELETE RESTRICT,
    FOREIGN KEY (play_id) REFERENCES play (id) ON DELETE RESTRICT
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_audit_sequence ON audit_event (sequence_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_audit_event_hash ON audit_event (event_hash)');
        $this->addSql('CREATE INDEX idx_audit_round_sequence ON audit_event (round_id, sequence_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_event');
        $this->addSql('DROP TABLE ledger_entry');
        $this->addSql('DROP TABLE play_credit');
        $this->addSql('DROP TABLE play_step');
        $this->addSql('DROP TABLE play');
        $this->addSql('DROP TABLE player_session');
        $this->addSql('DROP TABLE round_question');
        $this->addSql('DROP TABLE choice_pair');
        $this->addSql('DROP TABLE game_round');
    }
}
