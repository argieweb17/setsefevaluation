<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331191000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add received_by_name field for correspondence releases';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correspondence_record ADD received_by_name VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correspondence_record DROP received_by_name');
    }
}
