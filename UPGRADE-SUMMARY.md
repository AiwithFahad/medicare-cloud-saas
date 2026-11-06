# MediCare Cloud — Upgrade v2 Summary

**Date:** 2026-11-06 · **Scope:** upgrade of the existing product (no rebuild,
no redesign). Branding, colors (white surfaces, medical blue `#2563eb`,
emerald `#10b981`), typography (Inter), sidebar/topbar design, layouts and
animations are unchanged. The original `app/` tree, public site, auth flow
and Super Admin pages all remain functional; v2 adds dedicated role panels,
a real AJAX/JS layer, JSON APIs and the missing functional modules on top
of the same database.

---

## 1. New pages by role

### Super Admin — `super-admin/`
| Page | Status | What it does |
| --- | --- | --- |
| `dashboard.php` | **New** | Platform KPIs (hospitals by status, MRR/ARR, verified revenue, trials expiring, pending payments), revenue + plan-mix charts fed by `api/dashboard-stats.php?scope=platform`, **pending hospital approvals** queue, pending payment verifications, recent activity |
| `hospitals.php` | **New** | All hospitals with status filter incl. **pending**, search, pagination; **approve / suspend / reactivate** (server-side, audited, tenant admin notified); plan, billing cycle, subscription status and live usage vs plan limits per hospital |
| `reports.php` | **New** | Verified revenue by month (12 months), MRR by plan, subscription status mix, top hospitals by verified payments, hospital growth, **CSV exports** (revenue, tenants), API-driven charts |
| `users.php`, `subscriptions.php`, `plans.php`, `payments.php`, `settings.php` | Existing, kept | Users, monthly/yearly subscriptions + trials + renewals, plan CRUD, payments (**pending until verified — never auto-success**), global platform settings |
| `tenants.php` (renamed in nav to "Tenant Manager"), `coupons.php`, `invoices.php`, `tickets.php`, `announcements.php`, `logs.php` | Existing, kept | Full tenant detail/edit, coupons, subscription invoices, support tickets, announcements, activity/audit logs |
| `index.php` | Changed | Now redirects to `dashboard.php` (old dashboard superseded) |

Registration flow change: `auth/register.php` now creates hospitals in
**`pending`** status; login is blocked with an explanation until a Super
Admin approves the hospital in `super-admin/hospitals.php`.

### Hospital Admin — `admin/` (all new, role `hospital_admin`)
| Page | Highlights |
| --- | --- |
| `dashboard.php` | Every required KPI from the API: total patients, **new patients today**, total + available (on-duty) doctors, today's / pending / completed / cancelled appointments, **total revenue**, pending invoices + amount, available + occupied beds, emergency admissions, pharmacy stock alerts (+ expired medicines, lab queue, reminders due, monthly expenses). Charts: weekly patient registrations, monthly appointments, revenue analytics (date-filterable), department performance, doctor performance, admission trends. Live tables: today's appointments, active emergencies, stock alerts, reminders with mark-done |
| `patients.php` | AJAX live table (search/filter/pagination) + modal create/edit/delete via `api/patients.php`, generated Patient IDs (`PAT-0001`…), plan-limit enforcement. `?profile=` view: demographics, **medical timeline** (records, prescriptions, lab results, admissions, appointments merged chronologically), **documents** (upload ≤10 MB with type whitelist, download via guarded streamer, delete), quick add-record, invoice history |
| `doctors.php` | CRUD incl. specialization, qualification, consultation fee, status, optional portal-login creation; **weekly schedule editor** writing `doctor_schedules` and regenerating the human-readable `schedule` text; **14-day availability calendar** (`?calendar=`) with booked visits and free-slot counts |
| `appointments.php` | AJAX live table via `api/appointments.php`; booking modal with **real free-slot picker** and fee auto-fill; confirm / complete / cancel / no-show; reschedule modal; **emergency board** — today's `emergency_cases` with add-arrival and status/disposition updates |
| `departments.php` | Card-grid CRUD, head-of-department select, live doctor/appointment counts, delete blocked while doctors are assigned |
| `staff.php` | Staff account CRUD (receptionist, nurse, accountant, pharmacist, lab technician, doctor logins), plan staff-limit check, activate/deactivate, optional password reset; cannot modify Super Admins, hospital admins or your own account |
| `billing.php` | Invoice creation with dynamic line items (`BILL-0001`… numbering), payment recording with balance validation and automatic unpaid → partial → paid status, **printable invoice view** (`?print=`), **expenses CRUD**, **unified transactions ledger** (payments in, expenses out), monthly collected/expenses/net/outstanding stats |
| `pharmacy.php` | Medicine CRUD + stock adjustment, expiry/low-stock alerts, prescription queue with **dispense** that decrements real stock (partial dispense supported), **printable prescription view** (`?print_rx=`) |
| `laboratory.php` | Test catalogue CRUD, test ordering, result entry workflow (pending → in progress → completed, value required to complete), **printable lab report** (`?print=`) |
| `wards.php` | Ward CRUD with automatic bed generation, add-bed, **admission/discharge workflow** (admit to a free bed → bed occupied; discharge/transfer → bed freed), **live bed board** from `api/wards.php` auto-refreshing every 30 s with per-bed status controls |
| `reports.php` | API charts (monthly appointments, revenue, dept/doctor performance), department + doctor tables, appointment status mix, **CSV exports** (revenue, appointments, patients, expenses) |
| `settings.php` | Hospital profile + tenant settings (incl. `appointment_reminder_hours`), own password change, subscription summary with usage meters |

### Doctor — `doctor/` (all new, role `doctor`, force-scoped to own record)
`dashboard.php` (own KPIs, today's clinic, upcoming visits, today's working
window, own monthly appointments chart) · `appointments.php` (live table,
confirm/complete/no-show/cancel/reschedule via the API) · `patients.php`
("my patients" derived live from appointments/prescriptions/records/
admissions + profile with medical timeline and add-record) ·
`prescriptions.php` (prescription writer with medicine catalogue lines,
own history, print view) · `schedule.php` (own weekly schedule editor +
14-day calendar) · `reports.php` (own monthly chart, status mix, fees
earned by month, recent completed visits).

### Receptionist — `receptionist/` (all new, role `receptionist`)
`dashboard.php` (today's arrivals with one-click **check-in**, new patients
today, unpaid bills, active emergencies, reminders due) · `patients.php`
(live table + register/edit via API, compact profile) · `appointments.php`
(booking/reschedule/status tooling + emergency board) · `billing.php`
(payment collection with balance validation, printable invoice,
collected-today stats).

### Patient — `patient/` (all new, role `patient`, force-scoped to own record)
`dashboard.php` (next appointment, latest prescription, outstanding
balance, shared records) · `appointments.php` (**self-booking into real
free slots**, cancel, reschedule) · `medical-records.php` (only
patient-visible records, completed lab results, shared documents with
guarded download) · `prescriptions.php` (own prescriptions + print view) ·
`invoices.php` (own invoices + print view; balances are settled at the
front desk — **no fake "pay online" button**) · `profile.php` (contact
details + password change).

Login redirects updated in `auth/login.php`: super admin →
`super-admin/dashboard.php`, hospital admin → `admin/dashboard.php`,
doctor → `doctor/dashboard.php`, receptionist →
`receptionist/dashboard.php`, patient → `patient/dashboard.php`,
nurse/accountant/pharmacist/lab technician → `app/dashboard.php`
(unchanged shared tree).

Every panel page enforces `require_role()` + the matching permission
slugs server-side, and every query is filtered by the session
`tenant_id`. Doctors and patients are additionally force-scoped to their
own linked record in the API layer, so client parameters cannot widen
access.

---

## 2. New API endpoints — `api/`

Shared bootstrap `api/common.php` (JSON envelope, session auth, CSRF via
`X-CSRF` header / `_csrf` field, permission + tenant guards, pagination).

| Endpoint | Methods | Purpose |
| --- | --- | --- |
| `dashboard-stats.php` | GET | Real aggregates + Chart.js series. Tenant scope: all dashboard KPIs + `weekly_registrations`, `monthly_appointments`, `revenue` (date-filterable via `from`/`to`), `department_performance`, `doctor_performance`, `admission_trends`. `?scope=platform` (super admin): tenant counts, MRR/ARR, verified revenue, revenue-by-month, plan mix. Optional `doctor_id` restricts appointment series |
| `patients.php` | GET / POST | Live list (search, gender filter, pagination; rows carry age, visit count, admission status); `create` / `update` / `delete` with server-generated Patient IDs and plan-limit checks |
| `appointments.php` | GET / POST | List (doctors/patients force-scoped); `create` books a **validated free slot** (Emergency exempt), snapshots the consultation fee, **creates a front-desk reminder** and notifies the patient; `status` transitions; `reschedule` with availability re-check. Cancellation also cancels pending reminders |
| `doctor-availability.php` | GET | Free slots for a doctor + date from `doctor_schedules` minus booked appointments; past times excluded for today |
| `wards.php` | GET / POST | Bed board grouped by ward with current patient names; `bed_status` action (occupied requires a patient) |

Existing `api/search.php` and `api/notifications.php` are untouched; the
topbar bell now also marks notifications read through the API.

---

## 3. JavaScript layer — `assets/js/app.js` (`window.MediCare`)

Appended to the existing file (original theme/sidebar/confirm code kept;
the legacy `data-confirm` flow now opens the Bootstrap confirm modal
instead of `window.confirm`):

- `MediCare.api(path, opts)` — fetch wrapper: JSON envelope handling,
  `X-CSRF` header from the `<meta name="csrf-token">` tag, `api-base`
  meta, structured errors.
- `MediCare.toast(msg, type)` — Bootstrap toast stack (top-right, brand
  styling classes added to `style.css`).
- `MediCare.confirm(title, body)` → `Promise<boolean>` — shared confirm
  modal used for deletes and destructive actions.
- `MediCare.setLoading(btn, on)` — spinner + disabled state on submit.
- `MediCare.ajaxForm(form, opts)` — HTML5 validation, FormData POST,
  toast on result, modal auto-close, callback or reload.
- `MediCare.liveTable(cfg)` — server-driven tables: debounced live
  search, select filters, smart pagination, record counts, loading
  overlay, empty states.
- `MediCare.chart(id, config)` — one Chart.js instance per canvas
  (destroy/recreate safe).
- `MediCare.bindSlots(cfg)` — appointment slot picker: doctor + date →
  free-slot radio chips from `doctor-availability.php`, consultation fee
  auto-filled from the selected doctor.

Supporting CSS additions (same design tokens, appended to
`assets/css/style.css`): toast stack, loading overlay/spinner, slot
chips, medical timeline, availability-calendar day cards, occupied-bed
tint, document thumbnails. Small hooks added to `includes/header.php`
and `super-admin/sa-header.php`: `csrf-token` + `api-base` meta tags and
panel-aware profile links. New shared includes: `includes/panel.php`
(doctor/patient resolution, schedule + free-slot engine, print layout)
and `includes/panel-sidebar.php` (role menus in the existing sidebar
design, permission-filtered).

**No dummy buttons:** every control in the new panels performs a real
server action (CRUD, status transition, dispense, admit/discharge,
print, export, mark-done) or was omitted. The patient panel deliberately
has no online-payment button, because no payment gateway exists —
balances are collected at the front desk.

---

## 4. Database — migrations + schema changes

Numbered, non-destructive migrations in `database/migrations/`, folded
into `database.sql` for fresh installs (**38 → 43 tables**):

**`001_upgrade_v2.sql`**
- `tenants.status` ENUM gains `pending` (registration approval workflow).
- **New tables:** `patient_documents` (uploaded files, staff/patient
  visibility), `expenses` (category, vendor, payment method),
  `emergency_cases` (triage level, arrival, status, disposition; walk-ins
  supported via `patient_name`), `reminders` (appointment/follow-up/
  payment/custom, channel, status), `doctor_schedules` (per-weekday
  hours, slot length, availability — powers the slot engine).
- Demo seeds for tenant 1: schedules for all 6 seeded doctors matching
  their published hours, 4 expenses, 3 emergency cases, 3 reminders,
  1 patient document (sample file in `storage/documents/`, web access
  denied via `.htaccess`).

**`002_receptionist_billing_manage.sql`** — grants the existing
`billing.manage` permission to the receptionist role (front desk
collects payments in `receptionist/billing.php`). Idempotent.

All existing tables, seeds and demo logins are unchanged and keep
working (`super@medicare.test`, `admin@`, `doctor@`, `reception@`,
`patient@demo.com`, password `password`; coupon `WELCOME10`).

---

## 5. Modules completed (vs. the upgrade prompt)

Patient registration/edit/history/documents/profile with generated IDs ✓ ·
doctor specialties, schedules, availability calendar, consultation fees ✓ ·
appointment book/confirm/cancel/reschedule + reminders ✓ · departments ✓ ·
staff ✓ · ward/bed allocation + admission/discharge ✓ · medical records +
patient medical timeline ✓ · prescriptions with print view ✓ · lab
requests + printable lab reports ✓ · pharmacy stock + dispensing ✓ ·
billing/invoices with printable invoice ✓ · expenses + financial
transactions ledger ✓ · emergency patient records ✓ · hospital reports +
CSV exports ✓ · Super Admin: approvals, activation/suspension, plans,
monthly/yearly subscriptions, trials, renewals, payments pending-until-
verified, invoices, usage limits, platform revenue reports, tickets,
announcements, global settings ✓.

## 6. Vercel demo — `vercel-demo/index.html`

Design untouched; interactivity extended: **role switcher** (Super Admin /
Hospital Admin / Doctor / Receptionist / Patient) that filters the
sidebar to each role's real module set, swaps the signed-in user chip and
opens the role's home view; **four new views** — Platform Overview
(platform stats, verified-revenue chart, approvals queue with a
"pending until verified" note), Doctor Home (Dr. Sarah Khan's day +
monthly chart), Front Desk (check-ins + reminders), Patient Portal (John
Miller PAT-0001: next visit, balance, shared records — with an explicit
no-fake-payment note); **live search** on the Patients table and
**search + status filter** on Appointments (mock data, client-side).
All demo JavaScript parses cleanly (validated with Node).

## 7. Testing

- **PHP lint:** all **100 PHP files** pass `php -l` (PHP 8.3.13 static
  build) — zero failures, including all 39 new/rewritten files.
- **JS:** `assets/js/app.js` passes `node --check`; the demo page's
  inline script parses cleanly via Node `Function` compilation.
- **Runtime smoke test** (PHP built-in server): public pages return 200;
  the API correctly redirects unauthenticated requests to login (302);
  DB-backed pages fail **only** with `PDOException: Connection refused`.
- **Not runtime-tested:** full login → CRUD → chart round-trips against a
  live database. This VM has **no MySQL/MariaDB server** (no
  `mysqld`/`mariadbd` binaries present), so end-to-end verification
  requires importing `database.sql` on a MySQL host and clicking through
  the demo logins. SQL was written against the verified v1 schema and
  the same PDO/prepared-statement conventions the existing (working)
  pages use.

## 8. Known limitations

1. **No live-MySQL verification** (see §7) — the one gap to close on a
   real host before production use.
2. In the *reschedule* modal, the slot-picker chip list does not exclude
   the appointment being moved (its own current slot may look booked);
   the server-side reschedule validation itself correctly ignores it.
3. Pre-existing seed quirk kept as-is: bed `P-02` shows occupied for a
   seeded patient whose admission is already discharged (v1 demo data).
4. Patient documents are served through a PHP streamer with a path
   traversal guard and `storage/documents/.htaccess` denies direct web
   access on Apache; on Nginx add an equivalent `location` deny rule.
5. Migration `001` seed inserts use `INSERT IGNORE` and assume the demo
   tenant/doctor IDs (1 / 1–6) from `database.sql`; on a customized
   database the schema changes still apply, only the demo seed rows may
   be skipped.
6. Reminder *delivery* (SMS/email) is out of scope: reminders are
   created automatically and worked from the dashboards (mark done);
   channels are recorded for a future gateway integration.

## 9. Runtime verification (post-upgrade, live database)

_Added after the §7 smoke test. §7's "no MySQL/MariaDB on this VM" note is
superseded: MariaDB 10.11.14 was installed on the VM for this round, and
the app was exercised end-to-end over HTTP against a real database._

**Environment & setup**

- MariaDB 10.11.14 + static PHP 8.5.8 (`pdo_mysql`), PHP built-in server
  on `127.0.0.1:8101`, `display_errors=1`, `error_reporting=E_ALL`.
- Fresh import of `database.sql` into a scratch database (import copy
  only renamed the DB): **43 tables, 9 users, 8 patients** seeded, zero
  import errors. Credentials were passed to the server process as
  environment variables — no `.env` file was created in the tree.
- Tests used curl cookie jars per role and asserted **page content**
  (expected strings, absence of PHP error text), not just status codes.
  Scratch DB, test DB user and the test server were removed afterwards.

**Flows tested — all passed**

- Public pages: `/`, about, features, pricing, doctors, departments,
  blog, contact, privacy, terms, book-appointment, login/register —
  200 with real content, no PHP errors.
- **Logins** for all five demo roles land on the correct new trees:
  super → `super-admin/dashboard.php`, hospital admin →
  `admin/dashboard.php`, doctor → `doctor/dashboard.php`, receptionist →
  `receptionist/dashboard.php`, patient → `patient/dashboard.php`.
- **Every page of every role tree** (super-admin 13, admin 12, doctor 6,
  receptionist 4, patient 6) plus all 16 legacy `app/` pages render 200
  with real seeded data (e.g. "City Care Hospital" in Super Admin,
  `PAT-0001` / "John Miller" in the patient portal) and a **clean PHP
  error log**.
- **Role isolation:** patient → admin/super-admin trees = 403,
  doctor → admin/super-admin = 403, hospital admin → super-admin = 403,
  receptionist → admin billing = 403, super admin → patient tree = 403,
  anonymous → 302 to login; API permission gates return JSON 403
  (e.g. patient calling `api/wards.php`). Logout destroys the session.
- **`api/dashboard-stats.php`** (admin): `total_patients 8`,
  `total_doctors 6`, `appointments_today 4`, `beds_total 12`,
  `beds_available 6` — all match direct SQL counts. Platform scope
  (super admin): `total_tenants 3`, `total_users 9`, MRR/plan charts OK.
- **`api/doctor-availability.php`**: doctor 1 on a working Monday returns
  all 10 slots (09:00–13:30); on Saturday returns `available: false`
  with a reason; past slots on the current day are correctly filtered.
- **`api/wards.php`** GET returns the live bed board (12 beds, patient
  names/codes); POST `bed_status` occupied→available round-trip verified
  in the database and restored.
- **`api/search.php`**: `q=sarah` finds Dr. Sarah Khan (tenant view);
  super-admin view returns tenant counts (3 total).
- **`api/patients.php`**: search finds John Miller (`PAT-0001`); create
  registers a new patient (`PAT-0009`); update persists name/phone —
  both verified in the database.
- **`api/appointments.php`**: booking patient 9 with doctor 1 on
  2026-11-09 09:00 succeeded (fee defaulted to the doctor's $80
  consultation fee), created the front-desk reminder (1 h before), the
  slot disappeared from availability, and re-booking the same slot was
  rejected with 422 "That slot was just taken."
- **`api/notifications.php`** returns the seeded tenant notifications.
- Re-lint after fixes: **100 PHP files, 0 failures**; `node --check`
  passes on `assets/js/app.js`.

**Bugs found & fixed during runtime verification**

1. `api/dashboard-stats.php` — revenue chart always showed **$0 for the
   current month**: `paid_at BETWEEN ? AND ?` compared a DATETIME column
   against a date-only end bound, excluding every payment made on the
   end date. Fixed to `paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1
   DAY)`; chart now reports the real $730 for the current month.
2. `includes/footer.php` "Array to string conversion" warning, and
   **app.js never loaded** on four pages: bed/bill `foreach` loops
   reused `$b`, the variable `includes/header.php` sets to the base-path
   string, so the footer printed `Arrayassets/js/app.js`. Loop variables
   renamed in `admin/wards.php` ($b → $fb), `app/wards.php` ($b → $bed),
   `app/admissions.php` ($b → $fb) and `app/portal.php` ($b → $bill);
   all four pages now emit `../assets/js/app.js` with a clean error log.

**Files changed by this verification round (exact list)**

- `api/dashboard-stats.php`
- `admin/wards.php`
- `app/wards.php`
- `app/admissions.php`
- `app/portal.php`
- `UPGRADE-SUMMARY.md` (this section)

No design/branding changes; no other files were modified.
