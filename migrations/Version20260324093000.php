<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260324093000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verification selections JSON field to superior evaluations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE superior_evaluation ADD verification_selections JSON DEFAULT NULL COMMENT '(DC2Type:json)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE superior_evaluation DROP verification_selections');
    }
}
