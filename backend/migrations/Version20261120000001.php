<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds optional admin-facing label column to invite_tokens.
 */
final class Version20261120000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional label column to invite_tokens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite_tokens ADD label VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invite_tokens DROP label');
    }
}
