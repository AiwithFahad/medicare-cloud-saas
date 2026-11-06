<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Terms of Service';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Legal</span>
    <h1 class="display-5 fw-bold mt-2">Terms of Service</h1>
    <p class="lead text-muted">Last updated: <?= e(date('d M Y')) ?> · The agreement between MediCare Cloud and the hospitals that use it.</p>
  </div>
</section>

<section class="section" style="padding-top:32px">
  <div class="container" style="max-width:860px">
    <h3>1. The service</h3>
    <p class="text-muted">MediCare Cloud provides a subscription-based, multi-tenant Hospital Management platform including patient management, doctor scheduling, appointments, wards and beds, pharmacy, laboratory, billing, a patient portal and reporting. Features vary by plan as described on the <a href="pricing.php">Pricing page</a>.</p>

    <h3 class="mt-4">2. Accounts &amp; tenants</h3>
    <p class="text-muted">Each subscription creates one tenant (hospital workspace) with its own isolated data. The hospital administrator is responsible for the accuracy of data entered, for managing staff accounts and roles, and for keeping login credentials secure. You must not share accounts between individuals or attempt to access another tenant's data.</p>

    <h3 class="mt-4">3. Trials, billing &amp; payments</h3>
    <ul class="text-muted">
      <li>New subscriptions begin with a free trial (14 days on standard plans, 30 days on Enterprise) with no payment required.</li>
      <li>Paid plans are billed monthly or yearly in advance at the prices shown at checkout. Yearly billing is offered at a discount to monthly billing.</li>
      <li>Coupons are applied at checkout and recorded against the invoice.</li>
      <li>A payment is only treated as settled once it has been <strong>verified</strong> by our team against the payment provider's record. Creating a checkout or submitting payment details alone does not mark an invoice as paid.</li>
      <li>After a missed payment, subscriptions enter a grace period (7 days) before access may be restricted. Data is never deleted during the grace period.</li>
    </ul>

    <h3 class="mt-4">4. Plan limits</h3>
    <p class="text-muted">Each plan includes limits for doctors, staff accounts, patients, departments and storage, shown on the Pricing page (Enterprise limits are unlimited). Reaching a limit prevents creating additional resources of that type but never locks or deletes existing data; upgrading restores full creation immediately.</p>

    <h3 class="mt-4">5. Acceptable use</h3>
    <p class="text-muted">You agree not to: upload unlawful content; attempt to breach tenant isolation or platform security; reverse-engineer the service except where permitted by law; use the platform to send spam; or enter patient data you are not authorised to process. We may suspend accounts that violate these terms, with notice where practicable.</p>

    <h3 class="mt-4">6. Clinical responsibility</h3>
    <p class="text-muted">MediCare Cloud is an administrative and record-keeping system. It does not provide medical advice, diagnosis or treatment, and clinical decisions remain solely the responsibility of the Customer's qualified professionals. Customers must verify critical data (allergies, dosages, results) through their normal clinical processes.</p>

    <h3 class="mt-4">7. Availability</h3>
    <p class="text-muted">We target 99.9% monthly availability for paid plans (Enterprise includes an uptime SLA). Scheduled maintenance is announced in advance through in-app announcements. We are not liable for interruptions caused by factors outside our reasonable control.</p>

    <h3 class="mt-4">8. Data ownership &amp; export</h3>
    <p class="text-muted">Customers own their data. On cancellation, Customers may export their data during the grace period, after which it is handled as described in the <a href="privacy.php">Privacy Policy</a>.</p>

    <h3 class="mt-4">9. Liability</h3>
    <p class="text-muted">To the maximum extent permitted by law, our aggregate liability under these terms is limited to the fees paid by the Customer in the twelve months preceding the claim. Nothing in these terms limits liability that cannot lawfully be limited.</p>

    <h3 class="mt-4">10. Changes &amp; contact</h3>
    <p class="text-muted">We may update these terms; material changes will be announced in-app at least 14 days before they take effect. Continued use after that date constitutes acceptance. Questions: <a href="contact.php">contact our team</a>.</p>

    <div class="cta-band mt-5 text-center">
      <h4 class="mb-2">Ready to get started?</h4>
      <p class="text-white-50 mb-3">14-day free trial · No credit card required</p>
      <a class="btn btn-light" href="auth/register.php">Start Free Trial</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
