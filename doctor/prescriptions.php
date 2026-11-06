<?php
declare(strict_types=1);
/* Doctor — Prescriptions (Upgrade v2 page tree).
 * Write prescriptions with dynamic medicine lines (catalog medicines link
 * their stock record; free-text lines are allowed for anything outside the
 * catalog), review your own prescription history, and print a branded
 * prescription sheet (?print_rx=N). Dispensing happens in the pharmacy. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['doctor']);
$tid = require_tenant();
check_tenant_active($pdo);
$doctor = panel_doctor($pdo, $tid, (int)$user['id']);
if (!$doctor) {
    flash('error', 'Your login is not linked to a doctor profile yet. Ask your hospital admin to link it from the Doctors page.');
    redirect('../app/dashboard.php');
}
$did = (int)$doctor['id'];
$base = '../';
$panelSidebar = 'doctor';

/* ---------------- Print (own prescriptions only, exits) ---------------- */
$printRx = (int)($_GET['print_rx'] ?? 0);
if ($printRx > 0) {
    $rs = $pdo->prepare('SELECT pr.*, p.name AS patient_name, p.patient_code, p.dob, p.gender, d.name AS doctor_name, d.specialization, d.qualification FROM prescriptions pr JOIN patients p ON p.id = pr.patient_id JOIN doctors d ON d.id = pr.doctor_id WHERE pr.id = ? AND pr.tenant_id = ? AND pr.doctor_id = ?');
    $rs->execute([$printRx, $tid, $did]);
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

/* ---------------- Create ---------------- */
if (is_post()) {
    verify_csrf();
    if (!has_permission('records.manage')) {
        flash('error', 'You do not have permission to write prescriptions.');
        redirect('prescriptions.php');
    }
    if (($_POST['action'] ?? '') === 'create_rx') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $diagnosis = trim((string)($_POST['diagnosis'] ?? '')) ?: null;
        $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
        $medIds = (array)($_POST['med_id'] ?? []);
        $medNames = (array)($_POST['med_name'] ?? []);
        $dosages = (array)($_POST['dosage'] ?? []);
        $freqs = (array)($_POST['frequency'] ?? []);
        $durs = (array)($_POST['duration'] ?? []);
        $qtys = (array)($_POST['qty'] ?? []);
        $instr = (array)($_POST['instructions'] ?? []);
        $lines = [];
        foreach ($medNames as $i => $medName) {
            $medName = trim((string)$medName);
            if ($medName === '') {
                continue;
            }
            $lines[] = [
                'medicine_id' => (int)($medIds[$i] ?? 0) ?: null,
                'medicine_name' => $medName,
                'dosage' => trim((string)($dosages[$i] ?? '')) ?: null,
                'frequency' => trim((string)($freqs[$i] ?? '')) ?: null,
                'duration' => trim((string)($durs[$i] ?? '')) ?: null,
                'quantity' => max(1, (int)($qtys[$i] ?? 1)),
                'instructions' => trim((string)($instr[$i] ?? '')) ?: null,
            ];
        }
        $pc = $pdo->prepare('SELECT COUNT(*) FROM patients WHERE id = ? AND tenant_id = ?');
        $pc->execute([$patientId, $tid]);
        if ((int)$pc->fetchColumn() === 0 || !$lines) {
            flash('error', 'Pick a patient and add at least one medicine line.');
        } else {
            $apptId = (int)($_POST['appointment_id'] ?? 0) ?: null;
            if ($apptId) {
                $ac = $pdo->prepare('SELECT COUNT(*) FROM appointments WHERE id = ? AND tenant_id = ? AND doctor_id = ? AND patient_id = ?');
                $ac->execute([$apptId, $tid, $did, $patientId]);
                if ((int)$ac->fetchColumn() === 0) {
                    $apptId = null;
                }
            }
            $pdo->prepare('INSERT INTO prescriptions (tenant_id, patient_id, doctor_id, appointment_id, prescription_date, diagnosis, notes, status) VALUES (?, ?, ?, ?, CURDATE(), ?, ?, "pending")')
                ->execute([$tid, $patientId, $did, $apptId, $diagnosis, $notes]);
            $rxId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO prescription_items (prescription_id, medicine_id, medicine_name, dosage, frequency, duration, quantity, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $ln) {
                $ins->execute([$rxId, $ln['medicine_id'], $ln['medicine_name'], $ln['dosage'], $ln['frequency'], $ln['duration'], $ln['quantity'], $ln['instructions']]);
            }
            audit_log($pdo, 'prescription_created', 'prescriptions', $rxId, 'Prescription written for patient #' . $patientId);
            $pUser = $pdo->prepare('SELECT user_id, name FROM patients WHERE id = ?');
            $pUser->execute([$patientId]);
            $pRow = $pUser->fetch();
            if ($pRow && !empty($pRow['user_id'])) {
                notify_user($pdo, $tid, (int)$pRow['user_id'], 'New prescription', $doctor['name'] . ' has written a new prescription for you. You can view it in your portal.', 'info');
            }
            flash('success', 'Prescription #' . $rxId . ' written and sent to the pharmacy queue.');
        }
    }
    redirect('prescriptions.php');
}

$patientsList = $pdo->prepare('SELECT id, name, patient_code FROM patients WHERE tenant_id = ? ORDER BY name');
$patientsList->execute([$tid]);
$patientsList = $patientsList->fetchAll();
$medicines = $pdo->prepare('SELECT id, name, stock, unit FROM medicines WHERE tenant_id = ? ORDER BY name');
$medicines->execute([$tid]);
$medicines = $medicines->fetchAll();

$rxList = $pdo->prepare(
    "SELECT pr.*, p.name AS patient_name, p.patient_code,
            (SELECT COUNT(*) FROM prescription_items pi WHERE pi.prescription_id = pr.id) AS item_count
       FROM prescriptions pr JOIN patients p ON p.id = pr.patient_id
      WHERE pr.tenant_id = ? AND pr.doctor_id = ? ORDER BY pr.id DESC LIMIT 60"
);
$rxList->execute([$tid, $did]);
$rxList = $rxList->fetchAll();

$prefillPatient = (int)($_GET['patient'] ?? 0);
$pageTitle = 'Prescriptions';
include __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-prescription2 me-1"></i>Write prescription</div>
      <div class="card-body">
        <?php if (has_permission('records.manage')): ?>
        <form method="post" id="rxForm">
          <?= csrf_field() ?><input type="hidden" name="action" value="create_rx">
          <div class="mb-3"><label class="form-label">Patient *</label>
            <select class="form-select" name="patient_id" required><option value="">Select patient…</option>
              <?php foreach ($patientsList as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $prefillPatient === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (<?= e($p['patient_code']) ?>)</option><?php endforeach; ?>
            </select></div>
          <div class="mb-3"><label class="form-label">Diagnosis</label><input class="form-control" name="diagnosis" maxlength="255" placeholder="e.g. Essential hypertension"></div>
          <label class="form-label">Medicines</label>
          <div id="rxLines">
            <div class="rx-line border rounded p-2 mb-2">
              <div class="row g-2">
                <div class="col-md-6"><select class="form-select form-select-sm med-pick"><option value="">— Catalog medicine (optional) —</option>
                  <?php foreach ($medicines as $m): ?><option value="<?= (int)$m['id'] ?>" data-name="<?= e($m['name']) ?>"><?= e($m['name']) ?> (<?= (int)$m['stock'] ?> <?= e($m['unit']) ?> in stock)</option><?php endforeach; ?>
                </select></div>
                <div class="col-md-6"><input class="form-control form-control-sm med-name" name="med_name[]" placeholder="Medicine name *" maxlength="120"></div>
                <input type="hidden" name="med_id[]" class="med-id">
                <div class="col-4"><input class="form-control form-control-sm" name="dosage[]" placeholder="Dosage (500 mg)" maxlength="60"></div>
                <div class="col-4"><input class="form-control form-control-sm" name="frequency[]" placeholder="Frequency (1-0-1)" maxlength="60"></div>
                <div class="col-4"><input class="form-control form-control-sm" name="duration[]" placeholder="Duration (7 days)" maxlength="60"></div>
                <div class="col-4"><input class="form-control form-control-sm" type="number" name="qty[]" value="1" min="1" placeholder="Qty"></div>
                <div class="col-8"><input class="form-control form-control-sm" name="instructions[]" placeholder="Instructions (after food…)" maxlength="255"></div>
              </div>
            </div>
          </div>
          <button class="btn btn-sm btn-outline-primary mb-3" type="button" id="addRxLine"><i class="bi bi-plus-lg"></i> Add medicine</button>
          <div class="mb-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="2" placeholder="Advice, follow-up, warnings…"></textarea></div>
          <button class="btn btn-success w-100" type="submit"><i class="bi bi-prescription2"></i> Save prescription</button>
        </form>
        <?php else: ?>
        <p class="text-muted mb-0">Your role does not include prescription writing.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">My prescriptions</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Date</th><th>Patient</th><th>Diagnosis</th><th>Items</th><th>Status</th><th class="text-end">Print</th></tr></thead>
          <tbody>
          <?php if (!$rxList): ?><tr><td colspan="6" class="text-muted text-center py-4">You have not written any prescriptions yet.</td></tr><?php endif; ?>
          <?php foreach ($rxList as $rx): ?>
            <tr>
              <td><?= fmt_date($rx['prescription_date']) ?></td>
              <td><a href="patients.php?id=<?= (int)$rx['patient_id'] ?>"><?= e($rx['patient_name']) ?></a> <small class="text-muted"><?= e($rx['patient_code']) ?></small></td>
              <td><?= e($rx['diagnosis'] ?? '—') ?></td>
              <td><?= (int)$rx['item_count'] ?></td>
              <td><?= status_badge((string)$rx['status']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="prescriptions.php?print_rx=<?= (int)$rx['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-printer"></i></a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  function wireLine(line) {
    var pick = line.querySelector('.med-pick');
    pick.addEventListener('change', function () {
      var opt = pick.options[pick.selectedIndex];
      line.querySelector('.med-id').value = pick.value || '';
      if (pick.value) line.querySelector('.med-name').value = opt.getAttribute('data-name') || '';
    });
  }
  document.querySelectorAll('.rx-line').forEach(wireLine);
  document.getElementById('addRxLine').addEventListener('click', function () {
    var wrap = document.getElementById('rxLines');
    var clone = wrap.querySelector('.rx-line').cloneNode(true);
    clone.querySelectorAll('input').forEach(function (i) { i.value = i.name === 'qty[]' ? '1' : ''; });
    clone.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
    wrap.appendChild(clone);
    wireLine(clone);
  });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
