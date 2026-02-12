# Signed Update Pipeline

Release güncellemelerinin supply-chain güvenliği için imzalanmış güncelleme kullanın.

## Kurulum

### 1. Anahtar Çifti Oluşturma

```bash
# Ed25519 anahtarı (önerilen)
openssl genpkey -algorithm Ed25519 -out update-private.pem

# Ortak anahtarı çıkar
openssl pkey -in update-private.pem -pubout -out update-public.pem
```

### 2. Config Ayarları

`config.php` içinde:

```php
define('UPDATE_REQUIRE_SIGNATURE', true);
define('UPDATE_SIGNING_PUBLIC_KEY_PEM', file_get_contents('/path/to/update-public.pem'));
define('UPDATE_SIGNATURE_ASSET_NAME', 'cybokron-update.zip.sig');
define('UPDATE_PACKAGE_ASSET_NAME', 'cybokron-update.zip');  // opsiyonel
```

### 3. Release Oluşturma

1. ZIP paketini oluşturun: `zip -r cybokron-update.zip . -x "*.git*" -x "config.php" -x "cybokron-logs/*"`
2. İmza oluşturun: `php scripts/generate_signature.php cybokron-update.zip`
3. GitHub Release'e ZIP ve `.sig` dosyalarını asset olarak ekleyin

### 4. İmza Doğrulama

`Updater.php` release indirildikten sonra `.sig` dosyasını indirir ve `openssl_pkey_verify` ile doğrular. İmza geçersizse güncelleme iptal edilir.

## Güvenlik Notları

- **Özel anahtarı** asla repoya veya asset'lere eklemeyin
- Sadece **public key** config'de saklanmalı
- Compromised GitHub hesabında bile sahte güncelleme dağıtılamaz (anahtar sizde)
