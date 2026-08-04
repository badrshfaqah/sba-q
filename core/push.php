<?php
/**
 * إشعارات Web Push بدون مكتبات خارجية
 * - مفاتيح VAPID (ES256) تُولَّد وتُخزَّن تلقائياً
 * - تشفير الحمولة وفق RFC 8291 (aes128gcm) عبر امتداد OpenSSL المدمج
 * يتطلب: PHP 7.3+ مع openssl و curl (متوفرة في الاستضافات المشتركة)
 */
if (!defined('SBA')) exit;

/* ---------- أدوات ترميز ---------- */
function b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/* ---------- مفاتيح VAPID ---------- */

/** توليد زوج مفاتيح EC P-256 وإرجاع [publicRaw65, privatePem] */
function push_generate_keys(): array
{
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$res) throw new RuntimeException('تعذر توليد مفاتيح VAPID — تحقق من امتداد OpenSSL');
    openssl_pkey_export($res, $privatePem);
    $d = openssl_pkey_get_details($res);
    $x = str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT);
    $y = str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return ["\x04" . $x . $y, $privatePem];
}

/** المفتاح العام (base64url) — يولَّد ويُحفظ عند أول طلب */
function push_vapid_public(): string
{
    $pub = (string)setting('vapid_public', '');
    if ($pub === '') {
        [$raw, $pem] = push_generate_keys();
        $pub = b64url_encode($raw);
        setting_save('vapid_public', $pub);
        setting_save('vapid_private', $pem);
    }
    return $pub;
}

function push_vapid_private(): string
{
    push_vapid_public(); // يضمن التوليد
    return (string)setting('vapid_private', '');
}

/* ---------- JWT (ES256) ---------- */

/** تحويل توقيع DER إلى صيغة r||s الخام (64 بايت) */
function push_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $extract = function () use ($der, &$pos) {
        $pos++; // 0x02
        $len = ord($der[$pos++]);
        $val = substr($der, $pos, $len);
        $pos += $len;
        $val = ltrim($val, "\0");
        return str_pad($val, 32, "\0", STR_PAD_LEFT);
    };
    return $extract() . $extract();
}

/** JWT موقّع لعنوان خدمة الدفع */
function push_vapid_jwt(string $audience): string
{
    $header  = b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims  = b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:admin@' . (parse_url($audience, PHP_URL_HOST) ?: 'example.com'),
    ]));
    $data = $header . '.' . $claims;
    if (!openssl_sign($data, $sig, push_vapid_private(), OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('فشل توقيع VAPID');
    }
    return $data . '.' . b64url_encode(push_der_to_raw($sig));
}

/* ---------- تشفير الحمولة (RFC 8291 / aes128gcm) ---------- */

/** بناء مفتاح عام PEM من نقطة EC خام (65 بايت) */
function push_raw_to_pem(string $raw65): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * تشفير الرسالة لمشترك واحد
 * ترجع جسم الطلب الثنائي الجاهز للإرسال
 */
function push_encrypt(string $payload, string $clientP256dh, string $clientAuth): string
{
    $uaPublic = b64url_decode($clientP256dh);   // 65 بايت
    $authSecret = b64url_decode($clientAuth);   // 16 بايت
    if (strlen($uaPublic) !== 65) throw new RuntimeException('مفتاح المشترك غير صالح');

    // زوج مفاتيح مؤقت للخادم
    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $d = openssl_pkey_get_details($res);
    $asPublic = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);

    // السر المشترك ECDH (ناتج P-256 دائماً 32 بايت)
    $shared = openssl_pkey_derive(openssl_pkey_get_public(push_raw_to_pem($uaPublic)), $res);
    if ($shared === false) throw new RuntimeException('فشل اشتقاق سر ECDH');
    $shared = str_pad($shared, 32, "\0", STR_PAD_LEFT);

    // IKM ثم مفتاح التشفير والـnonce
    $info = "WebPush: info\0" . $uaPublic . $asPublic;
    $ikm  = hash_hkdf('sha256', $shared, 32, $info, $authSecret);
    $salt = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    // التشفير مع فاصل نهاية السجل 0x02
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) throw new RuntimeException('فشل تشفير الحمولة');

    // الترويسة الثنائية: salt || rs(4) || idlen(1) || as_public
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/* ---------- الإرسال ---------- */

/**
 * إرسال إشعار لمشترك واحد. ترجع رمز HTTP (0 عند فشل الاتصال)
 */
function push_send_one(array $sub, array $message): int
{
    $endpoint = $sub['endpoint'];
    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $body = push_encrypt(
        json_encode($message, JSON_UNESCAPED_UNICODE),
        $sub['p256dh'],
        $sub['auth']
    );
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'Content-Length: ' . strlen($body),
        'TTL: 86400',
        'Urgency: normal',
        'Authorization: vapid t=' . push_vapid_jwt($audience) . ', k=' . push_vapid_public(),
    ];
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($ch);
    return $code;
}

/**
 * إرسال إشعار لمستخدمين محددين (كل أجهزتهم المفعّلة)
 * ترجع [أُرسل, فشل]
 */
function push_send_to_users(array $userIds, string $title, string $body, string $url = 'index.php'): array
{
    $userIds = array_values(array_filter(array_map('intval', $userIds)));
    if (!$userIds) return [0, 0];
    $in = implode(',', $userIds);
    $subs = db()->query('SELECT * FROM ' . tbl('push_subscriptions') . "
        WHERE active = 1 AND user_id IN ($in)")->fetchAll();
    return push_dispatch($subs, $title, $body, $url);
}

/** إرسال لجميع المشتركين */
function push_send_all(string $title, string $body, string $url = 'index.php'): array
{
    $subs = db()->query('SELECT * FROM ' . tbl('push_subscriptions') . ' WHERE active = 1')->fetchAll();
    return push_dispatch($subs, $title, $body, $url);
}

function push_dispatch(array $subs, string $title, string $body, string $url): array
{
    $sent = 0; $failed = 0;
    $message = ['title' => $title, 'body' => $body, 'url' => $url];
    $deactivate = db()->prepare('UPDATE ' . tbl('push_subscriptions') . ' SET active = 0 WHERE id = ?');
    $touch = db()->prepare('UPDATE ' . tbl('push_subscriptions') . ' SET last_ok = NOW() WHERE id = ?');
    foreach ($subs as $sub) {
        try {
            $code = push_send_one($sub, $message);
        } catch (Throwable $e) {
            $code = 0;
        }
        if ($code >= 200 && $code < 300) {
            $sent++;
            $touch->execute([(int)$sub['id']]);
        } else {
            $failed++;
            // اشتراك منتهٍ أو محذوف من المتصفح
            if (in_array($code, [404, 410], true)) $deactivate->execute([(int)$sub['id']]);
        }
    }
    return [$sent, $failed];
}

/* ---------- إدارة الاشتراكات ---------- */

function push_save_subscription(int $userId, array $subscription): bool
{
    $endpoint = (string)($subscription['endpoint'] ?? '');
    $p256dh   = (string)($subscription['keys']['p256dh'] ?? '');
    $auth     = (string)($subscription['keys']['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '' || !preg_match('#^https://#', $endpoint)) {
        return false;
    }
    $st = db()->prepare('INSERT INTO ' . tbl('push_subscriptions') . '
        (user_id, endpoint_hash, endpoint, p256dh, auth, active)
        VALUES (?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh),
                                auth = VALUES(auth), active = 1');
    $st->execute([$userId, hash('sha256', $endpoint), $endpoint, $p256dh, $auth]);
    return true;
}

function push_remove_subscription(int $userId, string $endpoint): void
{
    $st = db()->prepare('UPDATE ' . tbl('push_subscriptions') . '
        SET active = 0 WHERE user_id = ? AND endpoint_hash = ?');
    $st->execute([$userId, hash('sha256', $endpoint)]);
}

/** إحصائيات المفعّلين (للمدير) */
function push_stats(): array
{
    $row = db()->query('SELECT COUNT(*) AS devices, COUNT(DISTINCT user_id) AS users
        FROM ' . tbl('push_subscriptions') . ' WHERE active = 1')->fetch();
    return ['devices' => (int)$row['devices'], 'users' => (int)$row['users']];
}
