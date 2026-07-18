<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.4 atomic first-winner settlement, interrupted-play restart credits and automatic next round.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql('CREATE UNIQUE INDEX uniq_round_winner_play ON game_round (winner_play_id) WHERE winner_play_id IS NOT NULL');
        $this->addSql("CREATE UNIQUE INDEX uniq_round_jackpot_payout ON ledger_entry (round_id) WHERE entry_type = 'JACKPOT_PAYOUT'");
        $this->addSql("CREATE UNIQUE INDEX uniq_restart_credit_issued_ledger ON ledger_entry (play_id) WHERE entry_type = 'RESTART_CREDIT_ISSUED'");
        $this->addSql("CREATE UNIQUE INDEX uniq_restart_credit_redeemed_ledger ON ledger_entry (play_id) WHERE entry_type = 'RESTART_CREDIT_REDEEMED'");
        $this->addSql('CREATE INDEX idx_credit_player_status_issued ON play_credit (player_session_id, status, issued_at)');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_catalog_keep_minimum_active_update
BEFORE UPDATE OF is_active ON choice_pair
WHEN OLD.pair_type = 'REGULAR'
  AND OLD.is_active = 1
  AND NEW.is_active = 0
  AND (SELECT COUNT(*) FROM choice_pair WHERE pair_type = 'REGULAR' AND is_active = 1) <= 19
BEGIN
    SELECT RAISE(ABORT, 'At least nineteen active regular pairs are required');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_catalog_keep_minimum_active_delete
BEFORE DELETE ON choice_pair
WHEN OLD.pair_type = 'REGULAR'
  AND OLD.is_active = 1
  AND (SELECT COUNT(*) FROM choice_pair WHERE pair_type = 'REGULAR' AND is_active = 1) <= 19
BEGIN
    SELECT RAISE(ABORT, 'At least nineteen active regular pairs are required');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_m14_validate_insert
BEFORE INSERT ON game_round
WHEN NEW.status <> 'PREPARING'
  OR NEW.winner_play_id IS NOT NULL
  OR NEW.frozen_jackpot_cents IS NOT NULL
  OR NEW.won_at IS NOT NULL
  OR NEW.settled_at IS NOT NULL
BEGIN
    SELECT RAISE(ABORT, 'A new round must start in a clean PREPARING state');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_protect_pending_win_fields
BEFORE UPDATE OF winner_play_id, frozen_jackpot_cents, won_at ON game_round
WHEN OLD.status IN ('PREPARING', 'ACTIVE')
 AND NEW.status <> 'WON'
 AND (
      NEW.winner_play_id IS NOT OLD.winner_play_id
   OR NEW.frozen_jackpot_cents IS NOT OLD.frozen_jackpot_cents
   OR NEW.won_at IS NOT OLD.won_at
 )
BEGIN
    SELECT RAISE(ABORT, 'Winning fields can be set only by the ACTIVE to WON transition');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_validate_state_transition
BEFORE UPDATE OF status ON game_round
WHEN NEW.status <> OLD.status
 AND NOT (
      (OLD.status = 'PREPARING' AND NEW.status IN ('ACTIVE', 'CANCELLED'))
   OR (OLD.status = 'ACTIVE' AND NEW.status IN ('WON', 'CANCELLED'))
   OR (OLD.status = 'WON' AND NEW.status = 'SETTLED')
 )
BEGIN
    SELECT RAISE(ABORT, 'Invalid round state transition');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_validate_win
BEFORE UPDATE OF status, winner_play_id, frozen_jackpot_cents, won_at ON game_round
WHEN NEW.status = 'WON'
 AND (
      OLD.status <> 'ACTIVE'
   OR NEW.winner_play_id IS NULL
   OR NEW.won_at IS NULL
   OR NEW.frozen_jackpot_cents <> OLD.initial_jackpot_cents + OLD.entry_contribution_cents
   OR NOT EXISTS (
        SELECT 1
        FROM play p
        WHERE p.id = NEW.winner_play_id
          AND p.round_id = OLD.id
          AND p.status = 'IN_PROGRESS'
          AND p.current_step = 20
          AND p.completed_at IS NOT NULL
   )
 )
BEGIN
    SELECT RAISE(ABORT, 'Invalid winning round finalization');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_protect_win_fields
BEFORE UPDATE ON game_round
WHEN OLD.status IN ('WON', 'SETTLED')
 AND (
      NEW.winner_play_id IS NOT OLD.winner_play_id
   OR NEW.frozen_jackpot_cents IS NOT OLD.frozen_jackpot_cents
   OR NEW.won_at IS NOT OLD.won_at
 )
BEGIN
    SELECT RAISE(ABORT, 'Winner and frozen jackpot are immutable after validation');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_round_validate_settlement
BEFORE UPDATE OF status, settled_at ON game_round
WHEN NEW.status = 'SETTLED'
 AND (
      OLD.status <> 'WON'
   OR NEW.settled_at IS NULL
   OR NEW.winner_play_id IS NULL
   OR NEW.frozen_jackpot_cents IS NULL
   OR (SELECT COUNT(*) FROM ledger_entry WHERE round_id = OLD.id AND entry_type = 'JACKPOT_PAYOUT') <> 1
 )
BEGIN
    SELECT RAISE(ABORT, 'A won round can be settled only after one jackpot payout');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_validate_status_transition
BEFORE UPDATE OF status ON play
WHEN NEW.status <> OLD.status
 AND NOT (
      (OLD.status = 'CREATED' AND NEW.status IN ('IN_PROGRESS', 'INTERRUPTED', 'CANCELLED', 'EXPIRED'))
   OR (OLD.status = 'IN_PROGRESS' AND NEW.status IN ('COMPLETED_LOST', 'COMPLETED_WON', 'INTERRUPTED', 'CANCELLED', 'EXPIRED'))
   OR (OLD.status = 'INTERRUPTED' AND NEW.status = 'CREDITED')
 )
BEGIN
    SELECT RAISE(ABORT, 'Invalid play state transition');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_validate_terminal_status
BEFORE UPDATE OF status ON play
WHEN NEW.status IN ('COMPLETED_LOST', 'COMPLETED_WON')
 AND (NEW.current_step <> 20 OR NEW.completed_at IS NULL)
BEGIN
    SELECT RAISE(ABORT, 'A completed play must contain exactly twenty accepted choices');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_play_validate_winner_status
BEFORE UPDATE OF status ON play
WHEN NEW.status = 'COMPLETED_WON'
 AND NOT EXISTS (
     SELECT 1
     FROM game_round r
     WHERE r.id = NEW.round_id
       AND r.winner_play_id = NEW.id
       AND r.status IN ('WON', 'SETTLED')
 )
BEGIN
    SELECT RAISE(ABORT, 'A play can be marked as won only after atomically claiming the round');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_credit_validate_insert
BEFORE INSERT ON play_credit
WHEN NEW.status <> 'AVAILABLE'
  OR NEW.redeemed_at IS NOT NULL
  OR NEW.redeemed_play_id IS NOT NULL
  OR NOT EXISTS (
      SELECT 1
      FROM play p
      WHERE p.id = NEW.source_play_id
        AND p.round_id = NEW.source_round_id
        AND p.player_session_id = NEW.player_session_id
        AND p.status = 'INTERRUPTED'
  )
BEGIN
    SELECT RAISE(ABORT, 'A restart credit can be issued only for an interrupted play');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_credit_protect_source
BEFORE UPDATE ON play_credit
WHEN NEW.id <> OLD.id
  OR NEW.player_session_id <> OLD.player_session_id
  OR NEW.source_round_id <> OLD.source_round_id
  OR NEW.source_play_id <> OLD.source_play_id
  OR NEW.issued_at <> OLD.issued_at
BEGIN
    SELECT RAISE(ABORT, 'Restart credit source is immutable');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_credit_validate_redemption
BEFORE UPDATE OF status, redeemed_at, redeemed_play_id ON play_credit
WHEN NEW.status <> OLD.status
 AND (
      OLD.status <> 'AVAILABLE'
   OR NEW.status <> 'REDEEMED'
   OR NEW.redeemed_at IS NULL
   OR NEW.redeemed_play_id IS NULL
   OR NOT EXISTS (
       SELECT 1
       FROM play p
       WHERE p.id = NEW.redeemed_play_id
         AND p.player_session_id = OLD.player_session_id
         AND p.entry_kind = 'RESTART_CREDIT'
         AND p.status IN ('CREATED', 'IN_PROGRESS')
   )
 )
BEGIN
    SELECT RAISE(ABORT, 'Invalid restart credit redemption');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_validate_jackpot_payout
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type = 'JACKPOT_PAYOUT'
 AND (
      NEW.play_id IS NULL
   OR NOT EXISTS (
       SELECT 1
       FROM game_round r
       WHERE r.id = NEW.round_id
         AND r.winner_play_id = NEW.play_id
         AND r.status IN ('WON', 'SETTLED')
         AND r.frozen_jackpot_cents = NEW.amount_cents
   )
 )
BEGIN
    SELECT RAISE(ABORT, 'Jackpot payout must match the atomically frozen winning round');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_ledger_validate_restart_credit
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type IN ('RESTART_CREDIT_ISSUED', 'RESTART_CREDIT_REDEEMED')
 AND (
      NEW.amount_cents <> 100
   OR NEW.play_id IS NULL
   OR (NEW.entry_type = 'RESTART_CREDIT_ISSUED' AND NOT EXISTS (
       SELECT 1 FROM play p WHERE p.id = NEW.play_id AND p.status = 'INTERRUPTED'
   ))
   OR (NEW.entry_type = 'RESTART_CREDIT_REDEEMED' AND NOT EXISTS (
       SELECT 1 FROM play p WHERE p.id = NEW.play_id AND p.entry_kind = 'RESTART_CREDIT'
   ))
 )
BEGIN
    SELECT RAISE(ABORT, 'Invalid restart-credit ledger movement');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_ledger_validate_restart_credit');
        $this->addSql('DROP TRIGGER trg_ledger_validate_jackpot_payout');
        $this->addSql('DROP TRIGGER trg_credit_validate_redemption');
        $this->addSql('DROP TRIGGER trg_credit_protect_source');
        $this->addSql('DROP TRIGGER trg_credit_validate_insert');
        $this->addSql('DROP TRIGGER trg_play_validate_winner_status');
        $this->addSql('DROP TRIGGER trg_play_validate_terminal_status');
        $this->addSql('DROP TRIGGER trg_play_validate_status_transition');
        $this->addSql('DROP TRIGGER trg_round_validate_settlement');
        $this->addSql('DROP TRIGGER trg_round_protect_win_fields');
        $this->addSql('DROP TRIGGER trg_round_validate_win');
        $this->addSql('DROP TRIGGER trg_round_validate_state_transition');
        $this->addSql('DROP TRIGGER trg_round_protect_pending_win_fields');
        $this->addSql('DROP TRIGGER trg_round_m14_validate_insert');
        $this->addSql('DROP TRIGGER trg_catalog_keep_minimum_active_delete');
        $this->addSql('DROP TRIGGER trg_catalog_keep_minimum_active_update');
        $this->addSql('DROP INDEX idx_credit_player_status_issued');
        $this->addSql('DROP INDEX uniq_restart_credit_redeemed_ledger');
        $this->addSql('DROP INDEX uniq_restart_credit_issued_ledger');
        $this->addSql('DROP INDEX uniq_round_jackpot_payout');
        $this->addSql('DROP INDEX uniq_round_winner_play');
    }
}
