<?php
/**
 * API Helper Functions
 * JSON responses, input parsing, and utility functions.
 */

/**
 * Send a JSON success response.
 */
function json_response(mixed $data, int $status = 200): void {
    if (ob_get_level()) ob_end_clean();
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response.
 */
function json_error(string $message, int $status = 400): void {
    if (ob_get_level()) ob_end_clean();
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get the JSON body from a POST/PUT request.
 */
function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Get a required field from an array, or error.
 */
function require_field(array $data, string $field): mixed {
    if (!isset($data[$field]) || $data[$field] === '') {
        json_error("Missing required field: {$field}");
    }
    return $data[$field];
}

/**
 * Get the HTTP method.
 */
function get_method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD']);
}

/**
 * Sanitize a string for safe output.
 */
function clean(?string $s): string {
    return htmlspecialchars(trim($s ?? ''), ENT_QUOTES, 'UTF-8');
}
