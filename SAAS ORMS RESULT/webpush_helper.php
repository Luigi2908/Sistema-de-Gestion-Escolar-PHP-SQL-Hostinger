<?php
/**
 * Developed by Mohammad Rameez Imdad (Rameez Scripts)
 * WhatsApp: https://whatsapp.rameezscripts.com/ (For Custom Projects)
 * YouTube: https://www.youtube.com/@rameezimdad (Subscribe for more!)
 */

// pure-php web push — VAPID (RFC 8292) + aes128gcm payload (RFC 8291). no composer.
// keys via getSetting(); subs via getDBConnection() (mysqli). best-effort, never throws.

if (!function_exists('wpB64UrlEncode')) {
    function wpB64UrlEncode($d) { return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }
    function wpB64UrlDecode($d) {
        $d = strtr($d, '-_', '+/');
        $m = strlen($d) % 4;
        if ($m) $d .= str_repeat('=', 4 - $m);
        return base64_decode($d);
    }
    // raw 65-byte P-256 point -> PEM SubjectPublicKeyInfo (fixed prime256v1 prefix)
    function wpRawToPem($raw65) {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
        return "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split(base64_encode($der), 64) . "-----END PUBLIC KEY-----\r\n";
    }
    // DER ECDSA signature -> raw r||s (64 bytes)
    function wpDerToRaw($der) {
        $o = 0;
        if (ord($der[$o++]) != 0x30) return false;
        $l = ord($der[$o++]); if ($l & 0x80) $o += ($l & 0x7f);
        if (ord($der[$o++]) != 0x02) return false;
        $rl = ord($der[$o++]); $r = substr($der, $o, $rl); $o += $rl;
        if (ord($der[$o++]) != 0x02) return false;
        $sl = ord($der[$o++]); $s = substr($der, $o, $sl);
        $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT);
        $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
        return $r . $s;
    }
}

if (!function_exists('webpushVapidHeaders')) {
    // VAPID Authorization header for the endpoint origin
    function webpushVapidHeaders($endpoint, $publicB64, $privatePem, $subject) {
        $u = parse_url($endpoint);
        if (!$u || empty($u['host'])) return false;
        $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
        $jh = wpB64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $jp = wpB64UrlEncode(json_encode(['aud' => $aud, 'exp' => time() + 43200, 'sub' => $subject]));
        $input = $jh . '.' . $jp;
        $der = '';
        if (!openssl_sign($input, $der, $privatePem, OPENSSL_ALGO_SHA256)) return false;
        $raw = wpDerToRaw($der);
        if ($raw === false) return false;
        $jwt = $input . '.' . wpB64UrlEncode($raw);
        return ['Authorization: vapid t=' . $jwt . ', k=' . $publicB64];
    }
}

if (!function_exists('webpushEncrypt')) {
    // RFC 8291 aes128gcm — returns binary body or false
    function webpushEncrypt($payload, $p256dhB64, $authB64) {
        $uaPublic = wpB64UrlDecode($p256dhB64);   // 65 bytes
        $authSecret = wpB64UrlDecode($authB64);    // 16 bytes
        if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) return false;

        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$ec) return false;
        $det = openssl_pkey_get_details($ec);
        $asPublic = "\x04" . str_pad($det['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($det['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $uaKey = openssl_pkey_get_public(wpRawToPem($uaPublic));
        if (!$uaKey) return false;
        $shared = openssl_pkey_derive($uaKey, $ec);   // ECDH
        if ($shared === false) return false;

        // IKM = HKDF(salt=auth, ikm=shared, info="WebPush: info\0"|ua|as, 32)
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $plain = $payload . "\x02";   // single record: data + 0x02 delimiter
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) return false;

        // aes128gcm header: salt(16)|rs(4)|idlen(1)|keyid(asPublic) then ciphertext|tag
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }
}

if (!function_exists('webpushSendOne')) {
    // send to one sub (assoc: endpoint,p256dh,auth). returns http code (0 on local failure)
    function webpushSendOne($sub, $title, $body, $url = 'dashboard.php') {
        $pub = getSetting('vapid_public_key', '');
        $priv = getSetting('vapid_private_key', '');
        $subject = getSetting('vapid_subject', 'mailto:admin@example.com');
        if (!$pub || !$priv) return 0;

        $enc = webpushEncrypt(json_encode(['title' => $title, 'body' => $body, 'url' => $url]), $sub['p256dh'], $sub['auth']);
        if ($enc === false) return 0;
        $vapid = webpushVapidHeaders($sub['endpoint'], $pub, $priv, $subject);
        if ($vapid === false) return 0;

        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $enc,
            CURLOPT_HTTPHEADER => array_merge($vapid, [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400'
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
}

if (!function_exists('webpushDeliver')) {
    // send to a list of subs; prune dead (404/410). returns counts. never throws.
    function webpushDeliver($conn, $subs, $title, $body, $url) {
        $res = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        if (empty($subs)) return $res;
        $del = $conn->prepare("DELETE FROM push_subscriptions WHERE id = ?");
        foreach ($subs as $s) {
            $code = webpushSendOne($s, $title, $body, $url);
            if ($code >= 200 && $code < 300) $res['sent']++;
            elseif ($code === 404 || $code === 410) { $del->bind_param("i", $s['id']); $del->execute(); $res['pruned']++; }
            else $res['failed']++;
        }
        $del->close();
        return $res;
    }
}

if (!function_exists('webpushSendToUser')) {
    // push to one user's devices only. best-effort.
    function webpushSendToUser($user_id, $title, $body, $url = 'dashboard.php') {
        $res = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        try {
            if (getSetting('enable_web_push', '1') !== '1') return $res;
            $conn = getDBConnection();
            $stmt = @$conn->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?");
            if (!$stmt) return $res; // table not ready
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $res = webpushDeliver($conn, $subs, $title, $body, $url);
        } catch (Exception $e) {
            error_log('webpushSendToUser: ' . $e->getMessage());
        }
        return $res;
    }
}

if (!function_exists('webpushBroadcast')) {
    // push to every device OF ONE SCHOOL. best-effort.
    // the school comes from the owning user — push_subscriptions has no school_id of its own, and
    // denormalising one onto it would drift. unscoped, publishing results at one school pushed a
    // notification to every school's parents. $school is trailing/optional, defaults to sid().
    function webpushBroadcast($title, $body, $url = 'dashboard.php', ?int $school = null) {
        $res = ['sent' => 0, 'failed' => 0, 'pruned' => 0];
        try {
            if (getSetting('enable_web_push', '1') !== '1') return $res;
            $conn = getDBConnection();
            $s = $school ?? (function_exists('sid') ? sid() : 0);
            $subs = null;
            if ($st = @$conn->prepare("SELECT ps.id, ps.endpoint, ps.p256dh, ps.auth
                                       FROM push_subscriptions ps JOIN users u ON u.id = ps.user_id
                                       WHERE u.school_id = ?")) {
                $st->bind_param('i', $s);
                $st->execute();
                $subs = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
            } else { // pre-migration schema — no school_id on users yet
                $r = @$conn->query("SELECT id, endpoint, p256dh, auth FROM push_subscriptions");
                if (!$r) return $res; // table not ready
                $subs = $r->fetch_all(MYSQLI_ASSOC);
            }
            $res = webpushDeliver($conn, $subs, $title, $body, $url);
        } catch (Exception $e) {
            error_log('webpushBroadcast: ' . $e->getMessage());
        }
        return $res;
    }
}
