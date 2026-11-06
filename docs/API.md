# MediCare Cloud — API Documentation

MediCare Cloud is primarily a server-rendered PHP application. The JSON
endpoints under `api/` power in-app features (global search, the
notification bell) and follow the conventions that the planned public REST
API will extend. This document covers both: the two live endpoints first,
then the target REST architecture.

---

## Authentication model

- **Session cookie.** All API calls require a logged-in browser session.
  Log in via `/auth/login.php`; the server sets an `HttpOnly`,
  `SameSite=Lax` session cookie (plus `Secure` on HTTPS). Send the cookie
  with every request (`fetch` does this automatically for same-origin
  calls; other clients must pass `credentials: 'include'` / the cookie
  header explicitly).
- There is **no token/API-key auth yet**. Do not call these endpoints from
  third-party servers — they are first-party, same-origin endpoints.
- Unauthenticated HTML requests redirect to login. API consumers should
  treat an HTML login response (or a redirect to `/auth/login.php`) as
  **401 — session expired** and send the user to log in again.
- **CSRF:** state-changing requests must include the session CSRF token,
  either as the `X-CSRF` header or the `_csrf` field. Read it from any
  page's `csrf_field()` hidden input, or from the `csrf_token` value
  returned by `GET /api/notifications.php`.
- **Tenant scoping:** the tenant is taken from the server-side session,
  never from a request parameter. A tenant user can only ever receive
  their own hospital's data. Super admins (no tenant) get platform-level
  responses where documented.
- **Content type:** all responses are `application/json; charset=utf-8`.
  IDs and counts are JSON numbers; all queries behind the endpoints are
  PDO prepared statements.

Common status codes:

| Code | Meaning |
| --- | --- |
| 200 | Success |
| 405 | Wrong HTTP method for the endpoint |
| 419 | CSRF token missing or mismatched |
| 422 | Well-formed request, unsupported `action`/payload |
| 500 | Server error — body is a generic `{"error": "..."}` |

Error body format (all endpoints):

```json
{ "error": "Human-readable message." }
```

---

## 1. Global search — `GET /api/search.php`

Tenant-scoped type-ahead search over patients and doctors, plus today's
appointment count for dashboard widgets.

### Request

```
GET /api/search.php?q=sarah
```

| Parameter | Required | Notes |
| --- | --- | --- |
| `q` | no | Search text. Patients match on `name` **or** `patient_code` (e.g. `PAT-0001`); doctors match on `name`. Empty/missing `q` returns empty lists plus the count. Max 10 patients + 10 doctors. |

### Response — tenant user

```json
{
  "query": "sarah",
  "patients": [
    { "id": 1, "name": "John Miller", "code": "PAT-0001" }
  ],
  "doctors": [
    { "id": 1, "name": "Dr. Sarah Khan", "specialization": "Interventional Cardiology" }
  ],
  "appointments_today": 4
}
```

```js
const res = await fetch('/api/search.php?q=' + encodeURIComponent(term));
const data = await res.json();
// data.patients → [{id, name, code}], data.doctors → [{id, name, specialization}]
```

Notes:

- Results contain **no clinical data** — names/codes/specialization only.
- `appointments_today` counts appointments where
  `appointment_date = CURDATE()` for the session tenant.
- Demo data: searching `khan`, `PAT-000`, or `miller` against the seeded
  City Care Hospital tenant returns the rows above.

### Response — super admin

Super admins have no tenant, so patient/doctor search is replaced with a
platform view: tenant matches plus tenant counts.

```json
{
  "query": "city",
  "tenants": [
    { "id": 1, "name": "City Care Hospital", "status": "active" }
  ],
  "counts": { "total": 3, "active": 3, "suspended": 0 },
  "appointments_today": 12
}
```

Here `appointments_today` is the platform-wide total for today.

---

## 2. Notifications — `/api/notifications.php`

Backs the topbar notification bell. A user receives their personal
notifications (`user_id` = them) plus broadcasts to their tenant
(`tenant_id` = their tenant, `user_id IS NULL`). Super admins receive
personal rows plus platform broadcasts (`tenant_id IS NULL`).

### Read — `GET /api/notifications.php`

Returns the latest 10 notifications (newest first), the unread count, and
the session CSRF token for follow-up POSTs.

```json
{
  "notifications": [
    {
      "id": 3,
      "tenant_id": 1,
      "title": "Insulin Glargine stock is low",
      "body": "Only 18 vials left (reorder level 15). Consider a purchase order.",
      "type": "stock",
      "is_read": false,
      "created_at": "2026-11-06 09:14:22"
    },
    {
      "id": 2,
      "tenant_id": 1,
      "title": "ICU bed ICU-04 under maintenance",
      "body": "Facilities flagged bed ICU-04 for maintenance until further notice.",
      "type": "bed",
      "is_read": false,
      "created_at": "2026-11-06 08:40:10"
    }
  ],
  "unread": 3,
  "csrf_token": "9f2c…64-hex-chars…"
}
```

`type` is a free-form category string (`info`, `appointment`, `bed`,
`stock`, …) the UI maps to an icon/colour.

### Mark read — `POST /api/notifications.php`

Marks notifications as read. CSRF token required — send it as the
`X-CSRF` header (recommended for `fetch`) or as the `_csrf` form field.
A JSON body (`{"action": "read", "_csrf": "..."}`) is also accepted.

Mark **all** read:

```
POST /api/notifications.php
X-CSRF: 9f2c…
Content-Type: application/x-www-form-urlencoded

action=read
```

Mark **one** read — add `id`:

```
action=read&id=3
```

Response:

```json
{ "ok": true, "updated": 3, "unread": 0 }
```

- `updated` = rows flipped from unread to read (already-read rows are not
  counted, so the call is idempotent).
- An `id` belonging to another user or another tenant matches nothing —
  the ownership/tenant scope is part of the `UPDATE`, so cross-tenant
  marking is impossible by construction.
- Missing/invalid CSRF token → `419 {"error": "CSRF token mismatch."}`
- Any other `action` → `422 {"error": "Unsupported action. Use action=read."}`

```js
// Typical bell flow
const { csrf_token } = await (await fetch('/api/notifications.php')).json();
await fetch('/api/notifications.php', {
  method: 'POST',
  headers: { 'X-CSRF': csrf_token, 'Content-Type': 'application/x-www-form-urlencoded' },
  body: 'action=read',
});
```

---

## 3. Planned REST architecture (not yet live)

The public API for integrations (Business plan and above) is planned on
top of the same domain layer. Design targets:

### Versioning & base path

- Base path: `/api/v1/…`. The current unversioned `api/*.php` endpoints
  remain first-party session endpoints; breaking changes ship as a new
  version (`/api/v2/…`) with the old version kept for one release cycle.
- Resources are plural nouns: `GET /api/v1/patients`,
  `GET /api/v1/patients/{id}`, `POST /api/v1/appointments`,
  `PATCH /api/v1/appointments/{id}`, etc.

### Authentication (planned)

- Per-tenant API keys / OAuth 2.0 client credentials for server-to-server
  integrations, in addition to the session cookie for first-party calls.
  Keys are stored hashed, scoped to one tenant and a permission set, and
  revocable per tenant in Settings.

### Tenant scoping

- The tenant is resolved from the credential, **never** from a
  `tenant_id` parameter. Every resource query filters by that tenant; a
  resource ID from another tenant returns `404` (not `403`, to avoid
  leaking existence).
- The Super Admin API is a separate surface (`/api/v1/platform/…`) for
  tenants, plans, subscriptions, and payment verification.

### Error format (planned, RFC 7807-style)

```json
{
  "error": {
    "code": "validation_failed",
    "message": "appointment_time is required.",
    "fields": { "appointment_time": ["required"] }
  }
}
```

Stable machine-readable `code`s: `unauthenticated`, `forbidden`,
`not_found`, `validation_failed`, `plan_limit_reached`,
`tenant_suspended`, `rate_limited`, `server_error`.

### Conventions

- **Pagination:** `?page=` / `?per_page=` (default 25, max 100) with a
  `meta` block (`total`, `page`, `per_page`, `last_page`).
- **Filtering/sorting:** explicit query params only (e.g.
  `?status=confirmed&date=2026-11-06`, `?sort=-appointment_date`); sorting
  fields are allow-listed per resource.
- **Dates:** ISO 8601 (`YYYY-MM-DD`, datetimes in UTC with offset).
  Money is returned as decimal strings plus a `currency` field.
- **Rate limiting:** per-tenant request quotas tied to the subscription
  plan, `429` with `Retry-After` when exceeded.
- **Idempotency:** creation endpoints accept an `Idempotency-Key` header
  so retried appointment/billing writes cannot duplicate.
- **Payments rule carries over:** creating a payment via the API leaves it
  `pending`; only the platform verification flow can move it to
  `verified` (see `docs/SECURITY.md` §10).
