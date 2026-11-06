<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);

    if ($action === 'mark_paid' && $invoiceId > 0) {
        /* An invoice may only be marked paid when money was actually
         * verified on the Payments page — never on trust. */
        $chk = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'verified'");
        $chk->execute([$invoiceId]);
        if ((int)$chk->fetchColumn() === 0) {
            flash('error', 'Cannot mark paid: no verified payment exists for this invoice. Verify a payment on the Payments page first.');
        } else {
            $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$invoiceId]);
            audit_log($pdo, 'invoice_marked_paid', 'invoices', $invoiceId, 'Invoice marked paid (verified payment exists)');
            flash('success', 'Invoice marked paid.');
        }
    } elseif ($action === 'cancel' && $invoiceId > 0) {
        $pdo->prepare("UPDATE invoices SET status = 'cancelled' WHERE id = ? AND status <> 'paid'")->execute([$invoiceId]);
        audit_log($pdo, 'invoice_cancelled', 'invoices', $invoiceId, 'Invoice cancelled');
        flash('success', 'Invoice cancelled.');
    } elseif ($action === 'create') {
        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $due = trim((string)($_POST['due_date'] ?? ''));
        $t = $pdo->prepare('SELECT name FROM tenants WHERE id = ?');
        $t->execute([$tenantId]);
        $tName = $t->fetchColumn();
        if ($tName === false || $amount <= 0) {
            flash('error', 'Choose a tenant and enter an amount greater than zero.');
        } else {
            $tmpNo = 'TMP-' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare("INSERT INTO invoices (tenant_id, subscription_id, invoice_no, amount, discount, tax, total, status, due_date) VALUES (?, NULL, ?, ?, 0, 0, ?, 'pending', ?)")
                ->execute([$tenantId, $tmpNo, $amount, $amount, $due === '' ? null : $due]);
            $newId = (int)$pdo->lastInsertId();
            $invoiceNo = sprintf('INV-%s-%04d', date('Y'), $newId);
            $pdo->prepare('UPDATE invoices SET invoice_no = ? WHERE id = ?')->execute([$invoiceNo, $newId]);
            audit_log($pdo, 'invoice_created', 'invoices', $newId, 'Manual invoice ' . $invoiceNo . ' (' . money($amount) . ') for ' . $tName);
            flash('success', 'Invoice ' . $invoiceNo . ' created for ' . $tName . '.');
        }
    }
    redirect('invoices.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(i.invoice_no LIKE ? OR t.name LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, ['draft', 'pending', 'paid', 'overdue', 'cancelled'], true)) {
    $where[] = 'i.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM invoices i JOIN tenants t ON t.id = i.tenant_id' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$listStmt = $pdo->prepare(
    'SELECT i.*, t.name AS tenant_name, p.name AS plan_name,
            (SELECT COUNT(*) FROM payments vp WHERE vp.invoice_id = i.id AND vp.status = "verified") AS verified_payments
       FROM invoices i
       JOIN tenants t ON t.id = i.tenant_id
       LEFT JOIN subscriptions s ON s.id = i.subscription_id
       LEFT JOIN subscription_plans p ON p.id = s.plan_id' . $whereSql . ' ORDER BY i.id DESC LIMIT ? OFFSET ?'
);
foreach ($params as $i2 => $v) {
    $listStmt->bindValue($i2 + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$invoices = $listStmt->fetchAll();
$tenants = $pdo->query('SELECT id, name FROM tenants ORDER BY name')->fetchAll();

$pageTitle = 'Invoices';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3">
  <div class="col-lg-9">
    <div class="card">
      <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <span>Platform invoices (<?= $total ?>)</span>
        <form method="get" class="d-flex gap-2">
          <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search invoice # or tenant…">
          <select class="form-select form-select-sm" name="status" style="width:auto">
            <option value="">All statuses</option>
            <?php foreach (['draft', 'pending', 'paid', 'overdue', 'cancelled'] as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option><?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-primary" type="submit">Filter</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Invoice</th><th>Tenant</th><th>Total</th><th>Status</th><th>Due</th><th>Paid</th><th class="text-end">Actions</th></tr></thead>
          <tbody>
          <?php if (!$invoices): ?><tr><td colspan="7" class="text-muted text-center py-4">No invoices match your filters.</td></tr><?php endif; ?>
          <?php foreach ($invoices as $inv): ?>
            <tr>
              <td><strong><?= e($inv['invoice_no']) ?></strong><?php if ($inv['plan_name']): ?><br><small class="text-muted"><?= e($inv['plan_name']) ?></small><?php endif; ?></td>
              <td><?= e($inv['tenant_name']) ?></td>
              <td><strong><?= money($inv['total']) ?></strong><?php if ((float)$inv['discount'] > 0): ?><br><small class="text-muted">− <?= money($inv['discount']) ?> discount</small><?php endif; ?></td>
              <td><?= status_badge((string)$inv['status']) ?></td>
              <td><?= fmt_date($inv['due_date']) ?></td>
              <td><?= fmt_datetime($inv['paid_at']) ?></td>
              <td class="text-end text-nowrap">
                <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
                  <?php if ((int)$inv['verified_payments'] > 0): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                      <button class="btn btn-sm btn-success" type="submit">Mark paid</button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                      <button class="btn btn-sm btn-outline-success" type="submit" title="Requires a verified payment">Mark paid</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" class="d-inline" data-confirm="Cancel this invoice?">
                    <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button>
                  </form>
                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($total > $perPage): ?><div class="card-body d-flex justify-content-end"><?= pager_html($total, $perPage, $page) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card">
      <div class="card-header">Create manual invoice</div>
      <div class="card-body">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create">
          <div class="mb-2"><label class="form-label small fw-semibold">Tenant *</label>
            <select class="form-select" name="tenant_id" required>
              <option value="">Select tenant…</option>
              <?php foreach ($tenants as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="mb-2"><label class="form-label small fw-semibold">Amount ($) *</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" required></div>
          <div class="mb-3"><label class="form-label small fw-semibold">Due date</label><input class="form-control" type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+14 days'))) ?>"></div>
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-plus-lg"></i> Create invoice</button>
        </form>
        <small class="text-muted d-block mt-2">The invoice starts as <strong>pending</strong>. It can only be marked paid once a matching payment has been verified.</small>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/sa-footer.php'; ?>
