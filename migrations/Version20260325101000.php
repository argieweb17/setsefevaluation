<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325101000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add position and academic_rank fields to user for superior profile information';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD position VARCHAR(120) DEFAULT NULL, ADD academic_rank VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP position, DROP academic_rank');
    }
}
