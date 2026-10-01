<?php
/**
 * /api/contacts.php
 * 
 * GET                — list all contacts (supports ?search=term)
 * GET  ?id=123       — single contact with full details
 * POST { knownAs, firstName, lastName, email?, phone?, dob?, streetAddress?, city?, postcode? }
 * PUT  { id, knownAs, firstName, lastName, email?, phone?, dob?, streetAddress?, city?, postcode? }
 * DELETE ?id=123     — delete a contact
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();
$method = get_method();

switch ($method) {
    case 'GET':
        handle_get();
        break;
    case 'POST':
        handle_post();
        break;
    case 'PUT':
        handle_put();
        break;
    case 'DELETE':
        handle_delete();
        break;
    default:
        json_error('Method not allowed', 405);
}

/* ─── GET ─────────────────────────────────────────────────── */
function handle_get(): void {
    global $link;

    // Single contact
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $link->prepare(
            "SELECT ContactID, KnownAs, FirstName, LastName, Email, PhoneNumber,
                    DOB, StreetAddress, City, Postcode, PhotoURL
             FROM Contacts WHERE ContactID = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) json_error('Contact not found', 404);
        json_response(format_contact($row));
    }

    // List with optional search
    $search = trim($_GET['search'] ?? '');

    if ($search !== '') {
        $like = "%{$search}%";
        $stmt = $link->prepare(
            "SELECT ContactID, KnownAs, FirstName, LastName, Email, PhoneNumber,
                    DOB, StreetAddress, City, Postcode, PhotoURL
             FROM Contacts
             WHERE KnownAs LIKE ? OR FirstName LIKE ? OR LastName LIKE ? OR Email LIKE ?
             ORDER BY KnownAs ASC
             LIMIT 200"
        );
        $stmt->bind_param('ssss', $like, $like, $like, $like);
    } else {
        $stmt = $link->prepare(
            "SELECT ContactID, KnownAs, FirstName, LastName, Email, PhoneNumber,
                    DOB, StreetAddress, City, Postcode, PhotoURL
             FROM Contacts
             ORDER BY KnownAs ASC
             LIMIT 500"
        );
    }

    $stmt->execute();
    $res = $stmt->get_result();

    $contacts = [];
    while ($row = $res->fetch_assoc()) {
        $contacts[] = format_contact($row);
    }
    $stmt->close();

    json_response($contacts);
}

/* ─── POST ────────────────────────────────────────────────── */
function handle_post(): void {
    global $link, $userID;

    $body = get_json_body();

    $knownAs = trim(require_field($body, 'knownAs'));
    $firstName = trim(require_field($body, 'firstName'));
    $lastName = trim(require_field($body, 'lastName'));
    $email = trim($body['email'] ?? '');
    $phone = trim($body['phone'] ?? '');
    $dob = ($body['dob'] ?? '') !== '' ? $body['dob'] : null;
    $street = trim($body['streetAddress'] ?? '');
    $city = trim($body['city'] ?? '');
    $postcode = trim($body['postcode'] ?? '');

    $stmt = $link->prepare(
        "INSERT INTO Contacts 
         (KnownAs, FirstName, LastName, Email, PhoneNumber, DOB, StreetAddress, City, Postcode, CreatedBy, LastUpdatedBy, LastUpdatedAt)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->bind_param(
        'sssssssssii',
        $knownAs, $firstName, $lastName, $email, $phone, $dob,
        $street, $city, $postcode, $userID, $userID
    );

    if (!$stmt->execute()) {
        json_error('Failed to create contact: ' . $stmt->error, 500);
    }

    $newID = $stmt->insert_id;
    $stmt->close();

    // Return the new contact
    $stmt = $link->prepare(
        "SELECT ContactID, KnownAs, FirstName, LastName, Email, PhoneNumber,
                DOB, StreetAddress, City, Postcode, PhotoURL
         FROM Contacts WHERE ContactID = ?"
    );
    $stmt->bind_param('i', $newID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_response(format_contact($row), 201);
}

/* ─── PUT ─────────────────────────────────────────────────── */
function handle_put(): void {
    global $link, $userID;

    $body = get_json_body();
    $id = (int)require_field($body, 'id');

    $knownAs = trim(require_field($body, 'knownAs'));
    $firstName = trim(require_field($body, 'firstName'));
    $lastName = trim(require_field($body, 'lastName'));
    $email = trim($body['email'] ?? '');
    $phone = trim($body['phone'] ?? '');
    $dob = ($body['dob'] ?? '') !== '' ? $body['dob'] : null;
    $street = trim($body['streetAddress'] ?? '');
    $city = trim($body['city'] ?? '');
    $postcode = trim($body['postcode'] ?? '');

    $stmt = $link->prepare(
        "UPDATE Contacts SET
            KnownAs = ?, FirstName = ?, LastName = ?, Email = ?, PhoneNumber = ?, DOB = ?,
            StreetAddress = ?, City = ?, Postcode = ?,
            LastUpdatedBy = ?, LastUpdatedAt = NOW()
         WHERE ContactID = ?"
    );
    $stmt->bind_param(
        'sssssssssii',
        $knownAs, $firstName, $lastName, $email, $phone, $dob,
        $street, $city, $postcode, $userID, $id
    );

    if (!$stmt->execute()) {
        json_error('Failed to update contact: ' . $stmt->error, 500);
    }

    if ($stmt->affected_rows === 0) {
        // Check if contact exists (might just have no changes)
        $check = $link->prepare("SELECT ContactID FROM Contacts WHERE ContactID = ?");
        $check->bind_param('i', $id);
        $check->execute();
        if ($check->get_result()->num_rows === 0) {
            $check->close();
            $stmt->close();
            json_error('Contact not found', 404);
        }
        $check->close();
    }
    $stmt->close();

    // Return updated contact
    $stmt = $link->prepare(
        "SELECT ContactID, KnownAs, FirstName, LastName, Email, PhoneNumber,
                DOB, StreetAddress, City, Postcode, PhotoURL
         FROM Contacts WHERE ContactID = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_response(format_contact($row));
}

/* ─── DELETE ──────────────────────────────────────────────── */
function handle_delete(): void {
    global $link;

    $id = (int)($_GET['id'] ?? 0);
    if ($id === 0) {
        json_error('Contact ID is required');
    }

    $stmt = $link->prepare("DELETE FROM Contacts WHERE ContactID = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        json_error('Contact not found', 404);
    }
    $stmt->close();

    json_response(['deleted' => true, 'id' => $id]);
}

/* ─── Helpers ─────────────────────────────────────────────── */
function format_contact(array $row): array {
    return [
        'id' => (int)$row['ContactID'],
        'knownAs' => $row['KnownAs'] ?? '',
        'firstName' => $row['FirstName'] ?? '',
        'lastName' => $row['LastName'] ?? '',
        'email' => $row['Email'] ?? '',
        'phone' => $row['PhoneNumber'] ?? '',
        'dob' => $row['DOB'] ?? null,
        'streetAddress' => $row['StreetAddress'] ?? '',
        'city' => $row['City'] ?? '',
        'postcode' => $row['Postcode'] ?? '',
        'photoURL' => $row['PhotoURL'] ?? null,
    ];
}
