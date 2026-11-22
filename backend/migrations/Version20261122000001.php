<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds is_public visibility flag to curated lists (defaulting to private).
 */
final class Version20261122000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_public boolean column to lists table (default false).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lists ADD is_public TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lists DROP is_public');
    }
}
