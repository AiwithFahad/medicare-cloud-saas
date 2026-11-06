<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'accountant', 'receptionist']);
$tid = require_tenant();
check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin', 'accountant'], true) || has_permission('payments.manage');

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error', 'You do not have permission to record payments.'); redirect('payments.php'); }
    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $method = (string)($_POST['method'] ?? 'cash');
    if (!in_array($method, ['cash', 'card', 'bank_transfer', 'insurance'], true)) $method = 'cash';
    $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
    $s = $pdo->prepare('SELECT * FROM patient_invoices WHERE id=? AND tenant_id=? AND status IN ("unpaid","partial")');
    $s->execute([$invoiceId, $tid]);
    $inv = $s->fetch();
    if (!$inv) { flash('error', 'Please choose an unpaid or partially paid invoice.'); redirect('payments.php'); }
    $balance = round((float)$inv['total'] - (float)$inv['amount_paid'], 2);
    if ($amount <= 0 || $amount > $balance) {
        flash('error', 'Amount must be greater than zero and no more than the balance of ' . money($balance) . '.');
        redirect('payments.php');
    }
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO patient_payments(tenant_id,invoice_id,patient_id,amount,method,received_by,paid_at,notes) VALUES(?,?,?,?,?,?,NOW(),?)');
        $ins->execute([$tid, $invoiceId, (int)$inv['patient_id'], $amount, $method, (int)$user['id'], $notes]);
        $payId = (int)$pdo->lastInsertId();
        $newPaid = round((float)$inv['amount_paid'] + $amount, 2);
        $newStatus = $newPaid >= (float)$inv['total'] ? 'paid' : 'partial';
        $upd = $pdo->prepare('UPDATE patient_invoices SET amount_paid=?, status=? WHERE id=? AND tenant_id=?');
        $upd->execute([$newPaid, $newStatus, $invoiceId, $tid]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('error', 'Could not record the payment. Please try again.');
        redirect('payments.php');
    }
    audit_log($pdo, 'payment.record', 'patient_payment', $payId, 'Invoice ' . $inv['invoice_no'] . ' ' . money($amount));
    log_activity($pdo, 'Recorded payment of ' . money($amount) . ' for invoice ' . $inv['invoice_no']);
    flash('success', 'Payment of ' . money($amount) . ' recorded for invoice ' . $inv['invoice_no'] . '.');
    redirect('payments.php');
}

/* Open invoices (for the form + outstanding table) */
$os = $pdo->prepare('SELECT pi.*, p.name AS patient_name, p.patient_code FROM patient_invoices pi JOIN patients p ON p.id=pi.patient_id WHERE pi.tenant_id=? AND pi.status IN ("unpaid","partial") ORDER BY pi.due_date IS NULL, pi.due_date ASC, pi.id ASC');
$os->execute([$tid]);
$openInvoices = $os->fetchAll();

$hs = $pdo->prepare('SELECT pp.*, pi.invoice_no, p.name AS patient_name, u.name AS received_by_name FROM patient_payments pp JOIN patient_invoices pi ON pi.id=pp.invoice_id JOIN patients p ON p.id=pp.patient_id LEFT JOIN users u ON u.id=pp.received_by WHERE pp.tenant_id=? ORDER BY pp.paid_at DESC, pp.id DESC LIMIT 50');
$hs->execute([$tid]);
$history = $hs->fetchAll();

$selInvoice = (int)($_GET['invoice'] ?? 0);
$pageTitle = 'Payments';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-4">
  <div class="col-lg-5">
    <?php if ($canManage): ?>
    <div class="card mb-4">
      <div class="card-header">Record payment</div>
      <div class="card-body">
        <?php if (!$openInvoices): ?><p class="text-muted mb-0">No outstanding invoices — everything is settled.</p><?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label">Invoice *</label>
            <select name="invoice_id" class="form-select" required><option value="">Select invoice…</option>
            <?php foreach ($openInvoices as $inv): $bal = (float)$inv['total'] - (float)$inv['amount_paid']; ?>
              <option value="<?= (int)$inv['id'] ?>" <?= $selInvoice === (int)$inv['id'] ? 'selected' : '' ?>><?= e($inv['invoice_no']) ?> — <?= e($inv['patient_name']) ?> — balance <?= money($bal) ?></option>
            <?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label">Amount ($) *</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Method</label>
            <select name="method" class="form-select"><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="insurance">Insurance</option></select></div>
          <div class="mb-3"><label class="form-label">Notes</label><input name="notes" class="form-control" maxlength="255" placeholder="Optional reference / remarks"></div>
          <button class="btn btn-success w-100"><i class="bi bi-cash-stack me-1"></i>Record payment</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info">You have view-only access to payments. Accountants and hospital admins can record payments.</div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Outstanding balances</div>
      <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Invoice</th><th>Patient</th><th>Due</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
        <?php if (!$openInvoices): ?><tr><td colspan="4" class="text-muted text-center py-3">No outstanding balances.</td></tr><?php endif; ?>
        <?php foreach ($openInvoices as $inv): $bal = (float)$inv['total'] - (float)$inv['amount_paid']; $overdue = $inv['due_date'] && $inv['due_date'] < date('Y-m-d'); ?>
          <tr class="<?= $overdue ? 'table-danger' : '' ?>">
            <td><a href="billing.php?view=<?= (int)$inv['id'] ?>"><?= e($inv['invoice_no']) ?></a> <?= status_badge((string)$inv['status']) ?></td>
            <td><?= e($inv['patient_name']) ?></td>
            <td><?= fmt_date($inv['due_date']) ?><?= $overdue ? ' <span class="badge text-bg-danger">Overdue</span>' : '' ?></td>
            <td class="text-end fw-semibold"><?= money($bal) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Payments history</div>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>Date</th><th>Invoice</th><th>Patient</th><th>Method</th><th>Received by</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
        <?php if (!$history): ?><tr><td colspan="6"><div class="empty-state">No payments recorded yet.</div></td></tr><?php endif; ?>
        <?php foreach ($history as $pay): ?>
          <tr><td><?= fmt_datetime($pay['paid_at']) ?></td>
            <td><a href="billing.php?view=<?= (int)$pay['invoice_id'] ?>"><?= e($pay['invoice_no']) ?></a></td>
            <td><?= e($pay['patient_name']) ?></td>
            <td class="text-capitalize"><?= e(str_replace('_', ' ', (string)$pay['method'])) ?></td>
            <td><?= e((string)($pay['received_by_name'] ?? '—')) ?></td>
            <td class="text-end fw-semibold"><?= money($pay['amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
