<?php
declare(strict_types=1);

require_once __DIR__ . '/wardrobe_common.php';

function wardrobe_ai_api_key(): string {
    $value = getenv('OPENAI_API_KEY');
    if (is_string($value) && trim($value) !== '') return trim($value);
    if (defined('WARDROBE_OPENAI_API_KEY') && trim((string)WARDROBE_OPENAI_API_KEY) !== '') {
        return trim((string)WARDROBE_OPENAI_API_KEY);
    }
    throw new RuntimeException('AI analysis is not configured. Set OPENAI_API_KEY or WARDROBE_OPENAI_API_KEY in the server configuration.');
}

function wardrobe_ai_model(): string {
    $value = getenv('WARDROBE_OPENAI_MODEL');
    if (is_string($value) && trim($value) !== '') return trim($value);
    return defined('WARDROBE_OPENAI_MODEL') ? (string)WARDROBE_OPENAI_MODEL : 'gpt-4.1-mini';
}

function wardrobe_ai_image_path(mysqli $link, int $itemId, string $view): string {
    $stmt = $link->prepare('SELECT RelativePath FROM WardrobeImages WHERE WardrobeItemID = ? AND ViewType = ? LIMIT 1');
    $stmt->bind_param('is', $itemId, $view);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) throw new RuntimeException(ucfirst($view) . ' image is missing.');
    $path = wardrobe_upload_dir() . '/' . ltrim((string)$row['RelativePath'], '/');
    $base = realpath(wardrobe_upload_dir());
    $real = realpath($path);
    if (!$base || !$real || strpos($real, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($real)) {
        throw new RuntimeException('Stored image is unavailable.');
    }
    return $real;
}

function wardrobe_ai_data_uri(string $path): string {
    $info = @getimagesize($path);
    if (!$info) throw new RuntimeException('Unable to read an uploaded image.');

    $maxDimension = 1600;
    $width = (int)$info[0];
    $height = (int)$info[1];
    $mime = (string)$info['mime'];
    $bytes = null;

    if (extension_loaded('gd') && max($width, $height) > $maxDimension) {
        $source = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($source) {
            $scale = $maxDimension / max($width, $height);
            $targetWidth = max(1, (int)round($width * $scale));
            $targetHeight = max(1, (int)round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefill($target, 0, 0, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            ob_start();
            imagejpeg($target, null, 84);
            $bytes = ob_get_clean();
            imagedestroy($source);
            imagedestroy($target);
            $mime = 'image/jpeg';
        }
    }

    if (!is_string($bytes)) {
        $bytes = @file_get_contents($path);
    }
    if (!is_string($bytes) || $bytes === '') throw new RuntimeException('Unable to prepare an uploaded image.');
    return 'data:' . $mime . ';base64,' . base64_encode($bytes);
}


function wardrobe_ai_brand_from_name(string $name): ?string {
    $brands = ['Adidas','Nike','Puma','Reebok','Under Armour','New Balance','Asics','Converse','Vans','Levi\'s','Tommy Hilfiger','Ralph Lauren','Calvin Klein','H&M','Zara','Uniqlo','The North Face','Patagonia','Columbia','Lacoste','Fred Perry'];
    foreach ($brands as $brand) {
        if (preg_match('/\b' . preg_quote($brand, '/') . '\b/i', $name)) return $brand;
    }
    return null;
}

function wardrobe_ai_schema(): array {
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['name','category','subcategory','brand','primaryColour','secondaryColours','pattern','material','thickness','fit','formality','seasons','occasions','tags','description','confidence','warnings'],
        'properties' => [
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string', 'enum' => wardrobe_allowed_categories()],
            'subcategory' => ['type' => ['string','null']],
            'brand' => ['type' => ['string','null']],
            'primaryColour' => ['type' => ['string','null']],
            'secondaryColours' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
            'pattern' => ['type' => ['string','null']],
            'material' => ['type' => ['string','null']],
            'thickness' => ['type' => ['string','null'], 'enum' => ['very_light','light','medium','heavy','very_heavy',null]],
            'fit' => ['type' => ['string','null']],
            'formality' => ['type' => ['string','null'], 'enum' => ['very_casual','casual','smart_casual','formal','very_formal',null]],
            'seasons' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['spring','summer','autumn','winter']]],
            'occasions' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 15],
            'description' => ['type' => 'string'],
            'confidence' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['overall','category','colour','material','thickness'],
                'properties' => [
                    'overall' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'category' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'colour' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'material' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                    'thickness' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                ],
            ],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 8],
        ],
    ];
}

function wardrobe_ai_extract_output_text(array $response): string {
    if (isset($response['output_text']) && is_string($response['output_text'])) return $response['output_text'];
    foreach (($response['output'] ?? []) as $output) {
        foreach (($output['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) return (string)$content['text'];
        }
    }
    throw new RuntimeException('The AI service returned no analysis text.');
}

function wardrobe_ai_analyse(mysqli $link, int $itemId, int $userId): array {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for AI analysis.');
    $apiKey = wardrobe_ai_api_key();
    $front = wardrobe_ai_data_uri(wardrobe_ai_image_path($link, $itemId, 'front'));
    $back = wardrobe_ai_data_uri(wardrobe_ai_image_path($link, $itemId, 'back'));

    $stmt = $link->prepare("UPDATE WardrobeItems SET ProcessingStatus='processing', AnalysisError=NULL, UpdatedByUserID=? WHERE WardrobeItemID=? AND DeletedAt IS NULL");
    $stmt->bind_param('ii', $userId, $itemId);
    $stmt->execute();
    if ($stmt->affected_rows < 1) { $stmt->close(); throw new RuntimeException('Wardrobe item not found.'); }
    $stmt->close();

    $instructions = 'Analyse the two photographs as front and back views of the same real garment. Return conservative catalogue metadata. '
        . 'Use only visible evidence for category, colour and pattern. Material, thickness, fit, season and occasion may be inferred, but use null or warnings when uncertain. '
        . 'Set brand when a visible logo, wordmark, label or unmistakable brand mark supports it. If the brand is clearly visible, keep it in the brand field as well as using it naturally in the product name. Do not invent a brand, exact fabric composition, model number, gender, size, waterproofing or hidden details. '
        . 'Use British English. Generate a concise searchable product name and description. Category must match the supplied enum.';

    $payload = [
        'model' => wardrobe_ai_model(),
        'input' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $instructions],
                ['type' => 'input_image', 'image_url' => $front, 'detail' => 'high'],
                ['type' => 'input_image', 'image_url' => $back, 'detail' => 'high'],
            ],
        ]],
        'text' => [
            'format' => [
                'type' => 'json_schema',
                'name' => 'wardrobe_garment_analysis',
                'strict' => true,
                'schema' => wardrobe_ai_schema(),
            ],
        ],
        'max_output_tokens' => 1800,
    ];

    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!is_string($body)) throw new RuntimeException('AI request failed: ' . ($curlError ?: 'unknown network error'));
    $response = json_decode($body, true);
    if (!is_array($response)) throw new RuntimeException('AI service returned an invalid response.');
    if ($status < 200 || $status >= 300) {
        $message = $response['error']['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException('AI analysis failed: ' . $message);
    }

    $analysisText = wardrobe_ai_extract_output_text($response);
    $analysis = json_decode($analysisText, true);
    if (!is_array($analysis)) throw new RuntimeException('AI analysis was not valid JSON.');

    $name = trim((string)($analysis['name'] ?? '')) ?: 'Untitled clothing item';
    $category = in_array($analysis['category'] ?? '', wardrobe_allowed_categories(), true) ? $analysis['category'] : 'other';
    $subcategory = $analysis['subcategory'] ?? null;
    $brand = isset($analysis['brand']) && trim((string)$analysis['brand']) !== '' ? trim((string)$analysis['brand']) : wardrobe_ai_brand_from_name($name);
    $primary = $analysis['primaryColour'] ?? null;
    $secondary = json_encode(array_values($analysis['secondaryColours'] ?? []));
    $pattern = $analysis['pattern'] ?? null;
    $material = $analysis['material'] ?? null;
    $thickness = in_array($analysis['thickness'] ?? null, ['very_light','light','medium','heavy','very_heavy'], true) ? $analysis['thickness'] : null;
    $fit = $analysis['fit'] ?? null;
    $formality = in_array($analysis['formality'] ?? null, ['very_casual','casual','smart_casual','formal','very_formal'], true) ? $analysis['formality'] : null;
    $seasons = json_encode(array_values($analysis['seasons'] ?? []));
    $occasions = json_encode(array_values($analysis['occasions'] ?? []));
    $tags = json_encode(array_values($analysis['tags'] ?? []));
    $description = trim((string)($analysis['description'] ?? ''));
    $confidence = json_encode($analysis['confidence'] ?? new stdClass());
    $warnings = json_encode(array_values($analysis['warnings'] ?? []));
    $raw = json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $sql = "UPDATE WardrobeItems SET Name=?, Category=?, Subcategory=?, Brand=?, PrimaryColour=?, SecondaryColours=?, Pattern=?, Material=?, Thickness=?, Fit=?, Formality=?, Seasons=?, Occasions=?, Tags=?, AIDescription=?, AnalysisConfidence=?, AnalysisWarnings=?, AnalysisRawJSON=?, AnalysisError=NULL, AnalysedAt=NOW(), ProcessingStatus='ready', MetadataSource='ai_suggested', UpdatedByUserID=? WHERE WardrobeItemID=?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param('ssssssssssssssssssii', $name, $category, $subcategory, $brand, $primary, $secondary, $pattern, $material, $thickness, $fit, $formality, $seasons, $occasions, $tags, $description, $confidence, $warnings, $raw, $userId, $itemId);
    $stmt->execute();
    $stmt->close();
    return $analysis;
}

function wardrobe_ai_record_failure(mysqli $link, int $itemId, int $userId, Throwable $error): void {
    $message = mb_substr($error->getMessage(), 0, 1000);
    $stmt = $link->prepare("UPDATE WardrobeItems SET ProcessingStatus='failed', AnalysisError=?, UpdatedByUserID=? WHERE WardrobeItemID=?");
    $stmt->bind_param('sii', $message, $userId, $itemId);
    $stmt->execute();
    $stmt->close();
}
