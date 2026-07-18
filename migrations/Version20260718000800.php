<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.7 fixed-window request throttling state for security-sensitive HTTP endpoints.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql(<<<'SQL'
CREATE TABLE request_rate_limit (
    scope VARCHAR(64) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    window_start INTEGER NOT NULL,
    request_count INTEGER NOT NULL,
    updated_at DATETIME_IMMUTABLE NOT NULL,
    PRIMARY KEY (scope, key_hash, window_start),
    CONSTRAINT chk_rate_scope CHECK (length(scope) BETWEEN 1 AND 64),
    CONSTRAINT chk_rate_hash CHECK (length(key_hash) = 64),
    CONSTRAINT chk_rate_window CHECK (window_start >= 0),
    CONSTRAINT chk_rate_count CHECK (request_count >= 1)
)
SQL);
        $this->addSql('CREATE INDEX idx_rate_limit_cleanup ON request_rate_limit (window_start)');
        $this->addSql('CREATE INDEX idx_rate_limit_updated ON request_rate_limit (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE request_rate_limit');
    }
}
