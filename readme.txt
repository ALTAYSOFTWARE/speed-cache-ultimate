=== WP Cache Ultimate ===
Contributors: altayyazilim
Donate link: https://bilgikasabasi.com
Tags: cache, page cache, performance, optimization, cdn
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gerçek sayfa önbellekleme, ön yükleme, veritabanı optimizasyonu ve CDN desteğiyle sitenizi hızlandıran modern önbellekleme eklentisi.

== Description ==

WP Cache Ultimate, WordPress siteniz için uçtan uca bir performans çözümüdür. Disk tabanlı gerçek sayfa önbellekleme (advanced-cache.php ile erken servis) sayesinde sayfalarınız PHP ve veritabanı katmanına hiç dokunmadan doğrudan sunulur.

**Öne çıkan özellikler:**

* **Gerçek sayfa önbellekleme** — disk cache ve `advanced-cache.php` drop-in ile erken servis
* **Önbellek ön yükleme (preload)** — ziyaretçi beklemeden sayfaları önceden önbelleğe alma
* **Veritabanı optimizasyonu** — gereksiz veriyi temizleme ve tabloları optimize etme
* **Hariç tutma kuralları** — URL, çerez veya sorgu parametresine göre önbellek dışı bırakma
* **CDN URL yeniden yazma** — statik dosyaları CDN üzerinden servis etme
* **Güvenlik başlıkları (Toolbox)** — temel HTTP güvenlik başlıklarını tek tıkla ekleme
* **Medya optimizasyonu** — WebP dönüşümü ve CLS (Cumulative Layout Shift) iyileştirmeleri
* **REST API** — durum, önbellek temizleme, ön yükleme, veritabanı optimizasyonu ve log uç noktaları
* **Üçüncü parti entegrasyonlar** — Cloudflare, Redis, WP Rocket uyumluluğu
* **WP-CLI desteği** — komut satırından önbellek yönetimi
* **Modern yönetim paneli** — canlı istatistikler, performans skoru, disk kullanımı ve sistem sağlığı denetimi

= Neden WP Cache Ultimate? =

Çoğu önbellekleme eklentisi ya çok karmaşık ayarlarla sizi boğar ya da yeterli kontrol sunmaz. WP Cache Ultimate, teknik kullanıcılar için ince ayar imkanı sunarken varsayılan ayarlarla da kutudan çıktığı gibi hızlı çalışır.

== Installation ==

1. Eklenti dosyasını `/wp-content/plugins/wp-cache-ultimate` klasörüne yükleyin ya da eklentiyi doğrudan WordPress yönetim panelinden yükleyin.
2. WordPress yönetim panelindeki "Eklentiler" menüsünden eklentiyi etkinleştirin.
3. Sol menüde beliren **WP Cache Ultimate** sayfasından ayarları yapılandırın.
4. Ayarlar sayfasından ihtiyacınıza göre bir ön ayar (preset) seçin veya ince ayarları manuel olarak yapın.

== Frequently Asked Questions ==

= Eklenti hangi PHP ve WordPress sürümlerini destekliyor? =

WordPress 5.8 ve üzeri, PHP 7.4 ve üzeri sürümlerde çalışır.

= advanced-cache.php dosyasını başka bir önbellekleme eklentisiyle birlikte kullanabilir miyim? =

Hayır. `advanced-cache.php` drop-in dosyası tek bir eklenti tarafından kullanılabilir. Başka bir sayfa önbellekleme eklentisi kullanıyorsanız önce onu devre dışı bırakın.

= Redis veya Cloudflare kullanmak zorunlu mu? =

Hayır, bu entegrasyonlar isteğe bağlıdır. Eklenti varsayılan olarak disk tabanlı önbellekleme ile çalışır.

= WP-CLI komutları nerede belgelendi? =

Terminalde `wp help wcu` komutunu çalıştırarak mevcut komutları ve kullanımlarını görebilirsiniz.

== Screenshots ==

1. Canlı istatistiklerle modern yönetim paneli
2. Performans skoru ve sistem sağlığı denetimi
3. Sekmeli ayarlar sayfası ve ön ayarlar (presets)

== Changelog ==

= 2.2.0 =
* Yönetim paneli arayüzü modernize edildi
* Küçük hata düzeltmeleri ve performans iyileştirmeleri

== Upgrade Notice ==

= 2.2.0 =
Yönetim paneli görsel güncellemesi içerir. Mevcut ayarlarınız korunur, herhangi bir manuel işlem gerekmez.
