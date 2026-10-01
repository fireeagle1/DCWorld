<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/WardrobeAI.php';
function wardrobe_process_catalogue_images(mysqli $link,int $itemId):void { WardrobeAI::generateCatalogueImages($link,$itemId); }
function wardrobe_generate_catalogue_images(mysqli $link,int $itemId):void { WardrobeAI::generateCatalogueImages($link,$itemId); }
function wardrobe_generate_model_images(mysqli $link,int $itemId):void { WardrobeAI::generateModelImages($link,$itemId); }
