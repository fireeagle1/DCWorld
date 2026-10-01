<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/wardrobe_common.php';

final class WardrobeAI
{
    public static function apiKey(): string
    {
        $value = getenv('OPENAI_API_KEY');
        if (is_string($value) && trim($value) !== '') return trim($value);
        if (defined('WARDROBE_OPENAI_API_KEY') && trim((string)WARDROBE_OPENAI_API_KEY) !== '') return trim((string)WARDROBE_OPENAI_API_KEY);
        throw new RuntimeException('OpenAI is not configured. Set OPENAI_API_KEY or WARDROBE_OPENAI_API_KEY.');
    }

    public static function imageModel(): string
    {
        return defined('WARDROBE_OPENAI_IMAGE_MODEL') && trim((string)WARDROBE_OPENAI_IMAGE_MODEL) !== ''
            ? trim((string)WARDROBE_OPENAI_IMAGE_MODEL) : 'gpt-image-1';
    }

    public static function imageQuality(): string
    {
        $quality = defined('WARDROBE_OPENAI_IMAGE_QUALITY') ? strtolower(trim((string)WARDROBE_OPENAI_IMAGE_QUALITY)) : 'medium';
        return in_array($quality, ['low','medium','high'], true) ? $quality : 'medium';
    }

    public static function detectImageMime(string $path): string
    {
        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($detected)) $mime = strtolower(trim($detected));
            }
        }
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            $info = @getimagesize($path);
            if ($info && isset($info[2])) $mime = image_type_to_mime_type((int)$info[2]);
        }
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = in_array($ext, ['jpg','jpeg'], true) ? 'image/jpeg' : ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : ''));
        }
        if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            throw new RuntimeException('Source image must be JPEG, PNG, or WebP.');
        }
        return $mime;
    }

    public static function imagePath(mysqli $link, int $itemId, string $viewType): string
    {
        $stmt = $link->prepare('SELECT RelativePath FROM WardrobeImages WHERE WardrobeItemID=? AND ViewType=? LIMIT 1');
        $stmt->bind_param('is', $itemId, $viewType); $stmt->execute();
        $relative = null; $stmt->bind_result($relative); $found = $stmt->fetch(); $stmt->close();
        if (!$found || !$relative) throw new RuntimeException(ucwords(str_replace('_',' ', $viewType)) . ' image is missing.');
        $root = realpath(wardrobe_upload_dir());
        $path = realpath(wardrobe_upload_dir() . '/' . ltrim(str_replace('\\','/', (string)$relative), '/'));
        if (!$root || !$path || !is_file($path) || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) throw new RuntimeException('Stored image is unavailable.');
        return $path;
    }

    public static function item(mysqli $link, int $itemId): array
    {
        $stmt = $link->prepare('SELECT Name,Brand,Category FROM WardrobeItems WHERE WardrobeItemID=? AND DeletedAt IS NULL LIMIT 1');
        $stmt->bind_param('i',$itemId); $stmt->execute();
        $name=$brand=$category=null; $stmt->bind_result($name,$brand,$category); $found=$stmt->fetch(); $stmt->close();
        if (!$found) throw new RuntimeException('Wardrobe item not found.');
        return ['Name'=>$name,'Brand'=>$brand,'Category'=>$category];
    }

    public static function editImage(string $sourcePath, string $prompt, string $size = '1024x1536'): string
    {
        if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL is required for OpenAI image generation.');
        $mime = self::detectImageMime($sourcePath);
        $uploadName = basename($sourcePath);
        if (!preg_match('/\.(jpe?g|png|webp)$/i', $uploadName)) {
            $uploadName .= $mime === 'image/png' ? '.png' : ($mime === 'image/webp' ? '.webp' : '.jpg');
        }
        $post = [
            'model' => self::imageModel(), 'prompt' => $prompt,
            'image' => new CURLFile($sourcePath, $mime, $uploadName),
            'size' => $size, 'quality' => self::imageQuality(), 'output_format' => 'png'
        ];
        $ch = curl_init('https://api.openai.com/v1/images/edits');
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>300,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer ' . self::apiKey()],CURLOPT_POSTFIELDS=>$post]);
        $body=curl_exec($ch); $error=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if (!is_string($body)) throw new RuntimeException('OpenAI image request failed: ' . ($error ?: 'unknown network error'));
        $decoded=json_decode($body,true);
        if ($status<200 || $status>=300) throw new RuntimeException('OpenAI image generation failed: ' . (is_array($decoded) ? ($decoded['error']['message'] ?? ('HTTP '.$status)) : ('HTTP '.$status)));
        $base64=is_array($decoded) ? ($decoded['data'][0]['b64_json'] ?? null) : null;
        $bytes=is_string($base64) ? base64_decode($base64,true) : false;
        if (!is_string($bytes) || $bytes==='') throw new RuntimeException('OpenAI returned no valid generated image.');
        return $bytes;
    }

    public static function saveDerivative(mysqli $link, int $itemId, string $viewType, string $bytes): void
    {
        if (!in_array($viewType,['processed_front','processed_back','model_front','model_back'],true)) throw new RuntimeException('Invalid derivative image type.');
        $dir=wardrobe_upload_dir().'/'.$itemId;
        if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) throw new RuntimeException('Unable to create image directory.');
        $relative=$itemId.'/'.$viewType.'-'.bin2hex(random_bytes(10)).'.png'; $path=wardrobe_upload_dir().'/'.$relative;
        if (file_put_contents($path,$bytes)===false) throw new RuntimeException('Unable to save generated image.');
        chmod($path,0640); $info=@getimagesize($path);
        if (!$info) { @unlink($path); throw new RuntimeException('Generated image is invalid.'); }
        $oldPath=null; $find=$link->prepare('SELECT RelativePath FROM WardrobeImages WHERE WardrobeItemID=? AND ViewType=? LIMIT 1');
        $find->bind_param('is',$itemId,$viewType); $find->execute(); $find->bind_result($oldPath); $find->fetch(); $find->close();
        $mime=image_type_to_mime_type((int)$info[2]); $size=filesize($path)?:0; $width=(int)$info[0]; $height=(int)$info[1];
        $stmt=$link->prepare('INSERT INTO WardrobeImages (WardrobeItemID,ViewType,RelativePath,MimeType,FileSize,Width,Height) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE RelativePath=VALUES(RelativePath),MimeType=VALUES(MimeType),FileSize=VALUES(FileSize),Width=VALUES(Width),Height=VALUES(Height),CreatedAt=CURRENT_TIMESTAMP');
        $stmt->bind_param('isssiii',$itemId,$viewType,$relative,$mime,$size,$width,$height); $stmt->execute(); $stmt->close();
        if ($oldPath && $oldPath!==$relative) { $old=wardrobe_upload_dir().'/'.ltrim((string)$oldPath,'/'); if (is_file($old)) @unlink($old); }
    }

    private static function brandInstruction(array $item): string
    {
        $brand=trim((string)($item['Brand']??''));
        return $brand!=='' ? "The detected brand is {$brand}. Preserve genuine visible branding exactly and add no new branding." : 'Do not invent any brand, logo, label, lettering, or graphic.';
    }

    public static function cataloguePrompt(array $item, string $view): string
    {
        $direction=$view==='back'?'Show the back of the garment.':'Show the front of the garment.';
        return "Create a photorealistic premium e-commerce garment-only image of the exact referenced {$item['Name']} ({$item['Category']}). {$direction} Remove the person, hanger, room and original background. Centre only the garment on a warm off-white studio background with soft even lighting and a subtle grounding shadow. Preserve exact colour, pattern, fabric texture, silhouette, proportions, seams, pockets, fasteners, graphics and wear details. ".self::brandInstruction($item)." Do not redesign, recolour, repair, add objects, text, watermark, or border.";
    }

    public static function modelPrompt(array $item, string $view): string
    {
        $direction=$view==='back'?'Show the model from the back.':'Show the model facing forward.';
        return "Create a photorealistic premium e-commerce studio photograph of a faceless adult male model wearing the exact referenced {$item['Name']} ({$item['Category']}). {$direction} Keep the entire head and face outside the frame. Use an average adult male build, natural anatomy, neutral pose, soft studio lighting and a warm off-white background. Preserve the garment's exact colour, material, pattern, silhouette, fit, proportions, seams, pockets, fasteners and graphics. ".self::brandInstruction($item)." Add only minimal neutral supporting clothing where anatomically required. No accessories, text, watermark, border or visible face.";
    }

    public static function generateCatalogueImages(mysqli $link, int $itemId): void
    {
        $item=self::item($link,$itemId);
        foreach(['front','back'] as $view) self::saveDerivative($link,$itemId,'processed_'.$view,self::editImage(self::imagePath($link,$itemId,$view),self::cataloguePrompt($item,$view)));
    }

    public static function generateModelImages(mysqli $link, int $itemId): void
    {
        $item=self::item($link,$itemId);
        foreach(['front','back'] as $view) {
            try { $source=self::imagePath($link,$itemId,'processed_'.$view); } catch(Throwable $e) { $source=self::imagePath($link,$itemId,$view); }
            self::saveDerivative($link,$itemId,'model_'.$view,self::editImage($source,self::modelPrompt($item,$view)));
        }
    }
}
