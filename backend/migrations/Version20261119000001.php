<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the activity_events table for the chronological, visibility-filtered activity feed.
 */
final class Version20261119000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create activity_events table for chronological activity feed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE activity_events (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                actor_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                event_type VARCHAR(32) NOT NULL,
                poi_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                target_type VARCHAR(32) NOT NULL,
                target_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                payload JSON NOT NULL,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_activity_events_created_id (created_at, id),
                INDEX idx_activity_events_poi (poi_id),
                INDEX idx_activity_events_target (target_type, target_id),
                INDEX idx_activity_events_actor (actor_id),
                PRIMARY KEY(id),
                CONSTRAINT fk_activity_events_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_activity_events_poi FOREIGN KEY (poi_id) REFERENCES pois (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_events');
    }
}
