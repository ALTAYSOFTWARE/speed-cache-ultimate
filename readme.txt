=== WP Cache Ultimate ===
Contributors: altayyazilim
Donate link: https://siteniz.com
Tags: cache, page cache, performance, optimization, cdn, advanced-cache
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gerçek sayfa önbelleği, ön yükleme, veritabanı optimizasyonu ve CDN desteği ile sitenizi hızlandırın.

== Description ==

WP Cache Ultimate, WordPress sitenizin arama motorlarındaki görünürlüğünü artırmak için tasarlanmış, içerik odaklı ve teknik optimizasyon araçlarını tek bir kontrol panelinde toplayan kapsamlı bir performans eklentisidir.

**Temel Özellikler:**

* **Gerçek Sayfa Önbelleği** — Disk tabanlı önbellek ile advanced-cache.php drop-in desteği
* **Ön Yükleme (Preload)** — Ziyaretçiler gelmeden sayfaları önceden önbelleğe alır
* **Veritabanı Optimizasyonu** — Gereksiz verileri temizler ve tabloları optimize eder
* **Hariç Tutma Kuralları** — URL, çerez veya sorgu parametresine göre önbellek dışı bırakma
* **CDN URL Yeniden Yazma** — Statik dosyaları CDN üzerinden sunar
* **Güvenlik Başlıkları (Toolbox)** — Tek tıkla temel HTTP güvenlik başlıkları ekler
* **Medya Optimizasyonu** — WebP dönüşümü ve CLS iyileştirmeleri
* **REST API** — Durum, önbellek temizleme, ön yükleme ve log uç noktaları
* **Üçüncü Taraf Entegrasyonları** — Cloudflare, Redis, WP Rocket uyumluluğu
* **WP-CLI Desteği** — Komut satırından önbellek yönetimi
* **Modern Admin Paneli** — Canlı istatistikler, performans skoru, disk kullanımı ve sistem sağlık kontrolleri

= Neden WP Cache Ultimate? =

Çoğu önbellek eklentisi ya karmaşık ayarlarla sizi bunaltır ya da yeterli kontrol sunmaz. WP Cache Ultimate, varsayılan ayarlarla kutudan çıkar çıkmaz çalışırken teknik kullanıcılar için ince ayar seçenekleri sunar.

== Installation ==

1. Eklenti dosyalarını `/wp-content/plugins/wp-cache-ultimate` klasörüne yükleyin veya WordPress admin panelinden doğrudan kurun.
2. WordPress admin panelindeki Eklentiler menüsünden eklentiyi etkinleştirin.
3. Sol menüdeki **WP Cache Ultimate** sayfasından ayarları yapılandırın.
4. Ayarlar sayfasından sitenize uygun hazır profili seçin veya manuel olarak yapılandırın.

== Frequently Asked Questions ==

= Hangi PHP ve WordPress sürümlerini destekliyor? =

WordPress 5.8 ve üzeri, PHP 7.4 ve üzeri ile çalışır.

= advanced-cache.php başka bir önbellek eklentisiyle birlikte kullanılabilir mi? =

Hayır. `advanced-cache.php` drop-in dosyası aynı anda yalnızca bir eklenti tarafından kullanılabilir. Önce diğer sayfa önbelleği eklentilerini devre dışı bırakın.

= Redis veya Cloudflare zorunlu mu? =

Hayır, bu entegrasyonlar isteğe bağlıdır. Eklenti varsayılan olarak disk tabanlı önbellek kullanır.

= WP-CLI komutları nerede dokümante edilmiş? =

Terminalinizde `wp help wcu` komutunu çalıştırarak mevcut komutları ve kullanımını görüntüleyebilirsiniz.

== Screenshots ==

1. Modern admin paneli ve canlı istatistikler
2. Performans skoru ve sistem sağlık kontrolleri
3. Sekmeli ayarlar sayfası ve hazır profiller

== Changelog ==

= 2.2.2 =
* Düzeltme: advanced-cache.php artık eklenti etkinleştirildiğinde otomatik kuruluyor (önceden panelde tetikleyecek bir buton olmadığı için hiçbir zaman kurulmuyordu)
* Başka bir önbellek eklentisine ait mevcut advanced-cache.php dosyası artık sessizce üzerine yazılmıyor; çakışma tespit edilirse admin bildirimi gösteriliyor

= 2.2.1 =
* WordPress Plugin Check uyumluluğu iyileştirildi
* WP_Filesystem kullanımı standartlara uygun hale getirildi
* Kod temizliği ve performans optimizasyonları

= 2.2.0 =
* Admin paneli arayüzü modernize edildi
* Küçük hata düzeltmeleri ve performans iyileştirmeleri
* WordPress kodlama standartlarına uygunluk geliştirildi

== Upgrade Notice ==

= 2.2.2 =
advanced-cache.php artık aktivasyonda otomatik kuruluyor. Güncelledikten sonra eklentiyi deaktive edip tekrar aktive etmeniz (veya bir sonraki aktivasyonu beklemeniz) kurulumun tetiklenmesi için yeterlidir.

= 2.2.1 =
WordPress.org Plugin Check uyumluluğu güncellemeleri içerir. Mevcut ayarlarınız korunur, manuel işlem gerekmez.
