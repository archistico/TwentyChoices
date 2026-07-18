<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260718000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.8 amministratori autenticati, ruoli espliciti e protezione dell ultimo SUPER_ADMIN.';
    }

    public function up(Schema $schema): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->abortIf(true, 'This prototype migration targets SQLite only.');
        }

        $this->addSql(<<<'SQL'
CREATE TABLE admin_user (
    id CHAR(26) NOT NULL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    username_normalized VARCHAR(64) COLLATE NOCASE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME_IMMUTABLE NOT NULL,
    updated_at DATETIME_IMMUTABLE NOT NULL,
    last_login_at DATETIME_IMMUTABLE DEFAULT NULL,
    password_changed_at DATETIME_IMMUTABLE NOT NULL,
    auth_version INTEGER NOT NULL DEFAULT 1,
    CONSTRAINT uq_admin_username_normalized UNIQUE (username_normalized),
    CONSTRAINT chk_admin_username CHECK (length(username) BETWEEN 3 AND 64),
    CONSTRAINT chk_admin_role CHECK (role IN ('SUPER_ADMIN', 'OPERATOR', 'AUDITOR')),
    CONSTRAINT chk_admin_active CHECK (is_active IN (0, 1)),
    CONSTRAINT chk_admin_auth_version CHECK (auth_version >= 1)
)
SQL);
        $this->addSql('CREATE INDEX idx_admin_user_role_active ON admin_user (role, is_active)');
        $this->addSql('CREATE INDEX idx_admin_user_last_login ON admin_user (last_login_at)');

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_admin_identity_immutable
BEFORE UPDATE OF id, username, username_normalized, created_at ON admin_user
FOR EACH ROW
WHEN NEW.id <> OLD.id
  OR NEW.username <> OLD.username
  OR NEW.username_normalized <> OLD.username_normalized
  OR NEW.created_at <> OLD.created_at
BEGIN
    SELECT RAISE(ABORT, 'Admin identity fields are immutable');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_admin_auth_version_monotonic
BEFORE UPDATE ON admin_user
FOR EACH ROW
WHEN NEW.auth_version < OLD.auth_version
  OR ((NEW.password_hash <> OLD.password_hash OR NEW.role <> OLD.role OR NEW.is_active <> OLD.is_active)
      AND NEW.auth_version <= OLD.auth_version)
BEGIN
    SELECT RAISE(ABORT, 'Admin auth_version must increase after security changes');
END
SQL);

        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_admin_keep_last_super_update
BEFORE UPDATE OF role, is_active ON admin_user
FOR EACH ROW
WHEN OLD.role = 'SUPER_ADMIN'
 AND OLD.is_active = 1
 AND (NEW.role <> 'SUPER_ADMIN' OR NEW.is_active <> 1)
 AND (SELECT COUNT(*) FROM admin_user WHERE role = 'SUPER_ADMIN' AND is_active = 1) <= 1
BEGIN
    SELECT RAISE(ABORT, 'Cannot disable or demote the last active SUPER_ADMIN');
END
SQL);
        $this->addSql(<<<'SQL'
CREATE TRIGGER trg_admin_keep_last_super_delete
BEFORE DELETE ON admin_user
FOR EACH ROW
WHEN OLD.role = 'SUPER_ADMIN'
 AND OLD.is_active = 1
 AND (SELECT COUNT(*) FROM admin_user WHERE role = 'SUPER_ADMIN' AND is_active = 1) <= 1
BEGIN
    SELECT RAISE(ABORT, 'Cannot delete the last active SUPER_ADMIN');
END
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS trg_admin_keep_last_super_delete');
        $this->addSql('DROP TRIGGER IF EXISTS trg_admin_auth_version_monotonic');
        $this->addSql('DROP TRIGGER IF EXISTS trg_admin_identity_immutable');
        $this->addSql('DROP TRIGGER IF EXISTS trg_admin_keep_last_super_update');
        $this->addSql('DROP TABLE admin_user');
    }
}
