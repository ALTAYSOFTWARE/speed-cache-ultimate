<?php
namespace WCU;

if ( ! defined( 'ABSPATH' ) ) exit;

class Dashboard {
    public static function register_menu() {
        add_menu_page( 'WP Cache Ultimate', 'WP Cache Ultimate', 'manage_options', 'wcu-dashboard', array( __CLASS__, 'render_page' ), 'dashicons-performance', 58 );
        add_submenu_page( 'wcu-dashboard', 'Ayarlar', 'Ayarlar', 'manage_options', 'wcu-settings', array( 'WCU\\Settings', 'render_page' ) );
    }

    public static function enqueue_assets( $hook ) {
        // WordPress alt menü "hook" adları üst menünün SLUG'ından değil, menü BAŞLIĞINDAN
        // türetildiği için ($admin_page_hooks[$slug] = sanitize_title($menu_title)), hook adını
        // tahmin etmek yerine doğrudan ?page= parametresine bakmak çok daha sağlam.
        $current_page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
        if ( ! in_array( $current_page, array( 'wcu-dashboard', 'wcu-settings' ), true ) ) return;

        wp_enqueue_style( 'wcu-admin-css', WCU_URL . 'assets/css/admin.css', array(), filemtime( WCU_DIR . 'assets/css/admin.css' ) );
        // Chart.js artık eklenti içinden (yerel dosyadan) yükleniyor; harici CDN bağımlılığı kaldırıldı.
        wp_enqueue_script( 'wcu-chartjs', WCU_URL . 'assets/js/chart.umd.min.js', array(), '4.4.4', true );
        wp_enqueue_script( 'wcu-admin-js', WCU_URL . 'assets/js/dashboard.js', array( 'wcu-chartjs' ), filemtime( WCU_DIR . 'assets/js/dashboard.js' ), true );

        wp_localize_script( 'wcu-admin-js', 'WCU', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'wcu_clear_nonce' ),
            'rest_url'   => esc_url_raw( rest_url( 'wcu/v1' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'strings'    => array(
                'confirm_clear_all'   => 'Tüm önbelleği temizlemek istediğinize emin misiniz?',
                'confirm_action'      => 'Bu işlemi yapmak istediğinize emin misiniz?',
                'confirm_preload'     => 'Önbellek ön yüklemesi arka planda başlatılacak. Devam edilsin mi?',
                'done'                => 'Tamamlandı',
                'failed'              => 'İşlem başarısız oldu',
                'preload_running'     => 'Ön yükleme çalışıyor',
                'preload_done'        => 'Ön yükleme tamamlandı',
            ),
        ) );
    }

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Yetkisiz' );
        $settings = Settings::get();
        ?>
        <div class="wrap wcu-wrap" id="wcu-app">
            <div class="wcu-topbar">
                <div class="wcu-title">
                    <h1>
                        WP Cache Ultimate
                        <span class="wcu-badge wcu-badge-active">● Aktif</span>
                        <span class="wcu-badge wcu-badge-version">Sürüm <?php echo esc_html( WCU_VERSION ); ?></span>
                    </h1>
                    <p class="wcu-sub">Gerçek zamanlı önbellek motoru & optimizasyon paneli</p>
                </div>
                <div class="wcu-actions">
                    <button id="wcu-theme-toggle" class="wcu-btn wcu-btn-outline" type="button" title="Açık/Koyu tema">🌓</button>
                    <button id="wcu-clear-red" class="wcu-btn wcu-btn-danger" type="button">Tüm Önbelleği Temizle</button>
                </div>
            </div>

            <div class="wcu-env-banner">
                <div class="wcu-env-item">
                    <span class="wcu-env-icon">🐘</span>
                    <div><div class="wcu-env-label">PHP</div><div class="wcu-env-value" id="wcu-env-php">—</div></div>
                </div>
                <div class="wcu-env-item">
                    <span class="wcu-env-icon">🗄️</span>
                    <div><div class="wcu-env-label">MySQL</div><div class="wcu-env-value" id="wcu-env-mysql">—</div></div>
                </div>
                <div class="wcu-env-item">
                    <span class="wcu-env-icon">🌐</span>
                    <div><div class="wcu-env-label">Web Sunucusu</div><div class="wcu-env-value" id="wcu-env-server">—</div></div>
                </div>
                <div class="wcu-env-item">
                    <span class="wcu-env-icon">📦</span>
                    <div><div class="wcu-env-label">WordPress</div><div class="wcu-env-value" id="wcu-env-wp">—</div></div>
                </div>
                <div class="wcu-env-item">
                    <span class="wcu-env-icon">⚡</span>
                    <div><div class="wcu-env-label">Erken Servis (advanced-cache.php)</div><div class="wcu-env-value" id="wcu-env-adv">—</div></div>
                </div>
            </div>

            <div class="wcu-disk-card">
                <div class="wcu-disk-head">
                    <span class="dashicons dashicons-database"></span>
                    <span>Disk Kullanımı</span>
                    <span class="wcu-disk-figures" id="wcu-disk-figures">—</span>
                </div>
                <div class="wcu-progress-track"><div class="wcu-progress-fill" id="wcu-disk-bar" style="width:0%"></div></div>
            </div>

            <div class="wcu-grid">
                <aside class="wcu-left">
                    <nav class="wcu-sidebar">
                        <ul>
                            <li class="active" data-panel="overview"><span class="dashicons dashicons-chart-area"></span> Genel Bakış</li>
                            <li data-panel="actions"><span class="dashicons dashicons-admin-tools"></span> Hızlı İşlemler</li>
                            <li data-panel="preload"><span class="dashicons dashicons-controls-play"></span> Ön Yükleme</li>
                            <li data-panel="db"><span class="dashicons dashicons-database"></span> Veritabanı</li>
                            <li data-panel="cachelist"><span class="dashicons dashicons-media-spreadsheet"></span> Önbellek Listesi</li>
                            <li data-panel="logs"><span class="dashicons dashicons-list-view"></span> Kayıtlar</li>
                            <li class="wcu-sidebar-settings" data-panel="settings"><span class="dashicons dashicons-admin-generic"></span> Ayarlar</li>
                        </ul>
                    </nav>
                </aside>

                <main class="wcu-main">
                    <section class="wcu-cards">
                        <div class="card card-blob card-blue"><div class="card-icon">🗜️</div><div class="label">Önbellek Boyutu</div><div class="value" id="wcu-cache-size">—</div></div>
                        <div class="card card-blob card-teal"><div class="card-icon">📄</div><div class="label">Önbelleğe Alınan Sayfa</div><div class="value" id="wcu-cache-count">—</div></div>
                        <div class="card card-blob card-purple"><div class="card-icon">🧠</div><div class="label">Nesne Önbellek</div><div class="value" id="wcu-object-cache"><?php echo function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ? 'Aktif' : 'Yok'; ?></div></div>
                        <div class="card card-blob card-orange"><div class="card-icon">🧹</div><div class="label">Son DB Optimizasyonu</div><div class="value" id="wcu-db-last">—</div></div>
                    </section>

                    <section class="wcu-panel" data-panel="overview">
                        <div class="wcu-dashboard-main">
                            <div class="wcu-score">
                                <div class="score-circle" id="wcu-score-circle"><span id="wcu-score-val">--</span></div>
                                <div class="score-text">Optimizasyon Skoru</div>
                                <ul class="wcu-score-list" id="wcu-score-list"></ul>
                            </div>
                            <div class="wcu-quick">
                                <h3>Kanal Bazında Etkinlik</h3>
                                <div class="wcu-chart-wrap">
                                    <canvas id="wcu-chart"></canvas>
                                    <p id="wcu-chart-empty" class="wcu-chart-empty" style="display:none">Henüz kayıt yok — bir işlem yaptığınızda burada görünecek.</p>
                                </div>
                            </div>
                        </div>
                        <div class="wcu-quick full wcu-health-card">
                            <h3>🛡️ Profesyonel Performans Denetimi</h3>
                            <p class="wcu-card-desc">Sunucunuzun temel performans yeteneklerini otomatik kontrol eder.</p>
                            <div class="wcu-health-grid" id="wcu-health-grid"></div>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="actions" style="display:none">
                        <div class="wcu-quick full">
                            <h3>Hızlı İşlemler</h3>
                            <div class="quick-list">
                                <button class="button wcu-quick-btn" data-action="clear_page_cache">Sayfa Önbelleğini Temizle</button>
                                <button class="button wcu-quick-btn" data-action="clear_transients">Transients Temizle</button>
                                <button class="button wcu-quick-btn" data-action="clear_object">Nesne Önbelleği Temizle</button>
                                <button class="button wcu-quick-btn" data-action="clear_cache_dirs">Cache Dizinlerini Temizle</button>
                                <button class="button wcu-quick-btn" data-action="db_optimize">Veritabanı Optimize Et</button>
                            </div>
                        </div>
                        <div id="wcu-result" class="wcu-result" style="display:none">
                            <h3>İşlem Sonucu</h3>
                            <pre id="wcu-result-pre"></pre>
                            <div id="wcu-progress">İlerleme: <span id="wcu-progress-val">0</span>%</div>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="preload" style="display:none">
                        <div class="wcu-quick full">
                            <h3>Ön Yükleme (Preload)</h3>
                            <p>Sitemap ve son içeriklerden URL toplayıp arka planda ziyaret ederek önbelleği ısıtır.</p>
                            <button class="button button-primary" id="wcu-preload-start" type="button">Ön Yüklemeyi Başlat</button>
                            <div id="wcu-preload-status" style="margin-top:12px;"></div>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="db" style="display:none">
                        <div class="wcu-quick full">
                            <h3>Veritabanı Bakımı</h3>
                            <p>Revizyonlar, otomatik taslaklar, çöp/spam yorumlar, süresi dolmuş transientlar ve öksüz meta kayıtlarını temizler; tabloları optimize eder.</p>
                            <button class="button button-primary" id="wcu-db-optimize-btn" type="button">Şimdi Optimize Et</button>
                            <pre id="wcu-db-result" style="margin-top:12px;"></pre>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="cachelist" style="display:none">
                        <div class="wcu-quick full">
                            <h3>Önbelleğe Alınmış Sayfalar</h3>
                            <p>Şu anda diskte hangi sayfaların önbelleklendiğini, boyutunu ve süresinin ne zaman dolacağını gösterir.</p>
                            <table class="widefat striped" id="wcu-cachelist-table">
                                <thead><tr><th>URL</th><th>Cihaz</th><th>Boyut</th><th>Oluşturulma</th><th>Süre Dolumu</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="logs" style="display:none">
                        <div class="wcu-quick full">
                            <h3>Son Kayıtlar</h3>
                            <table class="widefat striped" id="wcu-logs-table">
                                <thead><tr><th>Zaman</th><th>Kanal</th><th>Aksiyon</th><th>Detay</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="settings" style="display:none">
                        <?php \WCU\Settings::render_content(); ?>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }
}
