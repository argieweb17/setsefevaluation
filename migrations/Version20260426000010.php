<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260426000010 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE loadslip_verification_row (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(50) NOT NULL, section VARCHAR(50) DEFAULT NULL, description VARCHAR(255) DEFAULT NULL, schedule VARCHAR(100) DEFAULT NULL, units VARCHAR(16) DEFAULT NULL, verification_id INT NOT NULL, INDEX idx_loadslip_verification_row_verification_id (verification_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE loadslip_verification_row ADD CONSTRAINT FK_3006A6751623CB0A FOREIGN KEY (verification_id) REFERENCES loadslip_verification (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_custom_subject DROP year_level, DROP semester');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE loadslip_verification_row DROP FOREIGN KEY FK_3006A6751623CB0A');
        $this->addSql('DROP TABLE loadslip_verification_row');
        $this->addSql('ALTER TABLE student_custom_subject ADD year_level VARCHAR(30) DEFAULT NULL, ADD semester VARCHAR(30) DEFAULT NULL');
    }
}
