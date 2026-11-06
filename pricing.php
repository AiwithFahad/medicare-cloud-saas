<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$fallbackPlans = [
    ['name' => 'Free', 'slug' => 'free', 'monthly_price' => 0, 'yearly_price' => 0, 'trial_days' => 14, 'max_doctors' => 2, 'max_staff' => 5, 'max_patients' => 200, 'max_departments' => 3, 'max_storage_mb' => 500, 'features' => '["Up to 2 doctors","Patient registration","Appointment booking","Email support"]'],
    ['name' => 'Starter', 'slug' => 'starter', 'monthly_price' => 49, 'yearly_price' => 470, 'trial_days' => 14, 'max_doctors' => 10, 'max_staff' => 25, 'max_patients' => 2000, 'max_departments' => 8, 'max_storage_mb' => 2048, 'features' => '["Everything in Free","Bed management","Laboratory module","Billing & invoices"]'],
    ['name' => 'Professional', 'slug' => 'professional', 'monthly_price' => 99, 'yearly_price' => 950, 'trial_days' => 14, 'max_doctors' => 30, 'max_staff' => 75, 'max_patients' => 10000, 'max_departments' => 15, 'max_storage_mb' => 10240, 'features' => '["Everything in Starter","Pharmacy & prescriptions","Patient portal","Reports & analytics","Priority support"]'],
    ['name' => 'Business', 'slug' => 'business', 'monthly_price' => 199, 'yearly_price' => 1910, 'trial_days' => 14, 'max_doctors' => 80, 'max_staff' => 200, 'max_patients' => 50000, 'max_departments' => 30, 'max_storage_mb' => 51200, 'features' => '["Everything in Professional","Multi-branch ready","API access","Audit logs","Phone support"]'],
    ['name' => 'Enterprise', 'slug' => 'enterprise', 'monthly_price' => 399, 'yearly_price' => 3830, 'trial_days' => 30, 'max_doctors' => -1, 'max_staff' => -1, 'max_patients' => -1, 'max_departments' => -1, 'max_storage_mb' => 512000, 'features' => '["Everything in Business","Unlimited doctors, staff & patients","Dedicated account manager","Custom integrations","99.9% uptime SLA"]'],
];
$plans = $fallbackPlans;
try {
    require_once __DIR__ . '/config/database.php';
    $rows = db()->query('SELECT name, slug, monthly_price, yearly_price, trial_days, max_doctors, max_staff, max_patients, max_departments, max_storage_mb, features FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC')->fetchAll();
    if ($rows) { $plans = $rows; }
} catch (Throwable $ignored) {
}
$limit = static function ($v): string {
    $v = (int)$v;
    return $v < 0 ? 'Unlimited' : number_format($v);
};
$storage = static function ($v): string {
    $v = (int)$v;
    return $v >= 1024 ? rtrim(rtrim(number_format($v / 1024, 1), '0'), '.') . ' GB' : $v . ' MB';
};

$pageTitle = 'Pricing';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container text-center">
    <span class="kicker">Pricing</span>
    <h1 class="display-5 fw-bold mt-2">Pricing that scales with <span class="text-primary">your hospital</span></h1>
    <p class="lead text-muted mx-auto" style="max-width:640px">Start free, upgrade when you grow. Every paid plan includes all core modules, unlimited appointments and free updates.</p>
  </div>
</section>

<section class="section" style="padding-top:36px">
  <div class="container">
    <div class="d-flex justify-content-center align-items-center gap-3 mb-5">
      <span class="fw-semibold">Monthly</span>
      <div class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="billingToggle" style="width:3rem;height:1.5rem" aria-label="Toggle yearly billing"></div>
      <span class="fw-semibold">Yearly <span class="badge text-bg-success">Save ~20%</span></span>
    </div>
    <div class="row g-4 justify-content-center">
      <?php foreach ($plans as $p):
          $feats = json_decode((string)($p['features'] ?? '[]'), true);
          if (!is_array($feats)) { $feats = array_filter(array_map('trim', explode(',', (string)($p['features'] ?? '')))); }
          $monthly = (float)($p['monthly_price'] ?? 0);
          $yearlyPerMonth = $monthly > 0 ? (float)($p['yearly_price'] ?? 0) / 12 : 0;
          $popular = ((string)($p['slug'] ?? '') === 'professional');
      ?>
      <div class="col-md-6 col-lg-4 col-xl">
        <div class="price-card<?= $popular ? ' popular' : '' ?>">
          <?php if ($popular): ?><span class="badge text-bg-primary position-absolute top-0 start-50 translate-middle">Most Popular</span><?php endif; ?>
          <h5><?= e((string)$p['name']) ?></h5>
          <div class="amount" data-monthly="<?= e(money($monthly)) ?>" data-yearly="<?= e(money($yearlyPerMonth)) ?>"><?= e(money($monthly)) ?></div>
          <p class="bill-note text-muted small mb-1">per month, billed monthly</p>
          <p class="small text-muted"><?= e((string)($p['trial_days'] ?? 14)) ?>-day free trial included</p>
          <ul class="list-unstyled tick small">
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Up to <?= e($limit($p['max_doctors'] ?? 0)) ?> doctors</li>
            <li><i class="bi bi-check-circle-fill text-success me-2"></i>Up to <?= e($limit($p['max_patients'] ?? 0)) ?> patients</li>
            <li><i class="bi bi-check-circle-fill text-success me-2"></i><?= e($storage($p['max_storage_mb'] ?? 0)) ?> storage</li>
            <?php foreach ($feats as $f): ?><li><i class="bi bi-check-circle-fill text-success me-2"></i><?= e((string)$f) ?></li><?php endforeach; ?>
          </ul>
          <a class="btn <?= $popular ? 'btn-primary' : 'btn-outline-primary' ?> w-100 mt-2" href="auth/register.php">Start Free Trial</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="text-center text-muted small mt-4">Prices in USD. Use coupon <strong>WELCOME10</strong> for 10% off your first payment. Payments are only marked verified after confirmation by our team.</p>
  </div>
</section>

<section class="section section-alt">
  <div class="container" style="max-width:980px">
    <div class="text-center mb-4"><span class="kicker">Compare</span><h2 class="mt-2">Plan comparison</h2></div>
    <div class="card overflow-hidden">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead><tr><th>Limit / Feature</th><?php foreach ($plans as $p): ?><th class="text-center"><?= e((string)$p['name']) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
            <tr><td>Monthly price</td><?php foreach ($plans as $p): ?><td class="text-center fw-semibold"><?= e(money($p['monthly_price'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <tr><td>Yearly price</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e(money($p['yearly_price'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <tr><td>Free trial</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e((string)($p['trial_days'] ?? 14)) ?> days</td><?php endforeach; ?></tr>
            <tr><td>Doctors</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e($limit($p['max_doctors'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <tr><td>Staff accounts</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e($limit($p['max_staff'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <tr><td>Patients</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e($limit($p['max_patients'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <tr><td>Departments</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e($limit($p['max_departments'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <tr><td>Storage</td><?php foreach ($plans as $p): ?><td class="text-center"><?= e($storage($p['max_storage_mb'] ?? 0)) ?></td><?php endforeach; ?></tr>
            <?php
            $rowsCmp = [
                'Patient management & appointments' => [1, 1, 1, 1, 1],
                'Bed management' => [0, 1, 1, 1, 1],
                'Laboratory module' => [0, 1, 1, 1, 1],
                'Pharmacy & prescriptions' => [0, 0, 1, 1, 1],
                'Patient portal' => [0, 0, 1, 1, 1],
                'Reports & analytics' => [0, 0, 1, 1, 1],
                'API access & audit logs' => [0, 0, 0, 1, 1],
                'Dedicated account manager & SLA' => [0, 0, 0, 0, 1],
            ];
            foreach ($rowsCmp as $label => $vals): ?>
            <tr><td><?= e($label) ?></td><?php foreach ($plans as $idx => $p): ?><td class="text-center"><?= !empty($vals[$idx]) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="container" style="max-width:860px">
    <div class="text-center mb-5"><span class="kicker">Pricing FAQ</span><h2 class="mt-2">Billing questions, answered</h2></div>
    <div class="accordion" id="priceFaq">
      <?php
      $faqs = [
          ['Can I change plans later?', 'Yes — upgrade or downgrade anytime from your subscription screen. Upgrades apply immediately; downgrades take effect at the next billing cycle, and your data is never touched.'],
          ['How does yearly billing save money?', 'Yearly prices are roughly 20% lower than paying monthly — for example Professional is $99/month monthly, or $950/year (about $79/month). The toggle above shows the per-month equivalent.'],
          ['What payment methods do you accept?', 'Card and bank transfer. For your protection, a payment is only marked "verified" after our team confirms it against the settlement record — checkout alone never marks an invoice paid.'],
          ['What happens when my trial ends?', 'You get a 7-day grace period with full access. Subscribe to continue without interruption, or export your data — we never hold patient data hostage.'],
          ['Are there discounts?', 'Yes — use WELCOME10 for 10% off your first payment. Annual-plan and launch offers (such as HOSPITAL20) appear at checkout when active.'],
      ];
      foreach ($faqs as $i => [$q, $a]): ?>
      <div class="accordion-item">
        <h3 class="accordion-header"><button class="accordion-button<?= $i === 0 ? '' : ' collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#pf<?= $i ?>"><?= e($q) ?></button></h3>
        <div id="pf<?= $i ?>" class="accordion-collapse collapse<?= $i === 0 ? ' show' : '' ?>" data-bs-parent="#priceFaq"><div class="accordion-body text-muted"><?= e($a) ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="pb-5">
  <div class="container">
    <div class="cta-band text-center">
      <h2 class="mb-2">Start free — pay only when it works for you</h2>
      <p class="text-white-50 mx-auto mb-4" style="max-width:540px">14 days, full features, demo data included. No credit card required.</p>
      <a class="btn btn-light btn-lg" href="auth/register.php">Start Free Trial</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
