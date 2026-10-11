<?php
/**
 * Shared "new event" push helper.
 *
 * Called from BOTH:
 *   - the mobile API (api/events.php, after a POST insert), and
 *   - the website (create_event.php, after its form insert),
 * so an event notifies the apps no matter where it was created.
 *
 * All functions are guarded with function_exists so this file can be included
 * alongside api/events.php (which historically defined its own copies) without
 * fatal "cannot redeclare" errors.
 *
 * Everything here is best-effort: failures are logged, never thrown, so event
 * creation is never broken by a push problem, and it silently no-ops until the
 * APNs credentials in apns_config.php are filled in.
 */

require_once __DIR__ . '/APNsPusher.php';

if (!function_exists('event_in_scope')) {
    /**
     * Decide whether an event is in scope for a given device's notification setting.
     *
     * @param bool   $isJoint       event is joint/shared (userID 0)
     * @param int    $eventUserID   owner of the event (0/1/3)
     * @param int    $deviceUserID  user logged in on the device
     * @param string $scope         'all' | 'mine' | 'joint'
     */
    function event_in_scope(bool $isJoint, int $eventUserID, int $deviceUserID, string $scope): bool {
        switch ($scope) {
            case 'all':
                return true;
            case 'joint':
                return $isJoint;
            case 'mine':
            default:
                return $isJoint || $eventUserID === $deviceUserID;
        }
    }
}

if (!function_exists('event_push_body')) {
    /** Build a human-readable "· date · location" suffix for an event. */
    function event_push_body(array $event): string {
        $body = $event['title'] ?? 'Event';
        if (!empty($event['start'])) {
            $ts = strtotime($event['start']);
            if ($ts) {
                $body .= (bool)($event['allDay'] ?? false)
                    ? ' · ' . date('D j M', $ts)
                    : ' · ' . date('D j M, g:ia', $ts);
            }
        }
        if (!empty($event['location'])) {
            $body .= ' · ' . $event['location'];
        }
        return $body;
    }
}

if (!function_exists('send_event_push')) {
    /**
     * Core sender used by the create/update/cancel wrappers.
     *
     * @param mysqli $link       open DB connection
     * @param array  $event      normalized event (id, title, start, allDay, location, userID)
     * @param string $title      alert title shown to the user
     * @param bool   $silentSync  also send a silent sync push to every device
     */
    function send_event_push(mysqli $link, array $event, string $title, bool $silentSync = true): void {
        try {
            $eventUserID = (int)($event['userID'] ?? 0); // 0 = joint, 1 = CK, 3 = DC
            $isJoint = $eventUserID === 0;

            $res = $link->query(
                "SELECT Token, Environment, UserID, NotificationScope FROM DeviceTokens"
            );
            if (!$res) return;

            $alertTokens = [];
            $allTokens = [];
            while ($r = $res->fetch_assoc()) {
                $entry = ['token' => $r['Token'], 'environment' => $r['Environment']];
                $allTokens[] = $entry;

                $deviceUserID = (int)$r['UserID'];
                $scope = $r['NotificationScope'] ?: 'mine';
                if (event_in_scope($isJoint, $eventUserID, $deviceUserID, $scope)) {
                    $alertTokens[] = $entry;
                }
            }

            if (empty($allTokens)) return;

            $pusher = new APNsPusher();
            if (!$pusher->isConfigured()) return;

            $eventID = (int)($event['id'] ?? 0);
            $body = event_push_body($event);

            // An empty $title means "silent only" (e.g. a no-op update): send
            // no visible alert, just the background sync push below.
            if ($title !== '' && !empty($alertTokens)) {
                $pusher->sendAlert($alertTokens, $title, $body, ['eventID' => $eventID]);
            }
            // Silent sync push wakes every device so each app can re-sync its
            // Apple Calendar (create/update), or remove the event (cancel — the
            // app's syncEvents prunes IDs no longer returned by the API).
            if ($silentSync) {
                $pusher->sendSilent($allTokens, ['sync' => 'events', 'eventID' => $eventID]);
            }

            foreach ($pusher->invalidTokens as $dead) {
                if ($del = $link->prepare("DELETE FROM DeviceTokens WHERE Token = ?")) {
                    $del->bind_param('s', $dead);
                    $del->execute();
                    $del->close();
                }
            }
        } catch (\Throwable $e) {
            error_log('send_event_push failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('notify_event_created')) {
    /** Push for a newly created event. */
    function notify_event_created(mysqli $link, array $event): void {
        $isJoint = (int)($event['userID'] ?? 0) === 0;
        send_event_push($link, $event, $isJoint ? 'New shared event' : 'New event');
    }
}

if (!function_exists('notify_event_updated')) {
    /** Push for a modified event. */
    function notify_event_updated(mysqli $link, array $event): void {
        $isJoint = (int)($event['userID'] ?? 0) === 0;
        send_event_push($link, $event, $isJoint ? 'Shared event updated' : 'Event updated');
    }
}

if (!function_exists('notify_event_cancelled')) {
    /**
     * Push for a cancelled/deleted event. Still sends the silent sync push so
     * apps remove it from Apple Calendar.
     */
    function notify_event_cancelled(mysqli $link, array $event): void {
        send_event_push($link, $event, 'Event cancelled');
    }
}

if (!function_exists('event_details_changed')) {
    /**
     * True if a user-meaningful field changed between two event snapshots.
     * Used to suppress notifications for no-op updates (e.g. SEQUENCE-only
     * re-syncs from iCal). Compares title, start, end, location, all-day.
     *
     * Each array may use keys: title, start, end, location, allDay.
     */
    function event_details_changed(array $before, array $after): bool {
        $norm = function (array $e): array {
            return [
                'title'    => trim((string)($e['title'] ?? '')),
                'start'    => trim((string)($e['start'] ?? '')),
                'end'      => trim((string)($e['end'] ?? '')),
                'location' => trim((string)($e['location'] ?? '')),
                'allDay'   => (int)(bool)($e['allDay'] ?? false),
            ];
        };
        return $norm($before) !== $norm($after);
    }
}
