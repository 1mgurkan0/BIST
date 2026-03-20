<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260218083252 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE kap_news (id INT AUTO_INCREMENT NOT NULL, kap_id VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, stock_codes JSON NOT NULL, published_at DATETIME NOT NULL, is_analyzed TINYINT DEFAULT 0 NOT NULL, sentiment_score SMALLINT DEFAULT NULL, ai_summary VARCHAR(500) DEFAULT NULL, analyzed_at DATETIME DEFAULT NULL, INDEX IDX_PUBLISHED_AT (published_at), UNIQUE INDEX UNIQ_KAP_ID (kap_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE kap_news');
    }
}
