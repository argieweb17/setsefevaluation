<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260331173000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add release workflow fields to correspondence_record';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correspondence_record ADD is_released TINYINT(1) NOT NULL DEFAULT 0, ADD released_by_id INT DEFAULT NULL, ADD released_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE correspondence_record ADD CONSTRAINT FK_CORR_RELEASED_BY FOREIGN KEY (released_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_CORR_RELEASED_BY ON correspondence_record (released_by_id)');
        $this->addSql('CREATE INDEX idx_corr_released_at ON correspondence_record (released_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE correspondence_record DROP FOREIGN KEY FK_CORR_RELEASED_BY');
        $this->addSql('DROP INDEX IDX_CORR_RELEASED_BY ON correspondence_record');
        $this->addSql('DROP INDEX idx_corr_released_at ON correspondence_record');
        $this->addSql('ALTER TABLE correspondence_record DROP is_released, DROP released_by_id, DROP released_at');
    }
}
