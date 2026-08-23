# BIST AI Terminal 🚀

**BIST AI Terminal**, Borsa İstanbul (BIST) hisselerini tamamen otonom bir şekilde 7/24 izleyen, teknik göstergeleri hesaplayan, KAP (Kamuyu Aydınlatma Platformu) haberlerini anlık analiz eden ve **NVIDIA Nemotron 550b** gibi devasa Yapay Zeka modelleriyle karar destek sinyalleri üreten ileri seviye bir **Yatırım Analiz Motoru**dur.

Sistem, yatırımcıların duygusal karar vermesini engelleyip, veriye ve gelişmiş yapay zeka çıkarımlarına dayalı "takip_et / bekle / riskli" kararlarını Telegram üzerinden doğrudan cebinize ulaştırır.

---

## 🌟 Neden BIST AI Terminal?

- **100% Otonom Çalışma:** Sistemi bir kere kurduktan sonra arkasına yaslanın. Symfony Scheduler ve Supervisor sayesinde kendi kendine çalışır.
- **Nvidia Nemotron 550b Gücü:** Açık kaynaklı devasa dil modellerinden biriyle doğrudan OpenRouter üzerinden finansal haber analizi yapar. 
- **Devasa Veri İşleme:** Fiyat hareketleri, MACD, RSI, SMA(20-50-200), Destek/Direnç noktaları gibi derin teknik analizleri milisaniyeler içinde hesaplayıp yapay zekaya sunar.
- **Anlık KAP Sensörü:** BIST'e düşen her haberi çeker, okur, olumlu/olumsuz duygu skorunu (Sentiment) hesaplar. Eğer puan belirlediğiniz eşiğin üstündeyse saniyeler içinde **Telegram Sinyali** gönderir!
- **Gemini & Nvidia Arasında Geçiş:** Tek bir konfigürasyon değişkeni (`ACTIVE_AI_PROVIDER`) ile Google Gemini 2.5 veya NVIDIA NIM altyapıları arasında geçiş yapabilirsiniz.

## 🛠 Mimari & Teknolojiler

- **Backend:** PHP 8.2 / Symfony 7.4
- **Veritabanı:** MySQL 8.0 (Doctrine ORM)
- **Önbellek & Kuyruk:** Redis 7 (Symfony Messenger / Cache)
- **AI Sağlayıcıları:** OpenRouter (NVIDIA Modelleri) & Google Gemini API
- **Otomasyon:** Symfony Scheduler & Supervisor (Linux)
- **Frontend:** Twig & Tailwind CSS (Dark Mode Admin UI)

---

## ⚙️ Nasıl Çalışır? (Otomasyon Süreci)

1. **`10:00 - 18:00 (Her 2 dakikada bir)`:** Yahoo Finance üzerinden aktif fiyatlar çekilir ve senin belirlediğin Alarm (Alert) sınırları test edilir.
2. **`18:12 (Piyasa Kapanışı)`:** Günün tüm KAP haberleri çekilerek (12.000 karaktere kadar) veritabanına işlenir.
3. **`18:16`:** Yapay Zeka devreye girer. Günün KAP haberlerini tek tek okuyup, her birine puan (-100 / +100) ve gerekçe atar.
4. **`18:25`:** **Ana Karar Motoru** devreye girer. Portföyündeki ve takip listendeki hisselerin "Teknik Analizi + KAP Haberleri + Fiyat Hareketleri" birleştirilir ve devasa bir Prompt ile AI'a sorulur. Sonuçlar **Telegram** üzerinden sana raporlanır.
5. **`18:40 & 19:00`:** BIST 50 evreni taranır, RSI/MACD kriterlerine uyan fırsat hisseleri yapay zekanın önüne atılır ve sana "Yarın izlemen gerekenler" listesi çıkarılır.

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
# İçerisine NVIDIA_API_KEY, TELEGRAM_BOT_TOKEN gibi ayarlarınızı girin.

# 5. Veritabanını Oluşturun
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 6. Uygulamayı ve Otomasyonu Çalıştırın
symfony serve -d
php bin/console messenger:consume async scheduler_default -vv
```

---

## 📊 Örnek Yapay Zeka Çıktısı (Telegram Raporu)

Sistemin her gün sonu sana sunacağı özet formatı:

> 🟢 **EN GÜÇLÜ ADAYLAR**
> **THYAO (Skor: 85)** | Trend: POZITIF
> Fiyat: 320.50 (20 Günlük SMA üstünde)
> *AI Yorumu:* MACD yukarı kesiyor ve RSI toparlanmış. Son açıklanan yolcu kapasitesi KAP bildirimi şirketin karlılığını destekliyor.

> 🔴 **RİSKLİ / İZLENECEKLER**
> **SASA (Skor: 45)** | Trend: NEGATIF
> *AI Yorumu:* Negatif KAP haberi ve Bollinger alt bandına gerileme nedeniyle kısa vadede riskli.

---
*Geleceğin yatırım asistanı, bugünden seninle beraber.* 
