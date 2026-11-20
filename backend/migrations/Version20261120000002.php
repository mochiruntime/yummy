<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds spend tier, currency, and vibe tags to reviews, creates review_items, and links photos to review items.
 */
final class Version20261120000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add price_level, currency, and vibes to reviews; create review_items; add review_item_id to photos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reviews ADD price_level SMALLINT DEFAULT NULL, ADD currency VARCHAR(3) DEFAULT NULL, ADD vibes JSON NOT NULL DEFAULT (\'[]\')');
        $this->addSql(<<<'SQL'
            CREATE TABLE review_items (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                review_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                name VARCHAR(255) NOT NULL,
                verdict VARCHAR(16) NOT NULL,
                price_amount NUMERIC(10, 2) DEFAULT NULL,
                note VARCHAR(1000) DEFAULT NULL,
                sort_order INT NOT NULL,
                INDEX idx_review_items_review_sort (review_id, sort_order),
                PRIMARY KEY(id),
                CONSTRAINT FK_review_items_review FOREIGN KEY (review_id) REFERENCES reviews (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
        $this->addSql('ALTER TABLE photos ADD review_item_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\', ADD INDEX idx_photos_review_item (review_item_id)');
        $this->addSql('ALTER TABLE photos ADD CONSTRAINT FK_photos_review_item FOREIGN KEY (review_item_id) REFERENCES review_items (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE photos DROP FOREIGN KEY FK_photos_review_item');
        $this->addSql('DROP INDEX idx_photos_review_item ON photos');
        $this->addSql('ALTER TABLE photos DROP review_item_id');
        $this->addSql('DROP TABLE review_items');
        $this->addSql('ALTER TABLE reviews DROP price_level, DROP currency, DROP vibes');
    }
}
