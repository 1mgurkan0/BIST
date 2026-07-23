<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add index for latest stock quote lookup by symbol and timestamp.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_STOCK_SYMBOL_CREATED ON stock (symbol, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_STOCK_SYMBOL_CREATED ON stock');
    }
}
