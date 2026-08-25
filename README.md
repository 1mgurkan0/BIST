# BIST AI Terminal 🚀

**BIST AI Terminal**, Borsa İstanbul (BIST) hisselerini tamamen otonom bir şekilde 7/24 izleyen, teknik göstergeleri hesaplayan, KAP (Kamuyu Aydınlatma Platformu) haberlerini anlık analiz eden ve **Büyük Dil Modelleri (LLM)** ile karar destek sinyalleri üreten ileri seviye bir **Yatırım Analiz Motoru**dur.

Sistem, yatırımcıların duygusal karar vermesini engelleyip, tamamen "Dipten Dönüş (Value / Reversal)" stratejisine dayalı, fiyatı şişmemiş ve gerçek destekten zıplama sinyali veren hisseleri bularak "takip_et / bekle / riskli" analizlerini Telegram üzerinden doğrudan cebinize ulaştırır.

---

## 🌟 Neden BIST AI Terminal?

- **100% Otonom Çalışma:** Sistemi bir kere kurduktan sonra arkasına yaslanın. Symfony Scheduler ve Supervisor sayesinde kendi kendine çalışır.
- **Acımasız "Dipten Dönüş" Algoritması:** Hali hazırda uçmuş, fiyatı şişmiş (SMA20'den %10+ uzaklaşmış) hisseleri elinin tersiyle iter. Ana desteklerine (SMA50 / SMA200) geri çekilmiş, buralardan sekme sinyali veren (RSI kafayı kaldırmış) ve işlem hacmi artan hisseleri bulur.
- **XU100 Piyasa Filtresi:** Borsa İstanbul genel trendi düşüşteyse (XU100 SMA50 altındaysa), sistem defansif moda geçer ve tüm hisselerde risk notunu artırarak yatırımcıyı korur.
- **Anlık KAP Sensörü:** BIST'e düşen her haberi çeker, okur, olumlu/olumsuz duygu skorunu (Sentiment) hesaplar. Eğer puan belirlediğiniz eşiğin üstündeyse saniyeler içinde **Telegram Sinyali** gönderir!
- **Esnek AI Altyapısı:** Tek bir konfigürasyon değişkeni (`ACTIVE_AI_PROVIDER`) ile farklı Yapay Zeka sağlayıcıları arasında anında geçiş yapabilirsiniz.

## 🛠 Mimari & Teknolojiler

- **Backend:** PHP 8.2 / Symfony 7.4
- **Veritabanı:** MySQL 8.0 (Doctrine ORM)
- **Önbellek & Kuyruk:** Redis 7 (Symfony Messenger / Cache)
- **AI Sağlayıcıları:** Gelişmiş Büyük Dil Modelleri (API Entegrasyonu)
- **Otomasyon:** Symfony Scheduler & Supervisor (Linux)
- **Frontend:** Twig & Tailwind CSS (Dark Mode Admin UI)

---

## ⚙️ Nasıl Çalışır? (Otomasyon Süreci)

Günde **4 kez** (Piyasa saatleri içinde ve kapanışta) devasa BIST evreni taranır ve en iyi "Dipten Dönüş" fırsatları yapay zekaya sunulur:

1. **`10:30, 14:30, 16:30 ve 18:15 (Kapanış)`:** Tüm BIST 50 hisseleri taranır. Yeni algoritmamızla SMA ve RSI destekleri kontrol edilip Puanlama yapılır.
2. **Scan'den 5 Dakika Sonra (Yapay Zeka Devrede):** En yüksek puanı almış (şişmemiş, desteğe inmiş) en iyi 10 hisse, güncel KAP haberleriyle birlikte AI'a gönderilir. 
3. **Telegram Fırsat Radarı:** AI'ın onayından da geçen hisseler Telegram'a "Fırsatlar", geçemeyenler "Nötr/Riskli" olarak düşer.
4. **`18:16 (KAP Kapanış Tarayıcısı)`:** Günün tüm KAP haberleri çekilerek (12.000 karaktere kadar) veritabanına işlenir ve AI'a okutulup duygu analizi yaptırılır.

## 🚀 Kurulum (Development)

Projeyi kendi ortamınızda ayağa kaldırmak oldukça basittir:

```bash
# 1. Repoyu Klonlayın
git clone https://github.com/1mgurkan0/BIST.git
cd BIST

# 2. Bağımlılıkları Yükleyin
composer install

# 3. Docker Konteynerlerini Başlatın (MySQL, Redis, Mailpit vb.)
docker compose up -d

# 4. Çevresel Değişkenleri (.env) Ayarlayın
cp .env.example .env
# İçerisine gerekli API Key ayarlarınızı (AI ve Telegram) girin.

# 5. Veritabanını Oluşturun
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 6. Uygulamayı ve Otomasyonu Çalıştırın
symfony serve -d
php bin/console messenger:consume async scheduler_default -vv
```

---

## 📊 Örnek Yapay Zeka Çıktısı (Telegram Fırsat Radarı)

Sistemin her gün sana sunacağı özet formatı:

> 🔥 **AI Onaylı Fırsatlar (Potansiyel Alım)**
> **1. THYAO - 65/100 (Güven: orta)**
> *Ana desteğe (SMA200) yakın ve destek eğimi pozitif, tepki potansiyeli yüksek. Son açıklanan yolcu kapasitesi KAP bildirimi şirketin uzun vadeli görünümünü destekliyor.*

> ⚠️ **Teknik İyi Ama AI'dan Geçemeyenler / Nötrler**
> **- ASELS (45/100):** *Trend güçlü görünse de fiyat kısa vadeli ortalamadan (SMA20) %10'dan fazla uzaklaşmış (Aşırı Şişmiş). Kısa vadede düzeltme (düşüş) riski yüksek, Riskli/Bekle.*
