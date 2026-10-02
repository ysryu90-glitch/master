<?php
// 웹 푸시 알림 (표준 Web Push · RFC 8291 암호화 · VAPID). 추가 라이브러리 없이 openssl 확장만 쓴다.
// 아이폰은 사이트를 '홈 화면에 추가'한 뒤 그 아이콘으로 열어서 알림을 허용해야 받을 수 있다 (iOS 16.4+).

function b64u_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function b64u_decode(string $data): string
{
    return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/** P-256 공개키(65바이트, 0x04||X||Y) → PEM */
function p256_public_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** 새 P-256 키 쌍 [개인키 PEM, 공개키 65바이트] */
function p256_keypair(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) throw new RuntimeException('openssl 확장이 꺼져 있어 알림 키를 만들 수 없어요.');
    openssl_pkey_export($key, $pem);
    $ec = openssl_pkey_get_details($key)['ec'];
    $raw = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    return [$pem, $raw];
}

/** 이 사이트의 VAPID 키 (처음 한 번 만들어 DB에 저장) */
function vapid_keys(): array
{
    $keys = setting('vapid');
    if (!is_array($keys) || empty($keys['private']) || empty($keys['public'])) {
        [$pem, $raw] = p256_keypair();
        $keys = ['private' => $pem, 'public' => b64u_encode($raw)];
        set_setting('vapid', $keys);
    }
    return $keys;
}

/** DER 서명 → JWT용 64바이트 (R||S) */
function der_to_raw_signature(string $der): string
{
    $offset = 3;
    $rLen = ord($der[$offset]);
    $r = substr($der, $offset + 1, $rLen);
    $offset += 1 + $rLen + 1;
    $sLen = ord($der[$offset]);
    $s = substr($der, $offset + 1, $sLen);
    $fix = fn($v) => str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    return $fix($r) . $fix($s);
}

function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $parts = parse_url($endpoint);
    $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $header = b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u_encode(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:family@' . (parse_url(public_base(), PHP_URL_HOST) ?: 'example.com')]));
    $input = "$header.$claims";
    openssl_sign($input, $der, $keys['private'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . $input . '.' . b64u_encode(der_to_raw_signature($der)) . ', k=' . $keys['public'];
}

/** RFC 8291 (aes128gcm) 암호화한 본문 */
function push_encrypt(string $payload, string $p256dh, string $auth): string
{
    $uaPublic = b64u_decode($p256dh);
    $authSecret = b64u_decode($auth);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) throw new RuntimeException('구독 정보가 올바르지 않아요.');

    [$asPem, $asPublic] = p256_keypair();
    $shared = openssl_pkey_derive(openssl_pkey_get_public(p256_public_pem($uaPublic)), openssl_pkey_get_private($asPem), 32);
    if ($shared === false) throw new RuntimeException('암호화 키를 만들지 못했어요.');

    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** 구독 하나에 보내기. 반환: HTTP 상태 코드 */
function push_send(array $sub, array $message): int
{
    $body = push_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']);
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 43200',
            'Urgency: normal',
            'Authorization: ' . vapid_header($sub['endpoint']),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $status;
}

/** 한 사람의 모든 기기에 알림. 만료된 구독은 지운다. 반환: 보낸 기기 수 */
function push_to_member(int $memberId, string $title, string $body, string $url = 'index.php', string $tag = ''): int
{
    $stmt = db()->prepare('SELECT * FROM push_subscriptions WHERE member_id = ?');
    $stmt->execute([$memberId]);
    $sent = 0;
    $base = public_base();
    foreach ($stmt->fetchAll() as $sub) {
        try {
            $status = push_send($sub, ['title' => $title, 'body' => $body, 'url' => preg_match('#^https?://#', $url) ? $url : $base . '/' . $url, 'tag' => $tag]);
        } catch (Throwable $e) {
            $status = 0;
        }
        if ($status === 404 || $status === 410) {
            db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$sub['id']]);
        } elseif ($status >= 200 && $status < 300) {
            db()->prepare('UPDATE push_subscriptions SET last_ok = NOW() WHERE id = ?')->execute([$sub['id']]);
            $sent++;
        }
    }
    return $sent;
}

/** 같은 알림을 두 번 보내지 않도록 기록. 처음이면 true */
function notify_once(int $memberId, string $kind, string $ref): bool
{
    $stmt = db()->prepare('INSERT IGNORE INTO notify_log (member_id, kind, ref, sent_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$memberId, $kind, $ref]);
    return $stmt->rowCount() > 0;
}

/** 사람별 알림 설정 */
function notify_prefs(int $memberId): array
{
    $saved = setting("notify_$memberId", []);
    return (is_array($saved) ? $saved : []) + [
        'morning' => '07:30',   // 아침 요약 (빈 값이면 끔)
        'dinner' => '16:00',    // 저녁 출석 묻기 (빈 값이면 끔)
        'stale' => true,        // 건강 기록 끊김
        'weekly' => true,       // 일요일 저녁 주간 리포트
        'sick' => true,         // 아이 해열제 다음 복용 가능
    ];
}
