<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates mapping domain tables: lists, pois, poi_lists, poi_revisions, and geocode_cache.
 */
final class Version20261113000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create lists, pois, poi_lists, poi_revisions, and geocode_cache tables with spatial indexing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE lists (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                owner_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(100) NOT NULL,
                color VARCHAR(16) NOT NULL DEFAULT '#E11D48',
                icon VARCHAR(32) NOT NULL DEFAULT 'bookmark',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_lists_owner (owner_id),
                UNIQUE INDEX uniq_lists_owner_slug (owner_id, slug),
                PRIMARY KEY(id),
                CONSTRAINT fk_lists_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE pois (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                created_by_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                name VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                address VARCHAR(500) DEFAULT NULL,
                latitude DECIMAL(10, 7) NOT NULL,
                longitude DECIMAL(10, 7) NOT NULL,
                location POINT NOT NULL /*!80003 SRID 4326 */,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_pois_created_by (created_by_id),
                INDEX idx_pois_is_public (is_public),
                SPATIAL INDEX idx_pois_location (location),
                PRIMARY KEY(id),
                CONSTRAINT fk_pois_created_by FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE poi_lists (
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                list_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX idx_poi_lists_poi (poi_id),
                INDEX idx_poi_lists_list (list_id),
                PRIMARY KEY(poi_id, list_id),
                CONSTRAINT fk_poi_lists_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE,
                CONSTRAINT fk_poi_lists_list FOREIGN KEY (list_id) REFERENCES lists (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE poi_revisions (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                editor_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                revision_number INT UNSIGNED NOT NULL,
                changed_fields JSON NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_poi_revisions_poi (poi_id),
                INDEX idx_poi_revisions_editor (editor_id),
                UNIQUE INDEX uniq_poi_revisions_poi_rev (poi_id, revision_number),
                PRIMARY KEY(id),
                CONSTRAINT fk_poi_revisions_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE,
                CONSTRAINT fk_poi_revisions_editor FOREIGN KEY (editor_id) REFERENCES users (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE geocode_cache (
                query_hash VARCHAR(64) NOT NULL PRIMARY KEY,
                provider VARCHAR(32) NOT NULL,
                response_json JSON NOT NULL,
                expires_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_geocode_cache_expires (expires_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE geocode_cache');
        $this->addSql('DROP TABLE poi_revisions');
        $this->addSql('DROP TABLE poi_lists');
        $this->addSql('DROP TABLE pois');
        $this->addSql('DROP TABLE lists');
    }
}
