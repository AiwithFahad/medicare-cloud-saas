<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'accountant', 'receptionist']);
$tid = require_tenant();
check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin', 'accountant'], true) || has_permission('billing.manage');

/* ---------------- Actions ---------------- */
if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create' && $canManage) {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $dueDate = trim((string)($_POST['due_date'] ?? '')) ?: null;
        $discount = max(0, (float)($_POST['discount'] ?? 0));
        $descs = $_POST['item_description'] ?? [];
        $types = $_POST['item_type'] ?? [];
        $qtys = $_POST['item_qty'] ?? [];
        $prices = $_POST['item_price'] ?? [];
        $items = [];
        $subtotal = 0.0;
        if (is_array($descs)) {
            foreach ($descs as $i => $d) {
                $d = trim((string)$d);
                $qty = max(1, (int)($qtys[$i] ?? 1));
                $price = max(0, (float)($prices[$i] ?? 0));
                $type = (string)($types[$i] ?? 'other');
                if (!in_array($type, ['consultation', 'lab', 'pharmacy', 'bed', 'procedure', 'other'], true)) $type = 'other';
                if ($d === '') continue;
                $line = round($qty * $price, 2);
                $subtotal += $line;
                $items[] = [$d, $type, $qty, $price, $line];
            }
        }
        // Validate patient belongs to tenant
        $ps = $pdo->prepare('SELECT id FROM patients WHERE id=? AND tenant_id=?');
        $ps->execute([$patientId, $tid]);
        if (!$ps->fetch() || !$items) {
            flash('error', 'Please choose a patient and add at least one billable item.');
            redirect('billing.php');
        }
        // Tax from tenant settings
        $ts = $pdo->prepare('SELECT svalue FROM tenant_settings WHERE tenant_id=? AND skey="invoice_tax_percent"');
        $ts->execute([$tid]);
        $taxPct = (float)($ts->fetchColumn() ?: 0);
        $discount = min($discount, $subtotal);
        $tax = round(($subtotal - $discount) * $taxPct / 100, 2);
        $total = round($subtotal - $discount + $tax, 2);
        // Next invoice number: BILL- + padded sequence (per tenant)
        $ns = $pdo->prepare('SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_no,6) AS UNSIGNED)),0) FROM patient_invoices WHERE tenant_id=?');
        $ns->execute([$tid]);
        $invoiceNo = 'BILL-' . str_pad((string)((int)$ns->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO patient_invoices(tenant_id,patient_id,invoice_no,subtotal,discount,tax,total,amount_paid,status,due_date,created_at) VALUES(?,?,?,?,?,?,?,0,"unpaid",?,NOW())');
            $ins->execute([$tid, $patientId, $invoiceNo, $subtotal, $discount, $tax, $total, $dueDate]);
            $invId = (int)$pdo->lastInsertId();
            $ii = $pdo->prepare('INSERT INTO patient_invoice_items(invoice_id,description,item_type,quantity,unit_price,total) VALUES(?,?,?,?,?,?)');
            foreach ($items as $it) $ii->execute([$invId, $it[0], $it[1], $it[2], $it[3], $it[4]]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Could not create the invoice. Please try again.');
            redirect('billing.php');
        }
        audit_log($pdo, 'invoice.create', 'patient_invoice', $invId, $invoiceNo . ' total ' . $total);
        flash('success', 'Invoice ' . $invoiceNo . ' created.');
        redirect('billing.php?view=' . $invId);
    }
    if ($action === 'delete' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        $ds = $pdo->prepare('DELETE FROM patient_invoices WHERE id=? AND tenant_id=? AND status="unpaid" AND amount_paid=0');
        $ds->execute([$id, $tid]);
        if ($ds->rowCount() > 0) {
            audit_log($pdo, 'invoice.delete', 'patient_invoice', $id, '');
            flash('success', 'Unpaid invoice deleted.');
        } else {
            flash('error', 'Only unpaid invoices with no payments can be deleted.');
        }
        redirect('billing.php');
    }
    flash('error', 'You do not have permission for that action.');
    redirect('billing.php');
}

/* ---------------- Detail view ---------------- */
$view = null; $viewItems = []; $viewPayments = [];
if (!empty($_GET['view'])) {
    $vs = $pdo->prepare('SELECT pi.*, p.name AS patient_name, p.patient_code, p.phone AS patient_phone, p.email AS patient_email FROM patient_invoices pi JOIN patients p ON p.id=pi.patient_id WHERE pi.id=? AND pi.tenant_id=?');
    $vs->execute([(int)$_GET['view'], $tid]);
    $view = $vs->fetch() ?: null;
    if ($view) {
        $is = $pdo->prepare('SELECT * FROM patient_invoice_items WHERE invoice_id=? ORDER BY id');
        $is->execute([(int)$view['id']]);
        $viewItems = $is->fetchAll();
        $pys = $pdo->prepare('SELECT pp.*, u.name AS received_by_name FROM patient_payments pp LEFT JOIN users u ON u.id=pp.received_by WHERE pp.invoice_id=? AND pp.tenant_id=? ORDER BY pp.paid_at DESC');
        $pys->execute([(int)$view['id'], $tid]);
        $viewPayments = $pys->fetchAll();
    }
}

/* ---------------- List ---------------- */
$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$where = 'pi.tenant_id=?';
$args = [$tid];
if ($q !== '') { $where .= ' AND (pi.invoice_no LIKE ? OR p.name LIKE ? OR p.patient_code LIKE ?)'; $args[] = "%$q%"; $args[] = "%$q%"; $args[] = "%$q%"; }
if (in_array($status, ['unpaid', 'partial', 'paid', 'refunded'], true)) { $where .= ' AND pi.status=?'; $args[] = $status; }
$countS = $pdo->prepare("SELECT COUNT(*) FROM patient_invoices pi JOIN patients p ON p.id=pi.patient_id WHERE $where");
$countS->execute($args);
$totalRows = (int)$countS->fetchColumn();
[$perPage, $offset, $page] = page_params(15);
$ls = $pdo->prepare("SELECT pi.*, p.name AS patient_name, p.patient_code FROM patient_invoices pi JOIN patients p ON p.id=pi.patient_id WHERE $where ORDER BY pi.id DESC LIMIT $perPage OFFSET $offset");
$ls->execute($args);
$invoices = $ls->fetchAll();

$patients = [];
if ($canManage) {
    $ps = $pdo->prepare('SELECT id, name, patient_code FROM patients WHERE tenant_id=? AND status="active" ORDER BY name');
    $ps->execute([$tid]);
    $patients = $ps->fetchAll();
}
$sumS = $pdo->prepare('SELECT COALESCE(SUM(total),0) billed, COALESCE(SUM(amount_paid),0) collected FROM patient_invoices WHERE tenant_id=?');
$sumS->execute([$tid]);
$sums = $sumS->fetch() ?: ['billed' => 0, 'collected' => 0];

$pageTitle = 'Billing & Invoices';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<style>@media print{body *{visibility:hidden}#printArea,#printArea *{visibility:visible}#printArea{position:absolute;inset:0}}</style>

<?php if ($view): ?>
<div class="card mb-4" id="printArea">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Invoice <?= e($view['invoice_no']) ?></span>
    <span><?= status_badge((string)$view['status']) ?></span>
  </div>
  <div class="card-body">
    <div class="row mb-3">
      <div class="col-md-6">
        <h5 class="mb-1"><?= e($view['patient_name']) ?></h5>
        <div class="text-muted small"><?= e((string)$view['patient_code']) ?><?= $view['patient_phone'] ? ' · ' . e((string)$view['patient_phone']) : '' ?><?= $view['patient_email'] ? ' · ' . e((string)$view['patient_email']) : '' ?></div>
      </div>
      <div class="col-md-6 text-md-end small">
        <div>Issued: <?= fmt_date($view['created_at']) ?></div>
        <div>Due: <?= fmt_date($view['due_date']) ?></div>
      </div>
    </div>
    <div class="table-responsive"><table class="table">
      <thead><tr><th>Description</th><th>Type</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Total</th></tr></thead>
      <tbody>
      <?php foreach ($viewItems as $it): ?>
        <tr><td><?= e($it['description']) ?></td><td class="text-capitalize"><?= e($it['item_type']) ?></td><td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= money($it['unit_price']) ?></td><td class="text-end"><?= money($it['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="4" class="text-end">Subtotal</td><td class="text-end"><?= money($view['subtotal']) ?></td></tr>
        <?php if ((float)$view['discount'] > 0): ?><tr><td colspan="4" class="text-end">Discount</td><td class="text-end">−<?= money($view['discount']) ?></td></tr><?php endif; ?>
        <?php if ((float)$view['tax'] > 0): ?><tr><td colspan="4" class="text-end">Tax</td><td class="text-end"><?= money($view['tax']) ?></td></tr><?php endif; ?>
        <tr><td colspan="4" class="text-end fw-bold">Total</td><td class="text-end fw-bold"><?= money($view['total']) ?></td></tr>
        <tr><td colspan="4" class="text-end">Paid</td><td class="text-end"><?= money($view['amount_paid']) ?></td></tr>
        <tr><td colspan="4" class="text-end fw-bold">Balance</td><td class="text-end fw-bold"><?= money((float)$view['total'] - (float)$view['amount_paid']) ?></td></tr>
      </tfoot>
    </table></div>
    <h6 class="mt-4">Payments on this invoice</h6>
    <?php if (!$viewPayments): ?><p class="text-muted small">No payments recorded yet.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm">
      <thead><tr><th>Date</th><th>Method</th><th>Received by</th><th class="text-end">Amount</th></tr></thead>
      <tbody><?php foreach ($viewPayments as $pay): ?><tr><td><?= fmt_datetime($pay['paid_at']) ?></td><td class="text-capitalize"><?= e(str_replace('_', ' ', (string)$pay['method'])) ?></td><td><?= e((string)($pay['received_by_name'] ?? '—')) ?></td><td class="text-end"><?= money($pay['amount']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>
<div class="d-flex gap-2 mb-4">
  <button type="button" class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Print invoice</button>
  <a class="btn btn-outline-secondary" href="billing.php">Back to invoices</a>
  <?php if ($canManage && $view['status'] !== 'paid'): ?><a class="btn btn-success" href="payments.php?invoice=<?= (int)$view['id'] ?>"><i class="bi bi-cash-stack me-1"></i>Record payment</a><?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card stat-card"><span class="stat-ic ic-blue"><i class="bi bi-receipt"></i></span><div><div class="num"><?= money($sums['billed']) ?></div><div class="lbl">Total billed</div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><span class="stat-ic ic-green"><i class="bi bi-cash-stack"></i></span><div><div class="num"><?= money($sums['collected']) ?></div><div class="lbl">Collected</div></div></div></div>
  <div class="col-md-4"><div class="card stat-card"><span class="stat-ic ic-amber"><i class="bi bi-hourglass-split"></i></span><div><div class="num"><?= money((float)$sums['billed'] - (float)$sums['collected']) ?></div><div class="lbl">Outstanding</div></div></div></div>
</div>

<?php if ($canManage): ?>
<div class="card mb-4">
  <div class="card-header">Create invoice</div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <div class="row g-3 mb-3">
        <div class="col-md-5"><label class="form-label">Patient *</label>
          <select name="patient_id" class="form-select" required><option value="">Select patient…</option>
          <?php foreach ($patients as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
          </select></div>
        <div class="col-md-3"><label class="form-label">Due date</label><input type="date" name="due_date" class="form-control" value="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"></div>
        <div class="col-md-4"><label class="form-label">Discount ($)</label><input type="number" step="0.01" min="0" name="discount" class="form-control" value="0"></div>
      </div>
      <div id="itemRows">
        <div class="row g-2 mb-2 item-row">
          <div class="col-md-5"><input name="item_description[]" class="form-control" placeholder="Description *" required></div>
          <div class="col-md-2"><select name="item_type[]" class="form-select"><option value="consultation">Consultation</option><option value="lab">Lab</option><option value="pharmacy">Pharmacy</option><option value="bed">Bed</option><option value="procedure">Procedure</option><option value="other" selected>Other</option></select></div>
          <div class="col-md-2"><input type="number" name="item_qty[]" class="form-control" value="1" min="1" required></div>
          <div class="col-md-2"><input type="number" step="0.01" min="0" name="item_price[]" class="form-control" value="0" required></div>
          <div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100 remove-row" title="Remove row"><i class="bi bi-x"></i></button></div>
        </div>
      </div>
      <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="addRow"><i class="bi bi-plus-lg me-1"></i>Add item</button>
      <div><button class="btn btn-primary"><i class="bi bi-receipt me-1"></i>Create invoice</button></div>
    </form>
  </div>
</div>
<script>
document.getElementById('addRow').addEventListener('click',function(){
  var wrap=document.getElementById('itemRows');var row=wrap.querySelector('.item-row').cloneNode(true);
  row.querySelectorAll('input').forEach(function(i){i.value=i.name.indexOf('qty')>-1?'1':(i.name.indexOf('price')>-1?'0':'');});
  wrap.appendChild(row);
});
document.getElementById('itemRows').addEventListener('click',function(ev){
  var btn=ev.target.closest('.remove-row');if(!btn)return;
  var rows=document.querySelectorAll('.item-row');if(rows.length>1)btn.closest('.item-row').remove();
});
</script>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center"><span>Patient invoices</span>
    <form class="d-flex gap-2" method="get">
      <input name="q" class="form-control form-control-sm" placeholder="Search invoice / patient…" value="<?= e($q) ?>">
      <select name="status" class="form-select form-select-sm"><option value="">All statuses</option>
        <?php foreach (['unpaid', 'partial', 'paid', 'refunded'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-primary">Filter</button>
    </form>
  </div>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Invoice</th><th>Patient</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if (!$invoices): ?><tr><td colspan="8"><div class="empty-state">No invoices found.</div></td></tr><?php endif; ?>
    <?php foreach ($invoices as $inv): $bal = (float)$inv['total'] - (float)$inv['amount_paid']; ?>
      <tr>
        <td><a href="billing.php?view=<?= (int)$inv['id'] ?>"><?= e($inv['invoice_no']) ?></a></td>
        <td><?= e($inv['patient_name']) ?> <small class="text-muted"><?= e($inv['patient_code']) ?></small></td>
        <td><?= fmt_date($inv['created_at']) ?></td>
        <td class="text-end"><?= money($inv['total']) ?></td>
        <td class="text-end"><?= money($inv['amount_paid']) ?></td>
        <td class="text-end fw-semibold"><?= money($bal) ?></td>
        <td><?= status_badge((string)$inv['status']) ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-primary" href="billing.php?view=<?= (int)$inv['id'] ?>">View</a>
          <?php if ($canManage && $inv['status'] === 'unpaid' && (float)$inv['amount_paid'] == 0.0): ?>
          <form method="post" class="d-inline" data-confirm="Delete this unpaid invoice?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$inv['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="card-footer d-flex justify-content-between align-items-center"><small class="text-muted"><?= $totalRows ?> invoice(s)</small><?= pager_html($totalRows, $perPage, $page) ?></div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
