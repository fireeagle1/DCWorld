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

if (!function_exists('notify_event_created')) {
    /**
     * Send alert + silent pushes for a newly created event.
     *
     * @param mysqli $link   open DB connection
     * @param array  $event  normalized event: id, title, start, allDay, location, userID
     */
    function notify_event_created(mysqli $link, array $event): void {
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

            // Friendly notification body.
            $title = $isJoint ? 'New shared event' : 'New event';
            $body  = $event['title'] ?? 'A new event was added';
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

            $eventID = (int)($event['id'] ?? 0);

            if (!empty($alertTokens)) {
                $pusher->sendAlert($alertTokens, $title, $body, ['eventID' => $eventID]);
            }
            // Silent sync push to every device; the app applies its own sync scope.
            $pusher->sendSilent($allTokens, ['sync' => 'events', 'eventID' => $eventID]);

            // Prune tokens APNs reported as permanently invalid.
            foreach ($pusher->invalidTokens as $dead) {
                if ($del = $link->prepare("DELETE FROM DeviceTokens WHERE Token = ?")) {
                    $del->bind_param('s', $dead);
                    $del->execute();
                    $del->close();
                }
            }
        } catch (\Throwable $e) {
            error_log('notify_event_created failed: ' . $e->getMessage());
        }
    }
}
