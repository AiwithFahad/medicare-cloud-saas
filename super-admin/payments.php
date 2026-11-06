<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

/*
 * Payments — the ONLY place a payment can become 'verified'.
 * VERIFY runs in one transaction: payment → verified (verified_by, paid_at),
 * the linked invoice → paid, and the linked subscription → active with its
 * period extended by one billing cycle from today when it has expired.
 * REJECT marks the payment 'failed' and deliberately leaves the invoice
 * pending — a rejected payment must never settle an invoice.
 */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $paymentId = (int)($_POST['payment_id'] ?? 0);

    if ($action === 'verify' && $paymentId > 0) {
        try {
            $pdo->beginTransaction();
            $s = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $s->execute([$paymentId]);
            $pay = $s->fetch();
            if (!$pay) {
                $pdo->rollBack();
                flash('error', 'Payment not found.');
                redirect('payments.php');
            }
            if ($pay['status'] !== 'pending') {
                $pdo->rollBack();
                flash('error', 'Only pending payments can be verified. This payment is already ' . $pay['status'] . '.');
                redirect('payments.php');
            }
            $paidAt = $pay['paid_at'] ?: date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE payments SET status = 'verified', verified_by = ?, paid_at = ? WHERE id = ?")
                ->execute([(int)$user['id'], $paidAt, $paymentId]);

            if (!empty($pay['invoice_id'])) {
                $pdo->prepare("UPDATE invoices SET status = 'paid', paid_at = NOW() WHERE id = ?")
                    ->execute([(int)$pay['invoice_id']]);
            }

            if (!empty($pay['subscription_id'])) {
                $ss = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ? FOR UPDATE');
                $ss->execute([(int)$pay['subscription_id']]);
                $sub = $ss->fetch();
                if ($sub) {
                    $expired = empty($sub['ends_at']) || strtotime((string)$sub['ends_at']) < strtotime('today');
                    if ($expired) {
                        $interval = $sub['billing_cycle'] === 'yearly' ? '+1 year' : '+1 month';
                        $newEnd = date('Y-m-d', strtotime('today ' . $interval));
                        $pdo->prepare("UPDATE subscriptions SET status = 'active', ends_at = ?, starts_at = COALESCE(starts_at, CURDATE()) WHERE id = ?")
                            ->execute([$newEnd, (int)$sub['id']]);
                    } else {
                        $pdo->prepare("UPDATE subscriptions SET status = 'active' WHERE id = ?")
                            ->execute([(int)$sub['id']]);
                    }
                }
            }
            audit_log($pdo, 'payment_verified', 'payments', $paymentId, 'Payment verified: ' . money($pay['amount']) . ' (' . ($pay['transaction_ref'] ?? 'no ref') . ')');
            log_activity($pdo, 'Payment #' . $paymentId . ' verified (' . money($pay['amount']) . ')');
            $pdo->commit();
            flash('success', 'Payment verified. The linked invoice is paid and the subscription is active.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('error', 'Verification failed — no changes were saved. Please try again.');
        }
    } elseif ($action === 'reject' && $paymentId > 0) {
        $s = $pdo->prepare('SELECT * FROM payments WHERE id = ?');
        $s->execute([$paymentId]);
        $pay = $s->fetch();
        if (!$pay) {
            flash('error', 'Payment not found.');
        } elseif ($pay['status'] !== 'pending') {
            flash('error', 'Only pending payments can be rejected.');
        } else {
            /* Invoice intentionally untouched: it stays pending. */
            $pdo->prepare("UPDATE payments SET status = 'failed' WHERE id = ?")->execute([$paymentId]);
            audit_log($pdo, 'payment_rejected', 'payments', $paymentId, 'Payment rejected: ' . money($pay['amount']) . ' — invoice left pending');
            flash('success', 'Payment rejected (marked failed). The linked invoice remains pending.');
        }
    }
    redirect('payments.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(t.name LIKE ? OR pay.transaction_ref LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if (in_array($statusFilter, ['pending', 'verified', 'failed', 'refunded'], true)) {
    $where[] = 'pay.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM payments pay JOIN tenants t ON t.id = pay.tenant_id' . $whereSql);
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$listStmt = $pdo->prepare(
    'SELECT pay.*, t.name AS tenant_name, i.invoice_no, p.name AS plan_name, v.name AS verifier_name
       FROM payments pay
       JOIN tenants t ON t.id = pay.tenant_id
       LEFT JOIN invoices i ON i.id = pay.invoice_id
       LEFT JOIN subscriptions s ON s.id = pay.subscription_id
       LEFT JOIN subscription_plans p ON p.id = s.plan_id
       LEFT JOIN users v ON v.id = pay.verified_by' . $whereSql . ' ORDER BY pay.id DESC LIMIT ? OFFSET ?'
);
foreach ($params as $i => $v) {
    $listStmt->bindValue($i + 1, $v);
}
$listStmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$listStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$listStmt->execute();
$payments = $listStmt->fetchAll();

$pageTitle = 'Payments';
include __DIR__ . '/sa-header.php';
?>
<div class="alert alert-info"><i class="bi bi-shield-check me-1"></i> This page is the <strong>only</strong> place a payment becomes <strong>verified</strong>. Verifying also marks the linked invoice paid and activates / extends the linked subscription.</div>
<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
    <span>Platform payments (<?= $total ?>)</span>
    <form method="get" class="d-flex gap-2">
      <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Search tenant or reference…">
      <select class="form-select form-select-sm" name="status" style="width:auto">
        <option value="">All statuses</option>
        <?php foreach (['pending', 'verified', 'failed', 'refunded'] as $st): ?><option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Tenant</th><th>Amount</th><th>Method</th><th>Reference</th><th>Invoice</th><th>Status</th><th>Date</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$payments): ?><tr><td colspan="8" class="text-muted text-center py-4">No payments match your filters.</td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><strong><?= e($p['tenant_name']) ?></strong><?php if ($p['plan_name']): ?><br><small class="text-muted"><?= e($p['plan_name']) ?></small><?php endif; ?></td>
          <td><strong><?= money($p['amount']) ?></strong> <small class="text-muted"><?= e($p['currency']) ?></small></td>
          <td><?= e(ucwords(str_replace('_', ' ', (string)$p['method']))) ?></td>
          <td class="small"><?= e($p['transaction_ref'] ?? '—') ?><?php if ($p['notes']): ?><br><small class="text-muted"><?= e($p['notes']) ?></small><?php endif; ?></td>
          <td><?= $p['invoice_no'] ? e($p['invoice_no']) : '<span class="text-muted">—</span>' ?></td>
          <td><?= status_badge((string)$p['status']) ?><?php if ($p['status'] === 'verified' && $p['verifier_name']): ?><br><small class="text-muted">by <?= e($p['verifier_name']) ?></small><?php endif; ?></td>
          <td class="small"><?= fmt_datetime($p['paid_at'] ?? $p['created_at']) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($p['status'] === 'pending'): ?>
              <form method="post" class="d-inline" data-confirm="Verify this payment of <?= e(money($p['amount'])) ?>? The linked invoice will be marked paid and the subscription activated.">
                <?= csrf_field() ?><input type="hidden" name="action" value="verify"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg"></i> Verify</button>
              </form>
              <form method="post" class="d-inline" data-confirm="Reject this payment? It will be marked failed and the invoice stays pending.">
                <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-lg"></i> Reject</button>
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
<?php include __DIR__ . '/sa-footer.php'; ?>
