# MediCare Cloud — Security Model

MediCare Cloud is a multi-tenant Hospital Management SaaS. Hospitals hold
sensitive medical data, so security is enforced in layers: authentication,
authorization, tenant isolation, and auditability. This document describes
how the codebase implements each layer and what operators must do in
production.

---

## 1. Password storage & verification

- Passwords are hashed with PHP's `password_hash()` (bcrypt, `PASSWORD_DEFAULT`)
  and verified only with `password_verify()`. Plaintext passwords are never
  stored, logged, or emailed.
- The seeded demo accounts all use the password `password`. **Change or
  delete every demo account before any production or publicly reachable
  deployment.**
- Password-reset tokens are random, stored per email in `password_resets`,
  and must be treated as single-use and short-lived. Remember-me tokens are
  never stored raw: only their `sha256` hash is kept in
  `users.remember_token` and compared with `hash_equals()`.

## 2. SQL injection prevention — PDO prepared statements

- All database access goes through a single PDO connection
  (`config/database.php`) configured with:
  - `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
  - `PDO::ATTR_EMULATE_PREPARES => false` (native prepares)
  - `PDO::FETCH_ASSOC` by default
- Every query that involves user input uses a prepared statement with bound
  parameters. No user input is ever concatenated into SQL. Identifiers that
  cannot be bound (for example a whitelisted column name) must come from a
  fixed allow-list in code, never from request data.
- Integers coming from the request/session are cast with `(int)` before use.

## 3. CSRF protection

- Every session gets a 32-byte random token (`csrf_token()` in
  `includes/functions.php`, stored in `$_SESSION['csrf']`).
- HTML forms embed it via `csrf_field()` (`_csrf` hidden field) and every
  state-changing POST calls `verify_csrf()`, which compares with
  `hash_equals()` and aborts with HTTP 419 on mismatch.
- The JSON API (`api/notifications.php`) accepts the same token either as
  the `X-CSRF` header or the `_csrf` POST/JSON field and returns a JSON 419
  on mismatch. GET endpoints never mutate state.

## 4. Sessions & cookies

- Session cookies are configured in `config/config.php` with:
  `httponly => true`, `samesite => Lax`, `path => /`, and `secure => true`
  automatically when the request is over HTTPS. Always serve the app over
  HTTPS in production so the `Secure` flag applies.
- The session holds only the minimal identity payload set by
  `establish_session()` in `includes/auth.php`: user id, tenant id, name,
  email, role slug, and the permission slugs loaded at login.
- Logout and tenant suspension destroy the session. Regenerate the session
  ID after login and after any privilege change in hardened deployments.

## 5. Login rate limiting

- Every login attempt is recorded in `login_attempts` (email, IP address,
  success flag, timestamp).
- The login flow enforces `LOGIN_MAX_ATTEMPTS` (5) within a lockout window
  of `LOGIN_LOCK_MINUTES` (15) minutes (constants in `config/config.php`).
  Repeated failures lock further attempts for that email/IP until the
  window passes. Operators should alert on spikes in failed attempts —
  they are the earliest signal of credential stuffing.

## 6. RBAC — roles & permissions

Roles (seeded in `database.sql`):

| Role | Scope | Summary |
| --- | --- | --- |
| `super_admin` | platform | Platform owner: tenants, plans, subscriptions, payment verification |
| `hospital_admin` | tenant | Full control of one hospital |
| `doctor` | tenant | Consultations, prescriptions, lab orders, medical records |
| `receptionist` | tenant | Registration, appointments, admissions, front-desk billing view |
| `nurse` | tenant | Wards/beds, admissions view, observations |
| `accountant` | tenant | Billing, invoices, payments, reports |
| `pharmacist` | tenant | Medicine stock, prescription dispensing |
| `lab_technician` | tenant | Lab tests and results |
| `patient` | tenant | Patient portal: own appointments, bills, and shared records only |

- Permissions are fine-grained slugs (e.g. `patients.view`,
  `patients.manage`, `billing.manage`) stored in `permissions` and mapped
  per role in `role_permissions`.
- At login, the user's permission slugs are loaded into the session
  (`load_user_permissions()`). Pages/APIs check `has_permission($slug)`;
  `super_admin` bypasses slug checks but never tenant-data checks for
  tenant-owned medical data.
- `require_role([...])` guards role-restricted pages and exits with 403.
- Principle: deny by default. A new module must declare the permission it
  requires; absence of a check is a bug, not a convenience.

## 7. Tenant isolation & tenant-aware authorization

This is the most important rule in the system:

- **Every hospital business table carries `tenant_id`** (patients, doctors,
  appointments, wards, beds, admissions, medical records, medicines,
  prescriptions, lab tests/results, patient invoices/payments, and more),
  with indexes and foreign keys enforcing it at the schema level.
- **Every query for tenant data filters by `tenant_id = ?`** using the
  tenant from the server-side session (`tenant_id()`), never a value from
  the request. A `tenant_id` in `$_GET`/`$_POST` is never trusted.
- `require_tenant()` (in `includes/auth.php`) aborts with 403 when a
  tenant page/API is reached without a tenant context. Super admins have
  `tenant_id = NULL` and use separate platform queries.
- Plan limits (`active_subscription()`, `usage_counts()`,
  `plan_limit_reached()` in `includes/functions.php`) are computed per
  tenant, so one hospital can never consume another hospital's quota.
- File/record access follows the same rule: a record is only fetched when
  its `tenant_id` matches the session tenant — fetching by bare `id` alone
  is forbidden.

## 8. Suspended-tenant kill switch

- `tenants.status` is `active` or `suspended`.
- `check_tenant_active()` runs on protected pages/APIs: if the tenant is
  suspended, the session is wiped, destroyed, and the user is redirected to
  login with `?error=suspended`. Access stops immediately — no grace reads
  of medical or billing data.
- Use suspension for non-payment escalation (after the subscription grace
  period), abuse, or a hospital's own request. Suspension never deletes
  data; reactivation restores access.

## 9. XSS prevention

- All output is escaped with `e()` (`htmlspecialchars(..., ENT_QUOTES,
  'UTF-8')`) — names, titles, flash messages, table cells, and attribute
  values alike.
- JSON APIs set `Content-Type: application/json; charset=utf-8` and
  `X-Content-Type-Options: nosniff`, and return data (never HTML), casting
  IDs/counts to `int`.
- Never echo raw `$_GET`/`$_POST`/database free-text into a page. Rich-text
  fields, if ever added, need an allow-list HTML sanitizer first.

## 10. Payment verification rule — never auto-success

> **A payment is never successful just because a checkout was created.**

- Platform `payments.status` starts as `pending`. It only becomes
  `verified` when a **Super Admin records confirmation** (gateway
  settlement report, bank statement, or provider callback verified
  server-side), which also sets `verified_by` and `paid_at`.
- Subscription activation/renewal keys off `verified` payments and paid
  invoices — never off a redirect back from a payment page, a client-side
  callback, or an unverified webhook payload.
- Patient payments (`patient_payments`) are recorded by authorized billing
  staff against a specific invoice; invoice status (`unpaid` / `partial` /
  `paid`) is derived from recorded payments, not typed in by hand.
- Coupons (`WELCOME10`, etc.) only discount an invoice; a discounted total
  of $0 still requires an explicit verification step before the
  subscription is marked paid.

## 11. Audit & activity logging

- `audit_log()` writes security-relevant actions to `audit_logs`
  (tenant, user, action, entity, entity id, details, IP, timestamp) —
  logins, record changes, billing actions, role/permission changes, and
  payment verification.
- `log_activity()` writes the human-readable feed to `activity_logs`.
- `notify_user()` creates in-app notifications (`notifications` table)
  surfaced by the topbar and `api/notifications.php`.
- Logs are append-only from the application's perspective: no tenant role
  can edit or delete log rows. Ship logs to external storage in production
  and review them for cross-tenant access attempts and privilege changes.

## 12. Medical-data safeguards

- **Record visibility:** `medical_records.visibility` is `staff` or
  `patient`. Patients only ever see records explicitly marked `patient`,
  and only their own (`patient_id` bound to their linked patient record).
- **Least privilege:** doctors see clinical modules; reception sees
  scheduling and demographics but not full clinical detail; pharmacy sees
  prescriptions, not unrelated records; lab sees ordered tests and
  results. Role permission sets in `database.sql` encode this — review
  them before adding any role.
- Allergies, diagnoses, and lab values are medical data: they follow the
  same tenant + visibility rules, are excluded from global search results
  beyond name/code matching, and must never appear in URLs, logs' free-text
  details, or error messages.
- Exports (CSV) must apply the same tenant and permission checks as the
  screen they export from.

## 13. Secrets & `.env` guidance

- Configuration is read from environment variables in `config/config.php`
  (`DB_HOST`, `DB_USER`, `DB_PASS`, `APP_URL`, `APP_ENV`, `APP_TZ`, …).
  The fallback values in code are local-development placeholders only.
- Keep a real `.env` (or host-level environment variables) **outside the
  web root and outside git**. Commit only `.env.example` with empty or
  dummy values. Rotate the database password and any API keys immediately
  if a `.env` file is ever committed or shared.
- Use a dedicated, least-privilege MySQL user for the app (CRUD on the
  `medicare_cloud` database only — no `SUPER`, no access to other
  databases). Never run the app as MySQL `root` outside local development.
- Set `APP_ENV=production` and disable PHP error display in production;
  log errors server-side instead.

## 14. Backups & retention recommendations

- **Backups:** automated daily MySQL dumps of `medicare_cloud` (more often
  for busy hospitals), encrypted at rest, with at least one off-site /
  off-provider copy. Test restores quarterly — an untested backup is a
  hope, not a backup.
- **Retention:** keep daily backups for 30 days, weekly for 6 months, and
  monthly for at least 1 year, or longer where local medical-record
  retention law requires it. Audit logs should be retained at least as
  long as the records they describe.
- **Tenant off-boarding:** on cancellation, export the hospital's data for
  the hospital, retain per the policy above, then delete or anonymize on
  request. Document who approved any deletion in `audit_logs` before the
  data goes.
- **Uploads/documents:** if record attachments are enabled, store them
  outside the web root, serve them through a tenant-checked download
  script, and include them in the same backup schedule.
