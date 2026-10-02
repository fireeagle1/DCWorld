<?php
/**
 * calendar_ics.php - iCalendar (.ics) feed of calendar events.
 *
 * Designed to be subscribed to from an iPhone (Settings > Calendar >
 * Accounts > Add Account > Other > Add Subscribed Calendar), or opened
 * directly to download a one-off .ics file.
 *
 * Auth: either an active logged-in session, OR a valid token passed as
 * ?token=... (reuses the site MAGIC_KEY). A token is required for the
 * subscription use-case because iOS fetches the URL with no session.
 *
 * Optional filters (all default ON) mirror the calendar legend pills:
 *   ?work=0&night=0&events=0&duty=0&oncall=0&bookings=0&birthdays=0
 *
 * Window: by default spans ~1 year back to ~2 years forward so that
 * recurring subscription refreshes keep upcoming events in view.
 */

session_start();
require 'config.php';

$link->set_charset('utf8mb4');

/* ---------- auth: session OR token ---------- */
$token = (string)($_GET['token'] ?? '');
$hasSession = isset($_SESSION['userID']);
$tokenValid = ($token !== '' && hash_equals(MAGIC_KEY, $token));

if (!$hasSession && !$tokenValid) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden: a valid token is required to access this calendar feed.';
    exit;
}

$userID = $_SESSION['userID'] ?? 0;

/* ---------- which sources to include (default all on) ---------- */
function want($key) {
    return !(isset($_GET[$key]) && $_GET[$key] === '0');
}
$incWork      = want('work');
$incNight     = want('night');
$incEvents    = want('events');
$incDuty      = want('duty');
$incOnCall    = want('oncall');
$incBookings  = want('bookings');
$incBirthdays = want('birthdays');

/* ---------- window ---------- */
$startDt = date('Y-m-d 00:00:00', strtotime('-1 year'));
$endDt   = date('Y-m-d 23:59:59', strtotime('+2 years'));
$startTs = strtotime($startDt);
$endTs   = strtotime($endDt);

/* ---------- ICS helpers ---------- */

/** Escape text per RFC 5545 (commas, semicolons, backslashes, newlines). */
function ics_escape($text) {
    $text = (string)$text;
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(["\r\n", "\r", "\n"], '\\n', $text);
    $text = str_replace(',', '\\,', $text);
    $text = str_replace(';', '\\;', $text);
    return $text;
}

/** Fold long lines to 75 octets per RFC 5545. */
function ics_fold($line) {
    if (strlen($line) <= 75) {
        return $line;
    }
    $out = '';
    $len = strlen($line);
    $pos = 0;
    // First chunk up to 75 chars, subsequent chunks up to 74 (space prefix).
    $first = substr($line, 0, 75);
    $out .= $first;
    $pos = 75;
    while ($pos < $len) {
        $chunk = substr($line, $pos, 74);
        $out .= "\r\n " . $chunk;
        $pos += 74;
    }
    return $out;
}

/** Build a stable UID. */
function ics_uid($suffix) {
    $host = $_SERVER['HTTP_HOST'] ?? 'thedash.local';
    return $suffix . '@' . $host;
}

$lines = [];
function emit($line) {
    global $lines;
    $lines[] = ics_fold($line);
}

/**
 * Add one VEVENT.
 * $start / $end are PHP timestamps (or null for $end).
 * $allDay toggles DATE vs DATE-TIME formatting.
 */
function add_vevent($uidSuffix, $title, $startTs, $endTs, $allDay, $location = '', $description = '', $url = '') {
    $dtstamp = gmdate('Ymd\THis\Z');
    emit('BEGIN:VEVENT');
    emit('UID:' . ics_uid($uidSuffix));
    emit('DTSTAMP:' . $dtstamp);

    if ($allDay) {
        // All-day: DATE value, DTEND is exclusive (next day).
        emit('DTSTART;VALUE=DATE:' . gmdate('Ymd', $startTs));
        $endExclusive = $endTs ? $endTs : $startTs;
        // Ensure end is at least the day after start for a single-day event.
        $endDay = strtotime('+1 day', $endExclusive);
        emit('DTEND;VALUE=DATE:' . gmdate('Ymd', $endDay));
    } else {
        // Timed: use UTC Z form.
        emit('DTSTART:' . gmdate('Ymd\THis\Z', $startTs));
        if ($endTs) {
            emit('DTEND:' . gmdate('Ymd\THis\Z', $endTs));
        }
    }

    emit('SUMMARY:' . ics_escape($title));
    if ($location !== '' && $location !== null) {
        emit('LOCATION:' . ics_escape($location));
    }
    $descParts = [];
    if ($description !== '' && $description !== null) $descParts[] = $description;
    if ($url !== '' && $url !== null) {
        emit('URL:' . ics_escape($url));
        $descParts[] = $url;
    }
    if ($descParts) {
        emit('DESCRIPTION:' . ics_escape(implode("\n", $descParts)));
    }
    emit('END:VEVENT');
}

/** Absolute base URL for building links back into the site. */
function site_base() {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir;
}
$BASE = site_base();

/* ---------- collect events ---------- */

/* Events + DutySheet (mirror of fetch_events.php) */
if ($incEvents || $incDuty) {
    $sql = "SELECT EventID, EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source
            FROM Events
            WHERE StartDateTime <= ? AND (EndDateTime IS NULL OR EndDateTime >= ?)";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('ss', $endDt, $startDt);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $src = trim((string)($row['Source'] ?? ''));
        $isDuty = (strcasecmp($src, 'DutySheet') === 0);
        if ($isDuty && !$incDuty) continue;
        if (!$isDuty && !$incEvents) continue;

        $title = (string)$row['EventTitle'];
        if ($isDuty) $title = '[Duty] ' . $title;

        $sTs = strtotime($row['StartDateTime']);
        $eTs = $row['EndDateTime'] ? strtotime($row['EndDateTime']) : null;
        $allDay = ((int)$row['AllDay'] === 1);

        add_vevent(
            'event-' . (int)$row['EventID'],
            $title,
            $sTs,
            $eTs,
            $allDay,
            (string)($row['Location'] ?? ''),
            '',
            $BASE . '/view_event.php?eventId=' . (int)$row['EventID']
        );
    }
    $stmt->close();
}

/* On-call (mirror of fetch_events.php) */
if ($incOnCall) {
    $sql = "SELECT Date, CKOnCall, DCOnCall
            FROM DCDailyOpsPlan
            WHERE Date BETWEEN ? AND ?
            AND (CKOnCall=1 OR DCOnCall=1)";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $parts = [];
        if ((int)$row['CKOnCall'] === 1) $parts[] = 'CK On Call';
        if ((int)$row['DCOnCall'] === 1) $parts[] = 'DC On Call';
        $ts = strtotime($row['Date']);
        add_vevent(
            'oncall-' . $row['Date'],
            'On Call: ' . implode(' / ', $parts),
            $ts,
            null,
            true
        );
    }
    $stmt->close();
}

/* Night locations (mirror of fetch_events.php) */
if ($incNight) {
    $sql = "SELECT plan.Date, plan.CKLocation, plan.DCLocation,
                   loc.LocationDesc  AS CKLocationDesc,
                   loc2.LocationDesc AS DCLocationDesc
            FROM DCDailyOpsPlan AS plan
            LEFT JOIN DC_Locations AS loc  ON plan.CKLocation = loc.LocationID
            LEFT JOIN DC_Locations AS loc2 ON plan.DCLocation = loc2.LocationID
            WHERE plan.Date BETWEEN ? AND ?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $res = $stmt->get_result();
    $excluded = [4, 6, 7, 10];
    while ($row = $res->fetch_assoc()) {
        $ckDesc = trim((string)$row['CKLocationDesc']);
        $dcDesc = trim((string)$row['DCLocationDesc']);
        $ckValid = !in_array((int)$row['CKLocation'], $excluded, true) && $ckDesc !== '';
        $dcValid = !in_array((int)$row['DCLocation'], $excluded, true) && $dcDesc !== '';
        $ts = strtotime($row['Date']);
        if ($ckValid && $dcValid && $ckDesc === $dcDesc) {
            add_vevent('night-' . $row['Date'] . '-both', $ckDesc, $ts, null, true);
        } else {
            if ($ckValid) add_vevent('night-' . $row['Date'] . '-ck', 'CK: ' . $ckDesc, $ts, null, true);
            if ($dcValid) add_vevent('night-' . $row['Date'] . '-dc', 'DC: ' . $dcDesc, $ts, null, true);
        }
    }
    $stmt->close();
}

/* Work locations (mirror of fetch_events.php) */
if ($incWork) {
    $sql = "SELECT plan.Date, plan.CKWorkLocation, plan.DCWorkLocation,
                   w.WLocationDesc  AS CKWorkLocationDesc,
                   w2.WLocationDesc AS DCWorkLocationDesc
            FROM DCDailyOpsPlan AS plan
            LEFT JOIN DC_WorkLocation AS w  ON plan.CKWorkLocation = w.WorkID
            LEFT JOIN DC_WorkLocation AS w2 ON plan.DCWorkLocation = w2.WorkID
            WHERE plan.Date BETWEEN ? AND ?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('ss', $startDt, $endDt);
    $stmt->execute();
    $res = $stmt->get_result();
    $excluded = [4, 6, 7];
    while ($row = $res->fetch_assoc()) {
        $ckDesc = trim((string)$row['CKWorkLocationDesc']);
        $dcDesc = trim((string)$row['DCWorkLocationDesc']);
        $ckValid = !in_array((int)$row['CKWorkLocation'], $excluded, true) && $ckDesc !== '';
        $dcValid = !in_array((int)$row['DCWorkLocation'], $excluded, true) && $dcDesc !== '';
        $ts = strtotime($row['Date']);
        if ($ckValid && $dcValid && $ckDesc === $dcDesc) {
            add_vevent('work-' . $row['Date'] . '-both', $ckDesc, $ts, null, true);
        } else {
            if ($ckValid) add_vevent('work-' . $row['Date'] . '-ck', 'CK: ' . $ckDesc, $ts, null, true);
            if ($dcValid) add_vevent('work-' . $row['Date'] . '-dc', 'DC: ' . $dcDesc, $ts, null, true);
        }
    }
    $stmt->close();
}

/* Bookings (mirror of fetch_bookings.php) */
if ($incBookings) {
    $sql = "SELECT BookingID, StartDateTime, EndDateTime, GuestsJSON, Occasion, Status
            FROM Bookings
            WHERE StartDateTime <= ? AND EndDateTime >= ?
            ORDER BY StartDateTime";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('ss', $endDt, $startDt);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $allGuestIds = [];
    while ($b = $res->fetch_assoc()) {
        $rows[] = $b;
        $decoded = json_decode($b['GuestsJSON'] ?? '[]', true);
        $guestIDs = array_filter($decoded['guests'] ?? []);
        foreach ($guestIDs as $gid) {
            $allGuestIds[(int)$gid] = true;
        }
    }
    $stmt->close();

    $namesById = [];
    if ($allGuestIds) {
        $in = implode(',', array_map('intval', array_keys($allGuestIds)));
        $q = $link->query("SELECT ContactID, KnownAs FROM Contacts WHERE ContactID IN ($in)");
        if ($q) {
            while ($n = $q->fetch_assoc()) {
                $namesById[(int)$n['ContactID']] = $n['KnownAs'];
            }
        }
    }

    foreach ($rows as $b) {
        $status = strtolower(trim($b['Status'] ?? ''));
        $statusLabel = ($status === 'pencilled' || $status === 'penciled') ? ' (pencilled)'
                     : (($status === 'confirmed') ? ' (confirmed)' : '');
        $decoded = json_decode($b['GuestsJSON'] ?? '[]', true);
        $guestIDs = array_filter($decoded['guests'] ?? []);
        $names = [];
        foreach ($guestIDs as $gid) {
            if (isset($namesById[(int)$gid])) $names[] = $namesById[(int)$gid];
        }
        $titleBase = $names ? implode(', ', $names) : ('Booking #' . $b['BookingID']);
        $title = 'Booking: ' . $titleBase . $statusLabel;

        add_vevent(
            'booking-' . (int)$b['BookingID'],
            $title,
            strtotime($b['StartDateTime']),
            strtotime($b['EndDateTime']),
            false,
            '',
            (string)($b['Occasion'] ?? ''),
            $BASE . '/home/view_booking.php?BookingID=' . (int)$b['BookingID']
        );
    }
}

/* Birthdays (mirror of fetch_birthdays.php) */
if ($incBirthdays) {
    $sql = "SELECT ContactID, KnownAs, FirstName, LastName, DOB
            FROM Contacts
            WHERE DOB IS NOT NULL AND DOB <> '0000-00-00'";
    $res = $link->query($sql);
    $yearStart = (int)date('Y', $startTs);
    $yearEnd   = (int)date('Y', $endTs);

    if ($res) {
        while ($c = $res->fetch_assoc()) {
            $dob = (string)$c['DOB'];
            if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $dob, $m)) continue;
            $mm = (int)$m[1];
            $dd = (int)$m[2];
            if (!checkdate($mm, $dd, 2000)) continue;

            $name = trim((string)$c['KnownAs']);
            if ($name === '') {
                $name = trim(trim((string)$c['FirstName']) . ' ' . trim((string)$c['LastName']));
                if ($name === '') $name = 'Unknown';
            }

            for ($y = $yearStart; $y <= $yearEnd; $y++) {
                $mdY = sprintf('%04d-%02d-%02d', $y, $mm, $dd);
                if ($mm === 2 && $dd === 29 && !checkdate(2, 29, $y)) {
                    $mdY = sprintf('%04d-02-28', $y);
                }
                $ts = strtotime($mdY);
                if ($ts < $startTs || $ts > $endTs) continue;

                add_vevent(
                    'bday-' . (int)$c['ContactID'] . '-' . $y,
                    'Birthday: ' . $name,
                    $ts,
                    null,
                    true
                );
            }
        }
    }
}

$link->close();

/* ---------- output ---------- */
$filename = 'the-dash-calendar.ics';
if (isset($_GET['download'])) {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
}
header('Cache-Control: no-cache, must-revalidate');

$out = [];
$out[] = 'BEGIN:VCALENDAR';
$out[] = 'VERSION:2.0';
$out[] = 'PRODID:-//The Dash//Calendar Feed//EN';
$out[] = 'CALSCALE:GREGORIAN';
$out[] = 'METHOD:PUBLISH';
$out[] = ics_fold('X-WR-CALNAME:The Dash');
$out[] = 'X-WR-TIMEZONE:Europe/London';
$out[] = 'X-PUBLISHED-TTL:PT6H';
$out[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT6H';

$out = array_merge($out, $lines);
$out[] = 'END:VCALENDAR';

echo implode("\r\n", $out) . "\r\n";
