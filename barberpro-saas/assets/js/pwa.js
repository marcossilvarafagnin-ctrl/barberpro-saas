/* BarberPro PWA — Install Banner JS v2 */
(function () {
    'use strict';

    var DISMISS_KEY = 'bp_pwa_dismissed';
    var INSTALL_KEY = 'bp_pwa_installed';
    var deferredPrompt = null;

    var ua          = navigator.userAgent.toLowerCase();
    var isIOS       = /iphone|ipad|ipod/.test(ua);
    var isAndroid   = /android/.test(ua);
    var isSamsung   = /samsungbrowser/.test(ua);
    var isChrome    = /chrome/.test(ua) && !/edge|opr/.test(ua);
    var isSafari    = /safari/.test(ua) && !/chrome/.test(ua);

    var isStandalone = window.navigator.standalone === true
                    || window.matchMedia('(display-mode: standalone)').matches
                    || window.matchMedia('(display-mode: fullscreen)').matches;

    if ( isStandalone ) return;
    if ( localStorage.getItem(INSTALL_KEY) ) return;

    function wasDismissed() {
        try {
            var ts = localStorage.getItem(DISMISS_KEY);
            if (!ts) return false;
            return (Date.now() - parseInt(ts, 10)) < 3 * 24 * 60 * 60 * 1000;
        } catch(e) { return false; }
    }

    function markDismissed() {
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch(e) {}
    }

    function showBanner(id, delay) {
        setTimeout(function() {
            var el = document.getElementById(id);
            if (!el) return;
            el.style.display = 'block';
            void el.offsetHeight;
            el.classList.add('bp-pwa-slide-in');
        }, delay || 3000);
    }

    function hideBanner(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('bp-pwa-slide-in');
        el.classList.add('bp-pwa-slide-out');
        setTimeout(function() { el.style.display = 'none'; el.classList.remove('bp-pwa-slide-out'); }, 400);
    }

    // Android/Chrome
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        if (!wasDismissed()) showBanner('bpPwaAndroid', 3000);
    });

    document.addEventListener('DOMContentLoaded', function() {
        var btn = document.getElementById('bpPwaInstallBtn');
        if (btn) {
            btn.addEventListener('click', function() {
                if (!deferredPrompt) return;
                hideBanner('bpPwaAndroid');
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choice) {
                    deferredPrompt = null;
                    if (choice.outcome === 'accepted')
                        try { localStorage.setItem(INSTALL_KEY, '1'); } catch(e) {}
                });
            });
        }

        // iOS Safari
        if (isIOS && isSafari && !wasDismissed()) showBanner('bpPwaIOS', 4000);

        // Samsung sem beforeinstallprompt
        if (isAndroid && isSamsung && !wasDismissed()) {
            setTimeout(function() {
                if (!deferredPrompt) {
                    var b = document.getElementById('bpPwaInstallBtn');
                    if (b) b.textContent = 'Como instalar ›';
                    showBanner('bpPwaAndroid', 0);
                }
            }, 5000);
        }
    });

    window.addEventListener('appinstalled', function() {
        hideBanner('bpPwaAndroid');
        try { localStorage.setItem(INSTALL_KEY, '1'); } catch(e) {}
    });

    window.bpPwaDismiss    = function() { hideBanner('bpPwaAndroid'); markDismissed(); };
    window.bpPwaDismissIOS = function() { hideBanner('bpPwaIOS');     markDismissed(); };

    // Debug: abra o console e rode bpPwaDebug()
    window.bpPwaDebug = function() {
        console.table({
            isIOS: isIOS, isAndroid: isAndroid, isSafari: isSafari,
            isChrome: isChrome, isStandalone: isStandalone,
            dismissed: wasDismissed(), installed: !!localStorage.getItem(INSTALL_KEY),
            deferredPrompt: !!deferredPrompt, swSupported: 'serviceWorker' in navigator
        });
    };
})();
