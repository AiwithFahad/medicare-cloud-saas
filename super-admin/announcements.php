<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

$audiences = ['all', 'tenants', 'staff'];

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $annId = (int)($_POST['announcement_id'] ?? 0);

    if ($action === 'save') {
        $title = trim((string)($_POST['title'] ?? ''));
        $body = trim((string)($_POST['body'] ?? ''));
        $audience = (string)($_POST['audience'] ?? 'all');
        if (!in_array($audience, $audiences, true)) {
            $audience = 'all';
        }
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($title === '') {
            flash('error', 'Announcement title is required.');
            redirect('announcements.php');
        }
        if ($annId > 0) {
            $pdo->prepare('UPDATE announcements SET title = ?, body = ?, audience = ?, is_active = ? WHERE id = ?')
                ->execute([$title, $body ?: null, $audience, $isActive, $annId]);
            audit_log($pdo, 'announcement_updated', 'announcements', $annId, 'Announcement updated: ' . $title);
            flash('success', 'Announcement updated.');
        } else {
            $pdo->prepare('INSERT INTO announcements (title, body, audience, is_active, created_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$title, $body ?: null, $audience, $isActive, (int)$user['id']]);
            audit_log($pdo, 'announcement_created', 'announcements', (int)$pdo->lastInsertId(), 'Announcement created: ' . $title);
            flash('success', 'Announcement published.');
        }
    } elseif ($action === 'toggle' && $annId > 0) {
        $pdo->prepare('UPDATE announcements SET is_active = 1 - is_active WHERE id = ?')->execute([$annId]);
        audit_log($pdo, 'announcement_toggled', 'announcements', $annId, 'Announcement active flag toggled');
        flash('success', 'Announcement status changed.');
    } elseif ($action === 'delete' && $annId > 0) {
        $pdo->prepare('DELETE FROM announcements WHERE id = ?')->execute([$annId]);
        audit_log($pdo, 'announcement_deleted', 'announcements', $annId, 'Announcement deleted');
        flash('success', 'Announcement deleted.');
    }
    redirect('announcements.php');
}

$announcements = $pdo->query(
    'SELECT a.*, u.name AS author_name
       FROM announcements a
       LEFT JOIN users u ON u.id = a.created_by
      ORDER BY a.id DESC'
)->fetchAll();

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM announcements WHERE id = ?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch() ?: null;
}

$pageTitle = 'Announcements';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Announcements (<?= count($announcements) ?>)</div>
      <div class="card-body">
        <?php if (!$announcements): ?><p class="text-muted mb-0">No announcements yet. Create the first one on the right.</p><?php endif; ?>
        <?php foreach ($announcements as $a): ?>
          <div class="border rounded-3 p-3 mb-3" style="border-color:var(--line)">
            <div class="d-flex justify-content-between flex-wrap gap-2">
              <strong><?= e($a['title']) ?></strong>
              <span class="d-flex gap-1 align-items-center">
                <span class="chip">Audience: <?= e(ucfirst($a['audience'])) ?></span>
                <?= (int)$a['is_active'] === 1 ? status_badge('active') : status_badge('inactive') ?>
              </span>
            </div>
            <?php if ($a['body']): ?><p class="mb-2 mt-1" style="white-space:pre-wrap"><?= e($a['body']) ?></p><?php endif; ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
              <small class="text-muted"><?= e($a['author_name'] ?? 'Platform') ?> · <?= fmt_datetime($a['created_at']) ?></small>
              <span class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-primary" href="announcements.php?edit=<?= (int)$a['id'] ?>"><i class="bi bi-pencil"></i> Edit</a>
                <form method="post" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="announcement_id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-outline-secondary" type="submit"><?= (int)$a['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="post" class="d-inline" data-confirm="Delete this announcement?">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="announcement_id" value="<?= (int)$a['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                </form>
              </span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header"><?= $edit ? 'Edit announcement' : 'New announcement' ?></div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="announcement_id" value="<?= (int)($edit['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label small fw-semibold">Title *</label><input class="form-control" name="title" required value="<?= e($edit['title'] ?? '') ?>"></div>
          <div class="mb-2"><label class="form-label small fw-semibold">Body</label><textarea class="form-control" name="body" rows="5"><?= e($edit['body'] ?? '') ?></textarea></div>
          <div class="mb-2"><label class="form-label small fw-semibold">Audience</label>
            <select class="form-select" name="audience">
              <?php foreach ($audiences as $aud): ?><option value="<?= e($aud) ?>" <?= ($edit['audience'] ?? 'all') === $aud ? 'selected' : '' ?>><?= e(ucfirst($aud)) ?></option><?php endforeach; ?>
            </select></div>
          <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" id="annActive" value="1" <?= !$edit || (int)$edit['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="annActive">Active (visible)</label></div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary" type="submit"><i class="bi bi-megaphone"></i> <?= $edit ? 'Save changes' : 'Publish' ?></button>
            <?php if ($edit): ?><a class="btn btn-outline-secondary" href="announcements.php">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
