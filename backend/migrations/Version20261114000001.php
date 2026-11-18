<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates social and media tables: reviews, comments, reactions, and photos.
 */
final class Version20261114000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create reviews, comments, reactions, and photos tables for Phase 3 social and media features';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE reviews (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                author_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                rating SMALLINT NOT NULL,
                body LONGTEXT DEFAULT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_reviews_poi_created (poi_id, created_at),
                INDEX idx_reviews_author (author_id),
                INDEX idx_reviews_is_public (is_public),
                PRIMARY KEY(id),
                CONSTRAINT fk_reviews_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE,
                CONSTRAINT fk_reviews_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE comments (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                author_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                body LONGTEXT NOT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_comments_poi_created (poi_id, created_at),
                INDEX idx_comments_author (author_id),
                INDEX idx_comments_is_public (is_public),
                PRIMARY KEY(id),
                CONSTRAINT fk_comments_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE,
                CONSTRAINT fk_comments_author FOREIGN KEY (author_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE reactions (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                user_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                emoji VARCHAR(16) NOT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_reactions_poi (poi_id),
                INDEX idx_reactions_user (user_id),
                UNIQUE INDEX uniq_reactions_poi_user_emoji (poi_id, user_id, emoji),
                PRIMARY KEY(id),
                CONSTRAINT fk_reactions_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE,
                CONSTRAINT fk_reactions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE photos (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                review_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                uploader_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                storage_key VARCHAR(64) NOT NULL,
                mime_type VARCHAR(32) NOT NULL,
                width INT UNSIGNED NOT NULL,
                height INT UNSIGNED NOT NULL,
                caption VARCHAR(255) DEFAULT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_photos_poi_created (poi_id, created_at),
                INDEX idx_photos_review (review_id),
                INDEX idx_photos_uploader (uploader_id),
                INDEX idx_photos_is_public (is_public),
                PRIMARY KEY(id),
                CONSTRAINT fk_photos_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE,
                CONSTRAINT fk_photos_review FOREIGN KEY (review_id) REFERENCES reviews (id) ON DELETE SET NULL,
                CONSTRAINT fk_photos_uploader FOREIGN KEY (uploader_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE photos');
        $this->addSql('DROP TABLE reactions');
        $this->addSql('DROP TABLE comments');
        $this->addSql('DROP TABLE reviews');
    }
}
