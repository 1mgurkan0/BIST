<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260830190114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_symbol_history (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(20) NOT NULL, record_date DATE NOT NULL, decision VARCHAR(20) NOT NULL, trend VARCHAR(20) NOT NULL, price DOUBLE PRECISION NOT NULL, rsi DOUBLE PRECISION NOT NULL, UNIQUE INDEX uniq_symbol_date (symbol, record_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE ai_symbol_history');
    }
}
