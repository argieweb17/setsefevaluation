<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260319144946 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE staff_notification_read DROP FOREIGN KEY `FK_STAFF_NOTIF_EVAL`');
        $this->addSql('ALTER TABLE staff_notification_read DROP FOREIGN KEY `FK_STAFF_NOTIF_USER`');
        $this->addSql('DROP TABLE staff_notification_read');
        $this->addSql('ALTER TABLE evaluation_response ADD section VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE staff_notification_read (id INT AUTO_INCREMENT NOT NULL, read_at DATETIME NOT NULL, user_id INT NOT NULL, evaluation_period_id INT NOT NULL, INDEX IDX_STAFF_NOTIF_EVAL (evaluation_period_id), UNIQUE INDEX UNIQ_STAFF_NOTIF (user_id, evaluation_period_id), INDEX IDX_STAFF_NOTIF_USER (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE staff_notification_read ADD CONSTRAINT `FK_STAFF_NOTIF_EVAL` FOREIGN KEY (evaluation_period_id) REFERENCES evaluation_period (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE staff_notification_read ADD CONSTRAINT `FK_STAFF_NOTIF_USER` FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('ALTER TABLE evaluation_response DROP section');
    }
}
