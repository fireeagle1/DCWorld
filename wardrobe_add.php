<?php
declare(strict_types=1);
session_start();
require 'config.php';
require 'auth.php';
require_once 'wardrobe_common.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '') ?: 'New clothing item';
        $category = $_POST['category'] ?? 'other';
        if (!in_array($category, wardrobe_allowed_categories(), true)) $category = 'other';
        $uid = (int)$_SESSION['userID'];
        $link->begin_transaction();
        $stmt = $link->prepare("INSERT INTO WardrobeItems (Name, Category, ProcessingStatus, CreatedByUserID, UpdatedByUserID) VALUES (?, ?, 'pending', ?, ?)");
        $stmt->bind_param('ssii', $name, $category, $uid, $uid);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();
        wardrobe_store_image($link, $id, 'front', $_FILES['front'] ?? []);
        wardrobe_store_image($link, $id, 'back', $_FILES['back'] ?? []);
        $link->commit();
        if (isset($_POST['analyse_now'])) {
            header('Location: wardrobe_analyse.php?id=' . $id);
        } else {
            header('Location: wardrobe_item.php?id=' . $id);
        }
        exit;
    } catch (Throwable $e) {
        @$link->rollback();
        $error = $e->getMessage();
    }
}
$page_title = 'Add clothing';
$wardrobe_modern=true;include 'header.php';
?>
<div class="container py-4" style="max-width:900px">
  <h1>Add clothing</h1>
  <p class="text-muted">Upload clear front and back photographs of the same garment. AI analysis can suggest the name and metadata after upload.</p>
  <?php if ($error): ?><div class="alert alert-danger"><?=wardrobe_h($error)?></div><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="card shadow-sm">
    <div class="card-body row g-3">
      <div class="col-md-8"><label class="form-label">Temporary name <span class="text-muted">(optional)</span></label><input class="form-control" name="name" value="<?=wardrobe_h($_POST['name'] ?? '')?>" placeholder="AI will suggest a name"></div>
      <div class="col-md-4"><label class="form-label">Initial category <span class="text-muted">(optional)</span></label><select class="form-select" name="category"><option value="other">Let AI decide</option><?php foreach (wardrobe_allowed_categories() as $c): if ($c==='other') continue; ?><option value="<?=$c?>"><?=ucwords($c)?></option><?php endforeach; ?></select></div>
      <div class="col-md-6"><label class="form-label">Front photo</label><input required type="file" accept="image/jpeg,image/png,image/webp" class="form-control" name="front"></div>
      <div class="col-md-6"><label class="form-label">Back photo</label><input required type="file" accept="image/jpeg,image/png,image/webp" class="form-control" name="back"></div>
      <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="analyse_now" id="analyse_now" checked><label class="form-check-label" for="analyse_now">Analyse the garment after upload</label></div><div class="form-text">The analysis may take up to around two minutes on shared hosting. If it fails, the item remains saved and can be analysed again.</div></div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2"><a href="wardrobe.php" class="btn btn-outline-secondary">Cancel</a><button class="btn btn-primary">Upload clothing</button></div>
  </form>
</div>
<?php include 'footer.php'; ?>
