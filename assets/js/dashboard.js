(function () {
    'use strict';

    function restFetch(path, opts) {
        opts = opts || {};
        opts.headers = Object.assign({ 'Content-Type': 'application/json', 'X-WP-Nonce': WCU.rest_nonce }, opts.headers || {});
        return fetch(WCU.rest_url + path, opts).then(function (r) {
            return r.json().then(function (data) { return { ok: r.ok, status: r.status, data: data }; });
        });
    }

    function toast(msg, kind) {
        var el = document.createElement('div');
        el.className = 'wcu-toast wcu-toast-' + (kind || 'info');
        el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('show'); });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, 3500);
    }

    function fmtTime(ts) {
        if (!ts) return '—';
        var d = new Date(ts * 1000);
        return d.toLocaleString();
    }

    function fmtBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return bytes.toFixed(1).replace('.0', '') + ' ' + units[i];
    }

    function initTabs() {
        var items = document.querySelectorAll('.wcu-sidebar li');
        var panels = document.querySelectorAll('.wcu-panel');
        items.forEach(function (li) {
            li.addEventListener('click', function () {
                items.forEach(function (i) { i.classList.remove('active'); });
                li.classList.add('active');
                var target = li.getAttribute('data-panel');
                panels.forEach(function (p) {
                    p.style.display = (p.getAttribute('data-panel') === target) ? '' : 'none';
                });
                if (target === 'logs') loadLogs();
                if (target === 'cachelist') loadCacheList();
            });
        });
    }

    function initTheme() {
        var btn = document.getElementById('wcu-theme-toggle');
        if (!btn) return;
        var saved = localStorage.getItem('wcu_theme');
        if (saved === 'dark') document.getElementById('wcu-app').classList.add('wcu-dark');
        btn.addEventListener('click', function () {
            var app = document.getElementById('wcu-app');
            app.classList.toggle('wcu-dark');
            localStorage.setItem('wcu_theme', app.classList.contains('wcu-dark') ? 'dark' : 'light');
        });
    }

    var chartInstance = null;
    function renderChart(logCounts) {
        var canvas = document.getElementById('wcu-chart');
        var empty = document.getElementById('wcu-chart-empty');
        if (!canvas) return;
        var labels = Object.keys(logCounts || {});
        var hasData = labels.length > 0;

        if (!hasData || typeof Chart === 'undefined') {
            canvas.style.display = 'none';
            if (empty) {
                empty.style.display = 'flex';
                if (typeof Chart === 'undefined' && hasData) {
                    empty.textContent = 'Grafik kütüphanesi yüklenemedi.';
                }
            }
            return;
        }

        canvas.style.display = '';
        if (empty) empty.style.display = 'none';

        var data = labels.map(function (k) { return logCounts[k]; });

        if (chartInstance) {
            chartInstance.data.labels = labels;
            chartInstance.data.datasets[0].data = data;
            chartInstance.update('none');
            return;
        }

        chartInstance = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Son 200 kayıt',
                    data: data,
                    backgroundColor: '#0ea5a4',
                    borderRadius: 6,
                }],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    function computeScore(status) {
        var score = 20;
        var reasons = [];
        if (status.environment && status.environment.advanced_cache_installed) { score += 20; reasons.push(['✅', 'Erken servis (advanced-cache.php) kurulu']); } else { reasons.push(['⚠️', 'advanced-cache.php kurulu değil']); }
        if (status.environment && status.environment.wp_cache_const) { score += 15; reasons.push(['✅', 'WP_CACHE etkin']); } else { reasons.push(['⚠️', 'WP_CACHE tanımlı değil']); }
        if (status.page_cache && status.page_cache.count > 0) { score += 20; reasons.push(['✅', status.page_cache.count + ' sayfa önbellekte']); } else { reasons.push(['ℹ️', 'Henüz önbelleğe alınmış sayfa yok']); }
        if (status.environment && status.environment.object_cache) { score += 15; reasons.push(['✅', 'Harici nesne önbelleği aktif']); } else { reasons.push(['ℹ️', 'Nesne önbelleği yok']); }
        if (status.db_last_run) { score += 10; reasons.push(['✅', 'Veritabanı optimize edilmiş']); } else { reasons.push(['ℹ️', 'Veritabanı henüz optimize edilmedi']); }
        if (status.health) {
            var keys = Object.keys(status.health);
            var okCount = keys.filter(function (k) { return status.health[k].ok; }).length;
            if (keys.length) score += Math.round((okCount / keys.length) * 20);
        }
        score = Math.min(100, score);
        return { score: score, reasons: reasons };
    }

    function renderScore(status) {
        var r = computeScore(status);
        document.getElementById('wcu-score-val').textContent = r.score;
        document.getElementById('wcu-score-circle').style.setProperty('--wcu-score-deg', (r.score * 3.6) + 'deg');
        var list = document.getElementById('wcu-score-list');
        if (list) {
            list.innerHTML = '';
            r.reasons.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = item[0] + ' ' + item[1];
                list.appendChild(li);
            });
        }
    }

    function renderBanner(status) {
        var env = status.environment || {};
        var set = function (id, val) { var el = document.getElementById(id); if (el) el.textContent = val || '—'; };
        set('wcu-env-php', env.php);
        set('wcu-env-mysql', env.mysql);
        set('wcu-env-server', env.server);
        set('wcu-env-wp', env.wp);
        set('wcu-env-adv', env.advanced_cache_installed ? 'Kurulu' : 'Kurulu değil');

        var disk = status.disk || {};
        var bar = document.getElementById('wcu-disk-bar');
        var figures = document.getElementById('wcu-disk-figures');
        if (bar && disk.percent !== null && disk.percent !== undefined) {
            bar.style.width = disk.percent + '%';
        }
        if (figures) {
            figures.textContent = (disk.percent !== null && disk.percent !== undefined)
                ? (disk.used_human + ' / ' + disk.total_human + ' (%' + disk.percent + ')')
                : 'Bilinmiyor';
        }
    }

    var jobSteps = [
        { key: 'page_cache', label: 'Sayfa önbelleği', icon: '📄' },
        { key: 'transients', label: 'Transients', icon: '⚡' },
        { key: 'object_cache', label: 'Nesne önbelleği', icon: '🧠' },
        { key: 'cache_dirs', label: 'Cache dizinleri', icon: '🗂️' },
        { key: 'db_optimize', label: 'Veritabanı', icon: '🗄️' },
    ];

    function renderJobCard(job) {
        var card = document.getElementById('wcu-result');
        var pctEl = document.getElementById('wcu-job-pct');
        var bar = document.getElementById('wcu-progress-fill');
        var grid = document.getElementById('wcu-step-grid');
        var title = document.getElementById('wcu-job-title');
        var sub = document.getElementById('wcu-job-sub');

        if (!card || !job) return;
        card.style.display = 'block';

        var progress = job.progress || 0;
        if (pctEl) pctEl.textContent = '%' + progress;
        if (bar) bar.style.width = progress + '%';

        if (title) title.textContent = job.title || 'Temizleme İşlemi';
        if (sub) sub.textContent = job.status === 'running' ? 'Arka planda çalışıyor…' : 'Tamamlandı';

        if (!grid) return;
        grid.innerHTML = '';

        var steps = job.steps || jobSteps;
        steps.forEach(function (step, idx) {
            var state = 'waiting';
            var badgeClass = 'wait';
            var badgeText = '○';
            var detail = 'Bekliyor';

            if (step.done) {
                state = 'done';
                badgeClass = 'ok';
                badgeText = '✓';
                detail = step.detail || 'Tamamlandı';
            } else if (step.running) {
                state = 'running';
                badgeClass = 'run';
                badgeText = '⋯';
                detail = step.detail || 'İşleniyor…';
            }

            var div = document.createElement('div');
            div.className = 'wcu-step-item ' + state;
            div.innerHTML = '<span class="wcu-step-badge ' + badgeClass + '">' + badgeText + '</span>' +
                '<div><div class="wcu-step-label">' + (step.label || step.name || step.key) + '</div>' +
                '<div class="wcu-step-detail">' + detail + '</div></div>';
            grid.appendChild(div);
        });
    }

    function hideJobCard() {
        var card = document.getElementById('wcu-result');
        if (card) card.style.display = 'none';
    }

    function renderDbCards(data) {
        var grid = document.getElementById('wcu-db-cards');
        var detail = document.getElementById('wcu-db-detail');
        var pre = document.getElementById('wcu-db-result');
        if (!grid || !data) return;

        grid.style.display = 'grid';
        if (detail) detail.style.display = 'block';
        if (pre) pre.style.display = 'none';

        var cards = [];
        if (data.revisions_deleted !== undefined) {
            cards.push({ label: 'Revizyonlar', value: data.revisions_deleted.toLocaleString(), sub: 'Silindi ✓', color: 'yellow' });
        }
        if (data.drafts_deleted !== undefined) {
            cards.push({ label: 'Oto. Taslaklar', value: data.drafts_deleted.toLocaleString(), sub: 'Silindi ✓', color: 'blue' });
        }
        if (data.spam_deleted !== undefined) {
            cards.push({ label: 'Çöp Yorumlar', value: data.spam_deleted.toLocaleString(), sub: 'Silindi ✓', color: 'pink' });
        }
        if (data.tables_optimized !== undefined) {
            var total = data.tables_total || data.tables_optimized;
            cards.push({ label: 'Tablolar Optimize', value: data.tables_optimized + '/' + total, sub: 'Tüm tablolar ✓', color: 'green' });
        }
        if (data.space_saved !== undefined) {
            cards.push({ label: 'Kazanılan Alan', value: fmtBytes(data.space_saved), sub: 'Toplam tasarruf', color: 'purple' });
        }
        if (data.transients_deleted !== undefined) {
            cards.push({ label: 'Transientlar', value: data.transients_deleted.toLocaleString(), sub: 'Süresi dolmuş silindi', color: 'blue' });
        }
        if (data.orphan_meta_deleted !== undefined) {
            cards.push({ label: 'Öksüz Meta', value: data.orphan_meta_deleted.toLocaleString(), sub: 'Temizlendi', color: 'pink' });
        }

        if (cards.length === 0) {
            cards.push({ label: 'Optimizasyon', value: '✓', sub: 'Tamamlandı', color: 'green' });
        }

        grid.innerHTML = '';
        cards.forEach(function (c) {
            var div = document.createElement('div');
            div.className = 'wcu-db-card ' + c.color;
            div.innerHTML = '<div class="db-label">' + c.label + '</div>' +
                '<div class="db-value">' + c.value + '</div>' +
                '<div class="db-sub">' + c.sub + '</div>';
            grid.appendChild(div);
        });

        if (detail) {
            detail.innerHTML = '';
            var rows = [];
            if (data.tables) {
                data.tables.forEach(function (t) {
                    rows.push({ table: t.name, desc: (t.rows || '') + ' satır, ' + (t.space_saved || 'optimize edildi') });
                });
            }
            if (rows.length === 0 && data.details) {
                data.details.forEach(function (d) {
                    rows.push({ table: d.table || d.name, desc: d.message || d.detail || 'İşlem tamamlandı' });
                });
            }
            if (rows.length === 0) {
                rows.push({ table: 'Veritabanı', desc: 'Tüm optimizasyon işlemleri başarıyla tamamlandı.' });
            }
            rows.forEach(function (r) {
                var row = document.createElement('div');
                row.className = 'wcu-db-row';
                row.innerHTML = '<span class="db-check">✓</span><span class="db-table">' + r.table + '</span><span class="db-desc">' + r.desc + '</span>';
                detail.appendChild(row);
            });
        }
    }

    function renderPreloadCard(preload) {
        var card = document.getElementById('wcu-preload-card');
        var pctEl = document.getElementById('wcu-preload-pct');
        var bar = document.getElementById('wcu-preload-bar');
        var stats = document.getElementById('wcu-preload-stats');
        var status = document.getElementById('wcu-preload-status');

        if (!card || !preload) return;
        card.style.display = 'block';

        var done = preload.done || 0;
        var total = preload.total || 0;
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;

        if (pctEl) pctEl.textContent = done + '/' + total;
        if (bar) bar.style.width = pct + '%';

        if (status) {
            status.textContent = preload.status === 'running'
                ? WCU.strings.preload_running + ': ' + done + '/' + total
                : WCU.strings.preload_done + ' (' + done + ' url)';
        }

        if (stats) {
            stats.innerHTML = '<div class="wcu-preload-stat"><span class="dot" style="background:#4f46e5"></span><span class="stat-label">' + done + ' URL işlendi</span></div>' +
                '<div class="wcu-preload-stat"><span class="dot" style="background:#a855f7"></span><span class="stat-label">' + (total - done) + ' kalan</span></div>' +
                '<div class="wcu-preload-stat"><span class="dot" style="background:#10b981"></span><span class="stat-label">Çalışıyor</span></div>';
        }
    }

    function hidePreloadCard() {
        var card = document.getElementById('wcu-preload-card');
        if (card) card.style.display = 'none';
    }

    function renderLogsTable(data) {
        var tbody = document.querySelector('#wcu-logs-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        var rows = (data || []).slice(0, 100);
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--wcu-faint);padding:24px;">Henüz kayıt yok.</td></tr>';
            return;
        }
        rows.forEach(function (l) {
            var tagClass = 'tag-page';
            if (l.channel === 'db_optimize') tagClass = 'tag-db';
            if (l.channel === 'preload') tagClass = 'tag-preload';

            var statusTag = '<span class="tag tag-success">Başarılı</span>';
            if (l.action && l.action.indexOf('fail') !== -1) statusTag = '<span class="tag" style="background:#fee2e2;color:#991b1b;">Hata</span>';

            var tr = document.createElement('tr');
            tr.innerHTML = '<td class="td-num">' + fmtTime(l.t) + '</td>' +
                '<td><span class="tag ' + tagClass + '">' + l.channel + '</span></td>' +
                '<td style="font-weight:500;color:var(--wcu-ink);">' + l.action + '</td>' +
                '<td>' + (l.detail || '') + '</td>' +
                '<td>' + statusTag + '</td>';
            tbody.appendChild(tr);
        });
    }

    function renderCacheListTable(rows) {
        var tbody = document.querySelector('#wcu-cachelist-table tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:var(--wcu-faint);padding:24px;">Henüz önbelleğe alınmış sayfa yok.</td></tr>';
            return;
        }
        rows.forEach(function (row) {
            var expires = row.expires_at ? fmtTime(row.expires_at) : '—';
            var tagClass = row.variant === 'mobile' ? 'tag-mobile' : 'tag-desktop';
            var tr = document.createElement('tr');
            tr.innerHTML = '<td class="td-url" title="' + (row.url || '') + '">' + (row.url || '') + '</td>' +
                '<td><span class="tag ' + tagClass + '">' + (row.variant || 'desktop') + '</span></td>' +
                '<td class="td-num">' + (row.size_human || '') + '</td>' +
                '<td class="td-num">' + fmtTime(row.created) + '</td>' +
                '<td class="td-num">' + expires + '</td>' +
                '<td><span class="tag tag-success">Aktif</span></td>';
            tbody.appendChild(tr);
        });
    }

    function refreshStatus() {
        restFetch('/status').then(function (res) {
            if (!res.ok) return;
            var s = res.data;
            document.getElementById('wcu-cache-size').textContent = (s.page_cache && s.page_cache.size_human) || '0 B';
            document.getElementById('wcu-cache-count').textContent = (s.page_cache && s.page_cache.count) || 0;
            document.getElementById('wcu-db-last').textContent = fmtTime(s.db_last_run);
            renderScore(s);
            renderBanner(s);
            renderChart(s.log_counts);
            renderHealth(s.health);

            if (s.job && s.job.status === 'running') {
                renderJobCard(s.job);
            } else if (s.job && s.job.status === 'done') {
                renderJobCard(s.job);
                setTimeout(hideJobCard, 4000);
            } else {
                hideJobCard();
            }

            if (s.preload && s.preload.status === 'running') {
                renderPreloadCard(s.preload);
                setTimeout(refreshStatus, 2000);
            } else if (s.preload && s.preload.status === 'done') {
                renderPreloadCard(s.preload);
                var el = document.getElementById('wcu-preload-status');
                if (el && el.getAttribute('data-watching') === '1') {
                    el.removeAttribute('data-watching');
                    toast(WCU.strings.preload_done, 'success');
                }
                setTimeout(hidePreloadCard, 4000);
            } else {
                hidePreloadCard();
            }
        });
    }

    function loadLogs() {
        restFetch('/logs').then(function (res) {
            if (!res.ok) return;
            renderLogsTable(res.data);
        });
    }

    function loadCacheList() {
        restFetch('/cache-list').then(function (res) {
            if (!res.ok) return;
            renderCacheListTable(res.data || []);
        });
    }

    function renderHealth(health) {
        var grid = document.getElementById('wcu-health-grid');
        if (!grid || !health) return;
        grid.innerHTML = '';
        Object.keys(health).forEach(function (key) {
            var item = health[key];
            var div = document.createElement('div');
            div.className = 'wcu-health-item ' + (item.ok ? 'ok' : 'warn');
            div.innerHTML = '<span class="wcu-health-icon">' + (item.ok ? '✓' : '⚠') + '</span>' +
                '<div><div class="wcu-health-label">' + item.label + '</div><div class="wcu-health-detail">' + item.detail + '</div></div>';
            grid.appendChild(div);
        });
    }

    var MAX_PRELOAD_STEPS = 300;
    function drivePreload(stepsLeft) {
        if (stepsLeft <= 0) {
            toast('Ön yükleme çok uzun sürdü, lütfen sayfayı yenileyin', 'error');
            return;
        }

        var params = new URLSearchParams();
        params.append('action', 'wcu_process_preload_step');
        params.append('nonce', WCU.nonce || '');

        fetch(WCU.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
        }).then(function (r) { 
            if (!r.ok) {
                console.error('HTTP Hata:', r.status, r.statusText);
                return r.text().then(function(t) { console.error('Response:', t); return null; });
            }
            return r.json(); 
        }).then(function (resp) {
            if (!resp) return;

            console.log('Preload response:', resp);

            if (!resp.success) {
                var msg = resp.data || 'Ön yükleme adımı başarısız';
                toast(msg, 'error');
                return;
            }
            var data = resp.data || {};

            if (data.done !== undefined && data.total !== undefined) {
                var card = document.getElementById('wcu-preload-card');
                var pctEl = document.getElementById('wcu-preload-pct');
                var bar = document.getElementById('wcu-preload-bar');
                var stats = document.getElementById('wcu-preload-stats');
                var status = document.getElementById('wcu-preload-status');

                if (card) card.style.display = 'block';
                if (pctEl) pctEl.textContent = data.done + '/' + data.total;
                if (bar) {
                    var pct = data.total > 0 ? Math.round((data.done / data.total) * 100) : 0;
                    bar.style.width = pct + '%';
                }
                if (status) status.textContent = 'Ön yükleme çalışıyor: ' + data.done + '/' + data.total;
                if (stats) {
                    var remaining = data.total - data.done;
                    stats.innerHTML = '<div class="wcu-preload-stat"><span class="dot" style="background:#4f46e5"></span><span class="stat-label">' + data.done + ' URL işlendi</span></div>' +
                        '<div class="wcu-preload-stat"><span class="dot" style="background:#a855f7"></span><span class="stat-label">' + remaining + ' kalan</span></div>' +
                        '<div class="wcu-preload-stat"><span class="dot" style="background:#10b981"></span><span class="stat-label">Çalışıyor</span></div>';
                }
            }

            // DÜZELTME: done >= total ise HER DURUMDA bitir!
            if (data.done >= data.total || data.remaining === 0 || data.status === 'done') {
                var statusEl = document.getElementById('wcu-preload-status');
                if (statusEl) {
                    statusEl.textContent = 'Ön yükleme tamamlandı! (' + data.done + ' URL)';
                    statusEl.setAttribute('data-watching', '1');
                }
                
                var statsEl = document.getElementById('wcu-preload-stats');
                if (statsEl) {
                    statsEl.innerHTML = '<div class="wcu-preload-stat"><span class="dot" style="background:#10b981"></span><span class="stat-label">' + data.done + ' URL işlendi</span></div>' +
                        '<div class="wcu-preload-stat"><span class="dot" style="background:#cbd5e1"></span><span class="stat-label">0 kalan</span></div>' +
                        '<div class="wcu-preload-stat"><span class="dot" style="background:#10b981"></span><span class="stat-label">Tamamlandı</span></div>';
                }
                
                refreshStatus();
                toast('Ön yükleme tamamlandı! (' + data.done + ' URL)', 'success');
                return;
            }

            setTimeout(function () { drivePreload(stepsLeft - 1); }, 300);
        }).catch(function (err) { 
            console.error('Preload error:', err);
            toast('Ön yükleme hatası: ' + err.message, 'error'); 
        });
    }

    var MAX_JOB_STEPS = 40;
    function driveJob(stepsLeft) {
        if (stepsLeft <= 0) {
            toast('İşlem çok uzun sürdü, lütfen sayfayı yenileyin', 'error');
            return;
        }
        fetch(WCU.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=wcu_process_step&nonce=' + encodeURIComponent(WCU.nonce),
        }).then(function (r) { return r.json(); }).then(function (resp) {
            if (!resp || !resp.success) {
                toast(WCU.strings.failed, 'error');
                return;
            }
            var data = resp.data || {};
            refreshStatus();

            if (data.error) {
                toast(WCU.strings.failed, 'error');
                return;
            }
            if (data.note === 'done' || data.note === 'already_done') {
                toast('Temizleme tamamlandı, sayfa yenileniyor…', 'success');
                setTimeout(function () { window.location.reload(); }, 900);
                return;
            }
            setTimeout(function () { driveJob(stepsLeft - 1); }, 150);
        }).catch(function () { toast(WCU.strings.failed, 'error'); });
    }

    function bindActions() {
        var clearAll = document.getElementById('wcu-clear-red');
        if (clearAll) {
            clearAll.addEventListener('click', function () {
                if (!confirm(WCU.strings.confirm_clear_all)) return;
                fetch(WCU.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=wcu_start_job&nonce=' + encodeURIComponent(WCU.nonce),
                }).then(function (r) { return r.json(); }).then(function (resp) {
                    if (resp && resp.success) {
                        toast('Temizleme işi başlatıldı…', 'success');
                        driveJob(MAX_JOB_STEPS);
                    } else {
                        toast(WCU.strings.failed, 'error');
                    }
                });
            });
        }

        document.querySelectorAll('.wcu-quick-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-action');
                if (!confirm(WCU.strings.confirm_action + ' (' + action + ')')) return;
                fetch(WCU.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=wcu_quick_action&quick=' + encodeURIComponent(action) + '&nonce=' + encodeURIComponent(WCU.nonce),
                }).then(function (r) { return r.json(); }).then(function (r) {
                    if (r && r.success) {
                        var resultData = r.data || {};
                        var jobCard = {
                            title: 'Hızlı İşlem: ' + action,
                            status: 'done',
                            progress: 100,
                            steps: [
                                { key: action, label: action.replace(/_/g, ' '), done: true, detail: 'Tamamlandı' }
                            ]
                        };
                        renderJobCard(jobCard);
                        toast(WCU.strings.done + ', sayfa yenileniyor…', 'success');
                        setTimeout(function () { window.location.reload(); }, 1200);
                    } else {
                        toast(WCU.strings.failed, 'error');
                    }
                });
            });
        });

        var preloadBtn = document.getElementById('wcu-preload-start');
        if (preloadBtn) {
            preloadBtn.addEventListener('click', function () {
                if (!confirm(WCU.strings.confirm_preload)) return;
                restFetch('/preload', { method: 'POST' }).then(function (res) {
                    if (res.ok) {
                        toast('Ön yükleme kuyruğa alındı (' + res.data.queued + ' url)', 'success');
                        drivePreload(MAX_PRELOAD_STEPS);
                    } else {
                        toast(WCU.strings.failed, 'error');
                    }
                });
            });
        }

        document.querySelectorAll('.wcu-preset-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var preset = btn.getAttribute('data-preset');
                if (!confirm('"' + btn.textContent.trim() + '" profili uygulanacak ve önbellek temizlenecek. Devam edilsin mi?')) return;
                restFetch('/preset', { method: 'POST', body: JSON.stringify({ name: preset }) }).then(function (res) {
                    var box = document.getElementById('wcu-preset-result');
                    if (res.ok) {
                        if (box) box.textContent = 'Profil uygulandı, sayfa yenileniyor…';
                        toast('Profil uygulandı', 'success');
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else {
                        if (box) box.textContent = 'Profil uygulanamadı.';
                        toast(WCU.strings.failed, 'error');
                    }
                });
            });
        });

        var dbBtn = document.getElementById('wcu-db-optimize-btn');
        if (dbBtn) {
            dbBtn.addEventListener('click', function () {
                if (!confirm(WCU.strings.confirm_action)) return;
                restFetch('/db-optimize', { method: 'POST' }).then(function (res) {
                    if (res.ok) {
                        renderDbCards(res.data);
                        toast(WCU.strings.done, 'success');
                    } else {
                        toast(WCU.strings.failed, 'error');
                    }
                    refreshStatus();
                });
            });
        }
    }

    function initSettingsTabs() {
        var body = document.querySelector('.wcu-settings-body');
        if (!body) return;
        var links = body.querySelectorAll('.wcu-settings-tabs a[data-tab]');
        var panes = body.querySelectorAll('div[data-tab]');
        var saveBar = document.getElementById('wcu-settings-save-bar');
        var saveableTabs = ['cache', 'optimize', 'exclude', 'db', 'cdn', 'toolbox'];
        links.forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var target = link.getAttribute('data-tab');
                links.forEach(function (l) { l.classList.remove('active'); });
                link.classList.add('active');
                panes.forEach(function (p) {
                    p.style.display = (p.getAttribute('data-tab') === target) ? '' : 'none';
                });
                if (saveBar) saveBar.style.display = (saveableTabs.indexOf(target) !== -1) ? '' : 'none';
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTabs();
        initTheme();
        initSettingsTabs();
        bindActions();
        refreshStatus();
    });
})();