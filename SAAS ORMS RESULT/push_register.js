/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// web push opt-in — registers sw, shows a floating bell, subscribes via VAPID.
(function () {
    'use strict';

    // bail early on unsupported browsers
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return;

    var SUB_URL = 'push_subscribe.php';

    // same lookup orms.js uses — sidebar.php sets ORMS_CSRF on every page that has the bell
    function csrf() {
        if (window.ORMS_CSRF) return window.ORMS_CSRF;
        var m = document.querySelector('meta[name="csrf-token"]');
        if (m && m.content) return m.content;
        var i = document.querySelector('input[name="csrf_token"]');
        return i ? i.value : '';
    }

    // base64url vapid key -> Uint8Array (applicationServerKey)
    function urlBase64ToUint8Array(base64) {
        var pad = '='.repeat((4 - (base64.length % 4)) % 4);
        var b64 = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
        var raw = atob(b64);
        var out = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
        return out;
    }

    // ArrayBuffer -> base64url (for p256dh / auth)
    function bufToB64Url(buf) {
        var bytes = new Uint8Array(buf), bin = '';
        for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    // get existing sw reg or register sw.js (relative -> app-folder scope, portable in subfolders)
    async function getReg() {
        try {
            var reg = await navigator.serviceWorker.getRegistration();
            if (reg) return reg;
            return await navigator.serviceWorker.register('sw.js');
        } catch (e) { return null; }
    }

    // fetch the vapid public key from server
    async function getKey() {
        try {
            var res = await fetch(SUB_URL + '?action=key', { credentials: 'same-origin' });
            var j = await res.json();
            return (j && j.success && j.key) ? j.key : '';
        } catch (e) { return ''; }
    }

    // push the subscription up to the server
    async function saveSub(sub) {
        try {
            var raw = sub.toJSON ? sub.toJSON() : {};
            var p256dh = (raw.keys && raw.keys.p256dh) || (sub.getKey && bufToB64Url(sub.getKey('p256dh')));
            var auth = (raw.keys && raw.keys.auth) || (sub.getKey && bufToB64Url(sub.getKey('auth')));
            var body = new URLSearchParams();
            body.set('action', 'subscribe');
            body.set('endpoint', sub.endpoint);
            body.set('p256dh', p256dh || '');
            body.set('auth', auth || '');
            body.set('csrf_token', csrf());
            await fetch(SUB_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            return true;
        } catch (e) { return false; }
    }

    // do the actual browser subscribe + persist
    async function doSubscribe(reg) {
        try {
            var existing = await reg.pushManager.getSubscription();
            if (existing) { await saveSub(existing); return true; }
            var key = await getKey();
            if (!key) return false;
            var sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(key)
            });
            await saveSub(sub);
            return true;
        } catch (e) { return false; }
    }

    // floating navy bell — only when permission still 'default'. sits above the mobile nav.
    function showBtn(reg) {
        if (document.getElementById('rsPushBtn')) return;
        var btn = document.createElement('button');
        btn.id = 'rsPushBtn';
        btn.type = 'button';
        btn.innerHTML = '🔔 Enable alerts';
        btn.className = 'orms-float-btn left';   // styling lives in styles.css
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            try {
                var perm = await Notification.requestPermission();
                if (perm === 'granted') await doSubscribe(reg);
            } catch (e) { /* ignore */ }
            // hide once handled either way (granted = subscribed, denied = no point nagging)
            if (btn.parentNode) btn.parentNode.removeChild(btn);
        });
        document.body.appendChild(btn);
    }

    async function init() {
        try {
            var reg = await getReg();
            if (!reg) return;
            await navigator.serviceWorker.ready.catch(function () {});

            var perm = Notification.permission;
            if (perm === 'granted') {
                // already allowed — make sure we have a live sub (silent)
                var existing = await reg.pushManager.getSubscription();
                if (!existing) await doSubscribe(reg);
                return;
            }
            if (perm === 'default') {
                // not asked yet, and no sub — offer the bell
                var sub = await reg.pushManager.getSubscription();
                if (!sub) showBtn(reg);
            }
            // perm === 'denied' -> do nothing
        } catch (e) { /* never throw */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
