<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create database-backed storage for student loadslip verification data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE loadslip_verification (id INT AUTO_INCREMENT NOT NULL, school_id VARCHAR(32) NOT NULL, student_number VARCHAR(32) NOT NULL, codes JSON NOT NULL COMMENT '(DC2Type:json)', `rows` JSON NOT NULL COMMENT '(DC2Type:json)', preview_path VARCHAR(255) DEFAULT NULL, school_year VARCHAR(32) DEFAULT NULL, semester VARCHAR(16) DEFAULT NULL, verified TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX uniq_loadslip_verification_school_id (school_id), INDEX idx_loadslip_verification_updated_at (updated_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE loadslip_verification');
    }
}
