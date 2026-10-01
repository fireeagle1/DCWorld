<?php
declare(strict_types=1); session_start(); require 'config.php'; require 'auth.php'; require_once 'wardrobe_image_processing.php'; require_once 'wardrobe_common.php';
$notice='';$error='';$results=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $ids=array_values(array_unique(array_filter(array_map('intval',$_POST['items']??[]),fn($v)=>$v>0)));
    $mode=$_POST['mode']??'both'; if(!in_array($mode,['clean','model','both'],true))$mode='both';
    if(!$ids){$error='Select at least one wardrobe item.';} elseif(count($ids)>10){$error='Process a maximum of 10 items per run to avoid shared-hosting timeouts.';} else {
        @set_time_limit(0);
        foreach($ids as $id){
            $name='Item '.$id; $s=$link->prepare('SELECT Name FROM WardrobeItems WHERE WardrobeItemID=? AND DeletedAt IS NULL');$s->bind_param('i',$id);$s->execute();$s->bind_result($nameDb);if($s->fetch())$name=$nameDb;$s->close();
            try{
                if($mode==='clean'||$mode==='both')wardrobe_process_catalogue_images($link,$id);
                if($mode==='model'||$mode==='both')wardrobe_generate_model_images($link,$id);
                $results[]=['ok'=>true,'name'=>$name,'message'=>'Completed'];
            }catch(Throwable $e){$results[]=['ok'=>false,'name'=>$name,'message'=>$e->getMessage()];}
        }
        $notice='Batch processing finished. Successful derivatives have been saved.';
    }
}
$sql="SELECT wi.WardrobeItemID,wi.Name,wi.Brand,wi.Category,
MAX(CASE WHEN im.ViewType='processed_front' THEN 1 ELSE 0 END) HasCleanFront,
MAX(CASE WHEN im.ViewType='processed_back' THEN 1 ELSE 0 END) HasCleanBack,
MAX(CASE WHEN im.ViewType='model_front' THEN 1 ELSE 0 END) HasModelFront,
MAX(CASE WHEN im.ViewType='model_back' THEN 1 ELSE 0 END) HasModelBack
FROM WardrobeItems wi LEFT JOIN WardrobeImages im ON im.WardrobeItemID=wi.WardrobeItemID
WHERE wi.DeletedAt IS NULL GROUP BY wi.WardrobeItemID,wi.Name,wi.Brand,wi.Category
HAVING HasCleanFront=0 OR HasCleanBack=0 OR HasModelFront=0 OR HasModelBack=0
ORDER BY wi.UpdatedAt DESC";
$res=$link->query($sql);$items=[];while($row=$res->fetch_assoc())$items[]=$row;
$page_title='Wardrobe image backlog';$wardrobe_modern=true;include 'header.php';
?>
<div class="container py-4"><div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">Image backlog</h1><p class="text-muted mb-0">Generate missing clean and model images in batches of up to 10 items.</p></div><a class="btn btn-outline-secondary" href="wardrobe.php">Wardrobe</a></div>
<?php if($notice):?><div class="alert alert-success"><?=wardrobe_h($notice)?></div><?php endif;?><?php if($error):?><div class="alert alert-danger"><?=wardrobe_h($error)?></div><?php endif;?>
<?php if($results):?><div class="card mb-4"><div class="card-header">Latest batch</div><ul class="list-group list-group-flush"><?php foreach($results as $r):?><li class="list-group-item d-flex justify-content-between"><span><?=wardrobe_h($r['name'])?></span><span class="<?=$r['ok']?'text-success':'text-danger'?>"><?=wardrobe_h($r['message'])?></span></li><?php endforeach;?></ul></div><?php endif;?>
<?php if(!$items):?><div class="alert alert-light border">There are no items with missing generated images.</div><?php else:?><form method="post"><div class="card shadow-sm"><div class="card-header d-flex justify-content-between"><span><?=count($items)?> item(s) need derivatives</span><button type="button" class="btn btn-sm btn-outline-secondary" id="select-all">Select all shown</button></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th></th><th>Item</th><th>Clean</th><th>Model</th></tr></thead><tbody><?php foreach($items as $item):?><tr><td><input class="form-check-input backlog-item" type="checkbox" name="items[]" value="<?=(int)$item['WardrobeItemID']?>"></td><td><a href="wardrobe_item.php?id=<?=(int)$item['WardrobeItemID']?>"><?=wardrobe_h($item['Name'])?></a><div class="small text-muted"><?=wardrobe_h($item['Brand'])?><?=($item['Brand']&&$item['Category'])?' · ':''?><?=wardrobe_h(ucwords($item['Category']))?></div></td><td><?=($item['HasCleanFront']&&$item['HasCleanBack'])?'<span class="badge bg-success">Complete</span>':'<span class="badge bg-warning text-dark">Missing</span>'?></td><td><?=($item['HasModelFront']&&$item['HasModelBack'])?'<span class="badge bg-success">Complete</span>':'<span class="badge bg-warning text-dark">Missing</span>'?></td></tr><?php endforeach;?></tbody></table></div><div class="card-footer d-flex flex-wrap gap-2 align-items-center"><select class="form-select w-auto" name="mode"><option value="both">Generate missing clean + model</option><option value="clean">Generate clean images</option><option value="model">Generate model images</option></select><button class="btn btn-primary" onclick="return confirm('Generate images for the selected items? This may use OpenAI credits.')">Run batch</button><span class="small text-muted">Maximum 10 selected items per run.</span></div></div></form><script>document.getElementById('select-all').addEventListener('click',function(){document.querySelectorAll('.backlog-item').forEach((x,i)=>x.checked=i<10);});</script><?php endif;?></div><?php include 'footer.php';?>
