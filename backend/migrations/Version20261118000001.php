<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the unused slug column and uniq_lists_owner_slug index from the lists table.
 */
final class Version20261118000001 extends AbstractMigration
{
    /**
     * Returns a human-readable description of this migration.
     */
    public function getDescription(): string
    {
        return 'Drop unused slug column and uniq_lists_owner_slug index from lists table';
    }

    /**
     * Drops the unique index and slug column from lists.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lists DROP INDEX uniq_lists_owner_slug');
        $this->addSql('ALTER TABLE lists DROP COLUMN slug');
    }

    /**
     * Restores the slug column and uniq_lists_owner_slug index on lists.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lists ADD slug VARCHAR(100) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_lists_owner_slug ON lists (owner_id, slug)');
    }
}
