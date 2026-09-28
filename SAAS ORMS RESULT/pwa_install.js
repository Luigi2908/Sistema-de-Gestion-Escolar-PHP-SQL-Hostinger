/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// PWA install — shows a dismissible "Install app" chip only when the browser
// reports the app is installable; remembers dismissal; hides after install.
(function () {
    'use strict';
    var deferred = null;
    var KEY = 'pwa_install_dismissed';

    function makeBtn() {
        if (document.getElementById('rsInstallBtn')) return null;
        var b = document.createElement('button');
        b.id = 'rsInstallBtn';
        b.type = 'button';
        b.innerHTML = '⬇️ Install app';
        b.className = 'orms-float-btn right';   // styling lives in styles.css
        document.body.appendChild(b);
        return b;
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferred = e;
        if (localStorage.getItem(KEY) === '1') return; // already dismissed
        var b = makeBtn();
        if (!b) return;
        b.addEventListener('click', function () {
            if (!deferred) return;
            deferred.prompt();
            deferred.userChoice.then(function (c) {
                if (c && c.outcome === 'dismissed') localStorage.setItem(KEY, '1');
                deferred = null;
                if (b.parentNode) b.parentNode.removeChild(b);
            });
        });
    });

    window.addEventListener('appinstalled', function () {
        var b = document.getElementById('rsInstallBtn');
        if (b && b.parentNode) b.parentNode.removeChild(b);
        deferred = null;
    });
})();
