# MediCare Cloud — Build Summary

**Product:** MediCare Cloud — Hospital Management SaaS (multi-tenant, commercial)
**Stack:** Core PHP 8 + MySQL (PDO, no frameworks), Bootstrap 5.3, Chart.js 4, Bootstrap Icons, Inter font

## Counts

| Metric | Value |
| --- | --- |
| Total files (incl. this summary) | 74 |
| PHP files | 61 |
| PHP lint (`php -l`, PHP 8.5) | **61 / 61 passed, 0 failures** |
| MySQL tables in `database.sql` | **38** (22 platform/SaaS + 16 hospital) |
| Demo (`vercel-demo/index.html`) | 1 self-contained static page + `vercel.json` |

## Structure

- **Public website** (root): `index.php` (17-section landing), `about`, `features`, `pricing`, `departments`, `doctors`, `book-appointment`, `blog`, `contact`, `privacy`, `terms` — DB-backed with curated static fallbacks.
- **Auth** (`auth/`): login (rate-limited via `login_attempts`, remember-me), tenant registration with slug + email-verification token flow + trial activation, verify, forgot/reset password, logout.
- **Hospital app** (`app/`): dashboard, patients, doctors, appointments, departments, staff, wards & beds, admissions, billing, payments, pharmacy & prescriptions, laboratory, medical records + patient history, reports, subscription, support tickets, settings, patient portal.
- **Super Admin** (`super-admin/`): dashboard (real SQL aggregates, MRR/ARR), tenants (suspend/reactivate), plans, coupons, subscriptions, payments (the only place a payment can become `verified`), invoices, users, tickets, announcements, platform settings, activity/audit logs.
- **API** (`api/`): session-authenticated JSON `search.php` + `notifications.php`.
- **Docs:** `README.md`, `docs/SECURITY.md`, `docs/API.md`, `.env.example` (placeholders only).

## Demo credentials (all password: `password`)

| Role | Email |
| --- | --- |
| Super Admin | super@medicare.test |
| Hospital Admin | admin@demo.com |
| Doctor | doctor@demo.com |
| Receptionist | reception@demo.com |
| Nurse | nurse@demo.com |
| Accountant | accounts@demo.com |
| Pharmacist | pharmacy@demo.com |
| Lab Technician | lab@demo.com |
| Patient | patient@demo.com |

Demo tenant: **City Care Hospital** (`city-care-hospital`) on the Professional plan.
Coupon: **WELCOME10** (10% off, seeded and already redeemed once in demo data).
Seed password hashes were verified with `password_verify("password", …)` = true.

## Brand palette (from the reference brief)

- Backgrounds: white `#ffffff` / app canvas `#f4f8fc`
- Medical blue (primary): `#2563eb`, dark `#1d4ed8`, soft `#eff6ff`
- Emerald green (accent): `#10b981`, dark `#059669`, soft `#ecfdf5`
- Teal support: `#0d9488` · Ink `#0f172a` · Muted `#64748b` · Lines `#e2e8f0`
- Dark theme via `[data-theme="dark"]`, toggle persisted in localStorage; collapsible sidebar persisted too.

## SaaS rules enforced in code

- `tenant_id` isolation on every business table and every query; tenant-aware authorization on every protected page; suspended-tenant kill switch.
- RBAC: 9 roles, permissions loaded into the session at login from `role_permissions`.
- Subscriptions: Free / Starter / Professional / Business / Enterprise, monthly + yearly, trial, upgrade/downgrade/cancel/renew, coupons, invoices, grace period (7 days).
- Plan limits enforced on create for doctors, staff, patients, departments (`-1` = unlimited on Enterprise).
- Payments are recorded as `pending` and become `verified` **only** when the Super Admin confirms them — nothing is ever auto-marked paid.
- All Super Admin and dashboard statistics are computed from live database queries, never hardcoded.

## Deployment note

`vercel-demo/` is the static showcase for Vercel. The full PHP application requires PHP 8 + MySQL hosting (VPS or PHP host) — see README "Deployment" for the honest architecture notes. No Git/GitHub/Vercel commands were run during this build; files only.
