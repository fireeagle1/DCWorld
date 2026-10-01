<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_image_processing.php';
require_auth();
if (get_method() !== 'POST') json_error('Method not allowed',405);
$id=(int)($_GET['id']??0);
if($id<1) json_error('A valid wardrobe item id is required');
try { wardrobe_process_catalogue_images($link,$id); json_response(['processed'=>true,'imageType'=>'catalogue']); }
catch(Throwable $e){ json_error($e->getMessage(),500); }
