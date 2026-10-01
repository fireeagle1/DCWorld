<?php
declare(strict_types=1);
session_start();
require 'config.php';
require 'auth.php';
require_once 'wardrobe_image_processing.php';
$id=(int)($_GET['id']??0);
if($id<1){header('Location: wardrobe.php');exit;}
try {
    wardrobe_generate_model_images($link,$id);
    $_SESSION['wardrobe_notice']='Faceless male-model images created. Review them carefully because generated details may differ from the original garment.';
} catch(Throwable $e) {
    $_SESSION['wardrobe_error']=$e->getMessage();
}
header('Location: wardrobe_item.php?id='.$id); exit;
