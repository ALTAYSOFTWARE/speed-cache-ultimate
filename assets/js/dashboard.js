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

        // Grafik zaten varsa yok edip yeniden kurma; sadece verisini güncelle.
        // Bu, işlem devam ederken (job/preload polling) her 1.5-2 saniyede bir
        // grafiğin görünür şekilde "titremesini/yeniden çizilmesini" önler.
        if (chartInstance) {
            chartInstance.data.labels = labels;
            chartInstance.data.datasets[0].data = data;
            chartInstance.update('none'); // animasyonsuz, sessiz güncelleme
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
                document.getElementById('wcu-result').style.display = 'block';
                document.getElementById('wcu-result-pre').textContent = JSON.stringify(s.job, null, 2);
                document.getElementById('wcu-progress-val').textContent = s.job.progress || 0;
                // Not: burada artık kendini tekrar çağırmıyor. İlerleme, gerçek WP-Cron'un
                // (dakikada bir) tetiklenmesine bağlıydı ve cron çalışmazsa iş sonsuza kadar
                // "running" görünüyordu. İlerleme artık driveJob() tarafından aktif olarak
                // sürükleniyor (bkz. bindActions).
            }

            if (s.preload && s.preload.status === 'running') {
                var el = document.getElementById('wcu-preload-status');
                if (el) {
                    el.textContent = WCU.strings.preload_running + ': ' + (s.preload.done || 0) + '/' + (s.preload.total || 0);
                    setTimeout(refreshStatus, 2000);
                }
            } else if (s.preload && s.preload.status === 'done') {
                var el2 = document.getElementById('wcu-preload-status');
                if (el2 && el2.getAttribute('data-watching') === '1') {
                    el2.textContent = WCU.strings.preload_done + ' (' + (s.preload.done || 0) + ' url)';
                    el2.removeAttribute('data-watching');
                    toast(WCU.strings.preload_done, 'success');
                }
            }
        });
    }

    function loadLogs() {
        restFetch('/logs').then(function (res) {
            if (!res.ok) return;
            var tbody = document.querySelector('#wcu-logs-table tbody');
            tbody.innerHTML = '';
            (res.data || []).slice(0, 100).forEach(function (l) {
                var tr = document.createElement('tr');
                tr.innerHTML = '<td>' + fmtTime(l.t) + '</td><td>' + l.channel + '</td><td>' + l.action + '</td><td>' + (l.detail || '') + '</td>';
                tbody.appendChild(tr);
            });
        });
    }

    function loadCacheList() {
        restFetch('/cache-list').then(function (res) {
            if (!res.ok) return;
            var tbody = document.querySelector('#wcu-cachelist-table tbody');
            if (!tbody) return;
            tbody.innerHTML = '';
            var rows = res.data || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="color:#94a3b8">Henüz önbelleğe alınmış sayfa yok.</td></tr>';
                return;
            }
            rows.forEach(function (row) {
                var tr = document.createElement('tr');
                var expires = row.expires_at ? fmtTime(row.expires_at) : '—';
                tr.innerHTML = '<td style="max-width:420px;overflow:hidden;text-overflow:ellipsis">' + (row.url || '') + '</td>' +
                    '<td>' + (row.variant || '') + '</td>' +
                    '<td>' + (row.size_human || '') + '</td>' +
                    '<td>' + fmtTime(row.created) + '</td>' +
                    '<td>' + expires + '</td>';
                tbody.appendChild(tr);
            });
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

    var MAX_JOB_STEPS = 40; // güvenlik sınırı: normalde 7-15 adımda biter
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
            refreshStatus(); // ilerleme kutusunu güncel tut (kendini tekrar çağırmaz)

            if (data.error) {
                toast(WCU.strings.failed, 'error');
                return;
            }
            if (data.note === 'done' || data.note === 'already_done') {
                toast('Temizleme tamamlandı, sayfa yenileniyor…', 'success');
                setTimeout(function () { window.location.reload(); }, 900);
                return;
            }
            // Bir sonraki aşamayı hemen iste; gerçek cron'un dakikalarca beklemesine gerek yok.
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
                    var resultBox = document.getElementById('wcu-result');
                    if (r && r.success) {
                        resultBox.style.display = 'block';
                        document.getElementById('wcu-result-pre').textContent = JSON.stringify(r.data, null, 2);
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
                        var el = document.getElementById('wcu-preload-status');
                        el.setAttribute('data-watching', '1');
                        el.textContent = WCU.strings.preload_running + ': 0/' + res.data.queued;
                        toast('Ön yükleme kuyruğa alındı (' + res.data.queued + ' url)', 'success');
                        refreshStatus();
                    } else {
                        toast(WCU.strings.failed, 'error');
                    }
                });
            });
        }

        document.querySelectorAll('.wcu-preset-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var preset = btn.getAttribute('data-preset');
                if (!confirm('“' + btn.textContent.trim() + '” profili uygulanacak ve önbellek temizlenecek. Devam edilsin mi?')) return;
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
                    document.getElementById('wcu-db-result').textContent = JSON.stringify(res.data, null, 2);
                    toast(res.ok ? WCU.strings.done : WCU.strings.failed, res.ok ? 'success' : 'error');
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
