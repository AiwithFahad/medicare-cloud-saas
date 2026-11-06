/* MediCare Cloud — app behaviour: sidebar, theme, menu search, pricing toggle. */
(function () {
  var root = document.documentElement;

  /* --- Sidebar: collapse on desktop, slide-in on mobile --- */
  var toggle = document.getElementById('sidebarToggle');
  var backdrop = document.getElementById('sidebarBackdrop');
  function isMobile() { return window.innerWidth < 992; }
  if (localStorage.getItem('medicare-sidebar') === 'collapsed' && !isMobile()) {
    document.body.classList.add('sidebar-collapsed');
  }
  if (toggle) {
    toggle.addEventListener('click', function () {
      if (isMobile()) {
        document.body.classList.toggle('sidebar-open');
      } else {
        document.body.classList.toggle('sidebar-collapsed');
        try { localStorage.setItem('medicare-sidebar', document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'); } catch (e) {}
      }
    });
  }
  if (backdrop) backdrop.addEventListener('click', function () { document.body.classList.remove('sidebar-open'); });

  /* --- Light / dark theme --- */
  var themeBtn = document.getElementById('themeToggle');
  function themeIcon() {
    if (themeBtn) themeBtn.innerHTML = root.getAttribute('data-theme') === 'dark' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>';
  }
  themeIcon();
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var dark = root.getAttribute('data-theme') === 'dark';
      if (dark) { root.removeAttribute('data-theme'); } else { root.setAttribute('data-theme', 'dark'); }
      try { localStorage.setItem('medicare-theme', dark ? 'light' : 'dark'); } catch (e) {}
      themeIcon();
    });
  }

  /* --- Topbar menu search: filters sidebar links --- */
  var search = document.getElementById('globalSearch');
  if (search) {
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      document.querySelectorAll('#appSidebar .side-link').forEach(function (a) {
        a.style.display = (!q || a.textContent.toLowerCase().indexOf(q) > -1) ? '' : 'none';
      });
    });
  }

  /* --- Pricing monthly / yearly toggle (public pages) --- */
  var billToggle = document.getElementById('billingToggle');
  if (billToggle) {
    var apply = function () {
      var yearly = billToggle.checked;
      document.querySelectorAll('[data-monthly]').forEach(function (el) {
        el.textContent = yearly ? el.getAttribute('data-yearly') : el.getAttribute('data-monthly');
      });
      document.querySelectorAll('.bill-note').forEach(function (el) {
        el.textContent = yearly ? 'per month, billed yearly' : 'per month, billed monthly';
      });
    };
    billToggle.addEventListener('change', apply);
    apply();
  }

  /* --- Destructive-action confirmations --- */
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  /* --- Auto-dismiss alerts --- */
  setTimeout(function () {
    document.querySelectorAll('.alert-dismissible').forEach(function (a) {
      try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) {}
    });
  }, 6000);
})();
