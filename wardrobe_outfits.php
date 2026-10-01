<?php
declare(strict_types=1);
session_start();
require 'config.php';
require 'auth.php';
require_once 'wardrobe_common.php';

$currentUserID = (int)($_SESSION['userID'] ?? 0);
$person = isset($_GET['user']) ? (int)$_GET['user'] : 0;

$users = [];
$ur = $link->query("SELECT DISTINCT u.UserID,u.Name FROM WardrobeWearLogs wl JOIN DC_Users u ON u.UserID=wl.CreatedByUserID ORDER BY u.Name");
if ($ur) while ($row=$ur->fetch_assoc()) $users[]=$row;

$sql = "SELECT wl.WardrobeWearLogID,wl.WornDate,wl.CreatedAt,wl.CreatedByUserID,u.Name UserName,
               wli.WardrobeItemID,wli.IsPrimary,wi.Name ItemName,wi.Brand,wi.Category,wi.PrimaryColour,wi.CatalogueImagePreference
        FROM WardrobeWearLogs wl
        LEFT JOIN DC_Users u ON u.UserID=wl.CreatedByUserID
        JOIN WardrobeWearLogItems wli ON wli.WardrobeWearLogID=wl.WardrobeWearLogID
        JOIN WardrobeItems wi ON wi.WardrobeItemID=wli.WardrobeItemID
        WHERE wi.DeletedAt IS NULL";
$types='';$params=[];
if($person>0){$sql.=' AND wl.CreatedByUserID=?';$types='i';$params[]=$person;}
$sql.=' ORDER BY wl.WornDate DESC,wl.CreatedAt DESC,wli.IsPrimary DESC,wi.Name';
$stmt=$link->prepare($sql);if($types)$stmt->bind_param($types,...$params);$stmt->execute();$res=$stmt->get_result();
$outfits=[];
while($r=$res->fetch_assoc()){
    $logId=(int)$r['WardrobeWearLogID'];
    if(!isset($outfits[$logId]))$outfits[$logId]=[
        'id'=>$logId,'date'=>$r['WornDate'],'created_at'=>$r['CreatedAt'],'user_id'=>(int)$r['CreatedByUserID'],
        'user_name'=>$r['UserName']?:'Unknown user','items'=>[]
    ];
    $outfits[$logId]['items'][]=$r;
}
$stmt->close();

$page_title='Outfits worn';$wardrobe_modern=true;include 'header.php';
?>
<main class="wardrobe-shell">
  <div class="product-topbar">
    <a class="wardrobe-section-link" href="wardrobe.php">← Back to wardrobe</a>
    <a class="wardrobe-btn wardrobe-btn-dark" href="wardrobe.php">Browse wardrobe</a>
  </div>

  <section class="wardrobe-hero outfits-hero">
    <div>
      <div class="wardrobe-eyebrow">Shared wardrobe history</div>
      <h1 class="wardrobe-title">Outfits worn</h1>
      <p class="wardrobe-subtitle">See what you have both worn, discover repeated combinations and build better suggestions over time.</p>
    </div>
  </section>

  <nav class="wardrobe-category-strip" aria-label="Filter outfits by person">
    <a class="wardrobe-chip <?=$person===0?'active':''?>" href="wardrobe_outfits.php">Everyone</a>
    <?php foreach($users as $u):$uid=(int)$u['UserID'];$label=$uid===$currentUserID?'You':$u['Name'];?>
      <a class="wardrobe-chip <?=$person===$uid?'active':''?>" href="wardrobe_outfits.php?user=<?=$uid?>"><?=wardrobe_h($label)?></a>
    <?php endforeach;?>
  </nav>

  <?php if(!$outfits):?>
    <div class="wardrobe-empty"><h2>No outfits recorded yet</h2><p class="text-muted">Open an item and use “Worn today” to record your first outfit.</p></div>
  <?php else:?>
    <div class="outfit-history">
      <?php foreach($outfits as $outfit):$owner=((int)$outfit['user_id']===$currentUserID)?'You':$outfit['user_name'];?>
        <article class="outfit-entry">
          <header class="outfit-entry-head">
            <div>
              <div class="wardrobe-eyebrow"><?=wardrobe_h(date('l, j F Y',strtotime($outfit['date'])))?></div>
              <h2><?=wardrobe_h($owner)?> wore this outfit</h2>
            </div>
            <span class="outfit-item-count"><?=count($outfit['items'])?> <?=count($outfit['items'])===1?'item':'items'?></span>
          </header>
          <div class="outfit-items-row">
            <?php foreach($outfit['items'] as $oi):$iid=(int)$oi['WardrobeItemID'];$pref=$oi['CatalogueImagePreference']??'processed';?>
              <a class="outfit-mini-card <?=$oi['IsPrimary']?'primary':''?>" href="wardrobe_item.php?id=<?=$iid?>">
                <span class="outfit-mini-image"><img loading="lazy" src="<?=wardrobe_h(wardrobe_image_url($iid,$pref,'front'))?>" alt="<?=wardrobe_h(trim(($oi['Brand']??'').' '.($oi['ItemName']??'')))?>"></span>
                <span class="outfit-mini-copy"><strong><?=wardrobe_h($oi['ItemName'])?></strong><small><?=wardrobe_h($oi['Brand']?:ucwords($oi['Category']))?></small></span>
                <?php if($oi['IsPrimary']):?><span class="outfit-primary-label">Started with</span><?php endif;?>
              </a>
            <?php endforeach;?>
          </div>
        </article>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</main>
<?php include 'footer.php';?>
