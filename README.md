# MediCare Cloud — Hospital Management SaaS

**Hospital management, beautifully simple.** A multi-tenant SaaS that gives
every hospital its own complete system — patients, doctors, appointments,
wards & beds, pharmacy, laboratory, and billing — on one secure cloud
platform with subscriptions handled for you.

> Demo hospital: **City Care Hospital** · Headline doctor: **Dr. Sarah Khan,
> Cardiology** · Departments: Cardiology · Neurology · Pediatrics ·
> Emergency · Orthopedics · General Surgery

---

## Features

### Platform (SaaS core)

- **Multi-tenancy** — every hospital is an isolated tenant (`tenant_id` on
  every business table); hospitals never see each other's data.
- **Super Admin console** — manage tenants, plans, subscriptions,
  invoices, payments, coupons, announcements, and support tickets.
- **Subscriptions & billing** — 5 plans (Free / Starter / Professional /
  Business / Enterprise), monthly & yearly cycles, trials, grace periods,
  plan limits (doctors / staff / patients / departments / storage), and
  usage metering.
- **Coupons** — percent or fixed discounts with usage caps and validity
  windows (try `WELCOME10`).
- **Roles & permissions** — 9 roles (Super Admin, Hospital Admin, Doctor,
  Receptionist, Nurse, Accountant, Pharmacist, Lab Technician, Patient)
  with fine-grained permission slugs enforced on every page.
- **Payments are verified, never assumed** — a payment only becomes
  `verified` after Super Admin confirmation (see
  [docs/SECURITY.md](docs/SECURITY.md)).
- Notifications, audit logs, activity logs, support tickets, light/dark
  theme, and a fully responsive collapsible-sidebar UI.

### Hospital modules

- **Dashboard** — census, today's appointments, bed occupancy, revenue,
  and charts at a glance.
- **Patients** — registration with patient codes (`PAT-0001`), demographics,
  blood group, allergies, and emergency contacts.
- **Doctors & Departments** — specializations, qualifications, consultation
  fees, schedules, and department heads.
- **Appointments** — OPD / follow-up / emergency / teleconsult booking with
  status flow (scheduled → confirmed → completed / cancelled / no-show).
- **Wards & Beds** — ward types (General, ICU, CCU, Pediatric, Maternity,
  Private) and a live bed board (available / occupied / reserved /
  maintenance).
- **Admissions** — admit, transfer, and discharge with bed assignment.
- **Medical Records** — diagnoses, observations, and documents with
  staff/patient visibility controls.
- **Pharmacy** — medicine stock with reorder levels & expiry dates,
  prescriptions and dispensing status.
- **Laboratory** — test catalogue with normal ranges, ordered results, and
  technician workflow (pending → in progress → completed).
- **Billing** — patient invoices with itemized charges (consultation, lab,
  pharmacy, bed, procedure) and recorded payments (paid / partial / unpaid).
- **Reports** — operational and revenue reporting per module.
- **Patient Portal** — patients see their own appointments, bills, and
  records shared with them.
- **JSON API** — session-authenticated global search and notifications
  endpoints (see [docs/API.md](docs/API.md)).

### Plans

| Plan | Monthly | Yearly | Highlights |
| --- | ---: | ---: | --- |
| Free | $0 | $0 | 2 doctors, 200 patients, appointments |
| Starter | $49 | $470 | + Bed management, laboratory, billing |
| Professional | $99 | $950 | + Pharmacy, patient portal, reports |
| Business | $199 | $1,910 | + API access, audit logs, phone support |
| Enterprise | $399 | $3,830 | Unlimited usage, dedicated manager, 99.9% SLA |

---

## Tech stack

- **Backend:** PHP 8 (strict types, PDO prepared statements), no framework
- **Database:** MySQL 8 / MariaDB 10.4+ (`utf8mb4`)
- **Frontend:** Bootstrap 5, Bootstrap Icons, Chart.js, Inter font —
  custom design system in `assets/css/style.css` (white surfaces, medical
  blue `#2563eb`, emerald `#10b981`), light & dark themes
- **Demo showcase:** static page in `vercel-demo/` (HTML/CSS/JS only)

---

## Folder structure

```
medicare-cloud-saas/
├── index.php              # Public landing page
├── database.sql           # Full schema + demo seed data (import first)
├── README.md
├── api/
│   ├── search.php         # GET  global search (JSON, tenant-scoped)
│   └── notifications.php  # GET/POST notifications (JSON, CSRF-protected)
├── app/                   # Tenant app: dashboard, patients, doctors,
│                          # appointments, wards, pharmacy, lab, billing…
├── assets/
│   ├── css/style.css      # Design system (brand, layout, dark theme)
│   ├── js/                # Theme / sidebar / chart helpers
│   └── img/               # Illustrations
├── auth/                  # Login, register, logout, password reset
├── config/
│   ├── config.php         # Env-based config + secure session setup
│   └── database.php       # Shared PDO connection (db())
├── docs/
│   ├── API.md             # API documentation
│   └── SECURITY.md        # Security model
├── includes/
│   ├── auth.php           # require_login / require_role / require_tenant
│   ├── functions.php      # e(), CSRF, flash, plan limits, logging…
│   ├── header.php / sidebar.php / footer.php   # App shell
│   └── site-header.php / site-footer.php       # Public site shell
├── super-admin/           # Platform console: tenants, plans, payments…
└── vercel-demo/
    ├── index.html         # Static showcase deployed to Vercel
    └── vercel.json
```

---

## Installation

Requirements: **PHP 8.0+** (with `pdo_mysql`), **MySQL 8 / MariaDB 10.4+**,
and a web server (Apache/Nginx) or PHP's built-in server for local dev.

### 1. Create the database and import the schema

```bash
mysql -u root -p -e "CREATE DATABASE medicare_cloud CHARACTER SET utf8mb4;"
mysql -u root -p medicare_cloud < database.sql
```

`database.sql` creates all tables **and** seeds the plans, roles,
permissions, coupons, the demo tenant (City Care Hospital), staff users,
doctors, patients, appointments, beds, medicines, lab tests, and invoices.

### 2. Configure the environment

The app reads configuration from environment variables (see
`config/config.php`). Either export them in your shell / set them in your
hosting panel, or create a `.env` file your server loads — at minimum:

```bash
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=medicare_cloud
DB_USER=medicare            # use a dedicated least-privilege DB user
DB_PASS=your-strong-password
APP_URL=https://app.yourdomain.com
APP_ENV=production
APP_TZ=Asia/Karachi
```

Never commit a real `.env` — only `.env.example`-style placeholders belong
in git. See [docs/SECURITY.md](docs/SECURITY.md) §13.

### 3. Point the web root at the project root

The **project root is the web root** (the folder containing `index.php`).

Local development with PHP's built-in server:

```bash
cd medicare-cloud-saas
php -S localhost:8000
# → http://localhost:8000  (landing) · http://localhost:8000/auth/login.php
```

For Apache/Nginx, set `DocumentRoot` / `root` to the project root, enable
HTTPS, and make sure `.env` and `database.sql` are not web-accessible on
shared hosting (move them outside the public folder or deny access via
server rules).

### 4. Log in with the demo credentials

All demo passwords are **`password`**. Change or remove these accounts
before any real deployment.

| Role | Email | Tenant |
| --- | --- | --- |
| Super Admin | `super@medicare.test` | Platform (no tenant) |
| Hospital Admin | `admin@demo.com` | City Care Hospital |
| Doctor | `doctor@demo.com` | City Care Hospital (Dr. Sarah Khan) |
| Receptionist | `reception@demo.com` | City Care Hospital |
| Nurse | `nurse@demo.com` | City Care Hospital |
| Accountant | `accounts@demo.com` | City Care Hospital |
| Pharmacist | `pharmacy@demo.com` | City Care Hospital |
| Lab Technician | `lab@demo.com` | City Care Hospital |
| Patient | `patient@demo.com` | City Care Hospital (John Miller, PAT-0001) |

**Coupon:** `WELCOME10` — 10% off the first subscription payment
(also seeded: `HOSPITAL20`, `FLAT25`).

---

## Deployment notes

Be honest about the split — there are two different things to deploy:

- **The full application** is PHP + MySQL and needs PHP hosting: a VPS
  (Nginx/Apache + PHP-FPM + MySQL), a managed PHP platform, or shared PHP
  hosting with MySQL. **It cannot run on Vercel** — Vercel has no PHP
  runtime or MySQL database.
- **`vercel-demo/`** is a fully static, self-contained showcase of the
  product (landing page + interactive mock dashboard with demo data). It
  *is* deployed to Vercel and needs no build step:

  ```bash
  cd vercel-demo   # contains index.html + vercel.json (cleanUrls)
  vercel deploy --prod
  ```

Suggested domain layout:

| Host | Serves |
| --- | --- |
| `medicarecloud.example` | Marketing site / Vercel demo |
| `app.medicarecloud.example` | Full PHP application (VPS / PHP host) |
| `api` routes under the app host | `api/search.php`, `api/notifications.php` (session-authenticated) |

Production checklist: HTTPS everywhere, `APP_ENV=production`, PHP error
display off, dedicated DB user, demo accounts removed, automated database
backups running (see [docs/SECURITY.md](docs/SECURITY.md) §14).

---

## Security

Tenant isolation on every query, bcrypt password hashing, PDO prepared
statements, CSRF tokens, login rate limiting, RBAC, the suspended-tenant
kill switch, and the rule that **payments are only `verified` by a Super
Admin after confirmation — never auto-marked successful** are documented
in full in **[docs/SECURITY.md](docs/SECURITY.md)**. API consumers should
read **[docs/API.md](docs/API.md)**.

## License

Proprietary — part of the AiwithFahad SaaS products collection. Demo data
(patients, doctors, hospitals) is fictional and for demonstration only.
