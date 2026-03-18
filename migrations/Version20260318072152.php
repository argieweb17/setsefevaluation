<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260318072152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation_message ADD sender_type VARCHAR(10) DEFAULT NULL, ADD parent_message_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE evaluation_message ADD CONSTRAINT FK_95AE28F514399779 FOREIGN KEY (parent_message_id) REFERENCES evaluation_message (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_95AE28F514399779 ON evaluation_message (parent_message_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE evaluation_message DROP FOREIGN KEY FK_95AE28F514399779');
        $this->addSql('DROP INDEX IDX_95AE28F514399779 ON evaluation_message');
        $this->addSql('ALTER TABLE evaluation_message DROP sender_type, DROP parent_message_id');
    }
}
