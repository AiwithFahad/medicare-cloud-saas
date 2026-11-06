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

  /* --- Destructive-action confirmations (Bootstrap modal via MediCare layer,
   *     window.confirm fallback). A form passes through once after the user
   *     confirms, flagged by data-mc-confirmed. --- */
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.getAttribute('data-mc-confirmed') === '1') {
        f.removeAttribute('data-mc-confirmed');
        return;
      }
      ev.preventDefault();
      var msg = f.getAttribute('data-confirm');
      var proceed = function () {
        f.setAttribute('data-mc-confirmed', '1');
        if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
      };
      if (window.MediCare && MediCare.confirm) {
        MediCare.confirm('Please confirm', msg).then(function (ok) { if (ok) proceed(); });
      } else if (window.confirm(msg)) {
        proceed();
      }
    });
  });

  /* --- Auto-dismiss alerts --- */
  setTimeout(function () {
    document.querySelectorAll('.alert-dismissible').forEach(function (a) {
      try { bootstrap.Alert.getOrCreateInstance(a).close(); } catch (e) {}
    });
  }, 6000);
})();

/* ==========================================================================
 * Upgrade v2 — MediCare AJAX layer (shared by admin / doctor / receptionist /
 * patient panels). Fetch CRUD with CSRF, toasts, Bootstrap confirm modal,
 * live tables (search + filters + pagination), AJAX modal forms, loading
 * states, Chart.js helpers and the appointment slot picker.
 * API base + CSRF token come from the <meta> tags in includes/header.php.
 * ========================================================================== */
window.MediCare = (function () {
  var meta = function (name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? el.getAttribute('content') : '';
  };
  var csrf = meta('csrf-token');
  var apiBase = meta('api-base') || '../api/';
  var charts = {};

  function api(path, options) {
    options = options || {};
    var opts = {
      method: options.method || 'GET',
      headers: { 'Accept': 'application/json', 'X-CSRF': csrf },
      credentials: 'same-origin'
    };
    if (options.body !== undefined) {
      if (options.body instanceof FormData) {
        opts.body = options.body;
      } else {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(options.body);
      }
    }
    var url = path.indexOf('http') === 0 || path.charAt(0) === '/' ? path : apiBase + path;
    return fetch(url, opts).then(function (res) {
      return res.text().then(function (text) {
        var data = {};
        try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { ok: false, error: 'Invalid server response.' }; }
        if (!res.ok || data.ok === false) {
          var err = new Error(data.error || ('Request failed (' + res.status + ')'));
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  /* --- Toasts --- */
  function toastWrap() {
    var wrap = document.getElementById('mcToastWrap');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'mcToastWrap';
      wrap.className = 'mc-toast-wrap';
      document.body.appendChild(wrap);
    }
    return wrap;
  }
  function toast(message, type) {
    type = type || 'success';
    var icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill', warning: 'bi-exclamation-circle-fill' };
    var el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type === 'error' ? 'danger' : type) + ' border-0';
    el.setAttribute('role', 'alert');
    el.innerHTML = '<div class="d-flex"><div class="toast-body"><i class="bi ' + (icons[type] || icons.info) + ' me-2"></i>' +
      String(message).replace(/</g, '&lt;') + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    toastWrap().appendChild(el);
    var t = bootstrap.Toast.getOrCreateInstance(el, { delay: 4200 });
    el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    t.show();
  }

  /* --- Confirm modal (Promise<boolean>) --- */
  var confirmResolve = null;
  function confirmModal() {
    var m = document.getElementById('mcConfirmModal');
    if (m) return m;
    var div = document.createElement('div');
    div.innerHTML =
      '<div class="modal fade" id="mcConfirmModal" tabindex="-1" aria-hidden="true">' +
      '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title" id="mcConfirmTitle">Please confirm</h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>' +
      '<div class="modal-body" id="mcConfirmBody"></div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>' +
      '<button type="button" class="btn btn-danger" id="mcConfirmYes"><i class="bi bi-check-lg"></i> Yes, continue</button></div>' +
      '</div></div></div>';
    document.body.appendChild(div.firstElementChild);
    m = document.getElementById('mcConfirmModal');
    document.getElementById('mcConfirmYes').addEventListener('click', function () {
      bootstrap.Modal.getOrCreateInstance(m).hide();
      if (confirmResolve) { confirmResolve(true); confirmResolve = null; }
    });
    m.addEventListener('hidden.bs.modal', function () {
      if (confirmResolve) { confirmResolve(false); confirmResolve = null; }
    });
    return m;
  }
  function confirm(title, body) {
    return new Promise(function (resolve) {
      confirmResolve = resolve;
      var m = confirmModal();
      document.getElementById('mcConfirmTitle').textContent = title || 'Please confirm';
      document.getElementById('mcConfirmBody').textContent = body || '';
      bootstrap.Modal.getOrCreateInstance(m).show();
    });
  }

  /* --- Button loading state --- */
  function setLoading(btn, on) {
    if (!btn) return;
    if (on) {
      btn.dataset.mcHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Working…';
    } else {
      btn.disabled = false;
      if (btn.dataset.mcHtml) { btn.innerHTML = btn.dataset.mcHtml; delete btn.dataset.mcHtml; }
    }
  }

  /* --- AJAX form: validates, posts FormData, toasts, optional callback --- */
  function ajaxForm(form, opts) {
    opts = opts || {};
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      var btn = form.querySelector('[type="submit"]');
      setLoading(btn, true);
      api(form.getAttribute('action') || opts.url, { method: 'POST', body: new FormData(form) })
        .then(function (data) {
          toast(data.message || opts.successMessage || 'Saved successfully.');
          var modalEl = form.closest('.modal');
          if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
          if (opts.onSuccess) { opts.onSuccess(data); }
          else if (opts.reload !== false) { setTimeout(function () { window.location.reload(); }, 500); }
        })
        .catch(function (err) { toast(err.message || 'Something went wrong.', 'error'); })
        .finally(function () { setLoading(btn, false); });
    });
  }

  /* --- Live table: debounced search, select filters, pagination --- */
  function liveTable(cfg) {
    var state = { q: '', page: 1, filters: {} };
    var tbody = typeof cfg.tbody === 'string' ? document.querySelector(cfg.tbody) : cfg.tbody;
    var pager = cfg.pager ? (typeof cfg.pager === 'string' ? document.querySelector(cfg.pager) : cfg.pager) : null;
    var countEl = cfg.count ? (typeof cfg.count === 'string' ? document.querySelector(cfg.count) : cfg.count) : null;
    var wrap = cfg.wrap ? (typeof cfg.wrap === 'string' ? document.querySelector(cfg.wrap) : cfg.wrap) : (tbody ? tbody.closest('.card, .table-responsive') : null);
    var cols = cfg.columns || 1;
    var debounce = null;

    function params() {
      var p = new URLSearchParams();
      p.set('page', state.page);
      if (state.q) p.set('q', state.q);
      Object.keys(state.filters).forEach(function (k) { if (state.filters[k]) p.set(k, state.filters[k]); });
      if (cfg.extra) Object.keys(cfg.extra).forEach(function (k) { p.set(k, cfg.extra[k]); });
      return p.toString();
    }
    function load() {
      if (wrap) wrap.classList.add('mc-loading');
      api(cfg.url + (cfg.url.indexOf('?') > -1 ? '&' : '?') + params())
        .then(function (data) {
          var rows = data.rows || [];
          tbody.innerHTML = rows.length ? rows.map(cfg.renderRow).join('') :
            '<tr><td colspan="' + cols + '"><div class="empty-state py-4"><i class="bi bi-inbox"></i><p class="mb-0">' + (cfg.emptyText || 'No records found.') + '</p></div></td></tr>';
          if (countEl) countEl.textContent = (data.total || 0) + ' record' + ((data.total || 0) === 1 ? '' : 's');
          renderPager(data);
          if (cfg.onLoad) cfg.onLoad(data);
        })
        .catch(function (err) { toast(err.message || 'Could not load data.', 'error'); })
        .finally(function () { if (wrap) wrap.classList.remove('mc-loading'); });
    }
    function renderPager(data) {
      if (!pager) return;
      var pages = data.pages || 1;
      if (pages <= 1) { pager.innerHTML = ''; return; }
      var html = '<nav><ul class="pagination pagination-sm mb-0">';
      html += '<li class="page-item' + (state.page <= 1 ? ' disabled' : '') + '"><button type="button" class="page-link" data-mc-page="' + (state.page - 1) + '">‹ Prev</button></li>';
      for (var i = 1; i <= pages; i++) {
        if (pages > 9 && i > 2 && i < pages - 1 && Math.abs(i - state.page) > 1) {
          if (!html.endsWith('…</span></li>')) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
          continue;
        }
        html += '<li class="page-item' + (i === state.page ? ' active' : '') + '"><button type="button" class="page-link" data-mc-page="' + i + '">' + i + '</button></li>';
      }
      html += '<li class="page-item' + (state.page >= pages ? ' disabled' : '') + '"><button type="button" class="page-link" data-mc-page="' + (state.page + 1) + '">Next ›</button></li></ul></nav>';
      pager.innerHTML = html;
      pager.querySelectorAll('[data-mc-page]').forEach(function (b) {
        b.addEventListener('click', function () {
          var p = parseInt(b.getAttribute('data-mc-page'), 10);
          if (p >= 1 && p <= pages) { state.page = p; load(); }
        });
      });
    }
    if (cfg.search) {
      var searchEl = typeof cfg.search === 'string' ? document.querySelector(cfg.search) : cfg.search;
      if (searchEl) searchEl.addEventListener('input', function () {
        clearTimeout(debounce);
        debounce = setTimeout(function () { state.q = searchEl.value.trim(); state.page = 1; load(); }, 300);
      });
    }
    if (cfg.filters) {
      Object.keys(cfg.filters).forEach(function (name) {
        var el = typeof cfg.filters[name] === 'string' ? document.querySelector(cfg.filters[name]) : cfg.filters[name];
        if (el) el.addEventListener('change', function () { state.filters[name] = el.value; state.page = 1; load(); });
      });
    }
    load();
    return { reload: load, state: state };
  }

  /* --- Chart.js helper (keeps one instance per canvas) --- */
  function chart(id, config) {
    var el = document.getElementById(id);
    if (!el || typeof Chart === 'undefined') return null;
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(el, config);
    return charts[id];
  }

  /* --- Appointment slot picker: doctor + date -> free slots as chips --- */
  function bindSlots(cfg) {
    var doctorEl = document.querySelector(cfg.doctor);
    var dateEl = document.querySelector(cfg.date);
    var wrapEl = document.querySelector(cfg.wrap);
    var feeEl = cfg.fee ? document.querySelector(cfg.fee) : null;
    if (!doctorEl || !dateEl || !wrapEl) return;
    function refresh() {
      var doctorId = doctorEl.value;
      var date = dateEl.value;
      wrapEl.innerHTML = '<span class="text-muted small">Select a doctor and date to see free slots.</span>';
      if (feeEl) {
        var opt = doctorEl.options[doctorEl.selectedIndex];
        feeEl.value = opt && opt.getAttribute('data-fee') ? opt.getAttribute('data-fee') : '';
      }
      if (!doctorId || !date) return;
      wrapEl.innerHTML = '<span class="text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Loading free slots…</span>';
      var q = 'doctor-availability.php?doctor_id=' + encodeURIComponent(doctorId) + '&date=' + encodeURIComponent(date) +
        (cfg.ignoreId ? '&ignore=' + encodeURIComponent(cfg.ignoreId) : '');
      api(q).then(function (data) {
        if (!data.available) {
          wrapEl.innerHTML = '<span class="text-danger small"><i class="bi bi-calendar-x me-1"></i>' + (data.reason || 'Not available.') + '</span>';
          return;
        }
        if (!data.slots.length) {
          wrapEl.innerHTML = '<span class="text-warning small"><i class="bi bi-calendar-x me-1"></i>All slots are booked for this day.</span>';
          return;
        }
        wrapEl.innerHTML = data.slots.map(function (s, i) {
          var id = 'slot-' + cfg.name + '-' + i;
          return '<span class="slot-chip"><input type="radio" class="btn-check" name="' + cfg.name + '" id="' + id + '" value="' + s + '"' + (cfg.selected === s ? ' checked' : '') + ' required>' +
            '<label class="btn btn-outline-primary btn-sm" for="' + id + '">' + s + '</label></span>';
        }).join('');
      }).catch(function (err) {
        wrapEl.innerHTML = '<span class="text-danger small">' + err.message + '</span>';
      });
    }
    doctorEl.addEventListener('change', refresh);
    dateEl.addEventListener('change', refresh);
    refresh();
  }

  return {
    api: api, toast: toast, confirm: confirm, setLoading: setLoading,
    ajaxForm: ajaxForm, liveTable: liveTable, chart: chart, bindSlots: bindSlots,
    csrf: csrf, apiBase: apiBase
  };
})();

/* Bell dropdown: mark notifications read through the real API. */
(function () {
  var bell = document.querySelector('.topbar .dropdown .icon-btn .bi-bell');
  if (!bell || !window.MediCare) return;
  var btn = bell.closest('button');
  btn.addEventListener('click', function () {
    var dot = btn.querySelector('.dot');
    if (!dot) return;
    var fd = new FormData();
    fd.append('action', 'read');
    MediCare.api('notifications.php', { method: 'POST', body: fd })
      .then(function () { dot.remove(); })
      .catch(function () { /* non-critical */ });
  });
})();
