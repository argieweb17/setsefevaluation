<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260426003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy enrollment table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS enrollment');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE enrollment (id INT AUTO_INCREMENT NOT NULL, student_id INT NOT NULL, subject_id INT NOT NULL, status VARCHAR(20) DEFAULT \'pending\' NOT NULL, section VARCHAR(50) DEFAULT NULL, schedule VARCHAR(100) DEFAULT NULL, INDEX IDX_DBDCD7E1CB944F1A (student_id), INDEX IDX_DBDCD7E123EDC87 (subject_id), UNIQUE INDEX unique_enrollment (student_id, subject_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E1CB944F1A FOREIGN KEY (student_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE enrollment ADD CONSTRAINT FK_DBDCD7E123EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id)');
    }
}