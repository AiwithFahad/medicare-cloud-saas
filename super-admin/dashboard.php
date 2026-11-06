<?php
declare(strict_types=1);
/* Super Admin dashboard (Upgrade v2 page tree).
 * Stat cards + charts are filled live from api/dashboard-stats.php?scope=platform
 * through the shared MediCare JS layer; the approval queue, pending payments
 * and recent activity below are server-rendered from the same live tables. */
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['super_admin']);

$pendingTenants = $pdo->query(
    "SELECT t.*, (SELECT p.name FROM subscriptions s JOIN subscription_plans p ON p.id = s.plan_id WHERE s.tenant_id = t.id ORDER BY s.id DESC LIMIT 1) AS plan_name
       FROM tenants t WHERE t.status = 'pending' ORDER BY t.id DESC LIMIT 6"
)->fetchAll();

$pendingPayments = $pdo->query(
    "SELECT pay.*, t.name AS tenant_name, i.invoice_no
       FROM payments pay
       JOIN tenants t ON t.id = pay.tenant_id
       LEFT JOIN invoices i ON i.id = pay.invoice_id
      WHERE pay.status = 'pending'
      ORDER BY pay.id DESC LIMIT 6"
)->fetchAll();

$recentActivity = $pdo->query(
    'SELECT a.*, t.name AS tenant_name, u.name AS user_name
       FROM activity_logs a
       LEFT JOIN tenants t ON t.id = a.tenant_id
       LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 8'
)->fetchAll();

$pageTitle = 'Platform Dashboard';
include __DIR__ . '/sa-header.php';
?>
<div class="row g-3 mb-3" id="saStats">
  <?php
  $cards = [
      ['Total Hospitals', 'total_tenants', 'bi-buildings', 'ic-blue', ''],
      ['Active Hospitals', 'active_tenants', 'bi-building-check', 'ic-green', ''],
      ['Pending Approvals', 'pending_tenants', 'bi-hourglass-split', 'ic-amber', ''],
      ['Suspended', 'suspended_tenants', 'bi-building-slash', 'ic-rose', ''],
      ['Total Users', 'total_users', 'bi-people', 'ic-violet', ''],
      ['Active Subscriptions', 'active_subs', 'bi-arrow-repeat', 'ic-teal', ''],
      ['MRR', 'mrr', 'bi-graph-up-arrow', 'ic-blue', '$'],
      ['Verified Revenue', 'verified_revenue', 'bi-cash-stack', 'ic-green', '$'],
      ['Pending Payments', 'pending_payments', 'bi-credit-card-2-front', 'ic-amber', ''],
      ['Open Tickets', 'open_tickets', 'bi-life-preserver', 'ic-rose', ''],
  ];
  foreach ($cards as [$lbl, $key, $icon, $cls, $prefix]): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <div class="card stat-card h-100">
      <span class="stat-ic <?= e($cls) ?>"><i class="bi <?= e($icon) ?>"></i></span>
      <span><span class="num d-block" data-stat="<?= e($key) ?>" data-prefix="<?= e($prefix) ?>">…</span><span class="lbl"><?= e($lbl) ?></span></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Verified platform revenue — last 6 months</div>
      <div class="card-body"><div style="height:240px"><canvas id="revChart"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Subscriptions by plan</div>
      <div class="card-body"><div style="height:240px"><canvas id="planChart"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-hourglass-split me-1"></i>Hospital registration approvals</span>
        <a class="btn btn-sm btn-outline-primary" href="hospitals.php?status=pending">Review all</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Hospital</th><th>Plan</th><th>Registered</th><th></th></tr></thead>
          <tbody>
          <?php if (!$pendingTenants): ?><tr><td colspan="4" class="text-muted text-center py-4">No hospitals are waiting for approval.</td></tr><?php endif; ?>
          <?php foreach ($pendingTenants as $t): ?>
            <tr>
              <td><strong><?= e($t['name']) ?></strong><br><small class="text-muted"><?= e($t['email']) ?></small></td>
              <td><?= $t['plan_name'] ? '<span class="chip">' . e($t['plan_name']) . '</span>' : '—' ?></td>
              <td><?= fmt_date($t['created_at']) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-success" href="hospitals.php?status=pending"><i class="bi bi-check-lg"></i> Review</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-credit-card-2-front me-1"></i>Payments awaiting verification</span>
        <a class="btn btn-sm btn-outline-primary" href="payments.php">Open payments</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead><tr><th>Hospital</th><th>Invoice</th><th>Amount</th><th>Method</th></tr></thead>
          <tbody>
          <?php if (!$pendingPayments): ?><tr><td colspan="4" class="text-muted text-center py-4">No payments are waiting for verification.</td></tr><?php endif; ?>
          <?php foreach ($pendingPayments as $p): ?>
            <tr>
              <td><?= e($p['tenant_name']) ?></td>
              <td><?= e($p['invoice_no'] ?? '—') ?></td>
              <td><?= money((float)$p['amount']) ?></td>
              <td><?= e(ucfirst((string)$p['method'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body small text-muted">Payments are only marked verified after manual confirmation in Payments — never automatically.</div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Recent platform activity</div>
      <div class="card-body">
        <?php if (!$recentActivity): ?><p class="text-muted mb-0">No activity logged yet.</p><?php endif; ?>
        <ul class="list-unstyled mb-0">
          <?php foreach ($recentActivity as $a): ?>
            <li class="d-flex gap-2 py-2 border-bottom">
              <span class="doc-thumb"><i class="bi bi-activity"></i></span>
              <span>
                <span class="d-block small"><?= e((string)$a['description']) ?></span>
                <small class="text-muted"><?= e($a['tenant_name'] ?? 'Platform') ?><?= $a['user_name'] ? ' · ' . e($a['user_name']) : '' ?> · <?= fmt_datetime($a['created_at']) ?></small>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  MediCare.api('dashboard-stats.php?scope=platform').then(function (data) {
    document.querySelectorAll('[data-stat]').forEach(function (el) {
      var key = el.getAttribute('data-stat');
      var val = data.stats[key];
      if (val === undefined) { el.textContent = '—'; return; }
      el.textContent = (el.getAttribute('data-prefix') || '') + Number(val).toLocaleString(undefined, { maximumFractionDigits: 0 });
    });
    MediCare.chart('revChart', {
      type: 'bar',
      data: { labels: data.charts.revenue.labels, datasets: [{ label: 'Verified revenue ($)', data: data.charts.revenue.data, backgroundColor: 'rgba(37,99,235,.75)', borderRadius: 6 }] },
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
