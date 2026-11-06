<?php
declare(strict_types=1);
/* Super Admin — Platform Reports (Upgrade v2 page tree).
 * Revenue, growth and subscription reporting computed live from payments,
 * subscriptions and tenants. Charts load through api/dashboard-stats.php
 * (?scope=platform); tables are server-rendered; CSV exports are real. */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

/* ---- CSV exports ---- */
$export = (string)($_GET['export'] ?? '');
if ($export === 'revenue') {
    $rows = $pdo->query(
        "SELECT DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') AS month,
                COUNT(*) AS payments, COALESCE(SUM(amount),0) AS verified_revenue
           FROM payments WHERE status = 'verified'
          GROUP BY month ORDER BY month"
    )->fetchAll(PDO::FETCH_ASSOC);
    export_csv($rows, 'platform-revenue-by-month.csv');
}
if ($export === 'tenants') {
    $rows = $pdo->query(
        "SELECT t.id, t.name, t.slug, t.email, t.status, t.created_at,
                (SELECT p.name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS users,
                (SELECT COALESCE(SUM(amount),0) FROM payments pay WHERE pay.tenant_id = t.id AND pay.status = 'verified') AS verified_payments
           FROM tenants t ORDER BY t.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    export_csv($rows, 'platform-hospitals.csv');
}

/* ---- Revenue by month (last 12) ---- */
$revStmt = $pdo->query(
    "SELECT DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') AS ym, COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total
       FROM payments WHERE status = 'verified'
         AND COALESCE(paid_at, created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 11 MONTH), '%Y-%m-01')
      GROUP BY ym"
)->fetchAll();
$revMap = [];
foreach ($revStmt as $r) {
    $revMap[$r['ym']] = $r;
}
$revMonths = [];
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $ym = date('Y-m', $ts);
    $revMonths[] = [
        'label' => date('M Y', $ts),
        'payments' => (int)($revMap[$ym]['cnt'] ?? 0),
        'total' => (float)($revMap[$ym]['total'] ?? 0),
    ];
}
$revTotal12 = array_sum(array_column($revMonths, 'total'));

/* ---- Revenue by plan ---- */
$planRevenue = $pdo->query(
    "SELECT p.name AS plan_name, COUNT(DISTINCT s.tenant_id) AS hospitals, COUNT(s.id) AS subscriptions,
            COALESCE(SUM(CASE WHEN s.billing_cycle = 'yearly' THEN p.yearly_price / 12 ELSE p.monthly_price END), 0) AS mrr
       FROM subscription_plans p
       LEFT JOIN subscriptions s ON s.plan_id = p.id AND s.status IN ('active','trial')
      GROUP BY p.id, p.name ORDER BY mrr DESC"
)->fetchAll();

/* ---- Subscription status breakdown ---- */
$subStatus = $pdo->query('SELECT status, COUNT(*) AS c FROM subscriptions GROUP BY status ORDER BY c DESC')->fetchAll();

/* ---- Top hospitals by verified payments ---- */
$topTenants = $pdo->query(
    "SELECT t.name, t.status, COUNT(pay.id) AS payments, COALESCE(SUM(pay.amount),0) AS total
       FROM tenants t JOIN payments pay ON pay.tenant_id = t.id AND pay.status = 'verified'
      GROUP BY t.id, t.name, t.status ORDER BY total DESC LIMIT 8"
)->fetchAll();

/* ---- New hospitals by month (last 6) ---- */
$growthStmt = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c FROM tenants
      WHERE created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01') GROUP BY ym"
)->fetchAll();
$growthMap = [];
foreach ($growthStmt as $r) {
    $growthMap[$r['ym']] = (int)$r['c'];
}
$growth = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $growth[] = ['label' => date('M Y', $ts), 'count' => $growthMap[date('Y-m', $ts)] ?? 0];
}

$pageTitle = 'Platform Reports';
include __DIR__ . '/sa-header.php';
?>
<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="me-auto align-self-center text-muted">Verified revenue, last 12 months: <strong class="text-body"><?= money($revTotal12) ?></strong></span>
  <a class="btn btn-outline-primary btn-sm" href="reports.php?export=revenue"><i class="bi bi-download"></i> Revenue CSV</a>
  <a class="btn btn-outline-primary btn-sm" href="reports.php?export=tenants"><i class="bi bi-download"></i> Hospitals CSV</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Verified revenue — last 6 months (live API)</div>
      <div class="card-body"><div style="height:230px"><canvas id="revChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Subscriptions by plan (live API)</div>
      <div class="card-body"><div style="height:230px"><canvas id="planChart"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header">Verified revenue by month</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Month</th><th>Verified payments</th><th class="text-end">Revenue</th><th style="width:38%">Share of 12-month total</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($revMonths) as $m): $share = $revTotal12 > 0 ? round($m['total'] / $revTotal12 * 100) : 0; ?>
            <tr>
              <td><?= e($m['label']) ?></td>
              <td><?= $m['payments'] ?></td>
              <td class="text-end"><?= money($m['total']) ?></td>
              <td><div class="progress" role="progressbar" aria-valuenow="<?= $share ?>"><div class="progress-bar" style="width:<?= $share ?>%"></div></div></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot><tr><th colspan="2">Total (12 months)</th><th class="text-end"><?= money($revTotal12) ?></th><th></th></tr></tfoot>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Top hospitals by verified payments</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Hospital</th><th>Status</th><th>Payments</th><th class="text-end">Verified total</th></tr></thead>
          <tbody>
          <?php if (!$topTenants): ?><tr><td colspan="4" class="text-muted text-center py-4">No verified payments yet.</td></tr><?php endif; ?>
          <?php foreach ($topTenants as $t): ?>
            <tr><td><strong><?= e($t['name']) ?></strong></td><td><?= status_badge((string)$t['status']) ?></td><td><?= (int)$t['payments'] ?></td><td class="text-end"><?= money((float)$t['total']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Recurring revenue by plan</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Plan</th><th>Hospitals</th><th class="text-end">MRR</th></tr></thead>
          <tbody>
          <?php foreach ($planRevenue as $p): ?>
            <tr><td><span class="chip"><?= e($p['plan_name']) ?></span></td><td><?= (int)$p['hospitals'] ?></td><td class="text-end"><?= money((float)$p['mrr']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header">Subscriptions by status</div>
      <div class="card-body">
        <?php if (!$subStatus): ?><p class="text-muted mb-0">No subscriptions yet.</p><?php endif; ?>
        <?php $subTotal = array_sum(array_map(static fn($r) => (int)$r['c'], $subStatus)) ?: 1; ?>
        <?php foreach ($subStatus as $s): $pct = round((int)$s['c'] / $subTotal * 100); ?>
          <div class="d-flex justify-content-between small mb-1"><span><?= e(ucfirst(str_replace('_', ' ', (string)$s['status']))) ?></span><span><?= (int)$s['c'] ?> · <?= $pct ?>%</span></div>
          <div class="progress mb-3"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="card">
      <div class="card-header">New hospitals — last 6 months</div>
      <div class="card-body">
        <?php $gMax = max(1, ...array_map(static fn($g) => $g['count'], $growth)); ?>
        <?php foreach (array_reverse($growth) as $g): $pct = round($g['count'] / $gMax * 100); ?>
          <div class="d-flex justify-content-between small mb-1"><span><?= e($g['label']) ?></span><span><?= $g['count'] ?> new</span></div>
          <div class="progress mb-3"><div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  MediCare.api('dashboard-stats.php?scope=platform').then(function (data) {
    MediCare.chart('revChart', {
      type: 'line',
      data: { labels: data.charts.revenue.labels, datasets: [{ label: 'Verified revenue ($)', data: data.charts.revenue.data, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.12)', fill: true, tension: .35 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
    });
    MediCare.chart('planChart', {
      type: 'doughnut',
      data: { labels: data.charts.plans.labels, datasets: [{ data: data.charts.plans.data, backgroundColor: ['#2563eb', '#10b981', '#0d9488', '#f59e0b', '#8b5cf6', '#e11d48'] }] },
      options: { responsive: true, maintainAspectRatio: false }
    });
  }).catch(function (err) { MediCare.toast(err.message, 'error'); });
});
</script>
<?php include __DIR__ . '/sa-footer.php'; ?>
