<?php
declare(strict_types=1);

function wardrobe_h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function wardrobe_json_array(?string $json): array {
    if (!$json) return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
}

function wardrobe_json_object(?string $json): array {
    if (!$json) return [];
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function wardrobe_csv_array(?string $value): array {
    if (!$value) return [];
    $parts = preg_split('/[,\r\n]+/', $value) ?: [];
    return array_values(array_unique(array_filter(array_map(static fn($v) => trim((string)$v), $parts))));
}

function wardrobe_upload_dir(): string {
    return __DIR__ . '/wardrobe_uploads';
}


function wardrobe_allowed_image_preferences(): array { return ['original','processed','model']; }

function wardrobe_image_role(string $preference, string $side = 'front'): string {
    $map = ['original'=>$side, 'processed'=>'processed_'.$side, 'model'=>'model_'.$side];
    return $map[$preference] ?? 'processed_'.$side;
}

function wardrobe_image_url(int $itemId, string $preference, string $side = 'front'): string {
    return 'wardrobe_image.php?id=' . $itemId . '&view=' . rawurlencode(wardrobe_image_role($preference, $side));
}

function wardrobe_image_fallback_chain(string $preference, string $side = 'front'): array {
    $order = [$preference, 'processed', 'original', 'model'];
    $seen=[]; $roles=[];
    foreach($order as $value){ if(isset($seen[$value])) continue; $seen[$value]=true; $roles[]=wardrobe_image_role($value,$side); }
    return $roles;
}

function wardrobe_allowed_categories(): array {
    return ['tops','bottoms','dresses','outerwear','shoes','accessories','activewear','sleepwear','underwear','other'];
}

function wardrobe_validate_image(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Both front and back images are required.');
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > 12 * 1024 * 1024) {
        throw new RuntimeException('Each image must be no larger than 12 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Images must be JPEG, PNG, or WebP files.');
    }
    $mime = image_type_to_mime_type($info[2]);
    return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1], 'type' => $info[2]];
}

function wardrobe_store_image(mysqli $link, int $itemId, string $viewType, array $file): void {
    $meta = wardrobe_validate_image($file);
    $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $extension = $extensions[$meta['type']];
    $itemDir = wardrobe_upload_dir() . '/' . $itemId;
    if (!is_dir($itemDir) && !mkdir($itemDir, 0750, true) && !is_dir($itemDir)) {
        throw new RuntimeException('Unable to create image storage directory.');
    }
    $filename = $viewType . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
    $relative = $itemId . '/' . $filename;
    $destination = wardrobe_upload_dir() . '/' . $relative;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Unable to store uploaded image.');
    }
    chmod($destination, 0640);
    $stmt = $link->prepare("INSERT INTO WardrobeImages (WardrobeItemID, ViewType, RelativePath, MimeType, FileSize, Width, Height) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $size = filesize($destination) ?: 0;
    $stmt->bind_param('isssiii', $itemId, $viewType, $relative, $meta['mime'], $size, $meta['width'], $meta['height']);
    if (!$stmt->execute()) {
        @unlink($destination);
        throw new RuntimeException('Unable to save image record.');
    }
    $stmt->close();
}

function wardrobe_item_to_array(array $row): array {
    $id = (int)$row['WardrobeItemID'];
    return [
        'id' => $id,
        'name' => $row['Name'],
        'category' => $row['Category'],
        'subcategory' => $row['Subcategory'],
        'brand' => $row['Brand'],
        'primaryColour' => $row['PrimaryColour'],
        'secondaryColours' => wardrobe_json_array($row['SecondaryColours']),
        'pattern' => $row['Pattern'],
        'material' => $row['Material'],
        'thickness' => $row['Thickness'],
        'fit' => $row['Fit'],
        'formality' => $row['Formality'],
        'seasons' => wardrobe_json_array($row['Seasons']),
        'occasions' => wardrobe_json_array($row['Occasions']),
        'tags' => wardrobe_json_array($row['Tags']),
        'notes' => $row['Notes'],
        'aiDescription' => $row['AIDescription'] ?? null,
        'analysisConfidence' => wardrobe_json_object($row['AnalysisConfidence'] ?? null),
        'analysisWarnings' => wardrobe_json_array($row['AnalysisWarnings'] ?? null),
        'analysisError' => $row['AnalysisError'] ?? null,
        'analysedAt' => $row['AnalysedAt'] ?? null,
        'favourite' => (bool)$row['Favourite'],
        'catalogueImagePreference' => $row['CatalogueImagePreference'] ?? 'processed',
        'detailImagePreference' => $row['DetailImagePreference'] ?? 'processed',
        'processingStatus' => $row['ProcessingStatus'],
        'metadataSource' => $row['MetadataSource'],
        'createdAt' => $row['CreatedAt'],
        'updatedAt' => $row['UpdatedAt'],
        'deletedAt' => $row['DeletedAt'],
        'images' => [
            'front' => '/wardrobe_image.php?id=' . $id . '&view=front',
            'back' => '/wardrobe_image.php?id=' . $id . '&view=back',
            'processedFront' => '/wardrobe_image.php?id=' . $id . '&view=processed_front',
            'processedBack' => '/wardrobe_image.php?id=' . $id . '&view=processed_back',
            'modelFront' => '/wardrobe_image.php?id=' . $id . '&view=model_front',
            'modelBack' => '/wardrobe_image.php?id=' . $id . '&view=model_back'
        ]
    ];
}
