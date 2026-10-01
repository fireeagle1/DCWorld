<?php
declare(strict_types=1);
session_start(); require 'config.php'; require 'auth.php'; require_once 'wardrobe_image_processing.php';
$id=(int)($_GET['id']??0); if($id<1){header('Location: wardrobe.php');exit;}
try { wardrobe_process_catalogue_images($link,$id); $_SESSION['wardrobe_notice']='OpenAI catalogue images created. Review them against the originals for garment accuracy.'; }
catch(Throwable $e){ $_SESSION['wardrobe_error']=$e->getMessage(); }
header('Location: wardrobe_item.php?id='.$id); exit;
