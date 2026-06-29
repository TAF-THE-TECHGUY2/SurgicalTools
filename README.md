# Surgical Devices ERP

A production-grade ERP for a medical-device company, digitising inventory
movements, two-stage stock transfers, digital signatures, stock counts,
hospital/doctor management, approvals, notifications, reporting and Pastel
export — on desktop and mobile, **online or offline**.

- **Backend:** Laravel 13 REST API · PostgreSQL · Sanctum (bearer tokens) · RBAC
- **Frontend:** React 19 + TypeScript + Tailwind v4 · React Query · PWA (offline)
- **Storage:** local disk in dev, S3-compatible in production (PDFs / signatures / photos)
- **Email:** Mailpit in dev; SMTP/SES in production

---

## Repository layout

```
surgicaltool/
├── backend/            Laravel 13 API
│   ├── app/
│   │   ├── Enums/            Domain vocabularies (stock types, statuses, …)
│   │   ├── Models/           Eloquent models + relationships
│   │   ├── Policies/         RBAC authorization (hospital-scoped approvals)
│   │   ├── Services/         Business logic (Transfer, Inventory, Pdf, Pastel…)
│   │   ├── Http/Controllers/Api/   REST controllers
│   │   ├── Http/Resources/   JSON transformers
│   │   ├── Notifications/    In-app + email notifications
│   │   ├── Mail/             PDF-attachment mailables
│   │   └── Console/Commands/ Daily expiry + low-stock checks
│   ├── database/migrations/  Full schema
│   ├── database/seeders/     Roles/permissions + demo data
│   ├── routes/api.php        API routes
│   └── tests/Feature/        Workflow + API tests
├── frontend/           React PWA
│   └── src/
│       ├── auth/             Auth context, protected routes, <Can> gating
│       ├── components/       UI kit, layout, GlobalSearch, SignaturePad
│       ├── offline/          Dexie queue + sync logic
│       ├── pages/            All screens
│       ├── hooks/            useMeta, useOnlineStatus
│       └── lib/              axios client, formatters
└── docker-compose.yml  Full stack: db · mailpit · backend · frontend
```

---

## Quick start

### Option A — Docker (whole stack, one command)

```bash
docker compose up --build
```

- App (PWA):           http://localhost:8080
- API:                 http://localhost:8000/api
- Mail inbox (Mailpit): http://localhost:8025

The backend container migrates and seeds demo data automatically on first boot
(`SEED_ON_BOOT=true`).

### Option B — Run locally

**Backend** (PHP 8.2+, Composer; PostgreSQL optional — SQLite works out of the box):

```bash
cd backend
cp .env.example .env          # or keep the committed .env (SQLite default)
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve              # http://localhost:8000
```

For PostgreSQL, set `DB_CONNECTION=pgsql` and the `DB_*` vars in `.env`, then
`php artisan migrate:fresh --seed`.

**Frontend** (Node 20+):

```bash
cd frontend
npm install
npm run dev                    # http://localhost:5173 (proxies /api → :8000)
```

### Demo accounts (password: `password`)

| Role          | Email                 | Notes                                  |
| ------------- | --------------------- | -------------------------------------- |
| Super Admin   | super@surgical.test   | Full access                            |
| Admin         | admin@surgical.test   | Approve anything, manage master data   |
| General (rep) | mike@surgical.test    | Assigned to Arwyp — scoped approvals   |
| General (rep) | lerato@surgical.test  | Assigned to Milpark / St Augustine's   |
| General (runner) | runner@surgical.test | Runner at Arwyp                     |

---

## Feature → implementation map

| Module | Where |
| ------ | ----- |
| Inventory management + movement ledger | `InventoryItem`, `StockMovement`, `InventoryService` |
| Global search | `GlobalSearchController` → `/api/search` |
| Transfer 1 (Source → Boot) | `TransferService::createSourceToBoot` + workflow |
| Transfer 2 (Boot → Hospital) | `TransferService::createBootToHospital` + admin review |
| Digital signatures | `SignaturePad` (React) → `transfer_signatures` + embedded in PDFs |
| PDF generation | `PdfService` (DomPDF) → stored as `documents`, emailed |
| Stock counts + variance | `StockCount(Item)`, `StockCountService` |
| Hospitals / contacts / rep assignment | `Hospital`, `hospital_user` pivot |
| Doctors + preference cards (printable) | `Doctor`, `PreferenceCard`, `/print` |
| Admin Approval Centre | `ApprovalCentreController` queues |
| Notifications (in-app + email) | `NotificationService`, database notifications |
| Expiry alerts (90/60/30) | `surgical:check-expiring-stock` (scheduled daily) |
| Low-stock alerts | `surgical:check-low-stock` (scheduled daily) |
| Offline sync | Dexie queue → `POST /api/sync/push` (idempotent) |
| Pastel export (CSV) | `PastelExportService` → `/api/pastel-exports` |
| Audit trail | `spatie/laravel-activitylog` on key models → `/api/audit-logs` |
| RBAC | `spatie/laravel-permission` + Policies |

---

## Key workflows

**Transfer 1 — Source → Boot**
`draft → pending_approval → awaiting_signature → signed → completed`
On completion: stock moves into the rep's boot, a **transfer note PDF** is
generated, stored, and emailed to office / stock controller / rep.

**Transfer 2 — Boot → Hospital**
`draft → pending_approval → awaiting_signature → signed → awaiting_admin_review → completed`
The hospital stock controller signs → a **delivery note PDF** is generated and
emailed → an admin reviews → stock is posted to hospital inventory and the audit
trail is written.

**Authorization rule (enforced in `TransferPolicy`):**
- General users may approve a Transfer 2 **only for hospitals assigned to their
  login account**, or a Transfer 1 only when they own the source stock.
- Admins may approve/override any transfer. Super Admins bypass all gates.

---

## Offline strategy (PWA)

The app is installable and works offline. Reads are network-first; **writes
captured offline** (new transfers, signatures, stock counts) are queued in
IndexedDB (Dexie) with a client-generated `client_id`. On reconnect the queue is
replayed to `POST /api/sync/push`, which is **idempotent** — replaying an
already-synced operation returns the existing record instead of duplicating it.

---

## Testing

```bash
cd backend && php artisan test
```

Feature tests cover the full transfer workflow (stock movement + PDF), the
hospital-scoped approval policy, Transfer 2's admin-review gate, and the auth /
RBAC API surface.

```bash
cd frontend && npm run build      # type-checks (tsc -b) and bundles
```

---

## Production deployment

Recommended target: a VPS (Laravel Forge), Docker, or cloud (ECS/Fargate, App
Platform, etc.).

**Backend**
- Run PHP-FPM behind nginx (not `artisan serve`); set `APP_ENV=production`,
  `APP_DEBUG=false`, a strong `APP_KEY`.
- PostgreSQL (managed, e.g. RDS/Cloud SQL).
- `FILESYSTEM_DISK=s3` with bucket credentials for documents/signatures/photos.
- Queue worker for emails/notifications: `php artisan queue:work` (supervisor).
- Cron for scheduled alerts: `* * * * * php artisan schedule:run`.
- `php artisan config:cache route:cache` on deploy.

**Frontend**
- `npm run build` → static `dist/` served by nginx/CDN (Dockerfile included).
- Point `/api` and `/storage` at the backend (see `frontend/docker/nginx.conf`),
  or set `VITE_API_URL` to the API origin at build time.

**Scaling**
- Stateless API behind a load balancer; Redis for cache/session/queue at scale;
  read replicas for reporting; object storage + CDN for documents.

---

## Security architecture

- **AuthN:** Sanctum personal-access tokens (Bearer); 401s auto-redirect to login.
- **AuthZ:** RBAC via `spatie/laravel-permission`; per-resource Policies; the
  critical hospital-scoped approval rule is enforced server-side (the UI only
  hints at availability).
- **Audit:** every change to users, hospitals, doctors, inventory, transfers and
  counts is logged immutably (activitylog) and exposed to Super Admins.
- **Validation:** Form Requests / `$request->validate()` on every write; enum
  values constrained via `Rule::in(...)`.
- **Files:** documents live on a **private** disk and are streamed through
  authenticated download endpoints (no public URLs).
- **CORS:** locked to the configured `FRONTEND_URL`.
- **Transport:** terminate TLS at the edge; set `SESSION_SECURE_COOKIE=true` and
  HSTS in production.

---

## Roadmap

**MVP (built):** auth/RBAC, inventory + movement ledger, Transfer 1 & 2 with
signatures + PDFs + email, stock counts + variance, hospitals/doctors/preference
cards, approval centre, notifications, expiry/low-stock alerts, reports, Pastel
CSV export, audit trail, offline sync, PWA.

**Next phases:**
1. Barcode/QR scanning on mobile for stock picking and counts.
2. OCR for photo-captured stock counts (auto-fill counted quantities).
3. Consignment case tracking (scan products used in surgery → deduct → export).
4. Real-time push (WebSockets) for approvals and alerts.
5. Two-way Pastel/accounting integration; BI dashboards.
6. SSO (Azure AD) and granular field-level audit diffing.
