<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI report quality and technical analysis fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE ai_symbol_report ADD analysis_status VARCHAR(30) DEFAULT 'success' NOT NULL, ADD history_status VARCHAR(40) DEFAULT 'missing_history' NOT NULL, ADD technical_snapshot JSON DEFAULT NULL");
        $this->addSql("UPDATE ai_symbol_report SET technical_snapshot = '{}' WHERE technical_snapshot IS NULL");
        $this->addSql('ALTER TABLE ai_symbol_report CHANGE technical_snapshot technical_snapshot JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_symbol_report DROP analysis_status, DROP history_status, DROP technical_snapshot');
    }
}
