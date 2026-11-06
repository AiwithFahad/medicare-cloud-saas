<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

/* ---- Platform aggregates (all computed live, never hardcoded) ---- */
$scalar = static function (string $sql) use ($pdo) {
    return $pdo->query($sql)->fetchColumn();
};
$totalTenants       = (int)$scalar('SELECT COUNT(*) FROM tenants');
$activeTenants      = (int)$scalar("SELECT COUNT(*) FROM tenants WHERE status = 'active'");
$suspendedTenants   = (int)$scalar("SELECT COUNT(*) FROM tenants WHERE status = 'suspended'");
$totalUsers         = (int)$scalar('SELECT COUNT(*) FROM users');
$activeSubs         = (int)$scalar("SELECT COUNT(*) FROM subscriptions WHERE status IN ('active','trial')");
$deadSubs           = (int)$scalar("SELECT COUNT(*) FROM subscriptions WHERE status IN ('expired','cancelled')");
$verifiedRevenue    = (float)$scalar("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified'");
$pendingPaymentsCnt = (int)$scalar("SELECT COUNT(*) FROM payments WHERE status = 'pending'");

/* MRR: monthly plans count at monthly_price, yearly plans at yearly_price/12,
 * over subscriptions that are active or in trial. ARR = MRR x 12. */
$mrr = (float)$scalar(
    "SELECT COALESCE(SUM(CASE WHEN s.billing_cycle = 'yearly' THEN p.yearly_price / 12 ELSE p.monthly_price END), 0)
       FROM subscriptions s
       JOIN subscription_plans p ON p.id = s.plan_id
      WHERE s.status IN ('active','trial')"
);
$arr = $mrr * 12;

/* Verified revenue by month — last 6 months (zero-filled in PHP). */
$revRows = $pdo->query(
    "SELECT DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') AS ym, COALESCE(SUM(amount),0) AS total
       FROM payments
      WHERE status = 'verified'
        AND COALESCE(paid_at, created_at) >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
      GROUP BY ym"
)->fetchAll();
$revMap = [];
foreach ($revRows as $r) {
    $revMap[$r['ym']] = (float)$r['total'];
}
$revLabels = [];
$revData = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = strtotime("-{$i} months");
    $revLabels[] = date('M Y', $ts);
    $revData[] = $revMap[date('Y-m', $ts)] ?? 0;
}

/* Subscriptions by plan. */
$planRows = $pdo->query(
    'SELECT p.name AS plan_name, COUNT(*) AS c
       FROM subscriptions s
       JOIN subscription_plans p ON p.id = s.plan_id
      GROUP BY p.id, p.name
      ORDER BY c DESC'
)->fetchAll();
$planLabels = array_map(static fn($r) => (string)$r['plan_name'], $planRows);
$planData = array_map(static fn($r) => (int)$r['c'], $planRows);

$recentTenants = $pdo->query(
    'SELECT t.*,
            (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS user_count,
            (SELECT p.name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id
              WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name
       FROM tenants t ORDER BY t.id DESC LIMIT 8'
)->fetchAll();

$pendingPayments = $pdo->query(
    "SELECT pay.*, t.name AS tenant_name, i.invoice_no
       FROM payments pay
       JOIN tenants t ON t.id = pay.tenant_id
       LEFT JOIN invoices i ON i.id = pay.invoice_id
      WHERE pay.status = 'pending'
      ORDER BY pay.id DESC LIMIT 8"
)->fetchAll();

$recentActivity = $pdo->query(
    'SELECT a.*, t.name AS tenant_name, u.name AS user_name
       FROM activity_logs a
       LEFT JOIN tenants t ON t.id = a.tenant_id
       LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 10'
)->fetchAll();

$pageTitle = 'Platform Dashboard';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3 mb-3">
  <?php
  $cards = [
      ['Total Tenants', $totalTenants, 'bi-buildings', 'ic-blue'],
      ['Active Tenants', $activeTenants, 'bi-building-check', 'ic-green'],
      ['Suspended Tenants', $suspendedTenants, 'bi-building-slash', 'ic-rose'],
      ['Total Users', $totalUsers, 'bi-people', 'ic-violet'],
      ['Active Subscriptions', $activeSubs, 'bi-arrow-repeat', 'ic-teal'],
      ['Expired / Cancelled Subs', $deadSubs, 'bi-x-octagon', 'ic-amber'],
      ['Verified Revenue', money($verifiedRevenue), 'bi-cash-stack', 'ic-green'],
      ['Pending Payments', $pendingPaymentsCnt, 'bi-hourglass-split', 'ic-amber'],
  ];
  foreach ($cards as [$lbl, $num, $icon, $cls]): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card h-100">
      <span class="stat-ic <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></span>
      <span><span class="num d-block"><?= e((string)$num) ?></span><span class="lbl"><?= e($lbl) ?></span></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <span class="stat-ic ic-blue"><i class="bi bi-graph-up-arrow"></i></span>
      <span><span class="num d-block"><?= money($mrr) ?></span><span class="lbl">Monthly Recurring Revenue (MRR) — active &amp; trial subscriptions</span></span>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card stat-card h-100">
      <span class="stat-ic ic-green"><i class="bi bi-calendar-range"></i></span>
      <span><span class="num d-block"><?= money($arr) ?></span><span class="lbl">Annual Recurring Revenue (ARR) — MRR × 12</span></span>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Verified revenue — last 6 months</div>
      <div class="card-body"><canvas id="revChart" height="220"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Subscriptions by plan</div>
      <div class="card-body"><canvas id="planChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">Recent tenants <a class="btn btn-sm btn-outline-primary" href="tenants.php">View all</a></div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Tenant</th><th>Plan</th><th>Users</th><th>Status</th><th>Joined</th></tr></thead>
          <tbody>
          <?php if (!$recentTenants): ?><tr><td colspan="5" class="text-muted text-center py-4">No tenants yet.</td></tr><?php endif; ?>
          <?php foreach ($recentTenants as $t): ?>
            <tr>
              <td><strong><?= e($t['name']) ?></strong><br><small class="text-muted"><?= e($t['slug']) ?> · <?= e($t['email']) ?></small></td>
              <td><?= $t['plan_name'] ? '<span class="chip">' . e($t['plan_name']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
              <td><?= (int)$t['user_count'] ?></td>
              <td><?= status_badge((string)$t['status']) ?></td>
              <td><?= fmt_date($t['created_at']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header">Recent platform activity</div>
      <div class="card-body">
        <?php if (!$recentActivity): ?><p class="text-muted mb-0">No activity logged yet.</p><?php endif; ?>
        <ul class="list-unstyled mb-0">
          <?php foreach ($recentActivity as $a): ?>
            <li class="d-flex gap-2 py-2 border-bottom">
              <span class="avatar-sm flex-none"><?= e(initials((string)($a['user_name'] ?? 'S'))) ?></span>
              <span class="small"><span><?= e($a['description']) ?></span><br>
              <span class="text-muted"><?= e($a['tenant_name'] ?? 'Platform') ?><?= $a['user_name'] ? ' · ' . e($a['user_name']) : '' ?> · <?= fmt_datetime($a['created_at']) ?></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">Pending payments — quick verify <a class="btn btn-sm btn-outline-primary" href="payments.php">All payments</a></div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Tenant</th><th>Amount</th><th>Method</th><th></th></tr></thead>
          <tbody>
          <?php if (!$pendingPayments): ?><tr><td colspan="4" class="text-muted text-center py-4">No pending payments. 🎉</td></tr><?php endif; ?>
          <?php foreach ($pendingPayments as $p): ?>
            <tr>
              <td><strong><?= e($p['tenant_name']) ?></strong><br><small class="text-muted"><?= e($p['invoice_no'] ?? ('Payment #' . (int)$p['id'])) ?> · <?= fmt_date($p['created_at']) ?></small></td>
              <td><strong><?= money($p['amount']) ?></strong><br><small class="text-muted"><?= e($p['transaction_ref'] ?? '—') ?></small></td>
              <td><?= e(ucwords(str_replace('_', ' ', (string)$p['method']))) ?></td>
              <td class="text-end">
                <form method="post" action="payments.php" class="d-inline" data-confirm="Verify this payment? This activates the linked subscription.">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="verify">
                  <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                  <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg"></i> Verify</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body pt-0"><small class="text-muted">Verification is processed on the Payments page — it is the only place a payment can become <strong>verified</strong>.</small></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var rc = document.getElementById('revChart');
  if (rc) new Chart(rc, {
    type: 'bar',
    data: { labels: <?= json_encode($revLabels) ?>, datasets: [{ label: 'Verified revenue ($)', data: <?= json_encode($revData) ?>, backgroundColor: 'rgba(37,99,235,.75)', borderRadius: 6 }] },
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
  var pc = document.getElementById('planChart');
  if (pc) new Chart(pc, {
    type: 'doughnut',
    data: { labels: <?= json_encode($planLabels) ?>, datasets: [{ data: <?= json_encode($planData) ?>, backgroundColor: ['#2563eb','#10b981','#f59e0b','#7c3aed','#e11d48','#0d9488'] }] },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
  });
});
</script>
<?php include __DIR__ . '/sa-footer.php'; ?>
