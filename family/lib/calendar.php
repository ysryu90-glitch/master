<?php
// iCloud 캘린더 연결 (CalDAV). Apple ID와 '앱 전용 암호'로 접속한다.
// 원본은 iCloud에 있고, 앞뒤 기간의 일정을 calendar_events 표에 복사해 두고 보여준다.

const CALDAV_ROOT = 'https://caldav.icloud.com';

function caldav_request(string $method, string $url, string $body = '', array $headers = [], int $depth = -1): array
{
    $user = (string) setting('icloud_user', '');
    $pass = (string) setting('icloud_password', '');
    if ($user === '' || $pass === '') throw new RuntimeException('iCloud 계정이 설정되지 않았어요.');

    $ch = curl_init($url);
    $headerLines = array_merge(['Content-Type: application/xml; charset=utf-8'], $headers);
    if ($depth >= 0) $headerLines[] = 'Depth: ' . $depth;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_CONNECTTIMEOUT => 6,
    ]);
    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false) throw new RuntimeException('iCloud 연결 실패: ' . $error);
    if ($status === 401) throw new RuntimeException('iCloud 로그인 실패: Apple ID 또는 앱 전용 암호를 확인해 주세요.');
    if ($status >= 400) throw new RuntimeException("iCloud 응답 오류 ($status)");
    return [$status, $response];
}

function caldav_xml(string $xml): SimpleXMLElement
{
    $doc = simplexml_load_string($xml);
    if ($doc === false) throw new RuntimeException('iCloud 응답을 읽지 못했어요.');
    $doc->registerXPathNamespace('d', 'DAV:');
    $doc->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
    $doc->registerXPathNamespace('a', 'http://apple.com/ns/ical/');
    return $doc;
}

function caldav_absolute(string $base, string $href): string
{
    if (preg_match('#^https?://#', $href)) return $href;
    $parts = parse_url($base);
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $href;
}

/** iCloud의 캘린더 목록 [{name, url, color}] — 주소는 settings에 기억 */
function caldav_calendars(bool $refresh = false): array
{
    $cached = setting('icloud_calendars');
    if (!$refresh && is_array($cached) && $cached) return $cached;

    [, $xml] = caldav_request('PROPFIND', CALDAV_ROOT . '/',
        '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>', [], 0);
    $principal = (string) (caldav_xml($xml)->xpath('//d:current-user-principal/d:href')[0] ?? '');
    if ($principal === '') throw new RuntimeException('iCloud 사용자 정보를 찾지 못했어요.');

    $principalUrl = caldav_absolute(CALDAV_ROOT, $principal);
    [, $xml] = caldav_request('PROPFIND', $principalUrl,
        '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-home-set/></d:prop></d:propfind>', [], 0);
    $home = (string) (caldav_xml($xml)->xpath('//c:calendar-home-set/d:href')[0] ?? '');
    if ($home === '') throw new RuntimeException('iCloud 캘린더 위치를 찾지 못했어요.');
    $homeUrl = caldav_absolute($principalUrl, $home);

    [, $xml] = caldav_request('PROPFIND', $homeUrl,
        '<d:propfind xmlns:d="DAV:" xmlns:a="http://apple.com/ns/ical/" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>'
        . '<d:displayname/><d:resourcetype/><a:calendar-color/><c:supported-calendar-component-set/></d:prop></d:propfind>', [], 1);
    $doc = caldav_xml($xml);
    $calendars = [];
    foreach ($doc->xpath('//d:response') as $response) {
        $response->registerXPathNamespace('d', 'DAV:');
        $response->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $response->registerXPathNamespace('a', 'http://apple.com/ns/ical/');
        if (!$response->xpath('.//d:resourcetype/c:calendar')) continue;
        $components = array_map('strval', $response->xpath('.//c:comp/@name'));
        if ($components && !in_array('VEVENT', $components, true)) continue; // 미리알림 목록 제외
        $color = substr((string) ($response->xpath('.//a:calendar-color')[0] ?? '#4da3ff'), 0, 7);
        $calendars[] = [
            'name' => (string) ($response->xpath('.//d:displayname')[0] ?? '캘린더'),
            'url' => caldav_absolute($homeUrl, (string) $response->xpath('./d:href')[0]),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#4da3ff',
        ];
    }
    set_setting('icloud_calendars', $calendars);
    return $calendars;
}

/** 보여줄 캘린더 (설정에서 고른 것, 없으면 전부) */
function caldav_selected(): array
{
    $all = caldav_calendars();
    $names = setting('icloud_selected', []);
    if (!$names) return $all;
    return array_values(array_filter($all, fn($c) => in_array($c['name'], $names, true)));
}

/** 일정 복사본 새로 받기 (지난 7일 ~ 앞으로 45일) */
function caldav_sync(): int
{
    $start = gmdate('Ymd\THis\Z', strtotime('-7 day'));
    $end = gmdate('Ymd\THis\Z', strtotime('+45 day'));
    $events = [];
    foreach (caldav_selected() as $cal) {
        $body = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/>'
            . '<c:calendar-data><c:expand start="' . $start . '" end="' . $end . '"/></c:calendar-data></d:prop>'
            . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            . '<c:time-range start="' . $start . '" end="' . $end . '"/></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
        [, $xml] = caldav_request('REPORT', $cal['url'], $body, [], 1);
        foreach (caldav_xml($xml)->xpath('//c:calendar-data') as $data) {
            foreach (ics_events((string) $data) as $event) {
                $event['calendar'] = $cal['name'];
                $event['color'] = $cal['color'];
                $events[] = $event;
            }
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM calendar_events');
    $insert = $pdo->prepare('REPLACE INTO calendar_events (uid, start_at, end_at, all_day, title, location, calendar, color, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    foreach ($events as $e) {
        $insert->execute([mb_substr($e['uid'], 0, 255), $e['start'], $e['end'], $e['all_day'] ? 1 : 0,
            mb_substr($e['title'], 0, 300), mb_substr($e['location'], 0, 300), $e['calendar'], $e['color']]);
    }
    $pdo->commit();
    set_setting('calendar_synced_at', date('Y-m-d H:i:s'));
    set_setting('calendar_error', '');
    return count($events);
}

/** 10분 지난 복사본이면 새로 받기 (실패해도 화면은 예전 복사본으로) */
function calendar_refresh_if_stale(int $seconds = 600): void
{
    if (!setting('icloud_user')) return;
    $synced = (string) setting('calendar_synced_at', '');
    if ($synced && time() - strtotime($synced) < $seconds) return;
    try {
        caldav_sync();
    } catch (Throwable $e) {
        set_setting('calendar_error', $e->getMessage());
        set_setting('calendar_synced_at', date('Y-m-d H:i:s')); // 실패해도 매번 기다리지 않게
    }
}

function calendar_events(string $from, string $to): array
{
    $stmt = db()->prepare('SELECT * FROM calendar_events WHERE start_at < ? AND end_at > ? ORDER BY all_day DESC, start_at');
    $stmt->execute([$to . ' 23:59:59', $from . ' 00:00:00']);
    $rows = $stmt->fetchAll();
    // 종일 일정의 끝 날짜는 다음 날 0시라서 하루를 빼서 판단
    return array_values(array_filter($rows, fn($e) => !$e['all_day'] || date('Y-m-d', strtotime($e['end_at']) - 1) >= $from));
}

/** iCloud 캘린더에 새 일정 만들기 */
function caldav_create(string $calendarName, string $title, string $startDate, ?string $startTime, ?string $endTime, string $location = '', string $note = ''): void
{
    $cal = null;
    foreach (caldav_calendars() as $c) if ($c['name'] === $calendarName) $cal = $c;
    if (!$cal) throw new RuntimeException('캘린더를 찾지 못했어요.');

    $uid = strtoupper(bin2hex(random_bytes(16))) . '@family-health';
    $esc = fn($s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\;', '\,', '\n'], $s);
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//family-health//KO', 'BEGIN:VEVENT',
        'UID:' . $uid, 'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'SUMMARY:' . $esc($title)];
    if ($startTime) {
        $start = strtotime("$startDate $startTime");
        $end = $endTime ? strtotime("$startDate $endTime") : $start + 3600;
        if ($end <= $start) $end = $start + 3600;
        $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $start);
        $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $end);
    } else {
        $lines[] = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($startDate));
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime("$startDate +1 day"));
    }
    if ($location !== '') $lines[] = 'LOCATION:' . $esc($location);
    if ($note !== '') $lines[] = 'DESCRIPTION:' . $esc($note);
    array_push($lines, 'END:VEVENT', 'END:VCALENDAR');

    caldav_request('PUT', rtrim($cal['url'], '/') . '/' . rawurlencode($uid) . '.ics', implode("\r\n", $lines) . "\r\n",
        ['Content-Type: text/calendar; charset=utf-8', 'If-None-Match: *']);
    set_setting('calendar_synced_at', ''); // 다음 화면에서 바로 다시 받기
}

/** iCalendar 글에서 일정 꺼내기 (expand 덕분에 반복 일정은 iCloud가 펼쳐서 보내 줌) */
function ics_events(string $ics): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // 접힌 줄 펴기
    $events = [];
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks)) return [];
    foreach ($blocks[1] as $block) {
        $props = [];
        foreach (preg_split("/\r?\n/", trim($block)) as $line) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) continue;
            $props[$m[1]] ??= ['params' => $m[2], 'value' => $m[3]];
        }
        if (!isset($props['DTSTART'])) continue;
        [$start, $allDay] = ics_time($props['DTSTART']);
        $end = isset($props['DTEND']) ? ics_time($props['DTEND'])[0] : ($allDay ? date('Y-m-d H:i:s', strtotime("$start +1 day")) : $start);
        $unescape = fn($s) => str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
        $events[] = [
            'uid' => ($props['UID']['value'] ?? md5($block)) . (isset($props['RECURRENCE-ID']) ? '#' . $props['RECURRENCE-ID']['value'] : ''),
            'start' => $start,
            'end' => $end,
            'all_day' => $allDay,
            'title' => $unescape($props['SUMMARY']['value'] ?? '(제목 없음)'),
            'location' => $unescape($props['LOCATION']['value'] ?? ''),
        ];
    }
    return $events;
}

/** DTSTART 값 → [한국 시간 'Y-m-d H:i:s', 종일 여부] */
function ics_time(array $prop): array
{
    $value = $prop['value'];
    if (str_contains($prop['params'], 'VALUE=DATE') || preg_match('/^\d{8}$/', $value)) {
        return [date('Y-m-d 00:00:00', strtotime(substr($value, 0, 8))), true];
    }
    if (str_ends_with($value, 'Z')) {
        return [date('Y-m-d H:i:s', strtotime($value)), false];
    }
    $tz = 'Asia/Seoul';
    if (preg_match('/TZID=([^;:]+)/', $prop['params'], $m)) $tz = trim($m[1], '"');
    try {
        $dt = new DateTime($value, new DateTimeZone($tz));
    } catch (Throwable $e) {
        $dt = new DateTime($value, new DateTimeZone('Asia/Seoul'));
    }
    $dt->setTimezone(new DateTimeZone('Asia/Seoul'));
    return [$dt->format('Y-m-d H:i:s'), false];
}
