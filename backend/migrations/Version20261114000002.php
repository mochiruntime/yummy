<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extends the reactions table to support POI, Review, and Photo targets.
 */
final class Version20261114000002 extends AbstractMigration
{
    /**
     * Returns a human-readable description of this migration.
     */
    public function getDescription(): string
    {
        return 'Extend reactions table with review_id, photo_id, target_type, and target_id for 3-target reactions';
    }

    /**
     * Alters the reactions table to support polymorphic target uniqueness alongside cascading foreign keys.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reactions DROP INDEX uniq_reactions_poi_user_emoji');
        $this->addSql('ALTER TABLE reactions ADD review_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD photo_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD target_type VARCHAR(16) NOT NULL, ADD target_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('UPDATE reactions SET target_type = \'poi\', target_id = poi_id');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT fk_reactions_review FOREIGN KEY (review_id) REFERENCES reviews (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reactions ADD CONSTRAINT fk_reactions_photo FOREIGN KEY (photo_id) REFERENCES photos (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_reactions_review ON reactions (review_id)');
        $this->addSql('CREATE INDEX idx_reactions_photo ON reactions (photo_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_reactions_target_user_emoji ON reactions (target_type, target_id, user_id, emoji)');
    }

    /**
     * Reverts the reactions table back to POI-only reactions.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reactions DROP INDEX uniq_reactions_target_user_emoji');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY fk_reactions_review');
        $this->addSql('ALTER TABLE reactions DROP FOREIGN KEY fk_reactions_photo');
        $this->addSql('DROP INDEX idx_reactions_review ON reactions');
        $this->addSql('DROP INDEX idx_reactions_photo ON reactions');
        $this->addSql('DELETE FROM reactions WHERE target_type != \'poi\'');
        $this->addSql('ALTER TABLE reactions DROP COLUMN review_id, DROP COLUMN photo_id, DROP COLUMN target_type, DROP COLUMN target_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_reactions_poi_user_emoji ON reactions (poi_id, user_id, emoji)');
    }
}
