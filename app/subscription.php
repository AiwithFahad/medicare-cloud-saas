<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$pdo = db();
$user = require_role(['hospital_admin', 'accountant']);
$tid = require_tenant();
check_tenant_active($pdo);
$canManage = $user['role'] === 'hospital_admin' || has_permission('subscription.manage');
$sub = active_subscription($pdo, $tid);

if (is_post()) {
    verify_csrf();
    if (!$canManage) { flash('error', 'Only the hospital admin can manage the subscription.'); redirect('subscription.php'); }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'change_plan') {
        $planId = (int)($_POST['plan_id'] ?? 0);
        $cycle = (string)($_POST['billing_cycle'] ?? 'monthly');
        if (!in_array($cycle, ['monthly', 'yearly'], true)) $cycle = 'monthly';
        $couponCode = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $ps = $pdo->prepare('SELECT * FROM subscription_plans WHERE id=? AND is_active=1');
        $ps->execute([$planId]);
        $plan = $ps->fetch();
        if (!$plan) { flash('error', 'Please choose a valid plan.'); redirect('subscription.php'); }
        $price = (float)($cycle === 'yearly' ? $plan['yearly_price'] : $plan['monthly_price']);

        if ($price <= 0) {
            // Free plan: switch immediately, nothing to pay.
            $ends = date('Y-m-d', strtotime($cycle === 'yearly' ? '+1 year' : '+1 month'));
            if ($sub) {
                $u = $pdo->prepare('UPDATE subscriptions SET plan_id=?, billing_cycle=?, status="active", starts_at=CURDATE(), ends_at=?, cancelled_at=NULL WHERE id=? AND tenant_id=?');
                $u->execute([$planId, $cycle, $ends, (int)$sub['id'], $tid]);
                $subId = (int)$sub['id'];
            } else {
                $i = $pdo->prepare('INSERT INTO subscriptions(tenant_id,plan_id,billing_cycle,status,starts_at,ends_at,created_at) VALUES(?,?,?,"active",CURDATE(),?,NOW())');
                $i->execute([$tid, $planId, $cycle, $ends]);
                $subId = (int)$pdo->lastInsertId();
            }
            audit_log($pdo, 'subscription.change_plan', 'subscription', $subId, 'Switched to ' . $plan['name'] . ' (' . $cycle . ', free)');
            flash('success', 'You are now on the ' . $plan['name'] . ' plan (' . $cycle . '). The switch was free and is active immediately.');
            redirect('subscription.php');
        }

        // Paid plan: validate coupon, then create a PENDING platform invoice + payment.
        $discount = 0.0; $coupon = null;
        if ($couponCode !== '') {
            $cs2 = $pdo->prepare('SELECT * FROM coupons WHERE code=? AND is_active=1 AND (valid_from IS NULL OR valid_from<=CURDATE()) AND (valid_until IS NULL OR valid_until>=CURDATE()) AND (max_uses IS NULL OR used_count<max_uses)');
            $cs2->execute([$couponCode]);
            $coupon = $cs2->fetch() ?: null;
            if ($coupon) {
                $discount = $coupon['discount_type'] === 'percent' ? round($price * (float)$coupon['discount_value'] / 100, 2) : min((float)$coupon['discount_value'], $price);
            } else {
                flash('error', 'Coupon code "' . $couponCode . '" is not valid or has expired. No charge was created.');
                redirect('subscription.php');
            }
        }
        $total = round($price - $discount, 2);
        $ns = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM invoices');
        $ns->execute();
        $invoiceNo = 'INV-' . date('Y') . '-' . str_pad((string)((int)$ns->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
        $subId = $sub ? (int)$sub['id'] : null;
        $pdo->beginTransaction();
        try {
            $ii = $pdo->prepare('INSERT INTO invoices(tenant_id,subscription_id,invoice_no,amount,discount,tax,total,status,due_date,created_at) VALUES(?,?,?,?,?,0,?,"pending",DATE_ADD(CURDATE(), INTERVAL 7 DAY),NOW())');
            $ii->execute([$tid, $subId, $invoiceNo, $price, $discount, $total]);
            $invId = (int)$pdo->lastInsertId();
            $pp = $pdo->prepare('INSERT INTO payments(tenant_id,subscription_id,invoice_id,amount,currency,method,status,notes,created_at) VALUES(?,?,?,?,?,"manual","pending",?,NOW())');
            $pp->execute([$tid, $subId, $invId, $total, 'USD', 'Plan change to ' . $plan['name'] . ' (' . $cycle . ') — pending verification by the platform team. Your upgrade activates once payment is verified; nothing has been charged or marked paid yet.']);
            if ($coupon) {
                $cr = $pdo->prepare('INSERT INTO coupon_redemptions(coupon_id,tenant_id,invoice_id,discount_amount,created_at) VALUES(?,?,?,?,NOW())');
                $cr->execute([(int)$coupon['id'], $tid, $invId, $discount]);
                $cu = $pdo->prepare('UPDATE coupons SET used_count=used_count+1 WHERE id=?');
                $cu->execute([(int)$coupon['id']]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            flash('error', 'Could not create the plan-change invoice. Please try again.');
            redirect('subscription.php');
        }
        audit_log($pdo, 'subscription.change_requested', 'invoice', $invId, $invoiceNo . ' for ' . $plan['name'] . ' total ' . $total);
        flash('success', 'Invoice ' . $invoiceNo . ' (' . money($total) . ') was created with status PENDING. Please pay it using the instructions from the platform team — your plan upgrade activates once they verify the payment. It has NOT been marked paid.');
        redirect('subscription.php');
    }

    if ($action === 'cancel' && $sub) {
        $u = $pdo->prepare('UPDATE subscriptions SET status="cancelled", cancelled_at=NOW() WHERE id=? AND tenant_id=?');
        $u->execute([(int)$sub['id'], $tid]);
        audit_log($pdo, 'subscription.cancel', 'subscription', (int)$sub['id'], '');
        flash('success', 'Subscription cancelled. Access continues until the end of the current period' . ($sub['ends_at'] ? ' (' . fmt_date($sub['ends_at']) . ')' : '') . '.');
        redirect('subscription.php');
    }

    if ($action === 'renew' && $sub) {
        $baseDate = $sub['ends_at'] ?: ($sub['trial_ends_at'] ?: date('Y-m-d'));
        if ($baseDate < date('Y-m-d')) $baseDate = date('Y-m-d');
        $newEnd = date('Y-m-d', strtotime($baseDate . ($sub['billing_cycle'] === 'yearly' ? ' +1 year' : ' +1 month')));
        $u = $pdo->prepare('UPDATE subscriptions SET ends_at=?, status="active", cancelled_at=NULL WHERE id=? AND tenant_id=?');
        $u->execute([$newEnd, (int)$sub['id'], $tid]);
        audit_log($pdo, 'subscription.renew', 'subscription', (int)$sub['id'], 'New end ' . $newEnd);
        flash('success', 'Subscription renewed — new period ends ' . fmt_date($newEnd) . '.');
        redirect('subscription.php');
    }

    flash('error', 'That action could not be completed.');
    redirect('subscription.php');
}

$sub = active_subscription($pdo, $tid);
$usage = usage_counts($pdo, $tid);
$plansS = $pdo->prepare('SELECT * FROM subscription_plans WHERE is_active=1 ORDER BY sort_order, monthly_price');
$plansS->execute();
$plans = $plansS->fetchAll();

$hs = $pdo->prepare('SELECT pay.*, inv.invoice_no FROM payments pay LEFT JOIN invoices inv ON inv.id=pay.invoice_id WHERE pay.tenant_id=? ORDER BY pay.id DESC LIMIT 30');
$hs->execute([$tid]);
$payHistory = $hs->fetchAll();
$invS = $pdo->prepare('SELECT * FROM invoices WHERE tenant_id=? ORDER BY id DESC LIMIT 30');
$invS->execute([$tid]);
$invHistory = $invS->fetchAll();

$limits = [
    'doctors' => ['label' => 'Doctors', 'max' => $sub['max_doctors'] ?? null],
    'staff' => ['label' => 'Staff accounts', 'max' => $sub['max_staff'] ?? null],
    'patients' => ['label' => 'Patients', 'max' => $sub['max_patients'] ?? null],
    'departments' => ['label' => 'Departments', 'max' => $sub['max_departments'] ?? null],
];

$pageTitle = 'Subscription';
$base = '../';
include __DIR__ . '/../includes/header.php';
?>
<?php if (!$sub): ?>
<div class="alert alert-warning">No active subscription was found for your hospital. Choose a plan below to get started.</div>
<?php else: ?>
<div class="card mb-4"><div class="card-body">
  <div class="row align-items-center">
    <div class="col-md-7">
      <span class="chip mb-2">Current plan</span>
      <h3 class="mb-1"><?= e($sub['plan_name']) ?> <small class="text-muted fs-6 text-capitalize">· billed <?= e($sub['billing_cycle']) ?></small></h3>
      <div class="mb-2"><?= status_badge((string)$sub['status']) ?> <span class="text-muted small ms-2"><?= money($sub['billing_cycle'] === 'yearly' ? $sub['yearly_price'] : $sub['monthly_price']) ?> / <?= $sub['billing_cycle'] === 'yearly' ? 'year' : 'month' ?></span></div>
      <div class="small text-muted">
        <?php if ($sub['starts_at']): ?>Period starts <?= fmt_date($sub['starts_at']) ?>. <?php endif; ?>
        <?php if ($sub['status'] === 'trial' && $sub['trial_ends_at']): ?>Trial ends <strong><?= fmt_date($sub['trial_ends_at']) ?></strong>. <?php endif; ?>
        <?php if ($sub['ends_at']): ?>Current period ends <strong><?= fmt_date($sub['ends_at']) ?></strong>. <?php endif; ?>
        <?php if ($sub['ends_at']): ?>If a payment is missed, you keep access for a <?= GRACE_DAYS ?>-day grace period<?= $sub['grace_ends_at'] ? ' (grace until ' . fmt_date($sub['grace_ends_at']) . ')' : '' ?>.<?php endif; ?>
        <?php if ($sub['cancelled_at']): ?><br>Cancelled on <?= fmt_datetime($sub['cancelled_at']) ?>.<?php endif; ?>
      </div>
    </div>
    <?php if ($canManage): ?>
    <div class="col-md-5 text-md-end mt-3 mt-md-0">
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="renew"><button class="btn btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Renew</button></form>
      <?php if ($sub['status'] !== 'cancelled'): ?>
      <form method="post" class="d-inline" data-confirm="Cancel this subscription at the end of the current period?"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-outline-danger">Cancel subscription</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <hr>
  <h6>Usage vs plan limits</h6>
  <div class="row g-3">
  <?php foreach ($limits as $key => $lim): $used = (int)($usage[$key] ?? 0); $max = $lim['max'] === null ? -1 : (int)$lim['max']; $pct = $max > 0 ? min(100, round($used * 100 / $max)) : 0; ?>
    <div class="col-md-6">
      <div class="d-flex justify-content-between small mb-1"><span><?= e($lim['label']) ?></span><strong><?= $used ?> / <?= $max < 0 ? 'Unlimited' : $max ?></strong></div>
      <div class="progress"><div class="progress-bar <?= $max > 0 && $pct >= 90 ? 'bg-danger' : 'bg-success' ?>" style="width:<?= $max < 0 ? 8 : $pct ?>%"></div></div>
    </div>
  <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<h5 class="mb-3">Plans</h5>
<div class="row g-4 mb-4">
<?php foreach ($plans as $plan): $isCurrent = $sub && (int)$sub['plan_id'] === (int)$plan['id']; $features = json_decode((string)($plan['features'] ?? '[]'), true) ?: []; ?>
  <div class="col-md-6 col-xl-4">
    <div class="price-card <?= $isCurrent ? 'popular' : '' ?> h-100 d-flex flex-column">
      <?php if ($isCurrent): ?><span class="badge text-bg-primary align-self-start mb-2">Current plan</span><?php endif; ?>
      <h5><?= e($plan['name']) ?></h5>
      <div class="amount"><?= money($plan['monthly_price']) ?><small class="fs-6 text-muted">/mo</small></div>
      <div class="text-muted small mb-3">or <?= money($plan['yearly_price']) ?> billed yearly</div>
      <ul class="list-unstyled tick small flex-grow-1">
        <li>👩‍⚕️ <?= (int)$plan['max_doctors'] < 0 ? 'Unlimited' : (int)$plan['max_doctors'] ?> doctors · <?= (int)$plan['max_staff'] < 0 ? 'Unlimited' : (int)$plan['max_staff'] ?> staff</li>
        <li>🧑‍🤝‍🧑 <?= (int)$plan['max_patients'] < 0 ? 'Unlimited' : number_format((int)$plan['max_patients']) ?> patients · <?= (int)$plan['max_departments'] < 0 ? 'Unlimited' : (int)$plan['max_departments'] ?> departments</li>
        <?php foreach ($features as $f): ?><li>✓ <?= e((string)$f) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($canManage && !$isCurrent): ?>
      <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="change_plan"><input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
        <div class="row g-2 mb-2">
          <div class="col-6"><select name="billing_cycle" class="form-select form-select-sm"><option value="monthly">Monthly</option><option value="yearly">Yearly</option></select></div>
          <div class="col-6"><input name="coupon_code" class="form-control form-control-sm" placeholder="Coupon (optional)"></div>
        </div>
        <button class="btn <?= (float)$plan['monthly_price'] <= 0 ? 'btn-success' : 'btn-primary' ?> w-100"><?= (float)$plan['monthly_price'] <= 0 ? 'Switch free — immediate' : 'Change plan' ?></button>
        <?php if ((float)$plan['monthly_price'] > 0): ?><small class="text-muted d-block mt-1">Creates a pending invoice — the upgrade activates only after the platform team verifies your payment.</small><?php endif; ?>
      </form>
      <?php elseif ($isCurrent): ?><button class="btn btn-outline-secondary w-100" disabled>Your current plan</button>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<div class="row g-4">
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Platform invoices</div>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Invoice</th><th>Date</th><th class="text-end">Total</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$invHistory): ?><tr><td colspan="4" class="text-muted text-center py-3">No platform invoices yet.</td></tr><?php endif; ?>
      <?php foreach ($invHistory as $inv): ?><tr><td><?= e($inv['invoice_no']) ?></td><td><?= fmt_date($inv['created_at']) ?></td><td class="text-end"><?= money($inv['total']) ?><?php if ((float)$inv['discount'] > 0): ?><br><small class="text-muted">incl. −<?= money($inv['discount']) ?> discount</small><?php endif; ?></td><td><?= status_badge((string)$inv['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div></div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100"><div class="card-header">Payment history</div>
      <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Date</th><th>Invoice</th><th>Method</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$payHistory): ?><tr><td colspan="5" class="text-muted text-center py-3">No payments yet.</td></tr><?php endif; ?>
      <?php foreach ($payHistory as $pay): ?><tr><td><?= fmt_datetime($pay['created_at']) ?></td><td><?= e((string)($pay['invoice_no'] ?? '—')) ?></td><td class="text-capitalize"><?= e((string)$pay['method']) ?></td><td class="text-end"><?= money($pay['amount']) ?></td><td><?= status_badge((string)$pay['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="card-body small text-muted">Payments stay <strong>pending</strong> until the platform team verifies them — a pending payment never grants the upgrade by itself.</div></div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
