<?php
declare(strict_types=1);
/* Receptionist — Billing (Upgrade v2 page tree).
 * Front-desk billing: find a patient's bills, print the invoice, and
 * collect payment (billing.manage — granted to receptionists in
 * migration 002). Balances and invoice status are recomputed from the
 * recorded payment rows; nothing is marked paid without a payment. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['receptionist']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('billing.view')) {
    flash('error', 'You do not have permission to view billing.');
    redirect('dashboard.php');
}
$canManage = has_permission('billing.manage');
$base = '../';
$panelSidebar = 'receptionist';

/* ---------------- Printable invoice (standalone, exits) ---------------- */
$printId = (int)($_GET['print'] ?? 0);
if ($printId > 0) {
    $is = $pdo->prepare('SELECT pi.*, p.name AS patient_name, p.patient_code, p.phone, p.email, p.address FROM patient_invoices pi JOIN patients p ON p.id = pi.patient_id WHERE pi.id = ? AND pi.tenant_id = ?');
    $is->execute([$printId, $tid]);
    $inv = $is->fetch();
    if (!$inv) {
        http_response_code(404);
        exit('Invoice not found.');
    }
    $items = $pdo->prepare('SELECT * FROM patient_invoice_items WHERE invoice_id = ? ORDER BY id');
    $items->execute([$printId]);
    $items = $items->fetchAll();
    $pays = $pdo->prepare('SELECT * FROM patient_payments WHERE invoice_id = ? AND tenant_id = ? ORDER BY paid_at');
    $pays->execute([$printId, $tid]);
    $pays = $pays->fetchAll();
    $tenant = $pdo->query('SELECT name FROM tenants WHERE id = ' . $tid)->fetchColumn();
    print_page_start('Invoice ' . $inv['invoice_no'], '../');
    ?>
    <div class="d-flex justify-content-between align-items-start mb-4">
      <div><h4 class="mb-1"><?= e((string)$tenant) ?></h4><small class="text-muted">Patient invoice</small></div>
      <div class="text-end"><h4 class="mb-1"><?= e($inv['invoice_no']) ?></h4>
        <span class="badge text-bg-<?= $inv['status'] === 'paid' ? 'success' : ($inv['status'] === 'partial' ? 'warning' : 'danger') ?>"><?= e(ucfirst((string)$inv['status'])) ?></span>
        <div class="small text-muted mt-1">Issued <?= fmt_date($inv['created_at']) ?><?= $inv['due_date'] ? ' · Due ' . fmt_date($inv['due_date']) : '' ?></div></div>
    </div>
    <div class="mb-4"><small class="text-muted d-block">Billed to</small><strong><?= e($inv['patient_name']) ?></strong> <span class="text-muted">(<?= e($inv['patient_code']) ?>)</span><br>
      <small class="text-muted"><?= e($inv['phone'] ?? '') ?><?= $inv['email'] ? ' · ' . e($inv['email']) : '' ?></small></div>
    <table class="table table-bordered align-middle">
      <thead><tr><th>Description</th><th>Type</th><th class="text-center">Qty</th><th class="text-end">Unit price</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['description']) ?></td><td><?= e(ucfirst((string)$it['item_type'])) ?></td><td class="text-center"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= money((float)$it['unit_price']) ?></td><td class="text-end"><?= money((float)$it['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= money((float)$inv['total']) ?></th></tr>
        <tr><th colspan="4" class="text-end">Paid</th><th class="text-end"><?= money((float)$inv['amount_paid']) ?></th></tr>
        <tr><th colspan="4" class="text-end">Balance due</th><th class="text-end"><?= money((float)$inv['total'] - (float)$inv['amount_paid']) ?></th></tr>
      </tfoot>
    </table>
    <?php if ($pays): ?>
    <h6 class="mt-4">Payments received</h6>
    <table class="table table-sm align-middle"><thead><tr><th>Date</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
      <tbody><?php foreach ($pays as $pay): ?><tr><td><?= fmt_datetime($pay['paid_at']) ?></td><td><?= e(ucfirst((string)$pay['method'])) ?></td><td class="text-end"><?= money((float)$pay['amount']) ?></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
    <?php
    print_page_end();
    exit;
}

if (is_post()) {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'record_payment') {
        if (!$canManage) {
            flash('error', 'You do not have permission to record payments.');
            redirect('billing.php');
        }
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $method = in_array(($_POST['method'] ?? ''), ['cash', 'card', 'bank_transfer', 'insurance', 'online'], true) ? (string)$_POST['method'] : 'cash';
        $is = $pdo->prepare('SELECT * FROM patient_invoices WHERE id = ? AND tenant_id = ?');
        $is->execute([$invId, $tid]);
        $inv = $is->fetch();
        if (!$inv || $amount <= 0) {
            flash('error', 'Enter a valid payment amount.');
        } else {
            $balance = (float)$inv['total'] - (float)$inv['amount_paid'];
            if ($amount > $balance + 0.001) {
                flash('error', 'Payment exceeds the balance due (' . money($balance) . ').');
            } else {
                $pdo->prepare('INSERT INTO patient_payments (tenant_id, invoice_id, patient_id, amount, method, received_by) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$tid, $invId, (int)$inv['patient_id'], $amount, $method, (int)$user['id']]);
                $newPaid = (float)$inv['amount_paid'] + $amount;
                $newStatus = $newPaid >= (float)$inv['total'] - 0.001 ? 'paid' : 'partial';
                $pdo->prepare('UPDATE patient_invoices SET amount_paid = ?, status = ? WHERE id = ?')->execute([$newPaid, $newStatus, $invId]);
                audit_log($pdo, 'patient_payment_recorded', 'patient_payments', (int)$pdo->lastInsertId(), 'Front-desk payment ' . money($amount) . ' on ' . $inv['invoice_no']);
                flash('success', 'Payment of ' . money($amount) . ' recorded on ' . $inv['invoice_no'] . '.');
            }
        }
    }
    redirect('billing.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$onlyDue = (string)($_GET['due'] ?? '1') === '1';
[$perPage, $offset, $page] = page_params(15);
$where = 'pi.tenant_id = ?';
$params = [$tid];
if ($onlyDue) {
    $where .= " AND pi.status IN ('unpaid','partial')";
}
if ($q !== '') {
    $where .= ' AND (pi.invoice_no LIKE ? OR p.name LIKE ? OR p.patient_code LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM patient_invoices pi JOIN patients p ON p.id = pi.patient_id WHERE {$where}");
$cnt->execute($params);
$totalInvoices = (int)$cnt->fetchColumn();
$stmt = $pdo->prepare("SELECT pi.*, p.name AS patient_name, p.patient_code, p.phone FROM patient_invoices pi JOIN patients p ON p.id = pi.patient_id WHERE {$where} ORDER BY pi.id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$collectedToday = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM patient_payments WHERE tenant_id = ? AND DATE(paid_at) = CURDATE()');
$collectedToday->execute([$tid]);
$collectedToday = (float)$collectedToday->fetchColumn();

$pageTitle = 'Billing';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-4"><div class="card stat-card h-100"><span class="stat-ic ic-green"><i class="bi bi-cash-coin"></i></span><span><span class="num d-block"><?= money($collectedToday) ?></span><span class="lbl">Collected today</span></span></div></div>
  <div class="col-6 col-xl-4"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-receipt-cutoff"></i></span><span><span class="num d-block"><?= $totalInvoices ?></span><span class="lbl"><?= $onlyDue ? 'Bills with a balance' : 'Bills shown' ?></span></span></div></div>
  <div class="col-xl-4"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-info-circle"></i></span><span><span class="lbl">Payments are recorded against real invoices — a bill is only marked paid when its payments cover the full total.</span></span></div></div>
</div>

<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-md-5"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search invoice no, patient, ID…" aria-label="Search bills"></div>
      <div class="col-md-3">
        <select class="form-select" name="due" aria-label="Balance filter">
          <option value="1" <?= $onlyDue ? 'selected' : '' ?>>Balance due only</option>
          <option value="0" <?= !$onlyDue ? 'selected' : '' ?>>All bills</option>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Invoice</th><th>Patient</th><th>Issued</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$invoices): ?><tr><td colspan="8" class="text-muted text-center py-4">No bills match this filter.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $inv): $balance = (float)$inv['total'] - (float)$inv['amount_paid']; ?>
        <tr>
          <td><strong><?= e($inv['invoice_no']) ?></strong></td>
          <td><a href="patients.php?id=<?= (int)$inv['patient_id'] ?>"><?= e($inv['patient_name']) ?></a><br><small class="text-muted"><?= e($inv['patient_code']) ?> · <?= e($inv['phone'] ?? '') ?></small></td>
          <td><?= fmt_date($inv['created_at']) ?></td>
          <td class="text-end"><?= money((float)$inv['total']) ?></td>
          <td class="text-end"><?= money((float)$inv['amount_paid']) ?></td>
          <td class="text-end"><?= money($balance) ?></td>
          <td><?= status_badge((string)$inv['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="billing.php?print=<?= (int)$inv['id'] ?>" target="_blank" rel="noopener" title="Print invoice"><i class="bi bi-printer"></i></a>
            <?php if ($canManage && $balance > 0): ?>
            <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#payModal" data-invoice="<?= (int)$inv['id'] ?>" data-no="<?= e($inv['invoice_no']) ?>" data-balance="<?= e((string)$balance) ?>"><i class="bi bi-cash-coin"></i> Collect</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalInvoices > $perPage): ?>
  <div class="card-body d-flex justify-content-end"><?= pager_html($totalInvoices, $perPage, $page) ?></div>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Collect Payment — <span id="payInvNo"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="invoice_id" id="payInvId">
        <p class="small text-muted">Balance due: <strong id="payBalance"></strong></p>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Amount ($) *</label><input class="form-control" type="number" name="amount" id="payAmount" min="0.01" step="0.01" required></div>
          <div class="col-md-6"><label class="form-label">Method</label>
            <select class="form-select" name="method"><option value="cash">Cash</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option><option value="insurance">Insurance</option><option value="online">Online</option></select></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success"><i class="bi bi-cash-coin"></i> Record payment</button></div>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var payModal = document.getElementById('payModal');
  payModal.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    document.getElementById('payInvId').value = btn.getAttribute('data-invoice');
    document.getElementById('payInvNo').textContent = btn.getAttribute('data-no');
    var bal = parseFloat(btn.getAttribute('data-balance') || '0');
    document.getElementById('payBalance').textContent = '$' + bal.toFixed(2);
    var amt = document.getElementById('payAmount');
    amt.value = bal.toFixed(2);
    amt.max = bal.toFixed(2);
  });
});
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
