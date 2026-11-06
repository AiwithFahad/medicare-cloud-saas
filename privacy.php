<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Privacy Policy';
$base = '';
include __DIR__ . '/includes/site-header.php';
?>
<section class="page-hero">
  <div class="container">
    <span class="kicker">Legal</span>
    <h1 class="display-5 fw-bold mt-2">Privacy Policy</h1>
    <p class="lead text-muted">Last updated: <?= e(date('d M Y')) ?> · How MediCare Cloud collects, uses and protects data.</p>
  </div>
</section>

<section class="section" style="padding-top:32px">
  <div class="container" style="max-width:860px">
    <h3>1. Who we are</h3>
    <p class="text-muted">MediCare Cloud (“we”, “us”) provides a multi-tenant Hospital Management SaaS platform to hospitals and clinics (“Customers”). When a Customer uses the platform, we process their operational and patient data strictly on their behalf and under their instructions.</p>

    <h3 class="mt-4">2. Data we process</h3>
    <ul class="text-muted">
      <li><strong>Account data:</strong> names, emails, phone numbers and roles of Customer staff and platform administrators.</li>
      <li><strong>Patient data (Customer data):</strong> patient demographics, appointments, medical records, prescriptions, laboratory results, admissions and billing records entered by the Customer or booked by patients through the public booking page.</li>
      <li><strong>Subscription data:</strong> plan selections, invoices, coupon redemptions and payment confirmations.</li>
      <li><strong>Technical data:</strong> login attempts, IP addresses, activity and audit logs used for security and troubleshooting.</li>
    </ul>

    <h3 class="mt-4">3. Tenant isolation</h3>
    <p class="text-muted">Every business record in MediCare Cloud carries a tenant (hospital) identifier, and the application enforces that identifier on every query. One hospital cannot access another hospital's patients, doctors, departments, bills or any other data. Platform administrators access tenant data only for support, billing verification and security purposes, and such access is logged.</p>

    <h3 class="mt-4">4. Security safeguards</h3>
    <p class="text-muted">We apply security safeguards designed for healthcare data, including: encrypted connections (HTTPS/TLS), bcrypt-hashed passwords, prepared-statement database access, CSRF protection, login rate-limiting and lockouts, role-based permissions across nine built-in roles, comprehensive audit logs, and regular backups. Patients using the portal can only ever see records explicitly marked for patient visibility and belonging to their own account.</p>

    <h3 class="mt-4">5. How we use data</h3>
    <p class="text-muted">We use data only to operate, secure and improve the platform: providing the modules Customers subscribe to, processing subscriptions and payments, sending service notifications, preventing fraud and abuse, and producing aggregated, anonymised statistics that never identify a patient or a hospital.</p>

    <h3 class="mt-4">6. Sharing</h3>
    <p class="text-muted">We do not sell personal or patient data. Data is shared only with subprocessors needed to run the service (such as hosting providers) under contract, or when required by law. Public booking submissions are delivered only to the hospital the patient selected.</p>

    <h3 class="mt-4">7. Retention &amp; your rights</h3>
    <p class="text-muted">Customer data is retained for the life of the subscription and through the post-cancellation grace period, after which it is deleted or anonymised. Patients and staff may ask their hospital (the data controller) to access, correct or delete their records; we support Customers in fulfilling such requests promptly.</p>

    <h3 class="mt-4">8. Cookies</h3>
    <p class="text-muted">The platform uses only essential cookies — a session cookie for sign-in and CSRF protection, plus local preferences such as theme and sidebar state. We do not use advertising or cross-site tracking cookies on the application.</p>

    <h3 class="mt-4">9. Contact</h3>
    <p class="text-muted">Privacy questions: <a href="contact.php">contact our team</a> or email support@medicare.test. We respond to privacy requests within one business day where possible.</p>

    <div class="cta-band mt-5 text-center">
      <h4 class="mb-2">Questions about how your hospital's data is handled?</h4>
      <a class="btn btn-light" href="contact.php">Contact Us</a>
    </div>
  </div>
</section>
<?php include __DIR__ . '/includes/site-footer.php'; ?>
