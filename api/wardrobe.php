<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_common.php';

$userID = require_auth();
$method = get_method();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($method === 'GET') {
    $includeDeleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
    $where = $includeDeleted ? '1=1' : 'DeletedAt IS NULL';
    $types = '';
    $params = [];
    if ($id > 0) { $where .= ' AND WardrobeItemID = ?'; $types .= 'i'; $params[] = $id; }
    if (!empty($_GET['category'])) {
        $catFilter = trim($_GET['category']);
        // Map iOS display categories to DB parent categories + subcategory search
        $catMap = [
            'T-shirts'=>['tops','t-shirt'], 'Shirts'=>['tops','shirt'], 'Jumpers'=>['tops','jumper'],
            'Hoodies'=>['tops','hoodie'], 'Jackets'=>['outerwear','jacket'], 'Coats'=>['outerwear','coat'],
            'Jeans'=>['bottoms','jeans'], 'Trousers'=>['bottoms','trousers'], 'Shorts'=>['bottoms','shorts'],
            'Shoes'=>['shoes',null], 'Accessories'=>['accessories',null],
        ];
        if (isset($catMap[$catFilter])) {
            [$parentCat, $subCat] = $catMap[$catFilter];
            if ($subCat !== null) {
                $where .= ' AND Category = ? AND (Subcategory LIKE ? OR Name LIKE ?)';
                $types .= 'sss';
                $params[] = $parentCat;
                $params[] = '%' . $subCat . '%';
                $params[] = '%' . $subCat . '%';
            } else {
                $where .= ' AND Category = ?'; $types .= 's'; $params[] = $parentCat;
            }
        } elseif (in_array($catFilter, wardrobe_allowed_categories(), true)) {
            $where .= ' AND Category = ?'; $types .= 's'; $params[] = $catFilter;
        } else {
            // Fallback: try matching category or subcategory
            $where .= ' AND (Category = ? OR Subcategory LIKE ?)'; $types .= 'ss'; $params[] = $catFilter; $params[] = '%' . $catFilter . '%';
        }
    }
    if (!empty($_GET['colour'])) { $where .= ' AND PrimaryColour = ?'; $types .= 's'; $params[] = trim($_GET['colour']); }
    if (!empty($_GET['q'])) {
        $where .= " AND (Name LIKE ? OR Brand LIKE ? OR Category LIKE ? OR PrimaryColour LIKE ? OR Tags LIKE ?)";
        $needle = '%' . trim($_GET['q']) . '%';
        $types .= 'sssss';
        array_push($params, $needle, $needle, $needle, $needle, $needle);
    }
    $stmt = $link->prepare("SELECT * FROM WardrobeItems WHERE {$where} ORDER BY Favourite DESC, UpdatedAt DESC");
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) $items[] = wardrobe_item_to_array($row);
    $stmt->close();
    if ($id > 0) {
        if (!$items) json_error('Wardrobe item not found', 404);
        json_response($items[0]);
    }
    json_response(['items' => $items]);
}

if ($method === 'POST') {
    $data = get_json_body();
    $name = trim((string)require_field($data, 'name'));
    $category = trim((string)require_field($data, 'category'));
    if (!in_array($category, wardrobe_allowed_categories(), true)) json_error('Invalid category');
    $stmt = $link->prepare("INSERT INTO WardrobeItems (Name, Category, Subcategory, Brand, PrimaryColour, SecondaryColours, Pattern, Material, Thickness, Fit, Formality, Seasons, Occasions, Tags, Notes, Favourite, CreatedByUserID, UpdatedByUserID) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $secondary = json_encode($data['secondaryColours'] ?? []);
    $seasons = json_encode($data['seasons'] ?? []);
    $occasions = json_encode($data['occasions'] ?? []);
    $tags = json_encode($data['tags'] ?? []);
    $sub = $data['subcategory'] ?? null; $brand = $data['brand'] ?? null; $colour = $data['primaryColour'] ?? null;
    $pattern = $data['pattern'] ?? null; $material = $data['material'] ?? null; $thickness = $data['thickness'] ?? null;
    $fit = $data['fit'] ?? null; $formality = $data['formality'] ?? null; $notes = $data['notes'] ?? null; $fav = !empty($data['favourite']) ? 1 : 0;
    $stmt->bind_param('sssssssssssssssiii', $name, $category, $sub, $brand, $colour, $secondary, $pattern, $material, $thickness, $fit, $formality, $seasons, $occasions, $tags, $notes, $fav, $userID, $userID);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    json_response(['id' => $newId], 201);
}

if ($method === 'PUT' && $id > 0) {
    $data = get_json_body();
    $allowed = ['Name'=>'name','Category'=>'category','Subcategory'=>'subcategory','Brand'=>'brand','PrimaryColour'=>'primaryColour','Pattern'=>'pattern','Material'=>'material','Thickness'=>'thickness','Fit'=>'fit','Formality'=>'formality','Notes'=>'notes','CatalogueImagePreference'=>'catalogueImagePreference','DetailImagePreference'=>'detailImagePreference'];
    $sets = []; $types = ''; $params = [];
    foreach ($allowed as $column => $key) if (array_key_exists($key, $data)) { $sets[] = "$column = ?"; $types .= 's'; $params[] = $data[$key]; }
    foreach (['SecondaryColours'=>'secondaryColours','Seasons'=>'seasons','Occasions'=>'occasions','Tags'=>'tags'] as $column => $key) if (array_key_exists($key, $data)) { $sets[] = "$column = ?"; $types .= 's'; $params[] = json_encode($data[$key]); }
    if (array_key_exists('favourite', $data)) { $sets[] = 'Favourite = ?'; $types .= 'i'; $params[] = $data['favourite'] ? 1 : 0; }
    if (!$sets) json_error('No supported fields supplied');
    $sets[] = 'UpdatedByUserID = ?'; $types .= 'i'; $params[] = $userID;
    $types .= 'i'; $params[] = $id;
    $stmt = $link->prepare('UPDATE WardrobeItems SET ' . implode(', ', $sets) . ' WHERE WardrobeItemID = ? AND DeletedAt IS NULL');
    $stmt->bind_param($types, ...$params); $stmt->execute();
    if ($stmt->affected_rows === 0) json_error('Wardrobe item not found or unchanged', 404);
    $stmt->close(); json_response(['updated' => true]);
}

if ($method === 'DELETE' && $id > 0) {
    $stmt = $link->prepare('UPDATE WardrobeItems SET DeletedAt = NOW(), UpdatedByUserID = ? WHERE WardrobeItemID = ? AND DeletedAt IS NULL');
    $stmt->bind_param('ii', $userID, $id); $stmt->execute();
    if ($stmt->affected_rows === 0) json_error('Wardrobe item not found', 404);
    $stmt->close(); json_response(['deleted' => true]);
}

json_error('Method not allowed', 405);
