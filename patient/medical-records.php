<?php
declare(strict_types=1);
/* Patient portal — Medical Records (Upgrade v2 page tree).
 * Patients only ever see records their care team explicitly shared
 * (medical_records.visibility = 'patient') and documents marked visible
 * to them. Staff-only records never leave the hospital side. Document
 * downloads stream through this page with the same checks. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/panel.php';
$pdo = db();
$user = require_role(['patient']);
$tid = require_tenant();
check_tenant_active($pdo);
$base = '../';
$panelSidebar = 'patient';
$patient = panel_patient($pdo, $tid, (int)$user['id']);

/* Document download — own + patient-visible only. */
$docId = (int)($_GET['doc'] ?? 0);
if ($docId > 0 && $patient) {
    $ds = $pdo->prepare("SELECT * FROM patient_documents WHERE id = ? AND tenant_id = ? AND patient_id = ? AND visibility = 'patient'");
    $ds->execute([$docId, $tid, (int)$patient['id']]);
    $doc = $ds->fetch();
    $abs = $doc ? realpath(__DIR__ . '/../' . $doc['file_path']) : false;
    $root = realpath(__DIR__ . '/../storage/documents');
    if (!$doc || !$abs || !$root || !str_starts_with($abs, $root) || !is_file($abs)) {
        http_response_code(404);
        exit('Document not found.');
    }
    header('Content-Type: ' . ($doc['file_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename((string)$doc['file_name']) . '"');
    header('Content-Length: ' . filesize($abs));
    readfile($abs);
    exit;
}

$pageTitle = 'My Medical Records';
include __DIR__ . '/../includes/header.php';
if (!$patient) {
    ?>
    <div class="card"><div class="empty-state py-5"><i class="bi bi-person-heart"></i>
      <p class="mb-0"><strong>Your login is not linked to a patient profile yet.</strong><br><span class="text-muted">Ask the front desk to link your account to see your shared records.</span></p>
    </div></div>
    <?php include __DIR__ . '/../includes/footer.php';
    return;
}
$pid = (int)$patient['id'];

$records = $pdo->prepare(
    "SELECT mr.*, d.name AS doctor_name FROM medical_records mr
       LEFT JOIN doctors d ON d.id = mr.doctor_id
      WHERE mr.tenant_id = ? AND mr.patient_id = ? AND mr.visibility = 'patient'
      ORDER BY mr.created_at DESC"
);
$records->execute([$tid, $pid]);
$records = $records->fetchAll();

$docs = $pdo->prepare("SELECT * FROM patient_documents WHERE tenant_id = ? AND patient_id = ? AND visibility = 'patient' ORDER BY id DESC");
$docs->execute([$tid, $pid]);
$docs = $docs->fetchAll();

$labs = $pdo->prepare(
    "SELECT lr.*, lt.name AS test_name, lt.normal_range, lt.unit FROM lab_results lr
       JOIN lab_tests lt ON lt.id = lr.lab_test_id
      WHERE lr.tenant_id = ? AND lr.patient_id = ? AND lr.status = 'completed'
      ORDER BY lr.id DESC LIMIT 15"
);
$labs->execute([$tid, $pid]);
$labs = $labs->fetchAll();
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-file-medical me-1"></i>Records shared with me</div>
      <div class="card-body">
        <?php if (!$records): ?>
          <div class="empty-state py-4"><i class="bi bi-file-medical"></i><p class="mb-0">No records have been shared with you yet. When your doctor shares a diagnosis, note or document, it appears here.</p></div>
        <?php else: ?>
        <ul class="timeline">
          <?php foreach ($records as $r): ?>
            <li>
              <strong class="d-block small"><i class="bi bi-file-medical me-1"></i><?= e(ucfirst((string)$r['record_type'])) ?> — <?= e($r['title']) ?></strong>
              <?php if ($r['details']): ?><span class="d-block small text-muted"><?= e($r['details']) ?></span><?php endif; ?>
              <small class="text-muted"><?= $r['doctor_name'] ? 'By ' . e($r['doctor_name']) . ' · ' : '' ?><?= fmt_datetime($r['created_at']) ?></small>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header"><i class="bi bi-folder2-open me-1"></i>My documents</div>
      <div class="card-body">
        <?php if (!$docs): ?><p class="text-muted mb-0">No documents have been shared with you yet.</p><?php endif; ?>
        <?php foreach ($docs as $d): ?>
          <div class="d-flex align-items-center gap-2 py-2 border-bottom">
            <span class="doc-thumb"><i class="bi bi-file-earmark-text"></i></span>
            <span class="flex-grow-1"><strong class="d-block small"><?= e($d['title']) ?></strong><small class="text-muted"><?= e($d['file_name']) ?> · <?= fmt_date($d['created_at']) ?></small></span>
            <a class="btn btn-sm btn-outline-primary" href="medical-records.php?doc=<?= (int)$d['id'] ?>" title="Download"><i class="bi bi-download"></i></a>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><i class="bi bi-droplet-half me-1"></i>My lab results</div>
      <div class="card-body">
        <?php if (!$labs): ?><p class="text-muted mb-0">No completed lab results yet.</p><?php endif; ?>
        <?php foreach ($labs as $lr): ?>
          <div class="py-2 border-bottom">
            <strong class="d-block small"><?= e($lr['test_name']) ?></strong>
            <span class="small">Result: <strong><?= e($lr['result_value'] ?? '—') ?></strong> <?= e($lr['unit'] ?? '') ?> <span class="text-muted">(normal: <?= e($lr['normal_range'] ?? '—') ?>)</span></span>
            <small class="text-muted d-block"><?= $lr['completed_at'] ? fmt_datetime($lr['completed_at']) : fmt_datetime($lr['ordered_at']) ?><?= $lr['remarks'] ? ' · ' . e($lr['remarks']) : '' ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
