<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260721120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personal price alerts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE price_alert (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(20) NOT NULL, condition_type VARCHAR(30) NOT NULL, target_value DOUBLE PRECISION NOT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, is_triggered TINYINT(1) DEFAULT 0 NOT NULL, last_price DOUBLE PRECISION DEFAULT NULL, last_quote_status VARCHAR(40) DEFAULT NULL, last_quote_http_status INT DEFAULT NULL, note LONGTEXT DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', triggered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', INDEX IDX_PRICE_ALERT_SYMBOL (symbol), INDEX IDX_PRICE_ALERT_ACTIVE_SYMBOL (is_active, symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE price_alert');
    }
}
