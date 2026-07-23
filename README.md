# BAM - Kisisel BIST Karar Terminali

BAM; takip listesi ve portfoy hisselerini izleyen, fiyat alarmi ureten, KAP haberlerini toplayan ve gun sonu Gemini destekli cok vadeli analiz raporu hazirlayan kisisel Symfony uygulamasidir.

## Yerel gelistirme

```bash
composer install
docker compose up -d database redis mailer
php bin/console doctrine:migrations:migrate --no-interaction
symfony server:start
```

Admin hesabi web kaydiyla degil terminalden yonetilir:

```bash
php bin/console app:admin:reset-password email@example.com
```

Temel dogrulama:

```bash
php bin/console app:production:check
php bin/phpunit
```

## Calisma akisi

- `app:prices:refresh`: aktif portfoy, takip listesi ve alarm sembollerini tek Yahoo batch istegiyle yeniler.
- `app:alerts:check --dry-run`: taze snapshot uzerinden alarm kosullarini Telegram gondermeden sinar.
- `app:kap-crawl --days=2 --dry-run`: resmi KAP akisini kontrol eder.
- `app:run-analysis --dry-run --limit=1`: yeni KAP haberlerinin Gemini analiz yolunu sinar.
- `app:opportunities:scan --dry-run`: yapilandirilmis BIST evrenini teknik olarak siralar.
- `app:daily-ai-report --dry-run --mock-ai`: kayit ve Telegram yan etkisi olmadan gun sonu rapor hattini sinar.

Dashboard dis kaynak beklemez; son fiyat snapshotini ve son basarili AI raporunu gosterir. Yahoo 429 verirse son basarili fiyat `stale/!429` olarak korunur ve alarm tetiklenmez.

Canli kurulum, worker, Nginx, TLS, yedekleme ve test surusu icin [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) dosyasini kullanin.
