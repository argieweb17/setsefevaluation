<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260426000942 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE loadslip_verification DROP loadslip_rows');
        $this->addSql('ALTER TABLE student_custom_subject DROP year_level, DROP semester');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE loadslip_verification ADD loadslip_rows JSON NOT NULL');
        $this->addSql('ALTER TABLE student_custom_subject ADD year_level VARCHAR(30) DEFAULT NULL, ADD semester VARCHAR(30) DEFAULT NULL');
    }
}
