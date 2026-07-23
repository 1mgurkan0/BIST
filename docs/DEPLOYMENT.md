# BAM Canliya Alma Rehberi

Bu kurulum uygulamayi Nginx + PHP-FPM uzerinde, MySQL ve Redis'i Docker Compose ile calistirir. Uygulamanin scheduler ve async mesajlari systemd worker tarafindan kesintisiz tuketilir.

## 1. Sunucu gereksinimleri

- Linux sunucu (Ubuntu 24.04 onerilir), en az 2 GB RAM
- PHP 8.3+ FPM ve CLI
- PHP eklentileri: `ctype`, `curl`, `iconv`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `opcache`, `xml`
- Composer 2, Docker Engine + Compose plugin, Nginx ve Certbot
- Alan adinin A/AAAA kaydi sunucuya yonlendirilmis olmali

Uygulama dizini bu rehberde `/var/www/bist_project` kabul edilir. Dizin farkliysa `deploy/systemd/bist-worker.service`, Nginx dosyasi ve scriptlerdeki yolu degistirin.

## 2. Ortam ve altyapi

1. Repoyu `/var/www/bist_project` altina alin.
2. `.env.example` dosyasini `.env` olarak olusturup tum `CHANGE_ME` alanlarini doldurun. Docker Compose degiskenleri `.env` dosyasindan okur; dosyayi Git'e eklemeyin.
3. `APP_SECRET` icin `openssl rand -hex 32` kullanin.
4. `DEFAULT_URI` degerini gercek HTTPS alan adina ayarlayin.
5. `DATABASE_URL` icindeki ozel parola karakterlerini URL-encode edin.
6. Gemini modelini degistirmeniz gerekirse `GEMINI_MODEL` kullanin; varsayilan deger `gemini-2.5-flash`tir.
7. `docker compose up -d database redis` ile altyapiyi baslatin.
8. `docker compose ps` ciktisinda MySQL ve Redis'in `healthy` oldugunu dogrulayin.

phpMyAdmin canlida baslatilmaz. Gerekirse yalnizca SSH tunnel arkasinda `docker compose --profile dev up -d pma` kullanin; port zaten `127.0.0.1` ile sinirlidir.

## 3. Ilk deploy

```bash
cd /var/www/bist_project
sudo chown -R www-data:www-data var
sudo -u www-data APP_DIR=/var/www/bist_project bash deploy/deploy.sh
```

Script migration, transport tablolari, cache, asset derleme, schema dogrulama ve strict production kontrolunu tamamlamadan basarili sayilmaz.

## 4. Nginx ve TLS

1. `deploy/nginx/bist.conf` icindeki `bist.example.com` degerlerini gercek alan adiyla degistirin.
2. PHP-FPM socket'i kurulu PHP surumune gore guncelleyin.
3. Certbot ile sertifikayi alin.
4. Dosyayi `/etc/nginx/sites-available/bist.conf` altina kopyalayip `sites-enabled` baglantisini olusturun.
5. `nginx -t` basarili olduktan sonra Nginx'i reload edin.

## 5. Scheduler worker

```bash
sudo cp deploy/systemd/bist-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bist-worker
sudo systemctl status bist-worker
```

Worker su isleri otomatik calistirir:

- Hafta ici 10:00-18:58, iki dakikada bir fiyat snapshot yenileme
- Fiyat yenilemeden bir dakika sonra alarm kontrolu
- Hafta ici 18:12 KAP haber taramasi
- Hafta ici 18:16 takip edilen sembollerin yeni KAP haberleri icin AI etki analizi
- Hafta ici 18:25 gun sonu AI raporu ve Telegram ozeti
- Hafta ici 18:40 genis BIST evreni teknik firsat taramasi
- Hafta ici 19:00 en guclu 5 firsat adayinin KAP + AI analizi ve Telegram ozeti

Log takibi:

```bash
journalctl -u bist-worker -f
```

## 6. Canli dogrulama

```bash
APP_ENV=prod APP_DEBUG=0 php bin/console app:production:check --strict
APP_ENV=prod APP_DEBUG=0 php bin/console doctrine:migrations:up-to-date
curl -fsS https://ALAN_ADI/health/live
curl -fsS https://ALAN_ADI/health/ready
php bin/console app:prices:refresh
php bin/console app:alerts:check --dry-run
php bin/console app:kap-crawl --days=2 --dry-run
php bin/console app:run-analysis --dry-run --limit=1 --delay=0
php bin/console app:daily-ai-report --dry-run --mock-ai
```

`/health/live` processin ayakta oldugunu, `/health/ready` ise DB/cache/lock baglantilarini kontrol eder. Reverse proxy bunlari health-check endpoint'i olarak kullanabilir.

## 7. Yedekleme ve geri donus

Her deploy oncesi:

```bash
APP_DIR=/var/www/bist_project bash deploy/backup.sh
```

Script gzip MySQL yedegi alir ve 14 gunden eski yerel yedekleri siler. Sunucu disinda ikinci bir sifreli kopya tutulmalidir.

Kod geri donusu icin once onceki release/surum checkout edilir, Composer production kurulumu ve cache warmup tekrar calistirilir. Migration geri alma otomatik yapilmaz; veritabani geri donusu oncesi ilgili migration ve yedek birlikte degerlendirilmelidir.

## 8. Guvenlik kontrol listesi

- SSH parola girisi kapali, key tabanli erisim acik
- Firewall'da yalnizca 22, 80 ve 443 acik
- MySQL, Redis ve phpMyAdmin public porta acik degil
- `.env` izinleri `640`, sahibi deploy/PHP kullanicisi
- Kayit ve web sifre sifirlama route'lari login arkasinda
- Admin sifresi terminalden `php bin/console app:admin:reset-password EMAIL` ile yonetiliyor
- Telegram/Gemini anahtarlari log veya Git icinde bulunmuyor
- Otomatik guvenlik guncellemeleri ve disk/log alarmi etkin
