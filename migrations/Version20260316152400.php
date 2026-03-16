<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260316152400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY `FK_F6E1C0F52E65C292`');
        $this->addSql('ALTER TABLE audit_log CHANGE performed_by_id performed_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F52E65C292 FOREIGN KEY (performed_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user CHANGE email email VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F52E65C292');
        $this->addSql('ALTER TABLE audit_log CHANGE performed_by_id performed_by_id INT NOT NULL');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT `FK_F6E1C0F52E65C292` FOREIGN KEY (performed_by_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE NO ACTION');
        $this->addSql('ALTER TABLE `user` CHANGE email email VARCHAR(180) NOT NULL');
    }
}
