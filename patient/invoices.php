<?php
declare(strict_types=1);
/* Patient portal — My Invoices (Upgrade v2 page tree).
 * The patient's own bills with line items, recorded payments and a
 * printable invoice (?print=N). There is deliberately no fake "pay
 * online" button: balances are settled at the hospital desk, and the
 * status here always reflects real recorded payments. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'patient';
$patient = panel_patient($pdo, $tid, (int)$user['id']);

$printId = (int)($_GET['print'] ?? 0);
if ($printId > 0 && $patient) {
    $is = $pdo->prepare('SELECT pi.*, p.name AS patient_name, p.patient_code, p.phone, p.email FROM patient_invoices pi JOIN patients p ON p.id = pi.patient_id WHERE pi.id = ? AND pi.tenant_id = ? AND pi.patient_id = ?');
    $is->execute([$printId, $tid, (int)$patient['id']]);
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
    <div class="mb-4"><small class="text-muted d-block">Billed to</small><strong><?= e($inv['patient_name']) ?></strong> <span class="text-muted">(<?= e($inv['patient_code']) ?>)</span></div>
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

$pageTitle = 'My Invoices';
include __DIR__ . '/../includes/header.php';
if (!$patient) {
    ?>
    <div class="card"><div class="empty-state py-5"><i class="bi bi-person-heart"></i>
      <p class="mb-0"><strong>Your login is not linked to a patient profile yet.</strong><br><span class="text-muted">Ask the front desk to link your account to see your bills.</span></p>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}
$pid = (int)$patient['id'];
$invs = $pdo->prepare('SELECT * FROM patient_invoices WHERE tenant_id = ? AND patient_id = ? ORDER BY id DESC');
$invs->execute([$tid, $pid]);
$invs = $invs->fetchAll();
$balance = 0.0;
foreach ($invs as $inv) {
    if (in_array($inv['status'], ['unpaid', 'partial'], true)) {
        $balance += (float)$inv['total'] - (float)$inv['amount_paid'];
    }
}
?>
<div class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-center gap-3">
    <span class="stat-ic ic-amber"><i class="bi bi-wallet2"></i></span>
    <span><span class="num d-block fs-4 fw-bold"><?= money($balance) ?></span><span class="text-muted small">Total outstanding balance across <?= count($invs) ?> invoice<?= count($invs) === 1 ? '' : 's' ?></span></span>
    <span class="ms-auto text-muted small">Balances are settled at the hospital front desk.<br>Your payment appears here as soon as the desk records it.</span>
  </div>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Invoice</th><th>Issued</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Print</th></tr></thead>
      <tbody>
      <?php if (!$invs): ?><tr><td colspan="8" class="text-muted text-center py-4">No invoices yet.</td></tr><?php endif; ?>
      <?php foreach ($invs as $inv): ?>
        <tr>
          <td><strong><?= e($inv['invoice_no']) ?></strong></td>
          <td><?= fmt_date($inv['created_at']) ?></td>
          <td><?= $inv['due_date'] ? fmt_date($inv['due_date']) : '—' ?></td>
          <td class="text-end"><?= money((float)$inv['total']) ?></td>
          <td class="text-end"><?= money((float)$inv['amount_paid']) ?></td>
          <td class="text-end"><?= money((float)$inv['total'] - (float)$inv['amount_paid']) ?></td>
          <td><?= status_badge((string)$inv['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="invoices.php?print=<?= (int)$inv['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
