<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Settings {
    const OPTION_NAME = 'wcu_settings';

    public static function defaults() {
        return array(
            'transient_batch'     => 500,
            'page_cache_enabled'  => true,
            'cache_ttl'           => 3600,
            'cache_query_strings' => false,
            'cache_404'           => false,
            'gzip_static'         => true,
            'minify_html'         => false,
            'exclude_uris'        => "/wp-admin*\n/wp-login.php*\n/cart*\n/checkout*\n/my-account*",
            'exclude_cookies'     => 'woocommerce_items_in_cart',
            'db_auto_optimize'    => false,
            'cloudflare_token'    => '',
            'cloudflare_zone'     => '',
            'redis_enabled'       => false,
            'redis_host'          => '127.0.0.1',
            'redis_port'          => 6379,
            'redis_password'      => '',

            // Optimizasyon (WCU 2.1)
            'remove_emojis'        => false,
            'remove_query_strings' => false,
            'heartbeat_control'    => 'default', // default | admin_only | disabled
            'heartbeat_interval'   => 60,
            'lazy_load_images'     => false,
            'lazy_load_iframes'    => false,
            'lazy_load_skip_first' => 2,
            'defer_js'             => false,
            'exclude_js'           => 'jquery.min.js',
            'async_css'            => false,
            'exclude_css'          => '',
            'instant_click'        => false,

            // CDN URL Rewriting (WCU 2.2 — Tulpar Cache'ten esinlenildi)
            'cdn_enabled'          => false,
            'cdn_url'              => '',
            'cdn_include_images'   => true,
            'cdn_include_css'      => true,
            'cdn_include_js'       => true,

            // Toolbox (WCU 2.2)
            'security_headers_enabled' => false,

            // Medya Optimizasyonu (WCU 2.2)
            'media_webp'                  => false,
            'media_missing_dims'          => false,
            'media_responsive_placeholder' => false,
        );
    }

    /**
     * Hazır ayar profilleri. Mevcut ayarların üzerine yalnızca ilgili alanları
     * uygular; diğer ayarlar (Cloudflare/Redis token'ları vb.) korunur.
     */
    public static function presets() {
        return array(
            'blog' => array(
                'cache_ttl'          => 86400,
                'remove_emojis'      => true,
                'remove_query_strings' => true,
                'heartbeat_control'  => 'admin_only',
                'lazy_load_images'   => true,
                'lazy_load_iframes'  => true,
                'defer_js'           => true,
                'async_css'          => false,
                'instant_click'      => true,
                'minify_html'        => true,
            ),
            'ecommerce' => array(
                'cache_ttl'          => 3600,
                'remove_emojis'      => true,
                'remove_query_strings' => false,
                'heartbeat_control'  => 'admin_only',
                'lazy_load_images'   => true,
                'lazy_load_iframes'  => true,
                'defer_js'           => false,
                'async_css'          => false,
                'instant_click'      => false,
                'minify_html'        => false,
            ),
            'news' => array(
                'cache_ttl'          => 600,
                'remove_emojis'      => true,
                'remove_query_strings' => true,
                'heartbeat_control'  => 'disabled',
                'lazy_load_images'   => true,
                'lazy_load_iframes'  => true,
                'defer_js'           => true,
                'async_css'          => true,
                'instant_click'      => true,
                'minify_html'        => true,
            ),
        );
    }

    public static function apply_preset( $name ) {
        $presets = self::presets();
        if ( ! isset( $presets[ $name ] ) ) return false;
        $current = self::get();
        $merged  = array_merge( $current, $presets[ $name ] );
        update_option( self::OPTION_NAME, self::sanitize( $merged ) );
        return true;
    }

    public static function get() {
        $stored = get_option( self::OPTION_NAME, array() );
        return wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
    }

    public static function register_settings() {
        register_setting( 'wcu_settings_group', self::OPTION_NAME, array( __CLASS__, 'sanitize' ) );
        add_settings_section( 'wcu_general', 'Genel Ayarlar', null, 'wcu-settings' );
    }

    public static function sanitize( $in ) {
        $out = array();
        $out['transient_batch']     = max( 50, (int) ( $in['transient_batch'] ?? 500 ) );
        $out['page_cache_enabled']  = ! empty( $in['page_cache_enabled'] );
        $out['cache_ttl']           = max( 60, (int) ( $in['cache_ttl'] ?? 3600 ) );
        $out['cache_query_strings'] = ! empty( $in['cache_query_strings'] );
        $out['cache_404']           = ! empty( $in['cache_404'] );
        $out['gzip_static']         = ! empty( $in['gzip_static'] );
        $out['minify_html']         = ! empty( $in['minify_html'] );
        $out['exclude_uris']        = sanitize_textarea_field( $in['exclude_uris'] ?? '' );
        $out['exclude_cookies']     = sanitize_textarea_field( $in['exclude_cookies'] ?? '' );
        $out['db_auto_optimize']    = ! empty( $in['db_auto_optimize'] );
        $out['cloudflare_token']    = sanitize_text_field( $in['cloudflare_token'] ?? '' );
        $out['cloudflare_zone']     = sanitize_text_field( $in['cloudflare_zone'] ?? '' );
        $out['redis_enabled']       = ! empty( $in['redis_enabled'] );
        $out['redis_host']          = sanitize_text_field( $in['redis_host'] ?? '127.0.0.1' );
        $out['redis_port']          = (int) ( $in['redis_port'] ?? 6379 );
        $out['redis_password']     = sanitize_text_field( $in['redis_password'] ?? '' );

        $out['remove_emojis']        = ! empty( $in['remove_emojis'] );
        $out['remove_query_strings'] = ! empty( $in['remove_query_strings'] );
        $hb = $in['heartbeat_control'] ?? 'default';
        $out['heartbeat_control']    = in_array( $hb, array( 'default', 'admin_only', 'disabled' ), true ) ? $hb : 'default';
        $out['heartbeat_interval']   = max( 15, (int) ( $in['heartbeat_interval'] ?? 60 ) );
        $out['lazy_load_images']     = ! empty( $in['lazy_load_images'] );
        $out['lazy_load_iframes']    = ! empty( $in['lazy_load_iframes'] );
        $out['lazy_load_skip_first'] = max( 0, (int) ( $in['lazy_load_skip_first'] ?? 2 ) );
        $out['defer_js']             = ! empty( $in['defer_js'] );
        $out['exclude_js']           = sanitize_textarea_field( $in['exclude_js'] ?? 'jquery.min.js' );
        $out['async_css']            = ! empty( $in['async_css'] );
        $out['exclude_css']          = sanitize_textarea_field( $in['exclude_css'] ?? '' );
        $out['instant_click']        = ! empty( $in['instant_click'] );

        $out['cdn_enabled']          = ! empty( $in['cdn_enabled'] );
        $out['cdn_url']              = esc_url_raw( untrailingslashit( trim( $in['cdn_url'] ?? '' ) ) );
        $out['cdn_include_images']   = ! empty( $in['cdn_include_images'] );
        $out['cdn_include_css']      = ! empty( $in['cdn_include_css'] );
        $out['cdn_include_js']       = ! empty( $in['cdn_include_js'] );

        $out['security_headers_enabled'] = ! empty( $in['security_headers_enabled'] );

        $out['media_webp']                   = ! empty( $in['media_webp'] );
        $out['media_missing_dims']           = ! empty( $in['media_missing_dims'] );
        $out['media_responsive_placeholder'] = ! empty( $in['media_responsive_placeholder'] );

        // Full flush after settings change so no stale variants (minify/gzip toggles) linger.
        PageCache::purge_all();

        return $out;
    }

    protected static function toggle_row( $name, $label, $checked, $desc = '' ) {
        ?>
        <div class="wcu-setting-row">
            <div class="wcu-setting-info">
                <span class="wcu-setting-label"><?php echo esc_html( $label ); ?></span>
                <?php if ( $desc ) : ?><p><?php echo esc_html( $desc ); ?></p><?php endif; ?>
            </div>
            <div class="wcu-setting-control">
                <label class="wcu-switch">
                    <input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked, true ); ?> />
                    <span class="wcu-slider"></span>
                </label>
            </div>
        </div>
        <?php
    }

    protected static function select_row( $name, $label, $value, $options, $desc = '' ) {
        ?>
        <div class="wcu-setting-row">
            <div class="wcu-setting-info">
                <span class="wcu-setting-label"><?php echo esc_html( $label ); ?></span>
                <?php if ( $desc ) : ?><p><?php echo esc_html( $desc ); ?></p><?php endif; ?>
            </div>
            <div class="wcu-setting-control">
                <select name="<?php echo esc_attr( $name ); ?>">
                    <?php foreach ( $options as $opt_val => $opt_label ) : ?>
                        <option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php
    }

    protected static function text_row( $name, $label, $value, $type = 'text', $desc = '', $attrs = '' ) {
        ?>
        <div class="wcu-setting-row">
            <div class="wcu-setting-info">
                <span class="wcu-setting-label"><?php echo esc_html( $label ); ?></span>
                <?php if ( $desc ) : ?><p><?php echo esc_html( $desc ); ?></p><?php endif; ?>
            </div>
            <div class="wcu-setting-control">
                <input type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" <?php echo $attrs; ?> />
            </div>
        </div>
        <?php
    }

    /**
     * Bağımsız "Ayarlar" admin sayfası (admin.php?page=wcu-settings).
     * Not: Panodaki (Dashboard) sol menüden "Ayarlar" tıklandığında artık
     * ayrı bir sayfaya gidilmiyor; bunun yerine render_content() aynı
     * pano içinde bir panel olarak render ediliyor. Bu metod, doğrudan
     * bağlantı/yer imi ile erişim gibi durumlar için hâlâ mevcut.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz' );
        ?>
        <div class="wrap wcu-wrap" id="wcu-app">
            <div class="wcu-settings-header">
                <div>
                    <h1>WP Cache Ultimate — Ayarlar</h1>
                    <p class="wcu-sub">Önbellek davranışını, hariç tutma kurallarını ve entegrasyonları buradan yapılandırın.</p>
                </div>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wcu-dashboard' ) ); ?>" class="wcu-btn wcu-btn-outline">← Dashboard'a dön</a>
            </div>
            <?php self::render_content(); ?>
        </div>
        <?php
    }

    /**
     * Ayarlar içeriği. Hem bağımsız sayfada hem de Dashboard panelinde
     * (gömülü) kullanılabilir. Sekmeler arası geçiş tamamen JS ile
     * (sayfa yenilenmeden) yapılır; data-tab özniteliği JS tarafından okunur.
     */
    public static function render_content() {
        $s = self::get();
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'cache';
        $tabs = array(
            'cache'    => 'Sayfa Önbelleği',
            'optimize' => 'Optimizasyon',
            'exclude'  => 'Hariç Tutma Kuralları',
            'preload'  => 'Ön Yükleme',
            'db'       => 'Veritabanı',
            'cdn'      => 'CDN & Entegrasyonlar',
            'toolbox'  => 'Toolbox',
            'server'   => 'Sunucu Yapılandırması',
        );
        ?>
        <div class="wcu-settings-body">
            <nav class="wcu-settings-tabs">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="#" data-tab="<?php echo esc_attr( $slug ); ?>"
                       class="<?php echo $active_tab === $slug ? 'active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <form method="post" action="options.php">
                <?php settings_fields( 'wcu_settings_group' ); do_settings_sections( 'wcu-settings' ); ?>

                <div style="<?php echo $active_tab === 'cache' ? '' : 'display:none'; ?>" data-tab="cache">
                    <div class="wcu-setting-card">
                        <h2>Genel Ayarlar</h2>
                        <p class="wcu-card-desc">Sayfa önbelleğinin nasıl davranacağını belirleyin.</p>
                        <?php
                        self::toggle_row( 'wcu_settings[page_cache_enabled]', 'Sayfa Önbelleği', $s['page_cache_enabled'] );
                        self::text_row( 'wcu_settings[cache_ttl]', 'Önbellek Süresi (saniye)', $s['cache_ttl'], 'number', '', 'min="60"' );
                        self::toggle_row( 'wcu_settings[cache_query_strings]', "Sorgu Dizeli URL'leri Önbellekle", $s['cache_query_strings'] );
                        self::toggle_row( 'wcu_settings[cache_404]', '404 Sayfalarını Önbellekle', $s['cache_404'] );
                        self::toggle_row( 'wcu_settings[gzip_static]', 'Gzip Statik Dosya', $s['gzip_static'], '.html.gz varyantı oluşturur' );
                        self::toggle_row( 'wcu_settings[minify_html]', 'HTML Minify', $s['minify_html'], 'Yorumları ve fazladan boşlukları temizler' );
                        self::text_row( 'wcu_settings[transient_batch]', 'Transient Batch', $s['transient_batch'], 'number', 'Temizlik işleminde tek seferde işlenecek kayıt sayısı', 'min="50"' );
                        ?>
                    </div>
                </div>

                <div style="<?php echo $active_tab === 'optimize' ? '' : 'display:none'; ?>" data-tab="optimize">
                    <div class="wcu-setting-card">
                        <h2>Hazır Profiller</h2>
                        <p class="wcu-card-desc">Site türünüze göre önerilen ayarları tek tıkla uygulayın. Mevcut Cloudflare/Redis/CDN bilgileriniz korunur.</p>
                        <div class="wcu-preset-grid">
                            <div class="wcu-preset-card" style="border-left-color:#3b82f6">
                                <div class="wcu-preset-card-head">📝 <strong>Blog</strong></div>
                                <p>Uzun önbellek süresi, agresif erteleme ve tembel yükleme; içerik odaklı siteler için.</p>
                                <button type="button" class="button button-primary wcu-preset-btn" data-preset="blog">Uygula</button>
                            </div>
                            <div class="wcu-preset-card" style="border-left-color:#10b981">
                                <div class="wcu-preset-card-head">🛒 <strong>E-ticaret</strong></div>
                                <p>Sepet/ödeme akışını bozmayacak dengeli ayarlar; JS erteleme kapalı, önbellek süresi kısa.</p>
                                <button type="button" class="button button-primary wcu-preset-btn" data-preset="ecommerce">Uygula</button>
                            </div>
                            <div class="wcu-preset-card" style="border-left-color:#f97316">
                                <div class="wcu-preset-card-head">📰 <strong>Haber Sitesi</strong></div>
                                <p>Sık güncellenen içerik için kısa önbellek süresi; Heartbeat tamamen kapalı, maksimum hız.</p>
                                <button type="button" class="button button-primary wcu-preset-btn" data-preset="news">Uygula</button>
                            </div>
                        </div>
                        <div id="wcu-preset-result" style="margin-top:10px;font-size:12.5px;color:#475569"></div>
                    </div>

                    <div class="wcu-setting-card">
                        <h2>Kolay Kazanımlar</h2>
                        <p class="wcu-card-desc">Düşük efor, anında istek sayısı ve sunucu yükü azaltımı.</p>
                        <?php
                        self::toggle_row( 'wcu_settings[remove_emojis]', 'Emoji Script/Style Kaldır', $s['remove_emojis'], "WordPress'in varsayılan emoji JS/CSS'ini her sayfadan kaldırır." );
                        self::toggle_row( 'wcu_settings[remove_query_strings]', "Statik Dosyalardan Sürüm Sorgusunu Kaldır", $s['remove_query_strings'], "CSS/JS dosyalarındaki ?ver=... parametresini kaldırır, bazı proxy/CDN önbellekleri bu dosyaları daha iyi önbellekler." );
                        ?>
                        <p class="description" style="margin-top:10px">Heartbeat API kontrolü artık <strong>Toolbox</strong> sekmesinde.</p>
                    </div>

                    <div class="wcu-setting-card">
                        <h2>Görsel & İçerik Optimizasyonu</h2>
                        <p class="wcu-card-desc">İlk yükleme süresini kısaltır, ekran dışı içerikleri erteler.</p>
                        <?php
                        self::toggle_row( 'wcu_settings[lazy_load_images]', 'Tembel Yükleme (Görseller)', $s['lazy_load_images'], 'data-src + IntersectionObserver tekniğiyle görselleri ekrana gelene kadar yüklemez (tüm tarayıcılarda çalışır).' );
                        self::text_row( 'wcu_settings[lazy_load_skip_first]', 'İlk Kaç Görsel Hariç Tutulsun (LCP)', $s['lazy_load_skip_first'], 'number', 'Above-the-fold görseller tembel yüklenmez, ilki fetchpriority=high alır.', 'min="0" max="10"' );
                        self::toggle_row( 'wcu_settings[lazy_load_iframes]', 'Tembel Yükleme (Iframe)', $s['lazy_load_iframes'], 'YouTube/Vimeo gibi gömülü iframe içeriklerini data-src ile geciktirerek yükler.' );
                        ?>
                        <hr class="wcu-card-sep" />
                        <?php
                        self::toggle_row( 'wcu_settings[media_webp]', 'WebP Değişimi', $s['media_webp'], "Tarayıcı destekliyorsa ve diskte .webp versiyonu varsa görseli otomatik WebP olarak sunar." );
                        self::toggle_row( 'wcu_settings[media_missing_dims]', 'Eksik Görsel Boyutlarını Tamamla', $s['media_missing_dims'], "width/height niteliği olmayan görsellere otomatik ekleyerek CLS (Cumulative Layout Shift) sorununu azaltır." );
                        self::toggle_row( 'wcu_settings[media_responsive_placeholder]', 'Responsive Yer Tutucu (Placeholder)', $s['media_responsive_placeholder'], "Görsel yüklenene kadar en-boy oranını koruyan bir yer tutucu alan bırakır; sayfa zıplamasını önler." );
                        ?>
                    </div>

                    <div class="wcu-setting-card">
                        <h2>JS / CSS Optimizasyonu</h2>
                        <p class="wcu-card-desc">Render-blocking kaynakları azaltarak Core Web Vitals'ı iyileştirir.</p>
                        <?php
                        self::toggle_row( 'wcu_settings[defer_js]', 'JS Erteleme (defer)', $s['defer_js'], 'src ile yüklenen scriptlere defer ekler; jQuery ve JSON-LD otomatik hariç tutulur.' );
                        ?>
                        <div class="wcu-setting-full">
                            <span class="wcu-setting-label">JS Hariç Tutma Listesi</span>
                            <textarea name="wcu_settings[exclude_js]" rows="3" placeholder="jquery.min.js&#10;woocommerce"><?php echo esc_textarea( $s['exclude_js'] ); ?></textarea>
                            <p class="description">Bu metni içeren script src'leri defer edilmez. Her satıra bir kalıp.</p>
                        </div>
                        <hr class="wcu-card-sep" />
                        <?php self::toggle_row( 'wcu_settings[async_css]', 'CSS Async Yükleme', $s['async_css'], 'Kritik olmayan stil dosyalarını preload+onload tekniğiyle ertelemek.' ); ?>
                        <div class="wcu-setting-full">
                            <span class="wcu-setting-label">CSS Hariç Tutma Listesi</span>
                            <textarea name="wcu_settings[exclude_css]" rows="3" placeholder="admin-bar&#10;theme-critical"><?php echo esc_textarea( $s['exclude_css'] ); ?></textarea>
                            <p class="description">Bu metni içeren stylesheet link'leri async edilmez.</p>
                        </div>
                    </div>

                    <div class="wcu-setting-card">
                        <h2>Gelişmiş</h2>
                        <?php self::toggle_row( 'wcu_settings[instant_click]', 'Instant Click (Hover Prefetch)', $s['instant_click'], 'Bağlantı üzerine gelindiğinde arka planda sayfayı önceden çeker; tıklandığında anında açılma hissi verir.' ); ?>
                    </div>
                </div>

                <div style="<?php echo $active_tab === 'exclude' ? '' : 'display:none'; ?>" data-tab="exclude">
                    <div class="wcu-setting-card">
                        <h2>Hariç Tutma Kuralları</h2>
                        <p class="wcu-card-desc">Bu kurallara uyan istekler önbelleğe alınmaz.</p>
                        <div class="wcu-setting-full">
                            <span class="wcu-setting-label">Hariç Tutulacak URL Kalıpları</span>
                            <textarea name="wcu_settings[exclude_uris]" rows="6" placeholder="/wp-admin*&#10;/cart*"><?php echo esc_textarea( $s['exclude_uris'] ); ?></textarea>
                            <p class="description">Her satıra bir kalıp; sonuna * eklenebilir.</p>
                        </div>
                        <hr class="wcu-card-sep" />
                        <div class="wcu-setting-full">
                            <span class="wcu-setting-label">Hariç Tutulacak Çerezler</span>
                            <textarea name="wcu_settings[exclude_cookies]" rows="4"><?php echo esc_textarea( $s['exclude_cookies'] ); ?></textarea>
                            <p class="description">Bu isimleri içeren çerez varsa sayfa önbelleklenmez (giriş/yorum çerezleri zaten hariç).</p>
                        </div>
                    </div>
                </div>

                <div style="<?php echo $active_tab === 'db' ? '' : 'display:none'; ?>" data-tab="db">
                    <div class="wcu-setting-card">
                        <h2>Veritabanı</h2>
                        <p class="wcu-card-desc">Revizyon, taslak, spam ve süresi dolmuş verilerin otomatik temizliği.</p>
                        <?php self::toggle_row( 'wcu_settings[db_auto_optimize]', 'Otomatik Veritabanı Optimizasyonu', $s['db_auto_optimize'], 'Haftalık otomatik çalıştır' ); ?>
                        <p class="description" style="margin-top:14px">Manuel çalıştırma için Dashboard üzerindeki "Veritabanı Optimize Et" butonunu kullanın.</p>
                    </div>
                </div>

                <div style="<?php echo $active_tab === 'cdn' ? '' : 'display:none'; ?>" data-tab="cdn">
                    <div class="wcu-setting-card">
                        <h2>🌐 CDN URL Yeniden Yazma</h2>
                        <p class="wcu-card-desc">Statik dosyaları (görsel/CSS/JS) bir CDN URL'si üzerinden sunar. Cloudflare, BunnyCDN, KeyCDN vb. ile uyumludur.</p>
                        <?php self::toggle_row( 'wcu_settings[cdn_enabled]', "CDN'i Etkinleştir", $s['cdn_enabled'] ); ?>
                        <div class="wcu-setting-full" style="margin-top:6px">
                            <span class="wcu-setting-label">CDN URL</span>
                            <input type="text" name="wcu_settings[cdn_url]" value="<?php echo esc_attr( $s['cdn_url'] ); ?>" class="regular-text" placeholder="https://cdn.siteniz.com" />
                            <p class="description">CDN sağlayıcınızın URL'sini girin (sonunda / olmadan).</p>
                        </div>
                        <hr class="wcu-card-sep" />
                        <?php
                        self::toggle_row( 'wcu_settings[cdn_include_images]', 'Görselleri Dahil Et', $s['cdn_include_images'], "Medya kütüphanesindeki görsel URL'lerini CDN'e yönlendirir." );
                        self::toggle_row( 'wcu_settings[cdn_include_css]', 'CSS Dosyalarını Dahil Et', $s['cdn_include_css'] );
                        self::toggle_row( 'wcu_settings[cdn_include_js]', 'JS Dosyalarını Dahil Et', $s['cdn_include_js'] );
                        ?>
                    </div>

                    <div class="wcu-setting-card">
                        <h2>Cloudflare</h2>
                        <p class="wcu-card-desc">Önbellek temizlendiğinde Cloudflare edge cache'i de otomatik temizlenir.</p>
                        <?php
                        self::text_row( 'wcu_settings[cloudflare_token]', 'API Token', $s['cloudflare_token'], 'password' );
                        self::text_row( 'wcu_settings[cloudflare_zone]', 'Zone ID', $s['cloudflare_zone'] );
                        ?>
                    </div>
                    <div class="wcu-setting-card">
                        <h2>Redis</h2>
                        <p class="wcu-card-desc">Nesne önbelleği için harici Redis sunucusu bağlantısı.</p>
                        <?php
                        self::toggle_row( 'wcu_settings[redis_enabled]', 'Redis Etkin', $s['redis_enabled'] );
                        self::text_row( 'wcu_settings[redis_host]', 'Host', $s['redis_host'] );
                        self::text_row( 'wcu_settings[redis_port]', 'Port', $s['redis_port'], 'number' );
                        self::text_row( 'wcu_settings[redis_password]', 'Şifre', $s['redis_password'], 'password' );
                        ?>
                    </div>
                </div>

                <div style="<?php echo $active_tab === 'toolbox' ? '' : 'display:none'; ?>" data-tab="toolbox">
                    <div class="wcu-setting-card">
                        <h2>💓 Heartbeat Kontrolü</h2>
                        <p class="wcu-card-desc">WordPress'in arka planda sürekli çalışan Heartbeat API isteklerini sınırlayarak sunucu yükünü azaltır.</p>
                        <?php
                        self::select_row( 'wcu_settings[heartbeat_control]', 'Heartbeat API Kontrolü', $s['heartbeat_control'], array(
                            'default'    => 'Varsayılan (WordPress)',
                            'admin_only' => 'Sadece Yönetici Panelinde Çalışsın',
                            'disabled'   => 'Tamamen Kapat',
                        ), 'Ön yüzde (frontend) tamamen kapatmak veya sadece yönetici panelinde çalıştırmak için kullanın.' );
                        self::text_row( 'wcu_settings[heartbeat_interval]', 'Heartbeat Aralığı (saniye)', $s['heartbeat_interval'], 'number', 'Kontrol "Varsayılan" değilken uygulanır.', 'min="15"' );
                        ?>
                    </div>

                    <div class="wcu-setting-card">
                        <h2>🛡️ Güvenlik Başlıkları</h2>
                        <p class="wcu-card-desc">Sitenize temel HTTP güvenlik başlıkları ekler (clickjacking, MIME-sniffing ve referrer sızıntılarına karşı).</p>
                        <?php self::toggle_row( 'wcu_settings[security_headers_enabled]', 'Güvenlik Başlıklarını Etkinleştir', $s['security_headers_enabled'], 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy ve Permissions-Policy başlıklarını ekler.' ); ?>
                    </div>
                </div>

                <div class="wcu-save-bar" id="wcu-settings-save-bar" style="<?php echo in_array( $active_tab, array( 'cache', 'optimize', 'exclude', 'db', 'cdn', 'toolbox' ), true ) ? '' : 'display:none'; ?>">
                    <button type="submit" class="wcu-btn-primary">Değişiklikleri Kaydet</button>
                </div>
            </form>

            <div style="<?php echo $active_tab === 'preload' ? '' : 'display:none'; ?>" data-tab="preload">
                <?php self::render_preload_tab(); ?>
            </div>
            <div style="<?php echo $active_tab === 'server' ? '' : 'display:none'; ?>" data-tab="server">
                <?php self::render_server_tab(); ?>
            </div>
        </div>
        <?php
    }

    protected static function render_preload_tab() {
        ?>
        <div class="wcu-setting-card">
            <h2>Ön Yükleme (Preload)</h2>
            <p class="wcu-card-desc">Sitemap.xml üzerinden veya son yazı/sayfalardan URL listesi çıkarıp arka planda ziyaret ederek önbelleği önceden ısıtır.</p>
            <button type="button" class="wcu-btn-primary" id="wcu-preload-start">Ön Yüklemeyi Başlat</button>
            <div id="wcu-preload-status" style="margin-top:14px;font-size:13px;color:#475569"></div>
        </div>
        <?php
    }

    protected static function render_server_tab() {
        ?>
        <div class="wcu-setting-card">
            <h2>Sunucu Yapılandırması</h2>
            <p class="wcu-card-desc">Statik önbellek dosyalarının PHP'yi devre dışı bırakıp doğrudan sunucu tarafından servis edilmesi için aşağıdaki kuralları ekleyin. Bu isteğe bağlıdır — eklenmezse önbellek yine de <code>advanced-cache.php</code> üzerinden PHP seviyesinde çalışır.</p>

            <h3 style="font-size:13.5px;margin:18px 0 8px">Apache (.htaccess)</h3>
            <textarea readonly rows="12" class="wcu-code-block"><?php echo esc_textarea( ServerConfig::apache_snippet() ); ?></textarea>
            <p class="description">Bu bloğu WordPress'in ana <code>.htaccess</code> dosyasındaki mevcut WordPress kurallarının <strong>üstüne</strong> ekleyin.</p>

            <hr class="wcu-card-sep" />

            <h3 style="font-size:13.5px;margin:0 0 8px">Nginx</h3>
            <textarea readonly rows="12" class="wcu-code-block"><?php echo esc_textarea( ServerConfig::nginx_snippet() ); ?></textarea>
            <p class="description">Nginx <code>.htaccess</code> okumaz; bu bloğu sunucu bloğunuza (<code>server { }</code>) ekleyip Nginx'i yeniden yükleyin (<code>nginx -s reload</code>).</p>

            <hr class="wcu-card-sep" />

            <h3 style="font-size:13.5px;margin:0 0 8px">PHP Seviyesi Erken Önbellek (advanced-cache.php)</h3>
            <p class="description">Eklenti aktivasyonunda <code>wp-content/advanced-cache.php</code> otomatik kurulur ve <code>wp-config.php</code> içine <code>define('WP_CACHE', true);</code> eklenmeye çalışılır. Bu dosya WordPress çekirdeği yüklenmeden önce çalışıp önbellekteki sayfayı doğrudan sunar.</p>
        </div>
        <?php
    }
}
