<?php
declare(strict_types=1);
session_start();
require 'config.php';
require 'auth.php';
require_once 'wardrobe_common.php';

if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: wardrobe.php');exit;}
$id=(int)($_POST['item_id']??0);
$return='wardrobe_item.php?id='.$id;
try{
    if($id<1)throw new RuntimeException('Invalid wardrobe item.');
    $csrf=(string)($_POST['csrf']??'');
    if(empty($_SESSION['wardrobe_csrf'])||!hash_equals($_SESSION['wardrobe_csrf'],$csrf))throw new RuntimeException('Your session expired. Please try again.');

    $check=$link->prepare('SELECT WardrobeItemID FROM WardrobeItems WHERE WardrobeItemID=? AND DeletedAt IS NULL');
    $check->bind_param('i',$id);$check->execute();
    if(!$check->get_result()->fetch_assoc())throw new RuntimeException('Wardrobe item not found.');
    $check->close();

    $with=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['with_items']??[])),fn($v)=>$v>0&&$v!==$id)));
    if(count($with)>20)$with=array_slice($with,0,20);

    $link->begin_transaction();
    $userId=isset($_SESSION['userID'])?(int)$_SESSION['userID']:null;
    if(!$userId) throw new RuntimeException('Your user session could not be identified. Please sign in again.');
    $log=$link->prepare('INSERT INTO WardrobeWearLogs (WornDate,CreatedByUserID) VALUES (CURRENT_DATE,?)');
    $log->bind_param('i',$userId);$log->execute();$logId=(int)$link->insert_id;$log->close();

    $insert=$link->prepare('INSERT INTO WardrobeWearLogItems (WardrobeWearLogID,WardrobeItemID,IsPrimary) VALUES (?,?,?)');
    $primary=1;$insert->bind_param('iii',$logId,$id,$primary);$insert->execute();
    $primary=0;
    foreach($with as $companion){
        $valid=$link->prepare('SELECT WardrobeItemID FROM WardrobeItems WHERE WardrobeItemID=? AND DeletedAt IS NULL');
        $valid->bind_param('i',$companion);$valid->execute();$exists=(bool)$valid->get_result()->fetch_assoc();$valid->close();
        if(!$exists)continue;
        $insert->bind_param('iii',$logId,$companion,$primary);$insert->execute();
    }
    $insert->close();
    $link->commit();
    $_SESSION['wardrobe_notice']=$with?'Marked as worn today and saved the outfit combination.':'Marked as worn today.';
}catch(Throwable $e){
    if(isset($link)&&$link instanceof mysqli){try{$link->rollback();}catch(Throwable $ignored){}}
    $_SESSION['wardrobe_error']=$e->getMessage();
}
header('Location: '.$return);exit;
