<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20260721190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove unused legacy AI tables and align indexes/types with current Doctrine mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS bist_ai_report_item');
        $this->addSql('DROP TABLE IF EXISTS bist_ai_report');
        $this->addSql('ALTER TABLE ai_symbol_report CHANGE report_date report_date DATE NOT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE analysis_status analysis_status VARCHAR(30) NOT NULL, CHANGE history_status history_status VARCHAR(40) NOT NULL');
        $this->addSql('ALTER TABLE price_alert CHANGE last_checked_at last_checked_at DATETIME DEFAULT NULL, CHANGE triggered_at triggered_at DATETIME DEFAULT NULL, CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE watchlist_item CHANGE created_at created_at DATETIME NOT NULL, CHANGE updated_at updated_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Removed legacy AI tables had no mapped entities and cannot be reconstructed safely.');
    }
}
