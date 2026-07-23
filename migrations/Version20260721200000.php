<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add technical opportunity candidates and AI report scope.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE opportunity_candidate (id INT AUTO_INCREMENT NOT NULL, batch_id VARCHAR(32) NOT NULL, symbol VARCHAR(20) NOT NULL, scan_date DATE NOT NULL, score SMALLINT NOT NULL, candidate_rank SMALLINT DEFAULT NULL, status VARCHAR(20) NOT NULL, history_status VARCHAR(40) NOT NULL, is_history_stale TINYINT(1) DEFAULT 0 NOT NULL, technical_snapshot JSON NOT NULL, reasons JSON NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_OPPORTUNITY_BATCH_RANK (batch_id, candidate_rank), INDEX IDX_OPPORTUNITY_SYMBOL_CREATED (symbol, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql("ALTER TABLE ai_symbol_report ADD report_scope VARCHAR(20) DEFAULT 'tracked' NOT NULL");
        $this->addSql('CREATE INDEX IDX_AI_SYMBOL_REPORT_SCOPE_CREATED ON ai_symbol_report (report_scope, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE opportunity_candidate');
        $this->addSql('DROP INDEX IDX_AI_SYMBOL_REPORT_SCOPE_CREATED ON ai_symbol_report');
        $this->addSql('ALTER TABLE ai_symbol_report DROP report_scope');
    }
}
