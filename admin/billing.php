<?php
declare(strict_types=1);
/* Hospital Admin — Billing & Finance (Upgrade v2 page tree).
 * Patient invoices (create with line items, printable view), payments
 * recorded against invoices (balance + status recomputed from real rows),
 * hospital expenses, and a unified financial transactions ledger.
 * Nothing here fabricates a payment: balances move only when a payment
 * row is actually recorded. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('billing.view')) {
    flash('error', 'You do not have permission to view billing.');
    redirect('dashboard.php');
}
$canManage = has_permission('billing.manage');
$base = '../';
$panelSidebar = 'admin';

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
      <div>
        <h4 class="mb-1"><?= e((string)$tenant) ?></h4>
        <small class="text-muted">Patient invoice</small>
      </div>
      <div class="text-end">
        <h4 class="mb-1"><?= e($inv['invoice_no']) ?></h4>
        <span class="badge text-bg-<?= $inv['status'] === 'paid' ? 'success' : ($inv['status'] === 'partial' ? 'warning' : 'danger') ?>"><?= e(ucfirst((string)$inv['status'])) ?></span>
        <div class="small text-muted mt-1">Issued <?= fmt_date($inv['created_at']) ?><?= $inv['due_date'] ? ' · Due ' . fmt_date($inv['due_date']) : '' ?></div>
      </div>
    </div>
    <div class="mb-4">
      <small class="text-muted d-block">Billed to</small>
      <strong><?= e($inv['patient_name']) ?></strong> <span class="text-muted">(<?= e($inv['patient_code']) ?>)</span><br>
      <small class="text-muted"><?= e($inv['phone'] ?? '') ?><?= $inv['email'] ? ' · ' . e($inv['email']) : '' ?><?= $inv['address'] ? '<br>' . e($inv['address']) : '' ?></small>
    </div>
    <table class="table table-bordered align-middle">
      <thead><tr><th>Description</th><th>Type</th><th class="text-center">Qty</th><th class="text-end">Unit price</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><?= e($it['description']) ?></td><td><?= e(ucfirst((string)$it['item_type'])) ?></td><td class="text-center"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= money((float)$it['unit_price']) ?></td><td class="text-end"><?= money((float)$it['total']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><th colspan="4" class="text-end">Subtotal</th><th class="text-end"><?= money((float)$inv['subtotal']) ?></th></tr>
        <?php if ((float)$inv['discount'] > 0): ?><tr><th colspan="4" class="text-end">Discount</th><th class="text-end">−<?= money((float)$inv['discount']) ?></th></tr><?php endif; ?>
        <?php if ((float)$inv['tax'] > 0): ?><tr><th colspan="4" class="text-end">Tax</th><th class="text-end"><?= money((float)$inv['tax']) ?></th></tr><?php endif; ?>
        <tr><th colspan="4" class="text-end">Total</th><th class="text-end"><?= money((float)$inv['total']) ?></th></tr>
        <tr><th colspan="4" class="text-end">Paid</th><th class="text-end"><?= money((float)$inv['amount_paid']) ?></th></tr>
        <tr><th colspan="4" class="text-end">Balance due</th><th class="text-end"><?= money((float)$inv['total'] - (float)$inv['amount_paid']) ?></th></tr>
      </tfoot>
    </table>
    <?php if ($pays): ?>
    <h6 class="mt-4">Payments received</h6>
    <table class="table table-sm align-middle">
      <thead><tr><th>Date</th><th>Method</th><th class="text-end">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($pays as $pay): ?><tr><td><?= fmt_datetime($pay['paid_at']) ?></td><td><?= e(ucfirst((string)$pay['method'])) ?></td><td class="text-end"><?= money((float)$pay['amount']) ?></td></tr><?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php
    print_page_end();
    exit;
}

/* ---------------- POST actions ---------------- */
if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'You do not have permission to modify billing.');
        redirect('billing.php');
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_invoice') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $descs = (array)($_POST['item_desc'] ?? []);
        $types = (array)($_POST['item_type'] ?? []);
        $qtys = (array)($_POST['item_qty'] ?? []);
        $prices = (array)($_POST['item_price'] ?? []);
        $items = [];
        $subtotal = 0.0;
        foreach ($descs as $i => $desc) {
            $desc = trim((string)$desc);
            $qty = max(1, (int)($qtys[$i] ?? 1));
            $price = max(0.0, (float)($prices[$i] ?? 0));
            if ($desc === '' || $price <= 0) {
                continue;
            }
            $type = in_array(($types[$i] ?? ''), ['consultation', 'lab', 'pharmacy', 'bed', 'procedure', 'other'], true) ? (string)$types[$i] : 'other';
            $items[] = ['desc' => $desc, 'type' => $type, 'qty' => $qty, 'price' => $price, 'total' => $qty * $price];
            $subtotal += $qty * $price;
        }
        $discount = max(0.0, (float)($_POST['discount'] ?? 0));
        $tax = max(0.0, (float)($_POST['tax'] ?? 0));
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_date'] ?? '')) ? (string)$_POST['due_date'] : null;
        $pc = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND tenant_id = ?');
        $pc->execute([$patientId, $tid]);
        if ((int)$pc->fetchColumn() === 0 || !$items) {
            flash('error', 'Pick a patient and add at least one priced line item.');
        } else {
            $ns = $pdo->prepare('SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_no, 6) AS UNSIGNED)), 0) FROM patient_invoices WHERE tenant_id = ?');
            $ns->execute([$tid]);
            $invoiceNo = 'BILL-' . str_pad((string)((int)$ns->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
            $total = max(0.0, $subtotal - $discount + $tax);
            $pdo->prepare('INSERT INTO patient_invoices (tenant_id, patient_id, invoice_no, subtotal, discount, tax, total, amount_paid, status, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, 0, "unpaid", ?)')
                ->execute([$tid, $patientId, $invoiceNo, $subtotal, $discount, $tax, $total, $due]);
            $invId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO patient_invoice_items (invoice_id, description, item_type, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $ins->execute([$invId, $it['desc'], $it['type'], $it['qty'], $it['price'], $it['total']]);
            }
            audit_log($pdo, 'invoice_created', 'patient_invoices', $invId, 'Invoice ' . $invoiceNo . ' created: ' . money($total));
            flash('success', 'Invoice ' . $invoiceNo . ' created.');
        }
    } elseif ($action === 'record_payment') {
        $invId = (int)($_POST['invoice_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $method = in_array(($_POST['method'] ?? ''), ['cash', 'card', 'bank_transfer', 'insurance', 'online'], true) ? (string)$_POST['method'] : 'cash';
        $is = $pdo->prepare('SELECT * FROM patient_invoices WHERE id = ? AND tenant_id = ?');
        $is->execute([$invId, $tid]);
        $inv = $is->fetch();
        if (!$inv || $amount <= 0) {
            flash('error', 'Enter a valid payment amount for an existing invoice.');
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
                audit_log($pdo, 'patient_payment_recorded', 'patient_payments', (int)$pdo->lastInsertId(), 'Payment ' . money($amount) . ' on ' . $inv['invoice_no']);
                flash('success', 'Payment of ' . money($amount) . ' recorded on ' . $inv['invoice_no'] . '.');
            }
        }
    } elseif ($action === 'save_expense') {
        $expId = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $amount = (float)($_POST['amount'] ?? 0);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['expense_date'] ?? '')) ? (string)$_POST['expense_date'] : date('Y-m-d');
        if ($title === '' || $amount <= 0) {
            flash('error', 'Expense title and a positive amount are required.');
        } elseif ($expId > 0) {
            $pdo->prepare('UPDATE expenses SET title = ?, category = ?, amount = ?, expense_date = ?, vendor = ?, payment_method = ?, notes = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$title, trim((string)($_POST['category'] ?? 'Operations')) ?: 'Operations', $amount, $date, trim((string)($_POST['vendor'] ?? '')) ?: null, (string)($_POST['payment_method'] ?? 'card'), trim((string)($_POST['notes'] ?? '')) ?: null, $expId, $tid]);
            flash('success', 'Expense updated.');
        } else {
            $pdo->prepare('INSERT INTO expenses (tenant_id, title, category, amount, expense_date, vendor, payment_method, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tid, $title, trim((string)($_POST['category'] ?? 'Operations')) ?: 'Operations', $amount, $date, trim((string)($_POST['vendor'] ?? '')) ?: null, (string)($_POST['payment_method'] ?? 'card'), trim((string)($_POST['notes'] ?? '')) ?: null, (int)$user['id']]);
            flash('success', 'Expense recorded.');
        }
    } elseif ($action === 'delete_expense') {
        $pdo->prepare('DELETE FROM expenses WHERE id = ? AND tenant_id = ?')->execute([(int)($_POST['id'] ?? 0), $tid]);
        flash('success', 'Expense deleted.');
    }
    redirect('billing.php' . (!empty($_POST['tab']) ? '?tab=' . urlencode((string)$_POST['tab']) : ''));
}

/* ---------------- Lists ---------------- */
$tab = (string)($_GET['tab'] ?? 'invoices');
$q = trim((string)($_GET['q'] ?? ''));
$statusF = (string)($_GET['status'] ?? '');
[$perPage, $offset, $page] = page_params(15);

$where = 'pi.tenant_id = ?';
$params = [$tid];
if ($q !== '') {
    $where .= ' AND (pi.invoice_no LIKE ? OR p.name LIKE ? OR p.patient_code LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
if (in_array($statusF, ['unpaid', 'partial', 'paid', 'refunded'], true)) {
    $where .= ' AND pi.status = ?';
    $params[] = $statusF;
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM patient_invoices pi JOIN patients p ON p.id = pi.patient_id WHERE {$where}");
$cnt->execute($params);
$totalInvoices = (int)$cnt->fetchColumn();
$stmt = $pdo->prepare("SELECT pi.*, p.name AS patient_name, p.patient_code FROM patient_invoices pi JOIN patients p ON p.id = pi.patient_id WHERE {$where} ORDER BY pi.id DESC LIMIT {$perPage} OFFSET {$offset}");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$expenses = $pdo->prepare('SELECT * FROM expenses WHERE tenant_id = ? ORDER BY expense_date DESC, id DESC LIMIT 50');
$expenses->execute([$tid]);
$expenses = $expenses->fetchAll();

$txIn = $pdo->prepare("SELECT pp.paid_at AS ts, 'in' AS dir, pp.amount, CONCAT('Payment — ', pi.invoice_no, ' · ', p.name) AS label, pp.method FROM patient_payments pp JOIN patient_invoices pi ON pi.id = pp.invoice_id JOIN patients p ON p.id = pp.patient_id WHERE pp.tenant_id = ? ORDER BY pp.paid_at DESC LIMIT 25");
$txIn->execute([$tid]);
$txOut = $pdo->prepare("SELECT CONCAT(ex.expense_date, ' 12:00:00') AS ts, 'out' AS dir, ex.amount, CONCAT('Expense — ', ex.title, ' (', ex.category, ')') AS label, ex.payment_method AS method FROM expenses ex WHERE ex.tenant_id = ? ORDER BY ex.expense_date DESC LIMIT 25");
$txOut->execute([$tid]);
$transactions = array_merge($txIn->fetchAll(), $txOut->fetchAll());
usort($transactions, static fn($a, $b) => strcmp((string)$b['ts'], (string)$a['ts']));
$transactions = array_slice($transactions, 0, 30);

$sum = static function (string $sql) use ($pdo, $tid): float {
    $s = $pdo->prepare($sql);
    $s->execute([$tid]);
    return (float)$s->fetchColumn();
};
$revMonth = $sum('SELECT COALESCE(SUM(amount),0) FROM patient_payments WHERE tenant_id = ? AND DATE_FORMAT(paid_at, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")');
$expMonth = $sum('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE tenant_id = ? AND DATE_FORMAT(expense_date, "%Y-%m") = DATE_FORMAT(CURDATE(), "%Y-%m")');
$outstanding = $sum("SELECT COALESCE(SUM(total - amount_paid),0) FROM patient_invoices WHERE tenant_id = ? AND status IN ('unpaid','partial')");

$patientsList = $pdo->prepare('SELECT id, name, patient_code FROM patients WHERE tenant_id = ? ORDER BY name');
$patientsList->execute([$tid]);
$patientsList = $patientsList->fetchAll();

$pageTitle = 'Billing & Finance';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-green"><i class="bi bi-arrow-down-circle"></i></span><span><span class="num d-block"><?= money($revMonth) ?></span><span class="lbl">Collected this month</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-rose"><i class="bi bi-arrow-up-circle"></i></span><span><span class="num d-block"><?= money($expMonth) ?></span><span class="lbl">Expenses this month</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-wallet2"></i></span><span><span class="num d-block"><?= money($revMonth - $expMonth) ?></span><span class="lbl">Net this month</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-receipt-cutoff"></i></span><span><span class="num d-block"><?= money($outstanding) ?></span><span class="lbl">Outstanding balances</span></span></div></div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab !== 'expenses' && $tab !== 'ledger' ? 'active' : '' ?>" href="billing.php?tab=invoices"><i class="bi bi-receipt"></i> Invoices</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'expenses' ? 'active' : '' ?>" href="billing.php?tab=expenses"><i class="bi bi-arrow-up-circle"></i> Expenses</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'ledger' ? 'active' : '' ?>" href="billing.php?tab=ledger"><i class="bi bi-journal-text"></i> Transactions</a></li>
</ul>

<?php if ($tab === 'expenses'): ?>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center"><span>Hospital expenses</span>
    <?php if ($canManage): ?><button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#expenseModal"><i class="bi bi-plus-lg"></i> Add Expense</button><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Date</th><th>Expense</th><th>Category</th><th>Vendor</th><th>Method</th><th class="text-end">Amount</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$expenses): ?><tr><td colspan="7" class="text-muted text-center py-4">No expenses recorded yet.</td></tr><?php endif; ?>
      <?php foreach ($expenses as $ex): ?>
        <tr>
          <td><?= fmt_date($ex['expense_date']) ?></td>
          <td><strong><?= e($ex['title']) ?></strong><?php if ($ex['notes']): ?><br><small class="text-muted"><?= e($ex['notes']) ?></small><?php endif; ?></td>
          <td><span class="chip"><?= e($ex['category']) ?></span></td>
          <td><?= e($ex['vendor'] ?? '—') ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', (string)$ex['payment_method']))) ?></td>
          <td class="text-end"><?= money((float)$ex['amount']) ?></td>
          <?php if ($canManage): ?>
          <td class="text-end">
            <form method="post" class="d-inline" data-confirm="Delete this expense?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_expense"><input type="hidden" name="id" value="<?= (int)$ex['id'] ?>"><input type="hidden" name="tab" value="expenses">
              <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($canManage): ?>
<div class="modal fade" id="expenseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Add Expense</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_expense"><input type="hidden" name="tab" value="expenses">
        <div class="mb-3"><label class="form-label">Title *</label><input class="form-control" name="title" required maxlength="200"></div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Amount ($) *</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" required></div>
          <div class="col-md-6"><label class="form-label">Date</label><input class="form-control" type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-6"><label class="form-label">Category</label>
            <select class="form-select" name="category"><option>Operations</option><option>Supplies</option><option>Laboratory</option><option>Pharmacy</option><option>Utilities</option><option>HR</option><option>Maintenance</option><option>Other</option></select></div>
          <div class="col-md-6"><label class="form-label">Payment method</label>
            <select class="form-select" name="payment_method"><option value="card">Card</option><option value="cash">Cash</option><option value="bank_transfer">Bank transfer</option><option value="online">Online</option></select></div>
          <div class="col-12"><label class="form-label">Vendor</label><input class="form-control" name="vendor" maxlength="120"></div>
          <div class="col-12"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="255"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Record expense</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php elseif ($tab === 'ledger'): ?>
<div class="card">
  <div class="card-header">Financial transactions — money in (patient payments) and money out (expenses)</div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Date</th><th>Transaction</th><th>Method</th><th class="text-end">In</th><th class="text-end">Out</th></tr></thead>
      <tbody>
      <?php if (!$transactions): ?><tr><td colspan="5" class="text-muted text-center py-4">No transactions yet.</td></tr><?php endif; ?>
      <?php foreach ($transactions as $tx): ?>
        <tr>
          <td><?= fmt_datetime($tx['ts']) ?></td>
          <td><i class="bi bi-arrow-<?= $tx['dir'] === 'in' ? 'down' : 'up' ?>-circle text-<?= $tx['dir'] === 'in' ? 'success' : 'danger' ?> me-1"></i><?= e($tx['label']) ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', (string)$tx['method']))) ?></td>
          <td class="text-end"><?= $tx['dir'] === 'in' ? money((float)$tx['amount']) : '—' ?></td>
          <td class="text-end"><?= $tx['dir'] === 'out' ? money((float)$tx['amount']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="card-header">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-md-4"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search invoice no, patient…" aria-label="Search invoices"></div>
      <div class="col-md-2">
        <select class="form-select" name="status" aria-label="Filter by status">
          <option value="">All statuses</option>
          <?php foreach (['unpaid', 'partial', 'paid', 'refunded'] as $st): ?><option value="<?= $st ?>" <?= $statusF === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
      <div class="col-md-4 text-md-end">
        <span class="text-muted small me-2"><?= $totalInvoices ?> invoices</span>
        <?php if ($canManage): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#invoiceModal"><i class="bi bi-plus-lg"></i> New Invoice</button><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Invoice</th><th>Patient</th><th>Issued</th><th>Due</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$invoices): ?><tr><td colspan="9" class="text-muted text-center py-4">No invoices match this filter.</td></tr><?php endif; ?>
      <?php foreach ($invoices as $inv): $balance = (float)$inv['total'] - (float)$inv['amount_paid']; ?>
        <tr>
          <td><strong><?= e($inv['invoice_no']) ?></strong></td>
          <td><a href="patients.php?id=<?= (int)$inv['patient_id'] ?>"><?= e($inv['patient_name']) ?></a> <small class="text-muted"><?= e($inv['patient_code']) ?></small></td>
          <td><?= fmt_date($inv['created_at']) ?></td>
          <td><?= $inv['due_date'] ? fmt_date($inv['due_date']) : '—' ?></td>
          <td class="text-end"><?= money((float)$inv['total']) ?></td>
          <td class="text-end"><?= money((float)$inv['amount_paid']) ?></td>
          <td class="text-end"><?= money($balance) ?></td>
          <td><?= status_badge((string)$inv['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="billing.php?print=<?= (int)$inv['id'] ?>" target="_blank" rel="noopener" title="Print invoice"><i class="bi bi-printer"></i></a>
            <?php if ($canManage && $balance > 0): ?>
            <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#payModal" data-invoice="<?= (int)$inv['id'] ?>" data-no="<?= e($inv['invoice_no']) ?>" data-balance="<?= e((string)$balance) ?>" title="Record payment"><i class="bi bi-cash-coin"></i> Pay</button>
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
<div class="modal fade" id="invoiceModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" id="invoiceForm">
      <div class="modal-header"><h5 class="modal-title">Create Invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="create_invoice">
        <div class="row g-3 mb-2">
          <div class="col-md-6"><label class="form-label">Patient *</label>
            <select class="form-select" name="patient_id" required><option value="">Select patient…</option>
              <?php foreach ($patientsList as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="col-md-3"><label class="form-label">Due date</label><input class="form-control" type="date" name="due_date"></div>
          <div class="col-md-3"><label class="form-label">Tax ($)</label><input class="form-control" type="number" name="tax" min="0" step="0.01" value="0"></div>
        </div>
        <label class="form-label">Line items</label>
        <div id="invItems">
          <div class="row g-2 mb-2 inv-row">
            <div class="col-md-5"><input class="form-control" name="item_desc[]" placeholder="Description (e.g. Consultation — Dr. Khan)" maxlength="200"></div>
            <div class="col-md-2"><select class="form-select" name="item_type[]"><option value="consultation">Consultation</option><option value="lab">Lab</option><option value="pharmacy">Pharmacy</option><option value="bed">Bed</option><option value="procedure">Procedure</option><option value="other">Other</option></select></div>
            <div class="col-md-2"><input class="form-control" type="number" name="item_qty[]" value="1" min="1" placeholder="Qty"></div>
            <div class="col-md-3"><input class="form-control" type="number" name="item_price[]" min="0" step="0.01" placeholder="Unit price ($)"></div>
          </div>
        </div>
        <button class="btn btn-sm btn-outline-primary mb-3" type="button" id="addInvRow"><i class="bi bi-plus-lg"></i> Add line</button>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">Discount ($)</label><input class="form-control" type="number" name="discount" min="0" step="0.01" value="0"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Create invoice</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="payModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title">Record Payment — <span id="payInvNo"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="record_payment"><input type="hidden" name="invoice_id" id="payInvId">
        <p class="small text-muted">Balance due: <strong id="payBalance"></strong>. The invoice status updates from the recorded payment — nothing is marked paid without a payment row.</p>
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
  var addBtn = document.getElementById('addInvRow');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      var wrap = document.getElementById('invItems');
      var row = wrap.querySelector('.inv-row').cloneNode(true);
      row.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_qty[]' ? '1' : ''; });
      wrap.appendChild(row);
    });
  }
  var payModal = document.getElementById('payModal');
  if (payModal) {
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
  }
});
</script>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
