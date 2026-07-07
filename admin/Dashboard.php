<?php
namespace WCU;

use WCU\Settings;

if ( ! defined( 'ABSPATH' ) ) exit;

class Dashboard {
    public static function register_menu() {
        add_menu_page( 'WP Cache Ultimate', 'WP Cache Ultimate', 'manage_options', 'wcu-dashboard', array( __CLASS__, 'render_page' ), 'dashicons-performance', 58 );
        add_submenu_page( 'wcu-dashboard', 'Ayarlar', 'Ayarlar', 'manage_options', 'wcu-settings', array( 'WCU\Settings', 'render_page' ) );

    }

    public static function enqueue_assets( $hook ) {
        $current_page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS ) ?: '';
        if ( ! in_array( $current_page, array( 'wcu-dashboard', 'wcu-settings' ), true ) ) return;

        wp_enqueue_style( 'wcu-admin-css', WCU_URL . 'assets/css/admin.css', array(), filemtime( WCU_DIR . 'assets/css/admin.css' ) );
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

                    <!-- OVERVIEW PANEL -->
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

                    <!-- ACTIONS PANEL -->
                    <section class="wcu-panel" data-panel="actions" style="display:none">
                        <div class="wcu-quick full">
                            <h3>🚀 Hızlı İşlemler</h3>
                            <p style="color:var(--wcu-muted);font-size:12.5px;margin:4px 0 16px;">Tek tıklamayla önbellek temizliği ve optimizasyon işlemlerini başlatın.</p>
                            <div class="wcu-action-grid">
                                <button class="wcu-action-btn wcu-quick-btn" data-action="clear_page_cache">
                                    <span class="act-icon yellow">📄</span> Sayfa Önbelleğini Temizle
                                </button>
                                <button class="wcu-action-btn wcu-quick-btn" data-action="clear_transients">
                                    <span class="act-icon blue">⚡</span> Transients Temizle
                                </button>
                                <button class="wcu-action-btn wcu-quick-btn" data-action="clear_object">
                                    <span class="act-icon purple">🧠</span> Nesne Önbelleği Temizle
                                </button>
                                <button class="wcu-action-btn wcu-quick-btn" data-action="clear_cache_dirs">
                                    <span class="act-icon pink">🗂️</span> Cache Dizinlerini Temizle
                                </button>
                                <button class="wcu-action-btn wcu-quick-btn" data-action="db_optimize">
                                    <span class="act-icon green">🗄️</span> Veritabanı Optimize Et
                                </button>
                            </div>
                        </div>
                        <div id="wcu-result" class="wcu-job-card" style="display:none">
                            <div class="wcu-job-header">
                                <div class="wcu-job-icon">🔄</div>
                                <div>
                                    <div class="wcu-job-title" id="wcu-job-title">Temizleme İşlemi</div>
                                    <div class="wcu-job-sub" id="wcu-job-sub">Arka planda çalışıyor…</div>
                                </div>
                                <div class="wcu-job-pct" id="wcu-job-pct">%0</div>
                            </div>
                            <div class="wcu-progress-track" style="margin-bottom:14px;">
                                <div class="wcu-progress-fill" id="wcu-progress-fill" style="width:0%">
                                    <div class="wcu-shimmer-bar"></div>
                                </div>
                            </div>
                            <div class="wcu-step-grid" id="wcu-step-grid"></div>
                        </div>
                    </section>

                    <!-- PRELOAD PANEL -->
                    <section class="wcu-panel" data-panel="preload" style="display:none">
                        <div class="wcu-quick full">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                                <div>
                                    <h3 style="margin:0;">▶️ Ön Yükleme (Preload)</h3>
                                    <p style="color:var(--wcu-muted);font-size:12.5px;margin:4px 0 0;">Sitemap ve son içeriklerden URL toplayıp arka planda ziyaret ederek önbelleği ısıtır.</p>
                                </div>
                                <button class="wcu-btn wcu-btn-primary" id="wcu-preload-start" type="button">Ön Yüklemeyi Başlat</button>
                            </div>
                            <div id="wcu-preload-card" class="wcu-preload-card" style="display:none">
                                <div class="wcu-job-header">
                                    <div class="wcu-job-icon">🚀</div>
                                    <div>
                                        <div class="wcu-job-title">Ön Yükleme Çalışıyor</div>
                                        <div class="wcu-job-sub">Sitemap taranıyor ve URL'ler kuyruğa alınıyor…</div>
                                    </div>
                                    <div class="wcu-job-pct" id="wcu-preload-pct">0/0</div>
                                </div>
                                <div class="wcu-progress-track" style="margin-bottom:14px;">
                                    <div class="wcu-progress-fill" id="wcu-preload-bar" style="width:0%">
                                        <div class="wcu-shimmer-bar"></div>
                                    </div>
                                </div>
                                <div class="wcu-preload-stats" id="wcu-preload-stats"></div>
                            </div>
                            <div id="wcu-preload-status" style="margin-top:12px;"></div>
                        </div>
                    </section>

                    <!-- DB PANEL -->
                    <section class="wcu-panel" data-panel="db" style="display:none">
                        <div class="wcu-quick full">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                                <div>
                                    <h3 style="margin:0;">🗄️ Veritabanı Bakımı</h3>
                                    <p style="color:var(--wcu-muted);font-size:12.5px;margin:4px 0 0;">Revizyonlar, taslaklar, yorumlar ve transientları temizler; tabloları optimize eder.</p>
                                </div>
                                <button class="wcu-btn wcu-btn-primary" id="wcu-db-optimize-btn" type="button">Şimdi Optimize Et</button>
                            </div>
                            <div id="wcu-db-cards" class="wcu-db-grid" style="display:none"></div>
                            <div id="wcu-db-detail" class="wcu-db-detail" style="display:none"></div>
                            <pre id="wcu-db-result" style="display:none"></pre>
                        </div>
                    </section>

                    <!-- CACHE LIST PANEL -->
                    <section class="wcu-panel" data-panel="cachelist" style="display:none">
                        <div class="wcu-quick full">
                            <h3>📋 Önbelleğe Alınmış Sayfalar</h3>
                            <p style="color:var(--wcu-muted);font-size:12.5px;margin:4px 0 16px;">Şu anda diskte hangi sayfaların önbelleklendiğini, boyutunu ve süresinin ne zaman dolacağını gösterir.</p>
                            <div class="wcu-table-wrap">
                                <table class="wcu-table" id="wcu-cachelist-table">
                                    <thead><tr><th>URL</th><th>Cihaz</th><th>Boyut</th><th>Oluşturulma</th><th>Süre Dolumu</th><th>Durum</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <!-- LOGS PANEL -->
                    <section class="wcu-panel" data-panel="logs" style="display:none">
                        <div class="wcu-quick full">
                            <h3>📝 Son Kayıtlar</h3>
                            <p style="color:var(--wcu-muted);font-size:12.5px;margin:4px 0 16px;">Son 100 önbellek ve optimizasyon işleminin detaylı kayıtları.</p>
                            <div class="wcu-table-wrap">
                                <table class="wcu-table" id="wcu-logs-table">
                                    <thead><tr><th>Zaman</th><th>Kanal</th><th>Aksiyon</th><th>Detay</th><th>Durum</th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="wcu-panel" data-panel="settings" style="display:none">
                        <?php Settings::render_content(); ?>
                    </section>
                </main>
            </div>
        </div>
        <?php
    }

    public static function handle_preload_step() {
        $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'wcu_clear_nonce')) {
            wp_send_json_error('Geçersiz nonce');
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Yetkisiz');
        }

        $status = get_option(Preloader::STATE_OPTION, array());
        $urls = get_option(Preloader::QUEUE_OPTION, array());

        $total = isset($status['total']) && $status['total'] > 0 
            ? intval($status['total']) 
            : 0;
        
        $done = isset($status['done']) ? intval($status['done']) : 0;

        error_log('WCU Preload Step: Kuyruk=' . count($urls) . ', Done=' . $done . ', Total=' . $total);

        // DÜZELTME: Kuyruk boşsa VEYA done >= total ise HEMEN "done" dön!
        if (empty($urls) || $done >= $total) {
            if (isset($status['status']) && $status['status'] === 'running') {
                $status['status'] = 'done';
                $status['finished_at'] = time();
                update_option(Preloader::STATE_OPTION, $status);
            }
            
            wp_send_json_success(array(
                'status' => 'done',
                'done' => $done,
                'total' => $total > 0 ? $total : $done,
                'remaining' => 0
            ));
            return;
        }

        $url = array_shift($urls);
        $done++;

        if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
            wp_remote_get($url, array('timeout' => 3, 'blocking' => false, 'sslverify' => false));
        }

        if ($total === 0) {
            $total = $done + count($urls);
        }

        $status['done'] = $done;
        $status['total'] = $total;

        if (empty($urls) || $done >= $total) {
            $status['status'] = 'done';
            $status['finished_at'] = time();
        }

        update_option(Preloader::STATE_OPTION, $status);
        update_option(Preloader::QUEUE_OPTION, $urls);

        wp_send_json_success(array(
            'status' => (empty($urls) || $done >= $total) ? 'done' : 'running',
            'done' => $done,
            'total' => $total,
            'remaining' => count($urls)
        ));
    }
}
add_action('wp_ajax_wcu_process_preload_step', array('WCU\Dashboard', 'handle_preload_step'));