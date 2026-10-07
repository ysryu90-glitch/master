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
    // Content-Type은 한 번만 (일정 올리기는 text/calendar)
    $hasType = (bool) array_filter($headers, fn($h) => stripos($h, 'content-type:') === 0);
    $headerLines = array_merge($hasType ? [] : ['Content-Type: application/xml; charset=utf-8'], $headers);
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
    if ($status >= 400) {
        // 원인을 알 수 있게 iCloud가 보낸 글 앞부분도 함께 (자세히에 보임)
        $detail = trim(preg_replace('/\s+/', ' ', strip_tags((string) $response)));
        throw new RuntimeException("iCloud 응답 오류 ($status, $method)" . ($detail !== '' ? ' — ' . mb_substr($detail, 0, 160) : ''), $status);
    }
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
    // 예전에 저장한 목록에 쓰기 권한 정보가 없으면 한 번 새로 받기
    if (!$refresh && is_array($cached) && $cached && array_key_exists('writable', $cached[0])) return $cached;
    if (!$refresh && is_array($cached) && $cached) {
        try { return caldav_calendars(true); } catch (Throwable $e) { return $cached; }
    }

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
        . '<d:displayname/><d:resourcetype/><a:calendar-color/><c:supported-calendar-component-set/><d:current-user-privilege-set/></d:prop></d:propfind>', [], 1);
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
        $privs = array_map(fn($x) => $x->getName(), $response->xpath('.//d:current-user-privilege-set/d:privilege/*'));
        $calendars[] = [
            'writable' => !$privs || (bool) array_intersect($privs, ['write', 'write-content', 'bind', 'all']),
            'name' => (string) ($response->xpath('.//d:displayname')[0] ?? '캘린더'),
            'url' => caldav_absolute($homeUrl, (string) $response->xpath('./d:href')[0]),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#4da3ff',
        ];
    }
    set_setting('icloud_calendars', $calendars);
    return $calendars;
}

/** 일정을 넣을 수 있는 캘린더 (보기로 고른 것 먼저) */
function caldav_writable(): array
{
    $all = array_values(array_filter(caldav_calendars(), fn($c) => $c['writable'] ?? true));
    $sel = setting('icloud_selected', []);
    usort($all, fn($a, $b) => (int) !in_array($a['name'], (array) $sel, true) <=> (int) !in_array($b['name'], (array) $sel, true));
    return $all;
}

/** 보여줄 캘린더 (설정에서 고른 것, 없으면 전부) */
function caldav_selected(): array
{
    $all = caldav_calendars();
    $names = setting('icloud_selected', []);
    if (!$names) return $all;
    return array_values(array_filter($all, fn($c) => in_array($c['name'], $names, true)));
}

/** 일정 복사본 새로 받기 (지난 7일 ~ 앞으로 45일)
 *  반복 일정은 iCloud에 펼쳐 달라고(expand) 부탁하고, iCloud가 거절하면(501 등) 그냥 받아서 여기서 펼친다.
 *  캘린더 하나가 실패해도 나머지는 받는다. */
function caldav_sync(): int
{
    $fromTs = strtotime('-7 day');
    $toTs = strtotime('+45 day');
    $start = gmdate('Ymd\THis\Z', $fromTs);
    $end = gmdate('Ymd\THis\Z', $toTs);
    $filter = '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
        . '<c:time-range start="' . $start . '" end="' . $end . '"/></c:comp-filter></c:comp-filter></c:filter>';
    $withExpand = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/>'
        . '<c:calendar-data><c:expand start="' . $start . '" end="' . $end . '"/></c:calendar-data></d:prop>' . $filter . '</c:calendar-query>';
    $plain = '<c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/>'
        . '<c:calendar-data/></d:prop>' . $filter . '</c:calendar-query>';
    $noExpand = (bool) setting('caldav_no_expand', false); // 한 번 거절당하면 다음부터는 바로 그냥 받기

    $events = [];
    $failed = [];
    $calendars = caldav_selected();
    foreach ($calendars as $cal) {
        try {
            $expanded = false;
            $xml = null;
            if (!$noExpand) {
                try {
                    [, $xml] = caldav_request('REPORT', $cal['url'], $withExpand, [], 1);
                    $expanded = true;
                } catch (RuntimeException $e) {
                    if ($e->getCode() === 401 || $e->getCode() === 0) throw $e; // 로그인 · 연결 문제는 그대로
                    $noExpand = true;
                    set_setting('caldav_no_expand', true);
                }
            }
            if ($xml === null) [, $xml] = caldav_request('REPORT', $cal['url'], $plain, [], 1);
            $doc = caldav_xml($xml);
            foreach ($doc->xpath('//d:response') as $resp) {
                $resp->registerXPathNamespace('d', 'DAV:');
                $resp->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
                $href = caldav_absolute($cal['url'], (string) ($resp->xpath('./d:href')[0] ?? ''));
                foreach ($resp->xpath('.//c:calendar-data') as $data) {
                    foreach (ics_events((string) $data, $expanded ? null : [$fromTs, $toTs]) as $event) {
                        $event['calendar'] = $cal['name'];
                        $event['color'] = $cal['color'];
                        $event['href'] = $href;
                        $events[] = $event;
                    }
                }
            }
        } catch (RuntimeException $e) {
            if ($e->getCode() === 401) throw $e;
            $failed[] = $cal['name'] . ': ' . $e->getMessage();
        }
    }
    if ($calendars && count($failed) === count($calendars)) throw new RuntimeException(implode(' / ', $failed));

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM calendar_events');
    $insert = $pdo->prepare('REPLACE INTO calendar_events (uid, start_at, end_at, all_day, title, location, calendar, color, href, recurring, occ, note, fetched_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    foreach ($events as $e) {
        $insert->execute([mb_substr($e['uid'], 0, 255), $e['start'], $e['end'], $e['all_day'] ? 1 : 0,
            mb_substr($e['title'], 0, 300), mb_substr($e['location'], 0, 300), $e['calendar'], $e['color'],
            mb_substr($e['href'] ?? '', 0, 500), !empty($e['recurring']) ? 1 : 0, $e['occ'] ?? '', mb_substr($e['note'] ?? '', 0, 500)]);
    }
    $pdo->commit();
    set_setting('calendar_synced_at', date('Y-m-d H:i:s'));
    set_setting('calendar_error', $failed ? '일부 캘린더를 받지 못했어요 — ' . implode(' / ', $failed) : '');
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
    if (!$cal) foreach (caldav_writable() as $c) { $cal = $c; break; } // 이름이 바뀌었으면 쓸 수 있는 첫 캘린더
    if (!$cal) throw new RuntimeException('일정을 넣을 수 있는 iCloud 캘린더를 찾지 못했어요.');
    if (isset($cal['writable']) && !$cal['writable']) throw new RuntimeException("「{$cal['name']}」 캘린더는 iCloud에서 읽기 전용이라 일정을 넣을 수 없어요. 다른 캘린더를 골라 주세요.");

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

    caldav_request('PUT', rtrim($cal['url'], '/') . '/' . strtolower(strtok($uid, '@')) . '.ics', implode("\r\n", $lines) . "\r\n",
        ['Content-Type: text/calendar; charset=utf-8', 'If-None-Match: *']);
    set_setting('calendar_synced_at', ''); // 다음 화면에서 바로 다시 받기
    set_setting('calendar_last_add', $cal['name']);
}

/** iCalendar 글에서 일정 꺼내기.
 *  $window = [시작 ts, 끝 ts] 를 주면 반복 일정(RRULE)을 그 기간 안에서 여기서 펼친다 (iCloud가 expand를 거절할 때). */
function ics_events(string $ics, ?array $window = null): array
{
    $ics = preg_replace("/\r?\n[ \t]/", '', $ics); // 접힌 줄 펴기
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $blocks)) return [];
    $unescape = fn($s) => str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s);
    $parsed = [];
    $overrides = []; // UID => [원래 시작 ts, ...] (따로 고친 회차)
    foreach ($blocks[1] as $block) {
        $props = [];
        $multi = [];
        foreach (preg_split("/\r?\n/", trim($block)) as $line) {
            if (!preg_match('/^([A-Z-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) continue;
            $props[$m[1]] ??= ['params' => $m[2], 'value' => $m[3]];
            if ($m[1] === 'EXDATE') $multi['EXDATE'][] = ['params' => $m[2], 'value' => $m[3]];
        }
        if (!isset($props['DTSTART'])) continue;
        $uid = $props['UID']['value'] ?? md5($block);
        if (isset($props['RECURRENCE-ID'])) $overrides[$uid][] = strtotime(ics_time($props['RECURRENCE-ID'])[0]);
        $parsed[] = [$props, $multi, $uid];
    }
    $events = [];
    foreach ($parsed as [$props, $multi, $uid]) {
        if (isset($props['STATUS']) && strtoupper($props['STATUS']['value']) === 'CANCELLED') continue;
        [$start, $allDay] = ics_time($props['DTSTART']);
        $end = isset($props['DTEND']) ? ics_time($props['DTEND'])[0] : ($allDay ? date('Y-m-d H:i:s', strtotime("$start +1 day")) : $start);
        $base = [
            'all_day' => $allDay,
            'title' => $unescape($props['SUMMARY']['value'] ?? '(제목 없음)'),
            'location' => $unescape($props['LOCATION']['value'] ?? ''),
            'note' => $unescape($props['DESCRIPTION']['value'] ?? ''),
            'recurring' => isset($props['RRULE']) || isset($props['RECURRENCE-ID']),
        ];
        $rid = isset($props['RECURRENCE-ID']) ? '#' . $props['RECURRENCE-ID']['value'] : '';
        if ($window === null || !isset($props['RRULE']) || $rid !== '') {
            $events[] = $base + ['uid' => $uid . $rid, 'start' => $start, 'end' => $end, 'occ' => isset($props['RECURRENCE-ID']) ? ics_time($props['RECURRENCE-ID'])[0] : ''];
            continue;
        }
        // 여기서 반복 펼치기
        $skip = $overrides[$uid] ?? [];
        foreach ($multi['EXDATE'] ?? [] as $ex) {
            foreach (explode(',', $ex['value']) as $v) $skip[] = strtotime(ics_time(['params' => $ex['params'], 'value' => $v])[0]);
        }
        $len = strtotime($end) - strtotime($start);
        foreach (rrule_occurrences(strtotime($start), $props['RRULE']['value'], $window[0] - max($len, 86400), $window[1]) as $ts) {
            if (in_array($ts, $skip, true)) continue;
            if ($ts + $len < $window[0]) continue;
            $events[] = $base + ['uid' => $uid . '#' . date('Ymd\THis', $ts), 'start' => date('Y-m-d H:i:s', $ts), 'end' => date('Y-m-d H:i:s', $ts + $len), 'occ' => date('Y-m-d H:i:s', $ts)];
        }
    }
    return $events;
}

/** RRULE 펼치기: 첫 시작 ts부터 [from, to] 사이 회차 시작 ts 목록 (매일 · 매주(요일) · 매달(날짜 · n번째 요일) · 매년) */
function rrule_occurrences(int $dtstart, string $rule, int $from, int $to): array
{
    $r = [];
    foreach (explode(';', $rule) as $part) if (str_contains($part, '=')) { [$k, $v] = explode('=', $part, 2); $r[strtoupper($k)] = strtoupper($v); }
    $freq = $r['FREQ'] ?? '';
    if (!in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) return $dtstart >= $from && $dtstart <= $to ? [$dtstart] : [];
    $interval = max(1, (int) ($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int) $r['COUNT'] : null;
    $until = isset($r['UNTIL']) ? strtotime(ics_time(['params' => '', 'value' => $r['UNTIL']])[0]) : null;
    if ($until !== null && strlen($r['UNTIL']) === 8) $until += 86399; // 날짜만이면 그날 끝까지
    $days = ['SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6];
    $byday = isset($r['BYDAY']) ? explode(',', $r['BYDAY']) : [];
    $bymonthday = isset($r['BYMONTHDAY']) ? array_map('intval', explode(',', $r['BYMONTHDAY'])) : [];
    $time = date('H:i:s', $dtstart);
    $out = [];
    $n = 0;
    $limit = 2000;
    $add = function (int $ts) use (&$out, &$n, $from, $to, $count, $until, $dtstart): bool {
        if ($ts < $dtstart) return true;
        if ($until !== null && $ts > $until) return false;
        $n++;
        if ($count !== null && $n > $count) return false;
        if ($ts > $to) return false;
        if ($ts >= $from) $out[] = $ts;
        return true;
    };
    $cursor = $dtstart;
    for ($i = 0; $i < $limit; $i++) {
        $cands = [];
        if ($freq === 'DAILY') {
            $cands[] = $cursor;
        } elseif ($freq === 'WEEKLY') {
            if (!$byday) $cands[] = $cursor;
            else {
                $weekStart = strtotime('-' . (int) date('w', $cursor) . ' day', strtotime(date('Y-m-d', $cursor)));
                foreach ($byday as $d) if (isset($days[substr($d, -2)])) $cands[] = strtotime(date('Y-m-d', strtotime('+' . $days[substr($d, -2)] . ' day', $weekStart)) . ' ' . $time);
                sort($cands);
            }
        } elseif ($freq === 'MONTHLY') {
            $ym = date('Y-m', $cursor);
            if ($byday) {
                foreach ($byday as $d) {
                    if (!preg_match('/^([+-]?\d)?(SU|MO|TU|WE|TH|FR|SA)$/', $d, $m)) continue;
                    $nth = (int) ($m[1] ?? 0) ?: 1;
                    $names = ['SU' => 'sunday', 'MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday', 'TH' => 'thursday', 'FR' => 'friday', 'SA' => 'saturday'];
                    $ts = $nth > 0 ? strtotime(['', 'first', 'second', 'third', 'fourth', 'fifth'][min($nth, 5)] . ' ' . $names[$m[2]] . ' of ' . $ym) : strtotime('last ' . $names[$m[2]] . ' of ' . $ym);
                    if ($ts && date('Y-m', $ts) === $ym) $cands[] = strtotime(date('Y-m-d', $ts) . ' ' . $time);
                }
            } else {
                foreach ($bymonthday ?: [(int) date('j', $dtstart)] as $md) {
                    $last = (int) date('t', strtotime($ym . '-01'));
                    $day = $md < 0 ? $last + $md + 1 : $md;
                    if ($day >= 1 && $day <= $last) $cands[] = strtotime(sprintf('%s-%02d %s', $ym, $day, $time));
                }
            }
            sort($cands);
        } else { // YEARLY
            $md = date('m-d', $dtstart);
            $y = date('Y', $cursor);
            if (checkdate((int) substr($md, 0, 2), (int) substr($md, 3), (int) $y)) $cands[] = strtotime("$y-$md $time");
        }
        foreach ($cands as $ts) if (!$add($ts)) return $out;
        $step = ['DAILY' => 'day', 'WEEKLY' => 'week', 'MONTHLY' => 'month', 'YEARLY' => 'year'][$freq];
        $cursor = $freq === 'MONTHLY' ? strtotime(date('Y-m-01', $cursor) . " +$interval month " . $time) : strtotime("+$interval $step", $cursor);
        if ($cursor > $to + 86400 * 400) break;
    }
    return $out;
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

// ───────── 일정 고치기 · 지우기 ─────────

function ics_escape(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
}

/** 우리가 받아 둔 일정 한 줄 (uid + 시작) */
function calendar_event_row(string $uid, string $start): ?array
{
    $stmt = db()->prepare('SELECT * FROM calendar_events WHERE uid = ? AND start_at = ?');
    $stmt->execute([$uid, $start]);
    return $stmt->fetch() ?: null;
}

/** 일정 파일을 받아 줄 목록으로 (접힌 줄은 펴서) */
function caldav_get_lines(string $href): array
{
    [, $ics] = caldav_request('GET', $href, '', ['Accept: text/calendar']);
    $ics = preg_replace("/\r?\n[ \t]/", '', (string) $ics);
    $lines = preg_split("/\r?\n/", trim($ics));
    if (!$lines || !in_array('BEGIN:VCALENDAR', $lines, true)) throw new RuntimeException('iCloud에서 일정 내용을 읽지 못했어요.');
    return $lines;
}

/** VEVENT 블록 위치 [[시작 줄, 끝 줄], ...] */
function ics_blocks(array $lines): array
{
    $out = [];
    $start = null;
    foreach ($lines as $i => $l) {
        if ($l === 'BEGIN:VEVENT') $start = $i;
        if ($l === 'END:VEVENT' && $start !== null) { $out[] = [$start, $i]; $start = null; }
    }
    return $out;
}

function ics_prop_name(string $line): string
{
    return preg_match('/^([A-Z-]+)[;:]/', $line, $m) ? $m[1] : '';
}

/** 블록 안의 속성들을 바꾸기: $set = [이름 => 새 줄(들) | null(지우기)] */
function ics_block_set(array $block, array $set): array
{
    $out = [];
    $depth = 0; // 알람(VALARM) 같은 안쪽 묶음은 건드리지 않기
    foreach ($block as $i => $l) {
        if ($i > 0 && str_starts_with($l, 'BEGIN:')) $depth++;
        $inner = $depth > 0;
        if ($i > 0 && str_starts_with($l, 'END:') && $l !== 'END:VEVENT') $depth--;
        if (!$inner && array_key_exists(ics_prop_name($l), $set)) continue;
        if ($l === 'END:VEVENT') foreach ($set as $lines) foreach ((array) $lines as $nl) if ($nl !== null) $out[] = $nl;
        $out[] = $l;
    }
    return $out;
}

function ics_put(string $href, array $lines): void
{
    caldav_request('PUT', $href, implode("\r\n", $lines) . "\r\n", ['Content-Type: text/calendar; charset=utf-8']);
    set_setting('calendar_synced_at', '');
}

/** 일정 고치기. 반복 일정은 제목 · 장소 · 메모만 (반복 전체에) */
function caldav_update_event(array $ev, array $f): void
{
    if ($ev['href'] === '') throw new RuntimeException('이 일정은 고칠 수 있는 정보가 아직 없어요. 「지금 새로 받기」를 누른 뒤 다시 해 주세요.');
    $lines = caldav_get_lines($ev['href']);
    $baseUid = strtok($ev['uid'], '#');
    $now = gmdate('Ymd\THis\Z');
    $common = [
        'SUMMARY' => 'SUMMARY:' . ics_escape($f['title']),
        'LOCATION' => $f['location'] !== '' ? 'LOCATION:' . ics_escape($f['location']) : null,
        'DESCRIPTION' => $f['note'] !== '' ? 'DESCRIPTION:' . ics_escape($f['note']) : null,
        'DTSTAMP' => 'DTSTAMP:' . $now,
        'LAST-MODIFIED' => 'LAST-MODIFIED:' . $now,
    ];
    $out = [];
    $cursor = 0;
    foreach (ics_blocks($lines) as [$a, $b]) {
        $block = array_slice($lines, $a, $b - $a + 1);
        $out = array_merge($out, array_slice($lines, $cursor, $a - $cursor));
        $uidLine = current(array_filter($block, fn($l) => str_starts_with($l, 'UID:')));
        if ($uidLine !== false && substr($uidLine, 4) !== $baseUid) { $out = array_merge($out, $block); $cursor = $b + 1; continue; }
        $set = $common;
        $seq = 0;
        foreach ($block as $l) if (str_starts_with($l, 'SEQUENCE:')) $seq = (int) substr($l, 9);
        $set['SEQUENCE'] = 'SEQUENCE:' . ($seq + 1);
        if (!$ev['recurring']) {
            if ($f['all_day']) {
                $set['DTSTART'] = 'DTSTART;VALUE=DATE:' . date('Ymd', strtotime($f['date']));
                $set['DTEND'] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($f['date'] . ' +1 day'));
            } else {
                $st = strtotime($f['date'] . ' ' . $f['start']);
                $en = $f['end'] !== '' ? strtotime($f['date'] . ' ' . $f['end']) : $st + 3600;
                if ($en <= $st) $en = $st + 3600;
                $set['DTSTART'] = 'DTSTART:' . gmdate('Ymd\THis\Z', $st);
                $set['DTEND'] = 'DTEND:' . gmdate('Ymd\THis\Z', $en);
            }
            $set['DURATION'] = null;
        }
        $out = array_merge($out, ics_block_set($block, $set));
        $cursor = $b + 1;
    }
    $out = array_merge($out, array_slice($lines, $cursor));
    ics_put($ev['href'], $out);
}

/** 일정 지우기. $only = 반복 일정에서 이 날만 */
function caldav_delete_event(array $ev, bool $only = false): void
{
    if ($ev['href'] === '') throw new RuntimeException('이 일정은 지울 수 있는 정보가 아직 없어요. 「지금 새로 받기」를 누른 뒤 다시 해 주세요.');
    if (!$ev['recurring'] || !$only) {
        caldav_request('DELETE', $ev['href'], '', []);
        set_setting('calendar_synced_at', '');
        return;
    }
    // 반복 중 이 날만: 원래 일정에 EXDATE 더하고, 따로 고친 회차가 있으면 그것도 빼기
    $occ = $ev['occ'] !== '' ? $ev['occ'] : $ev['start_at'];
    $lines = caldav_get_lines($ev['href']);
    $out = [];
    $cursor = 0;
    foreach (ics_blocks($lines) as [$a, $b]) {
        $block = array_slice($lines, $a, $b - $a + 1);
        $out = array_merge($out, array_slice($lines, $cursor, $a - $cursor));
        $cursor = $b + 1;
        $rid = current(array_filter($block, fn($l) => ics_prop_name($l) === 'RECURRENCE-ID'));
        if ($rid !== false) {
            [$p, $v] = explode(':', $rid, 2) + [1 => ''];
            if (ics_time(['params' => substr($p, strlen('RECURRENCE-ID')), 'value' => $v])[0] === $occ) continue; // 이 회차의 따로 고친 일정 빼기
            $out = array_merge($out, $block);
            continue;
        }
        $dt = current(array_filter($block, fn($l) => ics_prop_name($l) === 'DTSTART'));
        $hasRule = (bool) array_filter($block, fn($l) => ics_prop_name($l) === 'RRULE');
        if ($dt === false || !$hasRule) { $out = array_merge($out, $block); continue; }
        [$params] = explode(':', $dt, 2);
        $params = substr($params, strlen('DTSTART'));
        $ts = strtotime($occ);
        if (str_contains($params, 'VALUE=DATE')) $ex = 'EXDATE;VALUE=DATE:' . date('Ymd', $ts);
        elseif (preg_match('/TZID=([^;:]+)/', $params, $m)) {
            try { $d = new DateTime('@' . $ts); $d->setTimezone(new DateTimeZone(trim($m[1], '"'))); $ex = 'EXDATE;TZID=' . $m[1] . ':' . $d->format('Ymd\THis'); }
            catch (Throwable $e) { $ex = 'EXDATE:' . gmdate('Ymd\THis\Z', $ts); }
        } else $ex = 'EXDATE:' . gmdate('Ymd\THis\Z', $ts);
        $block = array_merge(array_slice($block, 0, -1), [$ex, 'END:VEVENT']);
        $out = array_merge($out, $block);
    }
    $out = array_merge($out, array_slice($lines, $cursor));
    ics_put($ev['href'], $out);
}
