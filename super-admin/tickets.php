<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

$ticketStatuses = ['open', 'in_progress', 'resolved', 'closed'];

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    if ($ticketId > 0) {
        $s = $pdo->prepare('SELECT * FROM support_tickets WHERE id = ?');
        $s->execute([$ticketId]);
        $ticket = $s->fetch();
        if (!$ticket) {
            flash('error', 'Ticket not found.');
            redirect('tickets.php');
        }
        if ($action === 'reply') {
            $message = trim((string)($_POST['message'] ?? ''));
            if ($message === '') {
                flash('error', 'Reply message cannot be empty.');
            } else {
                $pdo->prepare('INSERT INTO support_ticket_replies (ticket_id, user_id, message) VALUES (?, ?, ?)')
                    ->execute([$ticketId, (int)$user['id'], $message]);
                $pdo->prepare('UPDATE support_tickets SET updated_at = NOW() WHERE id = ?')->execute([$ticketId]);
                audit_log($pdo, 'ticket_replied', 'support_tickets', $ticketId, 'Super admin replied to: ' . $ticket['subject']);
                notify_user($pdo, (int)$ticket['tenant_id'], (int)$ticket['user_id'], 'Support replied to your ticket', 'Ticket "' . $ticket['subject'] . '" has a new reply from the platform team.', 'info');
                flash('success', 'Reply sent.');
            }
        } elseif ($action === 'status') {
            $newStatus = (string)($_POST['new_status'] ?? '');
            if (!in_array($newStatus, $ticketStatuses, true)) {
                flash('error', 'Invalid status.');
            } else {
                $pdo->prepare('UPDATE support_tickets SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $ticketId]);
                audit_log($pdo, 'ticket_status_changed', 'support_tickets', $ticketId, 'Status ' . $ticket['status'] . ' → ' . $newStatus . ': ' . $ticket['subject']);
                notify_user($pdo, (int)$ticket['tenant_id'], (int)$ticket['user_id'], 'Ticket status updated', 'Ticket "' . $ticket['subject'] . '" is now ' . str_replace('_', ' ', $newStatus) . '.', 'info');
                flash('success', 'Ticket status updated.');
            }
        }
    }
    redirect('tickets.php?view=' . $ticketId);
}

$priorityBadge = static function (string $priority): string {
    $cls = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'info', 'low' => 'secondary'][$priority] ?? 'secondary';
    return '<span class="badge text-bg-' . $cls . '">' . e(ucfirst($priority)) . '</span>';
};

/* ---------------- Detail view ---------------- */
$viewId = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($viewId > 0) {
    $s = $pdo->prepare(
        'SELECT st.*, t.name AS tenant_name, u.name AS requester_name, u.email AS requester_email
           FROM support_tickets st
           JOIN tenants t ON t.id = st.tenant_id
           JOIN users u ON u.id = st.user_id
          WHERE st.id = ?'
    );
    $s->execute([$viewId]);
    $ticket = $s->fetch();
    if (!$ticket) {
        flash('error', 'Ticket not found.');
        redirect('tickets.php');
    }
    $rStmt = $pdo->prepare(
        'SELECT r.*, u.name AS author_name, ro.slug AS author_role, u.tenant_id AS author_tenant
           FROM support_ticket_replies r
           JOIN users u ON u.id = r.user_id
           JOIN roles ro ON ro.id = u.role_id
          WHERE r.ticket_id = ? ORDER BY r.id'
    );
    $rStmt->execute([$viewId]);
    $replies = $rStmt->fetchAll();

    $pageTitle = 'Ticket #' . (int)$ticket['id'];
    include __DIR__ . '/sa-header.php';
    ?>
    <p><a href="tickets.php" class="small"><i class="bi bi-arrow-left"></i> Back to tickets</a></p>
    <div class="row g-3">
      <div class="col-lg-8">
        <div class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span><?= e($ticket['subject']) ?></span>
            <span><?= status_badge((string)$ticket['status']) ?></span>
          </div>
          <div class="card-body">
            <?php if (!$replies): ?><p class="text-muted">No replies yet.</p><?php endif; ?>
            <?php foreach ($replies as $r): $isPlatform = $r['author_tenant'] === null; ?>
              <div class="d-flex gap-2 mb-3">
                <span class="avatar-sm flex-none" <?= $isPlatform ? 'style="background:#dbeafe;color:#1d4ed8"' : '' ?>><?= e(initials($r['author_name'])) ?></span>
                <div class="flex-grow-1 border rounded-3 p-3" style="border-color:var(--line)">
                  <div class="d-flex justify-content-between flex-wrap">
                    <strong class="small"><?= e($r['author_name']) ?> <?php if ($isPlatform): ?><span class="badge text-bg-primary ms-1">Platform</span><?php endif; ?></strong>
                    <small class="text-muted"><?= fmt_datetime($r['created_at']) ?></small>
                  </div>
                  <p class="mb-0 mt-1" style="white-space:pre-wrap"><?= e($r['message']) ?></p>
                </div>
              </div>
            <?php endforeach; ?>
            <hr>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reply">
              <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
              <label class="form-label small fw-semibold">Reply as Super Admin</label>
              <textarea class="form-control mb-2" name="message" rows="4" required placeholder="Write your reply to the tenant…"></textarea>
              <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send"></i> Send reply</button>
            </form>
          </div>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-header">Ticket details</div>
          <div class="card-body">
            <dl class="row small mb-0">
              <dt class="col-4 text-muted">Tenant</dt><dd class="col-8"><?= e($ticket['tenant_name']) ?></dd>
              <dt class="col-4 text-muted">Raised by</dt><dd class="col-8"><?= e($ticket['requester_name']) ?><br><small class="text-muted"><?= e($ticket['requester_email']) ?></small></dd>
              <dt class="col-4 text-muted">Category</dt><dd class="col-8"><?= e($ticket['category']) ?></dd>
              <dt class="col-4 text-muted">Priority</dt><dd class="col-8"><?= $priorityBadge((string)$ticket['priority']) ?></dd>
              <dt class="col-4 text-muted">Status</dt><dd class="col-8"><?= status_badge((string)$ticket['status']) ?></dd>
              <dt class="col-4 text-muted">Created</dt><dd class="col-8"><?= fmt_datetime($ticket['created_at']) ?></dd>
              <dt class="col-4 text-muted">Updated</dt><dd class="col-8"><?= fmt_datetime($ticket['updated_at']) ?></dd>
            </dl>
          </div>
        </div>
        <div class="card">
          <div class="card-header">Change status</div>
          <div class="card-body">
            <form method="post" class="d-flex gap-2">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
              <select class="form-select" name="new_status">
                <?php foreach ($ticketStatuses as $st): ?><option value="<?= e($st) ?>" <?= $ticket['status'] === $st ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $st))) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-primary" type="submit">Update</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    include __DIR__ . '/sa-footer.php';
    return;
}

/* ---------------- List ---------------- */
$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(st.subject LIKE ? OR t.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, $ticketStatuses, true)) {
    $where[] = 'st.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM support_tickets st JOIN tenants t ON t.id = st.tenant_id' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$listStmt = $pdo->prepare(
    'SELECT st.*, t.name AS tenant_name, u.name AS requester_name,
            (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = st.id) AS reply_count
       FROM support_tickets st
       JOIN tenants t ON t.id = st.tenant_id
       JOIN users u ON u.id = st.user_id' . $whereSql . ' ORDER BY st.id DESC LIMIT ? OFFSET ?'
);
foreach ($params as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$tickets = $listStmt->fetchAll();

$pageTitle = 'Support Tickets';
include __DIR__ . '/sa-header.php';
?>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span>All support tickets (<?= $total ?>)</span>
    <form method="get" class="d-flex gap-2">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search subject or tenant…">
      <select class="form-select form-select-sm" name="status" style="width:auto">
        <option value="">All statuses</option>
        <?php foreach ($ticketStatuses as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $st))) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>#</th><th>Subject</th><th>Tenant</th><th>Category</th><th>Priority</th><th>Status</th><th>Replies</th><th>Updated</th><th class="text-end"></th></tr></thead>
      <tbody>
      <?php if (!$tickets): ?><tr><td colspan="9" class="text-muted text-center py-4">No tickets match your filters.</td></tr><?php endif; ?>
      <?php foreach ($tickets as $tk): ?>
        <tr>
          <td><?= (int)$tk['id'] ?></td>
          <td><strong><?= e($tk['subject']) ?></strong><br><small class="text-muted">by <?= e($tk['requester_name']) ?></small></td>
          <td><?= e($tk['tenant_name']) ?></td>
          <td><?= e($tk['category']) ?></td>
          <td><?= $priorityBadge((string)$tk['priority']) ?></td>
          <td><?= status_badge((string)$tk['status']) ?></td>
          <td><?= (int)$tk['reply_count'] ?></td>
          <td class="small"><?= fmt_datetime($tk['updated_at']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="tickets.php?view=<?= (int)$tk['id'] ?>"><i class="bi bi-eye"></i> Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($total > $perPage): ?><div class="card-body d-flex justify-content-end"><?= pager_html($total, $perPage, $page) ?></div><?php endif; ?>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
