<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.6 isolated simulation runs, per-step choice statistics and top path frequencies.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql(<<<'SQL'
CREATE TABLE simulation_run (
    id CHAR(26) NOT NULL PRIMARY KEY,
    public_code VARCHAR(40) NOT NULL,
    profile VARCHAR(24) NOT NULL,
    bias_basis_points INTEGER NOT NULL,
    requested_plays INTEGER NOT NULL,
    completed_plays INTEGER NOT NULL,
    random_seed INTEGER NOT NULL,
    unique_paths INTEGER NOT NULL,
    duplicate_plays INTEGER NOT NULL,
    coverage_ppm INTEGER NOT NULL,
    shannon_entropy_millibits INTEGER NOT NULL,
    effective_path_count INTEGER NOT NULL,
    max_path_hits INTEGER NOT NULL,
    duration_ms INTEGER NOT NULL,
    started_at DATETIME_IMMUTABLE NOT NULL,
    completed_at DATETIME_IMMUTABLE NOT NULL,
    CONSTRAINT chk_sim_profile CHECK (profile IN ('UNIFORM', 'FIXED_A_BIAS', 'ALTERNATING_BIAS')),
    CONSTRAINT chk_sim_bias CHECK (bias_basis_points BETWEEN 5000 AND 9500),
    CONSTRAINT chk_sim_plays CHECK (requested_plays BETWEEN 1 AND 1000000 AND completed_plays = requested_plays),
    CONSTRAINT chk_sim_seed CHECK (random_seed BETWEEN 0 AND 2147483647),
    CONSTRAINT chk_sim_unique CHECK (unique_paths BETWEEN 1 AND completed_plays AND unique_paths <= 1048576),
    CONSTRAINT chk_sim_duplicates CHECK (duplicate_plays = completed_plays - unique_paths),
    CONSTRAINT chk_sim_coverage CHECK (coverage_ppm BETWEEN 0 AND 1000000),
    CONSTRAINT chk_sim_entropy CHECK (shannon_entropy_millibits BETWEEN 0 AND 20000),
    CONSTRAINT chk_sim_effective_paths CHECK (effective_path_count BETWEEN 1 AND 1048576),
    CONSTRAINT chk_sim_max_hits CHECK (max_path_hits BETWEEN 1 AND completed_plays),
    CONSTRAINT chk_sim_duration CHECK (duration_ms >= 0)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_simulation_public_code ON simulation_run (public_code)');
        $this->addSql('CREATE INDEX idx_simulation_completed_at ON simulation_run (completed_at DESC)');
        $this->addSql('CREATE INDEX idx_simulation_profile ON simulation_run (profile, completed_at DESC)');

        $this->addSql(<<<'SQL'
CREATE TABLE simulation_choice_stat (
    run_id CHAR(26) NOT NULL,
    step_number INTEGER NOT NULL,
    probability_a_basis_points INTEGER NOT NULL,
    option_a_count INTEGER NOT NULL,
    option_b_count INTEGER NOT NULL,
    PRIMARY KEY (run_id, step_number),
    FOREIGN KEY (run_id) REFERENCES simulation_run (id) ON DELETE CASCADE,
    CONSTRAINT chk_sim_choice_step CHECK (step_number BETWEEN 1 AND 20),
    CONSTRAINT chk_sim_choice_probability CHECK (probability_a_basis_points BETWEEN 500 AND 9500),
    CONSTRAINT chk_sim_choice_counts CHECK (option_a_count >= 0 AND option_b_count >= 0)
)
SQL);
        $this->addSql('CREATE INDEX idx_sim_choice_run ON simulation_choice_stat (run_id, step_number)');

        $this->addSql(<<<'SQL'
CREATE TABLE simulation_path_stat (
    run_id CHAR(26) NOT NULL,
    rank_number INTEGER NOT NULL,
    path_value INTEGER NOT NULL,
    path_bits CHAR(20) NOT NULL,
    hit_count INTEGER NOT NULL,
    PRIMARY KEY (run_id, rank_number),
    FOREIGN KEY (run_id) REFERENCES simulation_run (id) ON DELETE CASCADE,
    CONSTRAINT chk_sim_path_rank CHECK (rank_number BETWEEN 1 AND 50),
    CONSTRAINT chk_sim_path_value CHECK (path_value BETWEEN 0 AND 1048575),
    CONSTRAINT chk_sim_path_bits CHECK (length(path_bits) = 20 AND path_bits NOT GLOB '*[^01]*'),
    CONSTRAINT chk_sim_path_hits CHECK (hit_count >= 2)
)
SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_sim_path_value_per_run ON simulation_path_stat (run_id, path_value)');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_simulation_run_immutable_update
BEFORE UPDATE ON simulation_run
BEGIN
    SELECT RAISE(ABORT, 'Simulation runs are immutable');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_simulation_choice_immutable_update
BEFORE UPDATE ON simulation_choice_stat
BEGIN
    SELECT RAISE(ABORT, 'Simulation choice statistics are immutable');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_simulation_path_immutable_update
BEFORE UPDATE ON simulation_path_stat
BEGIN
    SELECT RAISE(ABORT, 'Simulation path statistics are immutable');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_simulation_run_immutable_delete
BEFORE DELETE ON simulation_run
BEGIN
    SELECT RAISE(ABORT, 'Simulation runs are immutable');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_simulation_choice_immutable_delete
BEFORE DELETE ON simulation_choice_stat
BEGIN
    SELECT RAISE(ABORT, 'Simulation choice statistics are immutable');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_simulation_path_immutable_delete
BEFORE DELETE ON simulation_path_stat
BEGIN
    SELECT RAISE(ABORT, 'Simulation path statistics are immutable');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER trg_simulation_path_immutable_delete');
        $this->addSql('DROP TRIGGER trg_simulation_choice_immutable_delete');
        $this->addSql('DROP TRIGGER trg_simulation_run_immutable_delete');
        $this->addSql('DROP TRIGGER trg_simulation_path_immutable_update');
        $this->addSql('DROP TRIGGER trg_simulation_choice_immutable_update');
        $this->addSql('DROP TRIGGER trg_simulation_run_immutable_update');
        $this->addSql('DROP TABLE simulation_path_stat');
        $this->addSql('DROP TABLE simulation_choice_stat');
        $this->addSql('DROP TABLE simulation_run');
    }
}
