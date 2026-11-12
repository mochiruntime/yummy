<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create users, invite_tokens, magic_link_tokens, and sessions tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                email VARCHAR(180) NOT NULL,
                display_name VARCHAR(60) NOT NULL,
                roles JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_users_email (email),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE invite_tokens (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                created_by_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                claimed_by_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                claimed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_invite_token_hash (token_hash),
                INDEX idx_invite_created_by (created_by_id),
                INDEX idx_invite_claimed_by (claimed_by_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_invite_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_invite_claimed_by FOREIGN KEY (claimed_by_id) REFERENCES users (id) ON DELETE SET NULL
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE magic_link_tokens (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                user_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                token_hash VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_magic_link_token_hash (token_hash),
                INDEX idx_magic_link_user (user_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_magic_link_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE sessions (
                sess_id VARBINARY(128) NOT NULL PRIMARY KEY,
                sess_data LONGBLOB NOT NULL,
                sess_lifetime INTEGER UNSIGNED NOT NULL,
                sess_time INTEGER UNSIGNED NOT NULL,
                INDEX sessions_sess_lifetime_idx (sess_lifetime)
            ) COLLATE utf8mb4_bin, ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
        $this->addSql('DROP TABLE magic_link_tokens');
        $this->addSql('DROP TABLE invite_tokens');
        $this->addSql('DROP TABLE users');
    }
}
