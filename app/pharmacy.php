<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'pharmacist', 'doctor']);
$tid = require_tenant();
check_tenant_active($pdo);
$canManage = in_array($user['role'], ['hospital_admin', 'pharmacist'], true) || has_permission('pharmacy.manage');
$canPrescribe = in_array($user['role'], ['hospital_admin', 'pharmacist', 'doctor'], true);

if (is_post()) {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'medicine_save' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            trim((string)($_POST['name'] ?? '')),
            trim((string)($_POST['generic_name'] ?? '')) ?: null,
            trim((string)($_POST['category'] ?? '')) ?: null,
            trim((string)($_POST['unit'] ?? 'tablet')) ?: 'tablet',
            max(0, (int)($_POST['stock'] ?? 0)),
            max(0, (int)($_POST['reorder_level'] ?? 10)),
            max(0, (float)($_POST['price'] ?? 0)),
            trim((string)($_POST['expiry_date'] ?? '')) ?: null,
        ];
        if ($data[0] === '') { flash('error', 'Medicine name is required.'); redirect('pharmacy.php'); }
        if ($id > 0) {
            $u = $pdo->prepare('UPDATE medicines SET name=?,generic_name=?,category=?,unit=?,stock=?,reorder_level=?,price=?,expiry_date=? WHERE id=? AND tenant_id=?');
            $u->execute(array_merge($data, [$id, $tid]));
            audit_log($pdo, 'medicine.update', 'medicine', $id, $data[0]);
            flash('success', 'Medicine updated.');
        } else {
            $i = $pdo->prepare('INSERT INTO medicines(tenant_id,name,generic_name,category,unit,stock,reorder_level,price,expiry_date) VALUES(?,?,?,?,?,?,?,?,?)');
            $i->execute(array_merge([$tid], $data));
            $id = (int)$pdo->lastInsertId();
            audit_log($pdo, 'medicine.create', 'medicine', $id, $data[0]);
            flash('success', 'Medicine added to stock.');
        }
        redirect('pharmacy.php');
    }

    if ($action === 'medicine_delete' && $canManage) {
        $id = (int)($_POST['id'] ?? 0);
        $d = $pdo->prepare('DELETE FROM medicines WHERE id=? AND tenant_id=?');
        $d->execute([$id, $tid]);
        audit_log($pdo, 'medicine.delete', 'medicine', $id, '');
        flash('success', 'Medicine deleted.');
        redirect('pharmacy.php');
    }

    if ($action === 'prescription_create' && $canPrescribe) {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $diagnosis = trim((string)($_POST['diagnosis'] ?? '')) ?: null;
        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
        $medIds = $_POST['med_id'] ?? [];
        $items = [];
        if (is_array($medIds)) {
            foreach ($medIds as $i => $mid) {
                $mid = (int)$mid;
                if ($mid <= 0) continue;
                $items[] = [
                    'medicine_id' => $mid,
                    'dosage' => trim((string)($_POST['med_dosage'][$i] ?? '')) ?: null,
                    'frequency' => trim((string)($_POST['med_frequency'][$i] ?? '')) ?: null,
                    'duration' => trim((string)($_POST['med_duration'][$i] ?? '')) ?: null,
                    'quantity' => max(1, (int)($_POST['med_qty'][$i] ?? 1)),
                ];
            }
        }
        $pv = $pdo->prepare('SELECT id FROM patients WHERE id=? AND tenant_id=?');
        $pv->execute([$patientId, $tid]);
        $dv = $pdo->prepare('SELECT id FROM doctors WHERE id=? AND tenant_id=?');
        $dv->execute([$doctorId, $tid]);
        if (!$pv->fetch() || !$dv->fetch() || !$items) {
            flash('error', 'Choose a patient, a doctor and at least one medicine.');
            redirect('pharmacy.php#prescriptions');
        }
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare('INSERT INTO prescriptions(tenant_id,patient_id,doctor_id,prescription_date,diagnosis,notes,status,created_at) VALUES(?,?,?,?,?,?,"pending",NOW())');
            $ins->execute([$tid, $patientId, $doctorId, date('Y-m-d'), $diagnosis, $notes]);
            $rxId = (int)$pdo->lastInsertId();
            $ii = $pdo->prepare('INSERT INTO prescription_items(prescription_id,medicine_id,medicine_name,dosage,frequency,duration,quantity) VALUES(?,?,?,?,?,?,?)');
            $mn = $pdo->prepare('SELECT name FROM medicines WHERE id=? AND tenant_id=?');
            foreach ($items as $it) {
                $mn->execute([$it['medicine_id'], $tid]);
                $mname = (string)($mn->fetchColumn() ?: 'Medicine #' . $it['medicine_id']);
                $ii->execute([$rxId, $it['medicine_id'], $mname, $it['dosage'], $it['frequency'], $it['duration'], $it['quantity']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Could not create the prescription.');
            redirect('pharmacy.php#prescriptions');
        }
        audit_log($pdo, 'prescription.create', 'prescription', $rxId, '');
        flash('success', 'Prescription created and queued for dispensing.');
        redirect('pharmacy.php?rx=' . $rxId . '#prescriptions');
    }

    if ($action === 'dispense' && $canManage) {
        $rxId = (int)($_POST['id'] ?? 0);
        $rs = $pdo->prepare('SELECT * FROM prescriptions WHERE id=? AND tenant_id=?');
        $rs->execute([$rxId, $tid]);
        $rx = $rs->fetch();
        if (!$rx || $rx['status'] === 'dispensed') { flash('error', 'Prescription not found or already dispensed.'); redirect('pharmacy.php#prescriptions'); }
        $is = $pdo->prepare('SELECT * FROM prescription_items WHERE prescription_id=?');
        $is->execute([$rxId]);
        $rxItems = $is->fetchAll();
        $short = [];
        $pdo->beginTransaction();
        try {
            $ms = $pdo->prepare('SELECT stock, name FROM medicines WHERE id=? AND tenant_id=? FOR UPDATE');
            $mu = $pdo->prepare('UPDATE medicines SET stock=? WHERE id=? AND tenant_id=?');
            foreach ($rxItems as $it) {
                if (empty($it['medicine_id'])) continue;
                $ms->execute([(int)$it['medicine_id'], $tid]);
                $med = $ms->fetch();
                if (!$med) { $short[] = $it['medicine_name']; continue; }
                $give = min((int)$med['stock'], (int)$it['quantity']);
                $mu->execute([(int)$med['stock'] - $give, (int)$it['medicine_id'], $tid]);
                if ($give < (int)$it['quantity']) $short[] = $it['medicine_name'] . ' (' . $give . '/' . (int)$it['quantity'] . ')';
            }
            $newStatus = $short ? 'partially_dispensed' : 'dispensed';
            $note = $rx['notes'] ?? '';
            if ($short) $note = trim($note . ' [Partial dispense ' . date('d M Y') . ': insufficient stock for ' . implode(', ', $short) . ']');
            $up = $pdo->prepare('UPDATE prescriptions SET status=?, notes=? WHERE id=? AND tenant_id=?');
            $up->execute([$newStatus, $note ?: null, $rxId, $tid]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Dispensing failed. No stock was changed.');
            redirect('pharmacy.php#prescriptions');
        }
        audit_log($pdo, 'prescription.dispense', 'prescription', $rxId, $newStatus);
        flash('success', $short ? 'Prescription partially dispensed — some items were short on stock (noted on the prescription).' : 'Prescription dispensed and stock updated.');
        redirect('pharmacy.php?rx=' . $rxId . '#prescriptions');
    }

    flash('error', 'You do not have permission for that action.');
    redirect('pharmacy.php');
}

/* ---------------- Data ---------------- */
$mq = trim((string)($_GET['mq'] ?? ''));
$mwhere = 'tenant_id=?'; $margs = [$tid];
if ($mq !== '') { $mwhere .= ' AND (name LIKE ? OR generic_name LIKE ? OR category LIKE ?)'; $margs[] = "%$mq%"; $margs[] = "%$mq%"; $margs[] = "%$mq%"; }
$ms = $pdo->prepare("SELECT * FROM medicines WHERE $mwhere ORDER BY name");
$ms->execute($margs);
$medicines = $ms->fetchAll();

$editMed = null;
if (!empty($_GET['edit_med'])) {
    $es = $pdo->prepare('SELECT * FROM medicines WHERE id=? AND tenant_id=?');
    $es->execute([(int)$_GET['edit_med'], $tid]);
    $editMed = $es->fetch() ?: null;
}

$rxStatus = trim((string)($_GET['rx_status'] ?? ''));
$rwhere = 'pr.tenant_id=?'; $rargs = [$tid];
if (in_array($rxStatus, ['pending', 'dispensed', 'partially_dispensed'], true)) { $rwhere .= ' AND pr.status=?'; $rargs[] = $rxStatus; }
$rs = $pdo->prepare("SELECT pr.*, p.name AS patient_name, d.name AS doctor_name FROM prescriptions pr JOIN patients p ON p.id=pr.patient_id JOIN doctors d ON d.id=pr.doctor_id WHERE $rwhere ORDER BY pr.id DESC LIMIT 50");
$rs->execute($rargs);
$prescriptions = $rs->fetchAll();

$viewRx = null; $viewRxItems = [];
if (!empty($_GET['rx'])) {
    $vs = $pdo->prepare('SELECT pr.*, p.name AS patient_name, p.patient_code, d.name AS doctor_name FROM prescriptions pr JOIN patients p ON p.id=pr.patient_id JOIN doctors d ON d.id=pr.doctor_id WHERE pr.id=? AND pr.tenant_id=?');
    $vs->execute([(int)$_GET['rx'], $tid]);
    $viewRx = $vs->fetch() ?: null;
    if ($viewRx) {
        $vis = $pdo->prepare('SELECT pi.*, m.stock AS current_stock FROM prescription_items pi LEFT JOIN medicines m ON m.id=pi.medicine_id WHERE pi.prescription_id=? ORDER BY pi.id');
        $vis->execute([(int)$viewRx['id']]);
        $viewRxItems = $vis->fetchAll();
    }
}

$patients = $doctors = [];
if ($canPrescribe) {
    $ps = $pdo->prepare('SELECT id,name,patient_code FROM patients WHERE tenant_id=? AND status="active" ORDER BY name'); $ps->execute([$tid]); $patients = $ps->fetchAll();
    $ds = $pdo->prepare('SELECT id,name FROM doctors WHERE tenant_id=? AND status="active" ORDER BY name'); $ds->execute([$tid]); $doctors = $ds->fetchAll();
}
$lowCount = 0; foreach ($medicines as $m) if ((int)$m['stock'] <= (int)$m['reorder_level']) $lowCount++;

$pageTitle = 'Pharmacy & Prescriptions';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<ul class="nav nav-tabs mb-4">
  <li class="nav-item"><a class="nav-link active" href="#medicines" data-bs-toggle="tab">Medicines<?php if ($lowCount): ?> <span class="badge text-bg-warning"><?= $lowCount ?> low</span><?php endif; ?></a></li>
  <li class="nav-item"><a class="nav-link" href="#prescriptions" data-bs-toggle="tab" id="rxTab">Prescriptions</a></li>
</ul>
<div class="tab-content">
<!-- ============ MEDICINES ============ -->
<div class="tab-pane fade show active" id="medicines">
  <div class="row g-4">
    <div class="col-lg-<?= $canManage ? '8' : '12' ?>">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center"><span>Medicine stock</span>
          <form class="d-flex gap-2" method="get"><input name="mq" class="form-control form-control-sm" placeholder="Search medicines…" value="<?= e($mq) ?>"><button class="btn btn-sm btn-primary">Search</button></form>
        </div>
        <div class="table-responsive"><table class="table">
          <thead><tr><th>Medicine</th><th>Category</th><th>Unit</th><th class="text-end">Stock</th><th class="text-end">Price</th><th>Expiry</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
          <tbody>
          <?php if (!$medicines): ?><tr><td colspan="7"><div class="empty-state">No medicines found.</div></td></tr><?php endif; ?>
          <?php foreach ($medicines as $m): $low = (int)$m['stock'] <= (int)$m['reorder_level']; $expiring = $m['expiry_date'] && $m['expiry_date'] <= date('Y-m-d', strtotime('+60 days')); $expired = $m['expiry_date'] && $m['expiry_date'] < date('Y-m-d'); ?>
            <tr class="<?= $low ? 'table-warning' : '' ?>">
              <td><strong><?= e($m['name']) ?></strong><?php if ($m['generic_name']): ?><br><small class="text-muted"><?= e($m['generic_name']) ?></small><?php endif; ?></td>
              <td><?= e((string)($m['category'] ?? '—')) ?></td>
              <td><?= e($m['unit']) ?></td>
              <td class="text-end fw-semibold"><?= (int)$m['stock'] ?><?php if ($low): ?> <span class="badge text-bg-warning">Low · reorder <?= (int)$m['reorder_level'] ?></span><?php endif; ?></td>
              <td class="text-end"><?= money($m['price']) ?></td>
              <td><?= fmt_date($m['expiry_date']) ?><?php if ($expired): ?> <span class="badge text-bg-danger">Expired</span><?php elseif ($expiring): ?> <span class="badge text-bg-warning">Expiring soon</span><?php endif; ?></td>
              <?php if ($canManage): ?><td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="pharmacy.php?edit_med=<?= (int)$m['id'] ?>">Edit</a>
                <form method="post" class="d-inline" data-confirm="Delete this medicine?"><?= csrf_field() ?><input type="hidden" name="action" value="medicine_delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
              </td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      </div>
    </div>
    <?php if ($canManage): ?>
    <div class="col-lg-4">
      <div class="card"><div class="card-header"><?= $editMed ? 'Edit medicine' : 'Add medicine' ?></div><div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="medicine_save"><input type="hidden" name="id" value="<?= (int)($editMed['id'] ?? 0) ?>">
          <div class="mb-2"><label class="form-label">Name *</label><input name="name" class="form-control" required value="<?= e((string)($editMed['name'] ?? '')) ?>"></div>
          <div class="mb-2"><label class="form-label">Generic name</label><input name="generic_name" class="form-control" value="<?= e((string)($editMed['generic_name'] ?? '')) ?>"></div>
          <div class="row g-2">
            <div class="col-6 mb-2"><label class="form-label">Category</label><input name="category" class="form-control" value="<?= e((string)($editMed['category'] ?? '')) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label">Unit</label><input name="unit" class="form-control" value="<?= e((string)($editMed['unit'] ?? 'tablet')) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label">Stock</label><input type="number" min="0" name="stock" class="form-control" value="<?= (int)($editMed['stock'] ?? 0) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label">Reorder level</label><input type="number" min="0" name="reorder_level" class="form-control" value="<?= (int)($editMed['reorder_level'] ?? 10) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label">Price ($)</label><input type="number" step="0.01" min="0" name="price" class="form-control" value="<?= e((string)($editMed['price'] ?? '0')) ?>"></div>
            <div class="col-6 mb-2"><label class="form-label">Expiry date</label><input type="date" name="expiry_date" class="form-control" value="<?= e((string)($editMed['expiry_date'] ?? '')) ?>"></div>
          </div>
          <button class="btn btn-primary w-100"><?= $editMed ? 'Save changes' : 'Add medicine' ?></button>
          <?php if ($editMed): ?><a class="btn btn-link w-100" href="pharmacy.php">Cancel edit</a><?php endif; ?>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ PRESCRIPTIONS ============ -->
<div class="tab-pane fade" id="prescriptions">
  <?php if ($viewRx): ?>
  <div class="card mb-4"><div class="card-header d-flex justify-content-between"><span>Prescription #<?= (int)$viewRx['id'] ?> — <?= e($viewRx['patient_name']) ?> (<?= e($viewRx['patient_code']) ?>)</span><?= status_badge((string)$viewRx['status']) ?></div>
    <div class="card-body">
      <p class="small text-muted mb-2">Doctor: <?= e($viewRx['doctor_name']) ?> · Date: <?= fmt_date($viewRx['prescription_date']) ?><?= $viewRx['diagnosis'] ? ' · Diagnosis: ' . e((string)$viewRx['diagnosis']) : '' ?></p>
      <?php if ($viewRx['notes']): ?><p class="small"><?= e((string)$viewRx['notes']) ?></p><?php endif; ?>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th class="text-end">Qty</th><th class="text-end">Stock now</th></tr></thead>
      <tbody><?php foreach ($viewRxItems as $it): ?><tr><td><?= e($it['medicine_name']) ?></td><td><?= e((string)($it['dosage'] ?? '—')) ?></td><td><?= e((string)($it['frequency'] ?? '—')) ?></td><td><?= e((string)($it['duration'] ?? '—')) ?></td><td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= $it['current_stock'] === null ? '—' : (int)$it['current_stock'] ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php if ($canManage && $viewRx['status'] !== 'dispensed'): ?>
      <form method="post" data-confirm="Dispense this prescription and deduct stock?"><?= csrf_field() ?><input type="hidden" name="action" value="dispense"><input type="hidden" name="id" value="<?= (int)$viewRx['id'] ?>"><button class="btn btn-success"><i class="bi bi-capsule me-1"></i>Dispense now</button></form>
      <?php endif; ?>
    </div></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-<?= $canPrescribe ? '7' : '12' ?>">
      <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><span>Prescriptions</span>
        <form class="d-flex gap-2" method="get"><select name="rx_status" class="form-select form-select-sm"><option value="">All statuses</option>
          <?php foreach (['pending', 'dispensed', 'partially_dispensed'] as $s): ?><option value="<?= $s ?>" <?= $rxStatus === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option><?php endforeach; ?>
        </select><button class="btn btn-sm btn-primary">Filter</button></form></div>
        <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Patient</th><th>Doctor</th><th>Diagnosis</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php if (!$prescriptions): ?><tr><td colspan="6"><div class="empty-state">No prescriptions found.</div></td></tr><?php endif; ?>
        <?php foreach ($prescriptions as $rx): ?>
          <tr><td><?= fmt_date($rx['prescription_date']) ?></td><td><?= e($rx['patient_name']) ?></td><td><?= e($rx['doctor_name']) ?></td><td><?= e((string)($rx['diagnosis'] ?? '—')) ?></td><td><?= status_badge((string)$rx['status']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="pharmacy.php?rx=<?= (int)$rx['id'] ?>#prescriptions">View</a>
          <?php if ($canManage && $rx['status'] !== 'dispensed'): ?><form method="post" class="d-inline" data-confirm="Dispense and deduct stock?"><?= csrf_field() ?><input type="hidden" name="action" value="dispense"><input type="hidden" name="id" value="<?= (int)$rx['id'] ?>"><button class="btn btn-sm btn-success">Dispense</button></form><?php endif; ?>
          </td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
      </div>
    </div>
    <?php if ($canPrescribe): ?>
    <div class="col-lg-5">
      <div class="card"><div class="card-header">New prescription</div><div class="card-body">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="prescription_create">
          <div class="mb-2"><label class="form-label">Patient *</label><select name="patient_id" class="form-select" required><option value="">Select patient…</option><?php foreach ($patients as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?></select></div>
          <div class="mb-2"><label class="form-label">Doctor *</label><select name="doctor_id" class="form-select" required><option value="">Select doctor…</option><?php foreach ($doctors as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
          <div class="mb-2"><label class="form-label">Diagnosis</label><input name="diagnosis" class="form-control" maxlength="255"></div>
          <div class="mb-2"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          <div id="rxRows">
            <div class="row g-2 mb-2 rx-row">
              <div class="col-12"><select name="med_id[]" class="form-select" required><option value="">Choose medicine…</option><?php foreach ($medicines as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?> (stock <?= (int)$m['stock'] ?>)</option><?php endforeach; ?></select></div>
              <div class="col-3"><input name="med_dosage[]" class="form-control" placeholder="Dosage"></div>
              <div class="col-3"><input name="med_frequency[]" class="form-control" placeholder="Frequency"></div>
              <div class="col-3"><input name="med_duration[]" class="form-control" placeholder="Duration"></div>
              <div class="col-2"><input type="number" name="med_qty[]" class="form-control" value="1" min="1"></div>
              <div class="col-1"><button type="button" class="btn btn-outline-danger w-100 remove-rx"><i class="bi bi-x"></i></button></div>
            </div>
          </div>
          <button type="button" class="btn btn-outline-primary btn-sm mb-3" id="addRx">Add medicine</button>
          <button class="btn btn-primary w-100">Create prescription</button>
        </form>
      </div></div>
    </div>
    <?php endif; ?>
  </div>
</div>
</div>
<script>
(function(){
  if(location.hash==='#prescriptions'||new URLSearchParams(location.search).has('rx')){var t=document.getElementById('rxTab');if(t)new bootstrap.Tab(t).show();}
  var add=document.getElementById('addRx');
  if(add){add.addEventListener('click',function(){var w=document.getElementById('rxRows');var r=w.querySelector('.rx-row').cloneNode(true);r.querySelectorAll('input').forEach(function(i){i.value=i.name.indexOf('qty')>-1?'1':'';});w.appendChild(r);});
  document.getElementById('rxRows').addEventListener('click',function(ev){var b=ev.target.closest('.remove-rx');if(!b)return;if(document.querySelectorAll('.rx-row').length>1)b.closest('.rx-row').remove();});}
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
