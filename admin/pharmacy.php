<?php
declare(strict_types=1);
/* Hospital Admin — Pharmacy & Prescriptions (Upgrade v2 page tree).
 * Medicine inventory (CRUD, stock adjustments, low-stock + expiry alerts)
 * and the prescription queue: dispensing decrements real stock per item
 * and flips the prescription status. Prescriptions print on a branded
 * sheet (?print_rx=N). */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['hospital_admin']);
$tid = require_tenant();
check_tenant_active($pdo);
if (!has_permission('pharmacy.view')) {
    flash('error', 'You do not have permission to view the pharmacy.');
    redirect('dashboard.php');
}
$canManage = has_permission('pharmacy.manage');
$base = '../';
$panelSidebar = 'admin';

/* ---------------- Printable prescription (standalone, exits) ---------------- */
$printRx = (int)($_GET['print_rx'] ?? 0);
if ($printRx > 0) {
    $rs = $pdo->prepare('SELECT pr.*, p.name AS patient_name, p.patient_code, p.dob, p.gender, d.name AS doctor_name, d.specialization, d.qualification FROM prescriptions pr JOIN patients p ON p.id = pr.patient_id JOIN doctors d ON d.id = pr.doctor_id WHERE pr.id = ? AND pr.tenant_id = ?');
    $rs->execute([$printRx, $tid]);
    $rx = $rs->fetch();
    if (!$rx) {
        http_response_code(404);
        exit('Prescription not found.');
    }
    $items = $pdo->prepare('SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY id');
    $items->execute([$printRx]);
    $items = $items->fetchAll();
    $tenant = $pdo->query('SELECT name FROM tenants WHERE id = ' . $tid)->fetchColumn();
    print_page_start('Prescription #' . $printRx, '../');
    ?>
    <div class="d-flex justify-content-between align-items-start mb-4">
      <div><h4 class="mb-1"><?= e((string)$tenant) ?></h4><small class="text-muted">Prescription</small></div>
      <div class="text-end"><h4 class="mb-1">Rx #<?= (int)$rx['id'] ?></h4><div class="small text-muted"><?= fmt_date($rx['prescription_date']) ?> · <?= e(ucfirst(str_replace('_', ' ', (string)$rx['status']))) ?></div></div>
    </div>
    <div class="row mb-4">
      <div class="col-6"><small class="text-muted d-block">Patient</small><strong><?= e($rx['patient_name']) ?></strong> (<?= e($rx['patient_code']) ?>)<br><small class="text-muted"><?= e(patient_age($rx['dob'])) ?> · <?= e(ucfirst((string)($rx['gender'] ?? ''))) ?></small></div>
      <div class="col-6 text-end"><small class="text-muted d-block">Prescribed by</small><strong><?= e($rx['doctor_name']) ?></strong><br><small class="text-muted"><?= e($rx['specialization'] ?? '') ?><?= $rx['qualification'] ? ' · ' . e($rx['qualification']) : '' ?></small></div>
    </div>
    <?php if ($rx['diagnosis']): ?><p><strong>Diagnosis:</strong> <?= e($rx['diagnosis']) ?></p><?php endif; ?>
    <div class="fs-4 fw-bold mb-2" style="color:var(--blue)">℞</div>
    <table class="table table-bordered align-middle">
      <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th class="text-center">Qty</th><th>Instructions</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td><strong><?= e($it['medicine_name']) ?></strong></td><td><?= e($it['dosage'] ?? '—') ?></td><td><?= e($it['frequency'] ?? '—') ?></td><td><?= e($it['duration'] ?? '—') ?></td><td class="text-center"><?= (int)$it['quantity'] ?></td><td><?= e($it['instructions'] ?? '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($rx['notes']): ?><p class="mt-3"><strong>Notes:</strong> <?= e($rx['notes']) ?></p><?php endif; ?>
    <div class="row mt-5">
      <div class="col-6"><small class="text-muted">Dispensed by / pharmacy stamp</small><div class="border-bottom mt-4"></div></div>
      <div class="col-6 text-end"><small class="text-muted">Doctor signature</small><div class="border-bottom mt-4"></div><small><?= e($rx['doctor_name']) ?></small></div>
    </div>
    <?php
    print_page_end();
    exit;
}

/* ---------------- POST actions ---------------- */
if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        flash('error', 'You do not have permission to modify pharmacy data.');
        redirect('pharmacy.php');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_medicine') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $data = [
            'name' => $name,
            'generic_name' => trim((string)($_POST['generic_name'] ?? '')) ?: null,
            'category' => trim((string)($_POST['category'] ?? '')) ?: null,
            'unit' => trim((string)($_POST['unit'] ?? 'tablet')) ?: 'tablet',
            'stock' => max(0, (int)($_POST['stock'] ?? 0)),
            'reorder_level' => max(0, (int)($_POST['reorder_level'] ?? 10)),
            'price' => max(0.0, (float)($_POST['price'] ?? 0)),
            'expiry_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['expiry_date'] ?? '')) ? (string)$_POST['expiry_date'] : null,
        ];
        if ($name === '') {
            flash('error', 'Medicine name is required.');
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE medicines SET name=?, generic_name=?, category=?, unit=?, stock=?, reorder_level=?, price=?, expiry_date=? WHERE id=? AND tenant_id=?')
                ->execute([$data['name'], $data['generic_name'], $data['category'], $data['unit'], $data['stock'], $data['reorder_level'], $data['price'], $data['expiry_date'], $id, $tid]);
            flash('success', 'Medicine updated.');
        } else {
            $pdo->prepare('INSERT INTO medicines (tenant_id, name, generic_name, category, unit, stock, reorder_level, price, expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$tid, $data['name'], $data['generic_name'], $data['category'], $data['unit'], $data['stock'], $data['reorder_level'], $data['price'], $data['expiry_date']]);
            audit_log($pdo, 'medicine_created', 'medicines', (int)$pdo->lastInsertId(), 'Medicine added: ' . $name);
            flash('success', 'Medicine added to inventory.');
        }
    } elseif ($action === 'adjust_stock') {
        $id = (int)($_POST['id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        if ($id > 0 && $delta !== 0) {
            $pdo->prepare('UPDATE medicines SET stock = GREATEST(0, stock + ?) WHERE id = ? AND tenant_id = ?')->execute([$delta, $id, $tid]);
            audit_log($pdo, 'medicine_stock_adjusted', 'medicines', $id, 'Stock adjusted by ' . $delta);
            flash('success', 'Stock adjusted by ' . ($delta > 0 ? '+' : '') . $delta . '.');
        }
    } elseif ($action === 'delete_medicine') {
        $pdo->prepare('DELETE FROM medicines WHERE id = ? AND tenant_id = ?')->execute([(int)($_POST['id'] ?? 0), $tid]);
        flash('success', 'Medicine removed from inventory.');
    } elseif ($action === 'dispense') {
        $rxId = (int)($_POST['prescription_id'] ?? 0);
        $rs = $pdo->prepare('SELECT * FROM prescriptions WHERE id = ? AND tenant_id = ?');
        $rs->execute([$rxId, $tid]);
        $rx = $rs->fetch();
        if (!$rx) {
            flash('error', 'Prescription not found.');
        } elseif ($rx['status'] === 'dispensed') {
            flash('error', 'This prescription has already been dispensed.');
        } else {
            $items = $pdo->prepare('SELECT * FROM prescription_items WHERE prescription_id = ?');
            $items->execute([$rxId]);
            $short = [];
            $dispensedAny = false;
            foreach ($items->fetchAll() as $it) {
                if (!$it['medicine_id']) {
                    $dispensedAny = true;
                    continue;
                }
                $ms = $pdo->prepare('SELECT stock, name FROM medicines WHERE id = ? AND tenant_id = ?');
                $ms->execute([(int)$it['medicine_id'], $tid]);
                $med = $ms->fetch();
                if (!$med) {
                    continue;
                }
                $take = min((int)$med['stock'], (int)$it['quantity']);
                if ($take > 0) {
                    $pdo->prepare('UPDATE medicines SET stock = stock - ? WHERE id = ? AND tenant_id = ?')->execute([$take, (int)$it['medicine_id'], $tid]);
                    $dispensedAny = true;
                }
                if ($take < (int)$it['quantity']) {
                    $short[] = $med['name'] . ' (needed ' . (int)$it['quantity'] . ', dispensed ' . $take . ')';
                }
            }
            $newStatus = $short ? 'partially_dispensed' : 'dispensed';
            if ($dispensedAny) {
                $pdo->prepare('UPDATE prescriptions SET status = ? WHERE id = ? AND tenant_id = ?')->execute([$newStatus, $rxId, $tid]);
                audit_log($pdo, 'prescription_' . $newStatus, 'prescriptions', $rxId, 'Dispensed from admin pharmacy');
                flash($short ? 'error' : 'success', $short ? 'Partially dispensed — insufficient stock for: ' . implode(', ', $short) : 'Prescription dispensed and stock updated.');
            } else {
                flash('error', 'Nothing could be dispensed — all items are out of stock.');
            }
        }
    }
    redirect('pharmacy.php' . (!empty($_POST['tab']) ? '?tab=' . urlencode((string)$_POST['tab']) : ''));
}

$tab = (string)($_GET['tab'] ?? 'inventory');
$q = trim((string)($_GET['q'] ?? ''));

$medWhere = 'tenant_id = ?';
$medParams = [$tid];
if ($q !== '' && $tab === 'inventory') {
    $medWhere .= ' AND (name LIKE ? OR generic_name LIKE ? OR category LIKE ?)';
    array_push($medParams, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
$ms = $pdo->prepare("SELECT * FROM medicines WHERE {$medWhere} ORDER BY (stock <= reorder_level) DESC, name");
$ms->execute($medParams);
$medicines = $ms->fetchAll();

$rxList = $pdo->prepare(
    "SELECT pr.*, p.name AS patient_name, p.patient_code, d.name AS doctor_name,
            (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = pr.id) AS item_count
       FROM prescriptions pr
       JOIN patients p ON p.id = pr.patient_id
       JOIN doctors d ON d.id = pr.doctor_id
      WHERE pr.tenant_id = ? ORDER BY FIELD(pr.status, 'pending','partially_dispensed','dispensed'), pr.id DESC LIMIT 60"
);
$rxList->execute([$tid]);
$rxList = $rxList->fetchAll();

$lowCount = 0;
$expiredCount = 0;
foreach ($medicines as $m) {
    if ((int)$m['stock'] <= (int)$m['reorder_level']) {
        $lowCount++;
    }
    if ($m['expiry_date'] && $m['expiry_date'] < date('Y-m-d')) {
        $expiredCount++;
    }
}
$editId = (int)($_GET['edit'] ?? 0);
$editMed = null;
foreach ($medicines as $m) {
    if ((int)$m['id'] === $editId) {
        $editMed = $m;
    }
}

$pageTitle = 'Pharmacy & Prescriptions';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3 mb-3">
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-blue"><i class="bi bi-capsule"></i></span><span><span class="num d-block"><?= count($medicines) ?></span><span class="lbl">Medicines in catalog</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-amber"><i class="bi bi-exclamation-triangle"></i></span><span><span class="num d-block"><?= $lowCount ?></span><span class="lbl">Low stock alerts</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-rose"><i class="bi bi-calendar-x"></i></span><span><span class="num d-block"><?= $expiredCount ?></span><span class="lbl">Expired medicines</span></span></div></div>
  <div class="col-6 col-xl-3"><div class="card stat-card h-100"><span class="stat-ic ic-teal"><i class="bi bi-prescription2"></i></span><span><span class="num d-block"><?= count(array_filter($rxList, static fn($r) => $r['status'] !== 'dispensed')) ?></span><span class="lbl">Prescriptions to dispense</span></span></div></div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab !== 'prescriptions' ? 'active' : '' ?>" href="pharmacy.php?tab=inventory"><i class="bi bi-capsule"></i> Inventory</a></li>
  <li class="nav-item"><a class="nav-link <?= $tab === 'prescriptions' ? 'active' : '' ?>" href="pharmacy.php?tab=prescriptions"><i class="bi bi-prescription2"></i> Prescriptions</a></li>
</ul>

<?php if ($tab === 'prescriptions'): ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Date</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th>Items</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$rxList): ?><tr><td colspan="7" class="text-muted text-center py-4">No prescriptions yet.</td></tr><?php endif; ?>
      <?php foreach ($rxList as $rx): ?>
        <tr>
          <td><?= fmt_date($rx['prescription_date']) ?></td>
          <td><a href="patients.php?id=<?= (int)$rx['patient_id'] ?>"><?= e($rx['patient_name']) ?></a> <small class="text-muted"><?= e($rx['patient_code']) ?></small></td>
          <td><?= e($rx['doctor_name']) ?></td>
          <td><?= e($rx['diagnosis'] ?? '—') ?></td>
          <td><?= (int)$rx['item_count'] ?></td>
          <td><?= status_badge((string)$rx['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="pharmacy.php?print_rx=<?= (int)$rx['id'] ?>" target="_blank" rel="noopener" title="Print prescription"><i class="bi bi-printer"></i></a>
            <?php if ($canManage && $rx['status'] !== 'dispensed'): ?>
            <form method="post" class="d-inline" data-confirm="Dispense this prescription? Stock will be decremented for each item.">
              <?= csrf_field() ?><input type="hidden" name="action" value="dispense"><input type="hidden" name="prescription_id" value="<?= (int)$rx['id'] ?>"><input type="hidden" name="tab" value="prescriptions">
              <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-bag-check"></i> Dispense</button>
            </form>
            <?php endif; ?>
          </td>
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
      <div class="col-md-5"><input class="form-control" type="search" name="q" value="<?= e($q) ?>" placeholder="Search medicines…" aria-label="Search medicines"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filter</button></div>
      <div class="col-md-5 text-md-end"><?php if ($canManage): ?><button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#medModal"><i class="bi bi-plus-lg"></i> Add Medicine</button><?php endif; ?></div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Medicine</th><th>Category</th><th>Price</th><th>Stock</th><th>Expiry</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php if (!$medicines): ?><tr><td colspan="6"><div class="empty-state py-4"><i class="bi bi-capsule"></i><p class="mb-0">No medicines match your search.</p></div></td></tr><?php endif; ?>
      <?php foreach ($medicines as $m): $low = (int)$m['stock'] <= (int)$m['reorder_level']; $expired = $m['expiry_date'] && $m['expiry_date'] < date('Y-m-d'); ?>
        <tr>
          <td><strong><?= e($m['name']) ?></strong><?php if ($m['generic_name']): ?><br><small class="text-muted"><?= e($m['generic_name']) ?></small><?php endif; ?></td>
          <td><?= $m['category'] ? '<span class="chip">' . e($m['category']) . '</span>' : '—' ?></td>
          <td><?= money((float)$m['price']) ?> <small class="text-muted">/ <?= e($m['unit']) ?></small></td>
          <td>
            <span class="badge text-bg-<?= (int)$m['stock'] <= 0 ? 'danger' : ($low ? 'warning' : 'success') ?>"><?= (int)$m['stock'] ?> <?= e($m['unit']) ?></span>
            <?php if ($low): ?><small class="text-danger d-block">Reorder level <?= (int)$m['reorder_level'] ?></small><?php endif; ?>
          </td>
          <td><?= $m['expiry_date'] ? fmt_date($m['expiry_date']) : '—' ?><?php if ($expired): ?> <span class="badge text-bg-danger">Expired</span><?php endif; ?></td>
          <td class="text-end">
            <?php if ($canManage): ?>
            <form method="post" class="d-inline-flex gap-1 align-items-center">
              <?= csrf_field() ?><input type="hidden" name="action" value="adjust_stock"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <input class="form-control form-control-sm" type="number" name="delta" value="10" style="width:76px" aria-label="Stock adjustment (+/-)">
              <button class="btn btn-sm btn-outline-primary" type="submit" title="Adjust stock by this amount"><i class="bi bi-arrow-left-right"></i></button>
            </form>
            <a class="btn btn-sm btn-outline-secondary" href="pharmacy.php?edit=<?= (int)$m['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" data-confirm="Remove <?= e($m['name']) ?> from the inventory?">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_medicine"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="medModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post">
      <div class="modal-header"><h5 class="modal-title"><?= $editMed ? 'Edit Medicine' : 'Add Medicine' ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_medicine"><input type="hidden" name="id" value="<?= (int)($editMed['id'] ?? 0) ?>">
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Name *</label><input class="form-control" name="name" required maxlength="120" value="<?= e($editMed['name'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Generic name</label><input class="form-control" name="generic_name" maxlength="120" value="<?= e($editMed['generic_name'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Category</label><input class="form-control" name="category" maxlength="80" value="<?= e($editMed['category'] ?? '') ?>" placeholder="Antibiotic, Analgesic…"></div>
          <div class="col-md-6"><label class="form-label">Unit</label><input class="form-control" name="unit" maxlength="30" value="<?= e($editMed['unit'] ?? 'tablet') ?>"></div>
          <div class="col-md-4"><label class="form-label">Stock</label><input class="form-control" type="number" name="stock" min="0" value="<?= (int)($editMed['stock'] ?? 0) ?>"></div>
          <div class="col-md-4"><label class="form-label">Reorder level</label><input class="form-control" type="number" name="reorder_level" min="0" value="<?= (int)($editMed['reorder_level'] ?? 10) ?>"></div>
          <div class="col-md-4"><label class="form-label">Price ($)</label><input class="form-control" type="number" name="price" min="0" step="0.01" value="<?= e((string)($editMed['price'] ?? '0')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Expiry date</label><input class="form-control" type="date" name="expiry_date" value="<?= e($editMed['expiry_date'] ?? '') ?>"></div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save medicine</button></div>
    </form>
  </div>
</div>
<?php if ($editMed): ?>
<script>document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(document.getElementById('medModal')).show(); });</script>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
