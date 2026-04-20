<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260419120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename reserved loadslip_verification rows column to loadslip_rows';
    }

    public function up(Schema $schema): void
    {
        $tableExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loadslip_verification'"
        );
        if ($tableExists === 0) {
            return;
        }

        $hasLegacyColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loadslip_verification' AND COLUMN_NAME = 'rows'"
        );
        $hasNewColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loadslip_verification' AND COLUMN_NAME = 'loadslip_rows'"
        );

        if ($hasLegacyColumn > 0 && $hasNewColumn === 0) {
            $this->addSql("ALTER TABLE loadslip_verification CHANGE COLUMN `rows` loadslip_rows JSON NOT NULL COMMENT '(DC2Type:json)'");
        }
    }

    public function down(Schema $schema): void
    {
        $tableExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loadslip_verification'"
        );
        if ($tableExists === 0) {
            return;
        }

        $hasLegacyColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loadslip_verification' AND COLUMN_NAME = 'rows'"
        );
        $hasNewColumn = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'loadslip_verification' AND COLUMN_NAME = 'loadslip_rows'"
        );

        if ($hasNewColumn > 0 && $hasLegacyColumn === 0) {
            $this->addSql("ALTER TABLE loadslip_verification CHANGE COLUMN loadslip_rows `rows` JSON NOT NULL COMMENT '(DC2Type:json)'");
        }
    }
}