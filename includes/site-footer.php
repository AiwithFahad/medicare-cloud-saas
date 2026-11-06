<?php $b = $base ?? ''; ?>
<footer class="site-footer">
  <div class="container">
    <div class="row g-4">
      <div class="col-lg-4">
        <a class="brand text-white" href="<?= $b ?>index.php"><span class="brand-mark"><i class="bi bi-heart-pulse-fill"></i></span><span class="brand-text">MediCare <em>Cloud</em></span></a>
        <p class="mt-3 text-white-50">The complete multi-tenant Hospital Management SaaS — patients, doctors, appointments, beds, pharmacy, laboratory, billing and subscriptions, isolated per hospital and secured by role.</p>
        <div class="d-flex gap-2">
          <a class="social-btn" href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
          <a class="social-btn" href="#" aria-label="Twitter / X"><i class="bi bi-twitter-x"></i></a>
          <a class="social-btn" href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
          <a class="social-btn" href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
        </div>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Product</h6>
        <a href="<?= $b ?>features.php">Features</a>
        <a href="<?= $b ?>pricing.php">Pricing</a>
        <a href="<?= $b ?>book-appointment.php">Book Appointment</a>
        <a href="<?= $b ?>auth/register.php">Start Free Trial</a>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Platform</h6>
        <a href="<?= $b ?>departments.php">Departments</a>
        <a href="<?= $b ?>doctors.php">Doctors</a>
        <a href="<?= $b ?>about.php">About Us</a>
        <a href="<?= $b ?>blog.php">Blog</a>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Support</h6>
        <a href="<?= $b ?>contact.php">Contact</a>
        <a href="<?= $b ?>auth/login.php">Login</a>
        <a href="<?= $b ?>auth/forgot-password.php">Forgot Password</a>
      </div>
      <div class="col-6 col-lg-2">
        <h6>Legal</h6>
        <a href="<?= $b ?>privacy.php">Privacy Policy</a>
        <a href="<?= $b ?>terms.php">Terms of Service</a>
      </div>
    </div>
    <hr class="border-secondary">
    <p class="mb-0 text-white-50 small">© <?= date('Y') ?> MediCare Cloud. All rights reserved. Demo build — the PHP application requires PHP 8 + MySQL hosting; see README for deployment notes.</p>
  </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="<?= $b ?>assets/js/app.js"></script>
</body>
</html>
