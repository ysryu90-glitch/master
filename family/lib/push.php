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

/** VAPID 연락처. 애플은 localhost · IP 주소로 된 값을 거절하므로 도메인일 때만 그 도메인을 쓴다 */
function vapid_subject(): string
{
    $host = rtrim(strtolower((string) parse_url(public_base(), PHP_URL_HOST)), '.');
    $isDomain = $host !== '' && strpos($host, '.') !== false && !filter_var($host, FILTER_VALIDATE_IP)
        && !preg_match('/(^|\.)(localhost|local|lan|home|internal)$/', $host);
    return 'mailto:family@' . ($isDomain ? $host : 'example.com');
}

function vapid_header(string $endpoint): string
{
    $keys = vapid_keys();
    $parts = parse_url($endpoint);
    $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $header = b64u_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = b64u_encode(json_encode(['aud' => $audience, 'exp' => time() + 3600, 'sub' => vapid_subject()], JSON_UNESCAPED_SLASHES));
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

/** 구독 하나에 보내기. 반환: ['status' => HTTP 코드(연결 실패면 0), 'error' => 알림 서버가 알려 준 이유] */
function push_send(array $sub, array $message): array
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
            'Urgency: high',
            'Authorization: ' . vapid_header($sub['endpoint']),
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = $response === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($status >= 300 && is_string($response)) {
        $json = json_decode($response, true);
        $error = is_array($json) ? (string) ($json['reason'] ?? $json['message'] ?? $json['error'] ?? '') : trim(strip_tags($response));
    }
    return ['status' => $status, 'error' => mb_substr($error, 0, 250)];
}

/** 기기 종류 (구독 주소로 판단) */
function push_device_name(array $sub): string
{
    $host = (string) parse_url($sub['endpoint'], PHP_URL_HOST);
    $ua = (string) ($sub['user_agent'] ?? '');
    if (str_contains($host, 'apple.com')) return preg_match('/iPhone/', $ua) ? '아이폰' : (preg_match('/iPad/', $ua) ? '아이패드' : '애플 기기');
    if (str_contains($host, 'googleapis.com')) return preg_match('/Android/', $ua) ? '안드로이드' : '크롬';
    if (str_contains($host, 'mozilla')) return '파이어폭스';
    if (str_contains($host, 'windows') || str_contains($host, 'microsoft')) return '엣지';
    return '브라우저';
}

/** 보내기 결과를 쉬운 말로 */
function push_explain(int $status, string $error): string
{
    if ($status >= 200 && $status < 300) return '✅ 보냈어요';
    if ($status === 0) return '⚠️ NAS가 알림 서버에 연결하지 못했어요 (' . ($error ?: '연결 실패') . '). NAS의 인터넷 · 시간 설정을 확인해 주세요.';
    if ($status === 404 || $status === 410) return '⚠️ 이 기기의 알림 등록이 만료됐어요. 그 기기에서 「알림 다시 연결」을 눌러 주세요.';
    if ($status === 403 && stripos($error, 'Mismatch') !== false) return '⚠️ 알림 키가 바뀌었어요. 그 기기에서 「알림 다시 연결」을 눌러 주세요. (' . $error . ')';
    if ($status === 403 && stripos($error, 'Expired') !== false) return '⚠️ NAS 시계가 맞지 않아요. DSM › 제어판 › 지역 옵션에서 시간 동기화(NTP)를 켜 주세요. (' . $error . ')';
    if ($status === 403 || $status === 401) return '⚠️ 알림 서버가 인증을 거절했어요 (' . ($error ?: $status) . '). 「알림 다시 연결」 뒤에도 같으면 이 문구를 알려 주세요.';
    if ($status === 413) return '⚠️ 알림 내용이 너무 길어요.';
    if ($status === 429) return '⚠️ 짧은 시간에 너무 많이 보냈어요. 잠시 뒤에 다시 해 주세요.';
    return '⚠️ 알림 서버 오류 ' . $status . ($error ? ' (' . $error . ')' : '') . '. 잠시 뒤에 다시 해 주세요.';
}

/** 한 사람의 모든 기기에 보내고 기기별 결과를 돌려준다. 만료된 구독은 지운다 */
function push_to_member_detail(int $memberId, string $title, string $body, string $url = 'index.php', string $tag = ''): array
{
    $stmt = db()->prepare('SELECT * FROM push_subscriptions WHERE member_id = ?');
    $stmt->execute([$memberId]);
    $base = public_base();
    $results = [];
    foreach ($stmt->fetchAll() as $sub) {
        try {
            $r = push_send($sub, ['title' => $title, 'body' => $body, 'url' => preg_match('#^https?://#', $url) ? $url : $base . '/' . $url, 'tag' => $tag]);
        } catch (Throwable $e) {
            $r = ['status' => 0, 'error' => $e->getMessage()];
        }
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        if ($r['status'] === 404 || $r['status'] === 410) {
            db()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$sub['id']]);
        } else {
            db()->prepare('UPDATE push_subscriptions SET last_try = NOW(), last_status = ?, last_error = ?' . ($ok ? ', last_ok = NOW()' : '') . ' WHERE id = ?')
                ->execute([$r['status'], $ok ? '' : mb_substr($r['error'], 0, 300), $sub['id']]);
        }
        $results[] = ['id' => (int) $sub['id'], 'device' => push_device_name($sub), 'ok' => $ok, 'status' => $r['status'],
            'message' => push_explain($r['status'], $r['error']), 'endpoint_hash' => $sub['endpoint_hash']];
    }
    return $results;
}

/** 한 사람의 모든 기기에 알림. 반환: 보낸 기기 수 */
function push_to_member(int $memberId, string $title, string $body, string $url = 'index.php', string $tag = ''): int
{
    return count(array_filter(push_to_member_detail($memberId, $title, $body, $url, $tag), fn($r) => $r['ok']));
}

/** 내 알림 기기 목록 (설정 화면용) */
function push_devices(int $memberId): array
{
    $stmt = db()->prepare('SELECT * FROM push_subscriptions WHERE member_id = ? ORDER BY created_at DESC');
    $stmt->execute([$memberId]);
    return array_map(fn($s) => [
        'id' => (int) $s['id'], 'device' => push_device_name($s), 'endpoint_hash' => $s['endpoint_hash'],
        'created' => $s['created_at'], 'last_ok' => $s['last_ok'],
        'last' => $s['last_try'] ? push_explain((int) $s['last_status'], (string) $s['last_error']) : '',
    ], $stmt->fetchAll());
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
        'budget' => true,       // 가계부 예산 80% · 100%
    ];
}

/** 이 사람이 알림 받을 기기를 등록했는지 */
function has_push(int $memberId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM push_subscriptions WHERE member_id = ? LIMIT 1');
    $stmt->execute([$memberId]);
    return (bool) $stmt->fetchColumn();
}
