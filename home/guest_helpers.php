<?php
/*  guest_helpers.php — shared helpers for the Guest Manager module
    ----------------------------------------------------------------
    Pure functions used by guest_manager.php (page) and guest_feeds.php
    (JSON endpoints). No output, no side effects — safe to include from
    anywhere in the module.
    ---------------------------------------------------------------- */

declare(strict_types=1);

/**
 * Escape a string for safe HTML output.
 */
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Canonical status vocabulary for bookings.
 *
 * Statuses are matched case-insensitively and both British/American
 * spellings of "pencilled" are accepted, mirroring fetch_bookings.php.
 * Anything unrecognised falls back to the "other" bucket.
 */
function gm_status_key(?string $status): string
{
    $s = strtolower(trim((string)$status));
    return match ($s) {
        'confirmed'              => 'confirmed',
        'tentative'              => 'tentative',
        'cancelled', 'canceled'  => 'cancelled',
        'pencilled', 'penciled'  => 'pencilled',
        default                  => 'other',
    };
}

/**
 * Calendar event colour for a status.
 */
function gm_status_color(?string $status): string
{
    return match (gm_status_key($status)) {
        'confirmed' => '#28a745',
        'tentative' => '#f0ad4e',
        'cancelled' => '#dc3545',
        'pencilled' => '#6f42c1',
        default     => '#6c757d',
    };
}

/**
 * Bootstrap/utility chip class for a status (sidebar cards).
 */
function gm_status_chip_class(?string $status): string
{
    return match (gm_status_key($status)) {
        'confirmed' => 'chip-status-confirmed',
        'tentative' => 'chip-status-tentative',
        'cancelled' => 'chip-status-cancelled',
        'pencilled' => 'chip-status-pencilled',
        default     => 'bg-secondary text-white',
    };
}

/**
 * Human-friendly status label (keeps the stored casing when it is a
 * known status, otherwise shows a neutral placeholder).
 */
function gm_status_label(?string $status): string
{
    $trimmed = trim((string)$status);
    return $trimmed !== '' ? $trimmed : 'Unknown';
}

/**
 * Decode the guest IDs stored in a booking's GuestsJSON column.
 *
 * @return int[] De-duplicated, positive integer contact IDs.
 */
function gm_guest_ids(?string $guestsJSON): array
{
    $decoded = json_decode($guestsJSON ?? '[]', true) ?: [];
    $ids = array_map('intval', $decoded['guests'] ?? []);
    $ids = array_filter($ids, static fn (int $id): bool => $id > 0);
    return array_values(array_unique($ids));
}

/**
 * Build a short, human display of a guest list.
 *
 * Mirrors the JS implementation in guest_manager.js so the server and
 * client never disagree. Examples:
 *   []                       -> "Guest booking"
 *   ["Ann"]                  -> "Ann"
 *   ["Ann","Bob"]            -> "Ann, Bob"
 *   ["Ann","Bob","Cat"]      -> "Ann, Bob and 1 other"
 *   ["Ann","Bob","Cat","Di"] -> "Ann, Bob and 2 others"
 *
 * @param string[] $names
 * @param int|null $totalCount Overall count when $names is a truncated subset.
 */
function gm_guest_summary(array $names, ?int $totalCount = null): string
{
    $names = array_values(array_filter(array_map('strval', $names), static fn ($n) => trim($n) !== ''));
    $count = $totalCount ?? count($names);

    if ($count <= 0 || !$names) {
        return 'Guest booking';
    }

    if ($count <= 2 || count($names) <= 2) {
        return implode(', ', array_slice($names, 0, 2));
    }

    $others = max(0, $count - 2);
    $noun   = $others === 1 ? 'other' : 'others';
    return $names[0] . ', ' . $names[1] . ' and ' . $others . ' ' . $noun;
}
