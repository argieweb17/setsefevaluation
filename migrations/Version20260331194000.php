<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331194000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add faculty_name field to correspondence records';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correspondence_record ADD faculty_name VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correspondence_record DROP faculty_name');
    }
}
