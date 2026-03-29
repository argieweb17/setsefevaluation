<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create correspondence_record table for saved SET/SEF print correspondence IDs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE correspondence_record (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, correspondence_id VARCHAR(120) NOT NULL, evaluation_type VARCHAR(10) NOT NULL, print_scope VARCHAR(30) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_corr_eval_created (evaluation_type, created_at), INDEX idx_corr_id (correspondence_id), INDEX IDX_CORR_CREATED_BY (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE correspondence_record ADD CONSTRAINT FK_CORR_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE correspondence_record');
    }
}
