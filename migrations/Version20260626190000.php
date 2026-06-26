<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260626190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add personal watchlist items table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE watchlist_item (id INT AUTO_INCREMENT NOT NULL, symbol VARCHAR(20) NOT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)', updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)', UNIQUE INDEX UNIQ_WATCHLIST_SYMBOL (symbol), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE watchlist_item');
    }
}
