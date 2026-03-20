<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260305201947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE portfolio ADD daily_change DOUBLE PRECISION DEFAULT NULL, ADD daily_change_percent DOUBLE PRECISION DEFAULT NULL, ADD total_value DOUBLE PRECISION DEFAULT NULL, ADD profit_loss DOUBLE PRECISION DEFAULT NULL, ADD profit_loss_percent DOUBLE PRECISION DEFAULT NULL, ADD sentiment_score INT DEFAULT NULL, ADD last_updated DATETIME DEFAULT NULL, CHANGE current_price current_price DOUBLE PRECISION DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE portfolio DROP daily_change, DROP daily_change_percent, DROP total_value, DROP profit_loss, DROP profit_loss_percent, DROP sentiment_score, DROP last_updated, CHANGE current_price current_price DOUBLE PRECISION NOT NULL');
    }
}
