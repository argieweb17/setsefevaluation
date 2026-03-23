<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260323091500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add evidence checklist items for questions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE question ADD evidence_items JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question DROP evidence_items');
    }
}
