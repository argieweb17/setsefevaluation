<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create subject master list entries for faculty-controlled student evaluation eligibility';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE subject_master_list_entry (id INT AUTO_INCREMENT NOT NULL, faculty_id INT NOT NULL, subject_id INT NOT NULL, section VARCHAR(50) DEFAULT '' NOT NULL, student_school_id VARCHAR(50) NOT NULL, student_name VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX idx_master_list_subject_section (faculty_id, subject_id, section), INDEX idx_master_list_student_school_id (student_school_id), UNIQUE INDEX uniq_master_list_student (faculty_id, subject_id, section, student_school_id), INDEX IDX_567E7D93C768A2E7 (faculty_id), INDEX IDX_567E7D9323EDC87 (subject_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE subject_master_list_entry ADD CONSTRAINT FK_567E7D93C768A2E7 FOREIGN KEY (faculty_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE subject_master_list_entry ADD CONSTRAINT FK_567E7D9323EDC87 FOREIGN KEY (subject_id) REFERENCES subject (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subject_master_list_entry');
    }
}
