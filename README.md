# Laravel Payment Gateways

Paynet, iyzico ve PayTR entegrasyonlarını ortak bir arayüzle kullanan Laravel uygulaması. Ödeme sağlayıcısı, controller kodu değiştirilmeden konfigürasyon üzerinden seçilir.

## Mimari

- **Strategy Pattern:** Gateway sınıfları `PaymentInterface`'i uygular. Laravel container, konfigürasyonda seçilen gateway'i kullanır.
- **Adapter Pattern:** Her gateway, sağlayıcının API'sini ortak ödeme sözleşmesine uyarlar; istek ve cevapları sağlayıcıya göre dönüştürür.
- **DTO:** Ödeme verileri, callback sonuçları ve tarayıcı aksiyonları tipli DTO'larla taşınır.

## SOLID

- **Single Responsibility:** İstek doğrulama `ProcessPaymentRequest`'te, HTTP akışı `PaymentController`'da, sağlayıcı entegrasyonu gateway sınıflarında tutulur.
- **Open/Closed:** Yeni sağlayıcı, `PaymentInterface`'i uygulayan bir gateway ve konfigürasyon kaydıyla eklenir. Controller kodunu değiştirmek gerekmez.
- **Dependency Inversion:** Controller somut gateway sınıfları yerine `PaymentInterface`'e bağımlıdır. Seçilen gateway constructor üzerinden enjekte edilir.

## Sağlayıcı seçimi

`.env` dosyasındaki `PAYMENT_GATEWAY` değeri `paynet`, `iyzico` veya `paytr` olabilir:

```dotenv
PAYMENT_GATEWAY=paynet
```

Gateway tanımları `config/payment.php` içinde bulunur. Ödeme `POST /api/payment`, callback `POST /api/payment/callback` üzerinden işlenir.

## Kurulum

Laravel 13, PHP 8.4+, Composer ve Node.js/npm kullanılır.

```bash
composer run setup
```

`.env` dosyasında sağlayıcının API bilgilerini ve callback adreslerini ayarladıktan sonra:

```bash
composer run dev
```

Testler: `php artisan test --compact --no-interaction`.
