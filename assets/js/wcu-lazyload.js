/**
 * WP Cache Ultimate - Lazy Load
 * <img>, <iframe> ve <picture><source> için data-src / data-srcset tabanlı
 * tembel yükleme. IntersectionObserver desteklemeyen eski tarayıcılarda
 * anında yükleme yapılır.
 */
(function () {
    'use strict';

    var THRESHOLD = '200px';

    function loadElement(el) {
        if (el.tagName === 'IMG' && el.parentNode && el.parentNode.tagName === 'PICTURE') {
            var sources = el.parentNode.querySelectorAll('source');
            for (var i = 0; i < sources.length; i++) {
                var srcset = sources[i].getAttribute('data-srcset');
                if (srcset) {
                    sources[i].setAttribute('srcset', srcset);
                    sources[i].removeAttribute('data-srcset');
                }
            }
        }

        var src = el.getAttribute('data-src');
        if (src) {
            if (el.tagName === 'IMG' || el.tagName === 'IFRAME') {
                el.src = src;
            } else {
                el.style.backgroundImage = 'url(' + src + ')';
            }
            el.removeAttribute('data-src');
        }

        var srcsetSelf = el.getAttribute('data-srcset');
        if (srcsetSelf) {
            el.setAttribute('srcset', srcsetSelf);
            el.removeAttribute('data-srcset');
        }

        el.classList.remove('wcu-lazy');
        el.classList.add('wcu-loaded');
    }

    var hasObserver = 'IntersectionObserver' in window;
    var observer = hasObserver ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                loadElement(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { rootMargin: THRESHOLD }) : null;

    function observeAll() {
        var els = document.querySelectorAll('.wcu-lazy');
        for (var i = 0; i < els.length; i++) {
            if (observer) {
                observer.observe(els[i]);
            } else {
                loadElement(els[i]);
            }
        }
    }

    // Mobilde IntersectionObserver bazen gecikmeli tetiklendiği için ekstra güvence.
    function checkFallbacks() {
        var els = document.querySelectorAll('.wcu-lazy');
        if (!els.length) return;
        var vh = window.innerHeight || document.documentElement.clientHeight;
        for (var i = 0; i < els.length; i++) {
            var rect = els[i].getBoundingClientRect();
            if (rect.top <= vh + 300 && rect.bottom >= -300) {
                loadElement(els[i]);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeAll);
    } else {
        observeAll();
    }
    window.addEventListener('load', observeAll);
    window.addEventListener('scroll', checkFallbacks, { passive: true });
    window.addEventListener('resize', checkFallbacks, { passive: true });
    document.addEventListener('touchstart', checkFallbacks, { passive: true });

    if ('MutationObserver' in window) {
        var mObserver = new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                for (var i = 0; i < m.addedNodes.length; i++) {
                    var n = m.addedNodes[i];
                    if (n.nodeType !== 1) continue;
                    if (n.classList && n.classList.contains('wcu-lazy') && observer) observer.observe(n);
                    var children = n.querySelectorAll ? n.querySelectorAll('.wcu-lazy') : [];
                    for (var j = 0; j < children.length; j++) {
                        if (observer) observer.observe(children[j]);
                    }
                }
            });
        });
        mObserver.observe(document.body, { childList: true, subtree: true });
    }
})();
