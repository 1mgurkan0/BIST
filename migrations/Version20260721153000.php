<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add AI symbol report table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE ai_symbol_report (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(20) NOT NULL, report_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)', score SMALLINT NOT NULL, trend_label VARCHAR(20) NOT NULL, decision_label VARCHAR(30) NOT NULL, confidence VARCHAR(20) NOT NULL, price DOUBLE PRECISION DEFAULT NULL, daily_change_percent DOUBLE PRECISION DEFAULT NULL, data_status VARCHAR(40) NOT NULL, is_price_stale TINYINT(1) DEFAULT 0 NOT NULL, is_portfolio TINYINT(1) DEFAULT 0 NOT NULL, is_watchlist TINYINT(1) DEFAULT 0 NOT NULL, daily_comment LONGTEXT NOT NULL, short_term LONGTEXT NOT NULL, medium_term LONGTEXT NOT NULL, long_term LONGTEXT NOT NULL, kap_impact LONGTEXT NOT NULL, risk_summary LONGTEXT NOT NULL, raw_response LONGTEXT DEFAULT NULL, price_snapshot JSON NOT NULL, kap_news_ids JSON NOT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_AI_SYMBOL_REPORT_SYMBOL_CREATED (symbol, created_at), INDEX IDX_AI_SYMBOL_REPORT_REPORT_DATE (report_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ai_symbol_report');
    }
}
