<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260910135754 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE blog_category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, slug VARCHAR(100) NOT NULL, UNIQUE INDEX UNIQ_72113DE6989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blog_post (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, content LONGTEXT NOT NULL, excerpt VARCHAR(500) DEFAULT NULL, status VARCHAR(20) NOT NULL, published_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, meta_title VARCHAR(255) DEFAULT NULL, meta_description VARCHAR(500) DEFAULT NULL, canonical_url VARCHAR(255) DEFAULT NULL, focus_keyword VARCHAR(100) DEFAULT NULL, category_id INT DEFAULT NULL, og_image_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_BA5AE01D989D9B62 (slug), INDEX IDX_BA5AE01D12469DE2 (category_id), INDEX IDX_BA5AE01D6EFCB8B8 (og_image_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blog_post_blog_tag (blog_post_id INT NOT NULL, blog_tag_id INT NOT NULL, INDEX IDX_CA877A44A77FBEAF (blog_post_id), INDEX IDX_CA877A442F9DC6D0 (blog_tag_id), PRIMARY KEY (blog_post_id, blog_tag_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE blog_tag (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, slug VARCHAR(50) NOT NULL, UNIQUE INDEX UNIQ_6EC3989989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE media (id INT AUTO_INCREMENT NOT NULL, path VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, alt_text VARCHAR(255) DEFAULT NULL, size INT NOT NULL, width INT DEFAULT NULL, height INT DEFAULT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE blog_post ADD CONSTRAINT FK_BA5AE01D12469DE2 FOREIGN KEY (category_id) REFERENCES blog_category (id)');
        $this->addSql('ALTER TABLE blog_post ADD CONSTRAINT FK_BA5AE01D6EFCB8B8 FOREIGN KEY (og_image_id) REFERENCES media (id)');
        $this->addSql('ALTER TABLE blog_post_blog_tag ADD CONSTRAINT FK_CA877A44A77FBEAF FOREIGN KEY (blog_post_id) REFERENCES blog_post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE blog_post_blog_tag ADD CONSTRAINT FK_CA877A442F9DC6D0 FOREIGN KEY (blog_tag_id) REFERENCES blog_tag (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE blog_post DROP FOREIGN KEY FK_BA5AE01D12469DE2');
        $this->addSql('ALTER TABLE blog_post DROP FOREIGN KEY FK_BA5AE01D6EFCB8B8');
        $this->addSql('ALTER TABLE blog_post_blog_tag DROP FOREIGN KEY FK_CA877A44A77FBEAF');
        $this->addSql('ALTER TABLE blog_post_blog_tag DROP FOREIGN KEY FK_CA877A442F9DC6D0');
        $this->addSql('DROP TABLE blog_category');
        $this->addSql('DROP TABLE blog_post');
        $this->addSql('DROP TABLE blog_post_blog_tag');
        $this->addSql('DROP TABLE blog_tag');
        $this->addSql('DROP TABLE media');
    }
}
