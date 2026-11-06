<?php
declare(strict_types=1);
/* Patient portal — Prescriptions (Upgrade v2 page tree).
 * The patient's own prescriptions with full medicine lines, dispensing
 * status, and a printable sheet (?print_rx=N) restricted to their own
 * prescriptions. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'patient';
$patient = panel_patient($pdo, $tid, (int)$user['id']);

$printRx = (int)($_GET['print_rx'] ?? 0);
if ($printRx > 0 && $patient) {
    $rs = $pdo->prepare('SELECT pr.*, p.name AS patient_name, p.patient_code, p.dob, p.gender, d.name AS doctor_name, d.specialization, d.qualification FROM prescriptions pr JOIN patients p ON p.id = pr.patient_id JOIN doctors d ON d.id = pr.doctor_id WHERE pr.id = ? AND pr.tenant_id = ? AND pr.patient_id = ?');
    $rs->execute([$printRx, $tid, (int)$patient['id']]);
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
    <?php if ($rx['notes']): ?><p class="mt-3"><strong>Doctor's notes:</strong> <?= e($rx['notes']) ?></p><?php endif; ?>
    <?php
    print_page_end();
    exit;
}

$pageTitle = 'My Prescriptions';
include __DIR__ . '/../includes/header.php';
if (!$patient) {
    ?>
    <div class="card"><div class="empty-state py-5"><i class="bi bi-person-heart"></i>
      <p class="mb-0"><strong>Your login is not linked to a patient profile yet.</strong><br><span class="text-muted">Ask the front desk to link your account to see your prescriptions.</span></p>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}
$pid = (int)$patient['id'];
$rxList = $pdo->prepare(
    'SELECT pr.*, d.name AS doctor_name FROM prescriptions pr JOIN doctors d ON d.id = pr.doctor_id
      WHERE pr.tenant_id = ? AND pr.patient_id = ? ORDER BY pr.id DESC'
);
$rxList->execute([$tid, $pid]);
$rxList = $rxList->fetchAll();
$itemsByRx = [];
if ($rxList) {
    $ids = array_map(static fn($r) => (int)$r['id'], $rxList);
    $is = $pdo->query('SELECT * FROM prescription_items WHERE prescription_id IN (' . implode(',', $ids) . ') ORDER BY id');
    foreach ($is->fetchAll() as $it) {
        $itemsByRx[(int)$it['prescription_id']][] = $it;
    }
}
?>
<?php if (!$rxList): ?>
<div class="card"><div class="empty-state py-5"><i class="bi bi-prescription2"></i><p class="mb-0">No prescriptions yet. Prescriptions your doctors write will appear here.</p></div></div>
<?php endif; ?>
<div class="row g-3">
  <?php foreach ($rxList as $rx): ?>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Rx #<?= (int)$rx['id'] ?> · <?= fmt_date($rx['prescription_date']) ?></span>
        <span class="d-flex gap-2 align-items-center"><?= status_badge((string)$rx['status']) ?>
          <a class="btn btn-sm btn-outline-primary" href="prescriptions.php?print_rx=<?= (int)$rx['id'] ?>" target="_blank" rel="noopener" title="Print"><i class="bi bi-printer"></i></a></span>
      </div>
      <div class="card-body">
        <p class="small mb-2"><strong><?= e($rx['doctor_name']) ?></strong><?php if ($rx['diagnosis']): ?> · Diagnosis: <?= e($rx['diagnosis']) ?><?php endif; ?></p>
        <table class="table table-sm align-middle mb-0">
          <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Qty</th></tr></thead>
          <tbody>
          <?php foreach ($itemsByRx[(int)$rx['id']] ?? [] as $it): ?>
            <tr><td><strong><?= e($it['medicine_name']) ?></strong><?php if ($it['instructions']): ?><br><small class="text-muted"><?= e($it['instructions']) ?></small><?php endif; ?></td><td><?= e($it['dosage'] ?? '—') ?></td><td><?= e($it['frequency'] ?? '—') ?></td><td><?= e($it['duration'] ?? '—') ?></td><td><?= (int)$it['quantity'] ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($rx['notes']): ?><p class="small text-muted mt-2 mb-0"><strong>Notes:</strong> <?= e($rx['notes']) ?></p><?php endif; ?>
        <?php if ($rx['status'] === 'pending'): ?><p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle"></i> Show this prescription at the hospital pharmacy to collect your medicines.</p><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
