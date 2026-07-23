<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260624193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Keep the historical migration marker; its obsolete AI tables are not used by the application';
    }

    public function up(Schema $schema): void
    {
    }

    public function down(Schema $schema): void
    {
    }
}
