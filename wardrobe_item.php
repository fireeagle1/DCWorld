<?php
declare(strict_types=1);
session_start();
require 'config.php';
require 'auth.php';
require_once 'wardrobe_common.php';

$id=(int)($_GET['id']??0);
if($id<1){header('Location: wardrobe.php');exit;}
$stmt=$link->prepare('SELECT * FROM WardrobeItems WHERE WardrobeItemID=?');
$stmt->bind_param('i',$id);$stmt->execute();$item=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$item){http_response_code(404);exit('Wardrobe item not found');}

$notice=$_SESSION['wardrobe_notice']??'';
$error=$_SESSION['wardrobe_error']??'';
unset($_SESSION['wardrobe_notice'],$_SESSION['wardrobe_error']);

if (empty($_SESSION['wardrobe_csrf'])) $_SESSION['wardrobe_csrf']=bin2hex(random_bytes(24));
$csrf=$_SESSION['wardrobe_csrf'];

$images=[];
$s=$link->prepare('SELECT ViewType FROM WardrobeImages WHERE WardrobeItemID=?');
$s->bind_param('i',$id);$s->execute();$r=$s->get_result();
while($row=$r->fetch_assoc())$images[]=$row['ViewType'];
$s->close();

$pref=$item['DetailImagePreference']??'processed';
$preferred=[wardrobe_image_role($pref,'front'),wardrobe_image_role($pref,'back')];
$all=['front','back','processed_front','processed_back','model_front','model_back'];
$order=[];
foreach(array_merge($preferred,$all) as $role){
    if(in_array($role,$images,true)&&!in_array($role,$order,true))$order[]=$role;
}
$labels=['front'=>'Original front','back'=>'Original back','processed_front'=>'Clean front','processed_back'=>'Clean back','model_front'=>'Model front · AI generated','model_back'=>'Model back · AI generated'];

function wardrobe_compatible_categories(string $category): array {
    $map=[
        'tops'=>['bottoms','outerwear','shoes','accessories'],
        'bottoms'=>['tops','outerwear','shoes','accessories'],
        'dresses'=>['outerwear','shoes','accessories'],
        'outerwear'=>['tops','bottoms','dresses','shoes','accessories'],
        'shoes'=>['tops','bottoms','dresses','outerwear','accessories'],
        'accessories'=>['tops','bottoms','dresses','outerwear','shoes','accessories'],
        'activewear'=>['activewear','shoes','accessories'],
        'sleepwear'=>['sleepwear','accessories'],
        'underwear'=>['tops','bottoms','dresses','activewear','sleepwear'],
        'other'=>wardrobe_allowed_categories(),
    ];
    return $map[$category]??wardrobe_allowed_categories();
}

$wornWithByUser=[];
$ww=$link->prepare("SELECT wl.CreatedByUserID,u.Name UserName,comp.WardrobeItemID,wi.Name,wi.Brand,wi.Category,wi.CatalogueImagePreference,COUNT(*) TimesWorn
                    FROM WardrobeWearLogItems anchor
                    JOIN WardrobeWearLogs wl ON wl.WardrobeWearLogID=anchor.WardrobeWearLogID
                    JOIN WardrobeWearLogItems comp ON comp.WardrobeWearLogID=anchor.WardrobeWearLogID AND comp.WardrobeItemID<>anchor.WardrobeItemID
                    JOIN WardrobeItems wi ON wi.WardrobeItemID=comp.WardrobeItemID AND wi.DeletedAt IS NULL
                    LEFT JOIN DC_Users u ON u.UserID=wl.CreatedByUserID
                    WHERE anchor.WardrobeItemID=?
                    GROUP BY wl.CreatedByUserID,u.Name,comp.WardrobeItemID,wi.Name,wi.Brand,wi.Category,wi.CatalogueImagePreference
                    ORDER BY wl.CreatedByUserID,TimesWorn DESC,wi.Name");
$ww->bind_param('i',$id);$ww->execute();$wwr=$ww->get_result();
while($row=$wwr->fetch_assoc()){
    $uid=(int)$row['CreatedByUserID'];
    if(!isset($wornWithByUser[$uid]))$wornWithByUser[$uid]=['name'=>$row['UserName']?:'Unknown user','items'=>[]];
    if(count($wornWithByUser[$uid]['items'])<6)$wornWithByUser[$uid]['items'][]=$row;
}
$ww->close();

$matchItems=[];
$compatible=wardrobe_compatible_categories((string)$item['Category']);
if($compatible){
    $placeholders=implode(',',array_fill(0,count($compatible),'?'));
    $types=str_repeat('s',count($compatible)).'i';
    $params=array_merge($compatible,[$id]);
    $sql="SELECT WardrobeItemID,Name,Brand,Category,Subcategory,PrimaryColour,CatalogueImagePreference FROM WardrobeItems WHERE DeletedAt IS NULL AND Category IN ($placeholders) AND WardrobeItemID<>? ORDER BY Favourite DESC,CreatedAt DESC LIMIT 80";
    $m=$link->prepare($sql);$m->bind_param($types,...$params);$m->execute();$mr=$m->get_result();
    while($row=$mr->fetch_assoc())$matchItems[]=$row;
    $m->close();
}

$page_title=$item['Name'];$wardrobe_modern=true;include 'header.php';
?>
<main class="wardrobe-shell">
  <div class="product-topbar">
    <a class="wardrobe-section-link" href="wardrobe.php">← Back to wardrobe</a>
    <a class="wardrobe-btn wardrobe-btn-light" href="wardrobe_item_edit.php?id=<?=$id?>">Edit item</a>
  </div>
  <?php if($notice):?><div class="alert alert-success mt-3"><?=wardrobe_h($notice)?></div><?php endif;?>
  <?php if($error):?><div class="alert alert-danger mt-3"><?=wardrobe_h($error)?></div><?php endif;?>

  <div class="product-layout">
    <section class="product-gallery-section">
      <?php if(!$order):?>
        <div class="wardrobe-empty"><h2>No images available</h2></div>
      <?php else:?>
        <div class="product-gallery-main">
          <img id="wardrobe-main-image" src="wardrobe_image.php?id=<?=$id?>&view=<?=wardrobe_h($order[0])?>" alt="<?=wardrobe_h($labels[$order[0]]??'Clothing image')?>">
        </div>
        <div class="product-image-label" id="wardrobe-main-label"><?=wardrobe_h($labels[$order[0]]??$order[0])?></div>
        <div class="product-thumbs">
          <?php foreach($order as $index=>$role):?>
            <button class="product-thumb <?=$index===0?'active':''?>" type="button" data-src="wardrobe_image.php?id=<?=$id?>&view=<?=wardrobe_h($role)?>" data-label="<?=wardrobe_h($labels[$role]??$role)?>">
              <img src="wardrobe_image.php?id=<?=$id?>&view=<?=wardrobe_h($role)?>" alt="<?=wardrobe_h($labels[$role]??$role)?>">
            </button>
          <?php endforeach;?>
        </div>
      <?php endif;?>
    </section>

    <aside class="product-panel">
      <div class="product-brand"><?=wardrobe_h($item['Brand']?:'Your wardrobe')?></div>
      <h1 class="product-name"><?=wardrobe_h($item['Name'])?></h1>
      <div class="product-category"><?=wardrobe_h(ucwords($item['Category']))?><?=!empty($item['Subcategory'])?' · '.wardrobe_h($item['Subcategory']):''?></div>
      <div class="product-actions product-actions-primary">
        <button class="btn btn-dark" type="button" id="open-worn-modal">Worn today</button>
        <button class="btn btn-outline-dark" type="button" title="Favourite" aria-label="Favourite item"><?=$item['Favourite']?'♥':'♡'?></button>
      </div>
      <a class="product-edit-link" href="wardrobe_item_edit.php?id=<?=$id?>">Edit item details</a>

      <dl class="product-info">
      <?php $fields=['PrimaryColour'=>'Colour','Pattern'=>'Pattern','Material'=>'Material','Thickness'=>'Thickness','Fit'=>'Fit','Formality'=>'Formality'];foreach($fields as $f=>$label):if(!empty($item[$f])):?>
        <div class="product-info-row"><dt><?=$label?></dt><dd><?=wardrobe_h(ucwords(str_replace('_',' ',$item[$f])))?></dd></div>
      <?php endif;endforeach;?>
      <?php foreach(['Seasons','Occasions','Tags'] as $f):$vals=wardrobe_json_array($item[$f]??null);if($vals):?>
        <div class="product-info-row"><dt><?=$f?></dt><dd><?=wardrobe_h(implode(', ',$vals))?></dd></div>
      <?php endif;endforeach;?>
      </dl>
      <?php if($wornWithByUser):?>
      <section class="worn-with-section">
        <div class="wardrobe-eyebrow">Worn with</div>
        <?php foreach($wornWithByUser as $uid=>$group):$personLabel=((int)$uid===(int)($_SESSION['userID']??0))?'You':$group['name'];?>
          <div class="worn-with-person">
            <h3><?=wardrobe_h($personLabel)?> usually <?=((int)$uid===(int)($_SESSION['userID']??0))?'wear':'wears'?> it with</h3>
            <div class="worn-with-grid">
              <?php foreach($group['items'] as $linked):$lid=(int)$linked['WardrobeItemID'];$lpref=$linked['CatalogueImagePreference']??'processed';?>
                <a class="worn-with-card" href="wardrobe_item.php?id=<?=$lid?>">
                  <img loading="lazy" src="<?=wardrobe_h(wardrobe_image_url($lid,$lpref,'front'))?>" alt="<?=wardrobe_h(trim(($linked['Brand']??'').' '.($linked['Name']??'')))?>">
                  <span><strong><?=wardrobe_h($linked['Name'])?></strong><small><?=wardrobe_h((string)$linked['TimesWorn'])?> times</small></span>
                </a>
              <?php endforeach;?>
            </div>
          </div>
        <?php endforeach;?>
        <a class="wardrobe-section-link" href="wardrobe_outfits.php">See all outfits worn</a>
      </section>
      <?php endif;?>

      <?php if(!empty($item['AIDescription'])):?><div class="product-notes"><div class="wardrobe-eyebrow">Description</div><p><?=nl2br(wardrobe_h($item['AIDescription']))?></p></div><?php endif;?>
      <?php if($item['Notes']):?><div class="product-notes"><div class="wardrobe-eyebrow">Notes</div><p><?=nl2br(wardrobe_h($item['Notes']))?></p></div><?php endif;?>
    </aside>
  </div>
</main>

<div class="wardrobe-modal" id="worn-modal" aria-hidden="true">
  <div class="wardrobe-modal-backdrop" data-close-modal></div>
  <div class="wardrobe-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="worn-modal-title">
    <button class="wardrobe-modal-close" type="button" data-close-modal aria-label="Close">×</button>
    <div class="wardrobe-eyebrow">Today’s outfit</div>
    <h2 id="worn-modal-title">What did you wear with it?</h2>
    <p class="wardrobe-modal-copy">This is optional. Select anything worn with this item so your wardrobe can learn useful outfit combinations.</p>
    <form method="post" action="wardrobe_worn_today.php" id="worn-form">
      <input type="hidden" name="csrf" value="<?=wardrobe_h($csrf)?>">
      <input type="hidden" name="item_id" value="<?=$id?>">
      <?php if($matchItems):?>
      <div class="match-gallery" id="match-gallery">
        <?php foreach($matchItems as $match):$mid=(int)$match['WardrobeItemID'];$mpref=$match['CatalogueImagePreference']??'processed';?>
          <label class="match-card">
            <input type="checkbox" name="with_items[]" value="<?=$mid?>">
            <span class="match-card-image">
              <img loading="lazy" src="<?=wardrobe_h(wardrobe_image_url($mid,$mpref,'front'))?>" data-fallback="<?=wardrobe_h(implode(',',array_slice(wardrobe_image_fallback_chain($mpref,'front'),1)))?>" onerror="var a=this.dataset.fallback.split(',');if(a.length&&a[0]){this.src='wardrobe_image.php?id=<?=$mid?>&view='+a.shift();this.dataset.fallback=a.join(',');}else{this.onerror=null;}" alt="<?=wardrobe_h(trim(($match['Brand']??'').' '.($match['Name']??'')))?>">
              <span class="match-selected">✓</span>
            </span>
            <span class="match-card-text"><strong><?=wardrobe_h($match['Name'])?></strong><small><?=wardrobe_h($match['Brand']?:ucwords($match['Category']))?></small></span>
          </label>
        <?php endforeach;?>
      </div>
      <?php else:?>
        <div class="wardrobe-empty compact"><p>No compatible wardrobe items are available yet.</p></div>
      <?php endif;?>
      <div class="wardrobe-modal-actions">
        <button class="wardrobe-btn wardrobe-btn-light" type="submit" name="save_mode" value="item_only">Just mark as worn</button>
        <button class="wardrobe-btn wardrobe-btn-dark" type="submit" name="save_mode" value="outfit">Save outfit</button>
      </div>
    </form>
  </div>
</div>

<script>
document.querySelectorAll('.product-thumb').forEach(function(b){b.addEventListener('click',function(){document.getElementById('wardrobe-main-image').src=this.dataset.src;document.getElementById('wardrobe-main-label').textContent=this.dataset.label;document.querySelectorAll('.product-thumb').forEach(x=>x.classList.remove('active'));this.classList.add('active');});});
const wornModal=document.getElementById('worn-modal');
function setModal(open){wornModal.classList.toggle('open',open);wornModal.setAttribute('aria-hidden',open?'false':'true');document.body.classList.toggle('wardrobe-modal-open',open);}
document.getElementById('open-worn-modal').addEventListener('click',()=>setModal(true));
document.querySelectorAll('[data-close-modal]').forEach(el=>el.addEventListener('click',()=>setModal(false)));
document.addEventListener('keydown',e=>{if(e.key==='Escape')setModal(false);});
document.querySelectorAll('.match-card input').forEach(input=>input.addEventListener('change',()=>input.closest('.match-card').classList.toggle('selected',input.checked)));
</script>
<?php include 'footer.php';?>
