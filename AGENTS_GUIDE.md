# AGENTS_GUIDE.md (Backend)

Guidance for AI agents working on the **Backend_Computer** Laravel API. Read
this before changing any backend file. For the API consumer's view, see
`../Frontend_Computer/AGENTS_GUIDE.md`; for the repo-wide overview, see
`../AGENTS_GUIDE.md`.

---

## 1. What This Backend Is

A Laravel 10 REST API for the Sale_Computer e-commerce / computer-shop project.
It currently implements the **foundation**: roles, users, OTP verification,
categories, and brands. Products, inventory, carts, and orders exist only in the
reference SQL, not in Laravel yet.

It is a **standalone git repository** nested inside the project root. Check
`git status` before editing; there are already uncommitted changes on the single
`api` commit. Never commit unless explicitly asked.

---

## 2. Tech Stack

| Item | Value |
|------|-------|
| PHP | `^8.1` |
| Laravel | `^10.0` |
| Sanctum | `^3.2` (installed, **not enforced**) |
| Database | MySQL 8, `utf8mb4`, `utf8mb4_unicode_ci`, strict mode |
| Local DB | `sale_computer` at `127.0.0.1:3307`, user `root`, empty password |
| Tests | PHPUnit 10 |
| Code style | Laravel Pint |
| Dev extras | Tinker, Guzzle, Faker, Collision, Ignition, Sail |

Run `composer install` if `vendor/` is missing. `vendor/` is already present
locally and is gitignored.

---

## 3. Directory Map (application code only)

```
app/
├── Console/Kernel.php
├── Exceptions/Handler.php            JSON errors for /api paths
├── Http/
│   ├── Controllers/API/
│   │   ├── BrandController.php
│   │   ├── CategoryController.php
│   │   ├── OtpController.php
│   │   ├── RoleController.php
│   │   └── UserController.php
│   ├── Kernel.php                    Middleware groups + aliases
│   └── Middleware/
├── Models/
│   ├── Brand.php
│   ├── Category.php
│   ├── OtpVerification.php
│   ├── Role.php
│   └── User.php
├── Providers/
│   ├── AppServiceProvider.php
│   └── RouteServiceProvider.php      /api prefix + rate limiters
└── Service/
    └── OtpService.php                OTP logic (keep service logic here)

bootstrap/app.php
config/                               Laravel config (cors, sanctum, database, ...)
database/
├── migrations/                       Schema source of truth for Laravel
├── factories/UserFactory.php
└── seeders/DatabaseSeeder.php        No-op today
routes/
├── api.php                           All REST endpoints
├── web.php                           Only GET / -> welcome view
├── channels.php
└── console.php
tests/
├── Feature/CategoryApiTest.php
├── Feature/ExampleTest.php
├── Unit/ExampleTest.php
├── TestCase.php
└── CreatesApplication.php
```

There is **no** `app/Http/Requests/`, `app/Http/Resources/`, `app/Policies/`,
`app/Events/`, or `app/Jobs/` yet. Follow the existing simple style unless a
task justifies introducing these layers.

---

## 4. Request Lifecycle and Middleware

- `RouteServiceProvider` registers `routes/api.php` under the `api` prefix.
- The `api` middleware group = `throttle:api` (60 requests/minute by user id or
  IP) + `SubstituteBindings`. CSRF does **not** apply to API routes.
- `HandleCors` is global; `config/cors.php` allows `api/*` and
  `sanctum/csrf-cookie` from any origin, all methods/headers, with
  `supports_credentials: false`.
- `App\Exceptions\Handler::shouldReturnJson()` forces JSON errors for
  `api` and `api/*` even when the client omits `Accept: application/json`.
  Preserve this so validation errors stay JSON (`422`).
- Sanctum's `EnsureFrontendRequestsAreStateful` is commented out. There is no
  `auth:sanctum` on any route.

**No route is authenticated or authorized.** Adding auth changes every
consumer's contract. Do it only when asked, and apply it deliberately.

---

## 5. API Endpoints

All are defined in `routes/api.php` under `/api`.

| Method | Path | Action | Auth |
|--------|------|--------|------|
| GET | `/api/roles` | `RoleController@index` | none |
| POST | `/api/roles` | `RoleController@store` | none |
| GET | `/api/roles/{role}` | `RoleController@show` | none |
| PUT/PATCH | `/api/roles/{role}` | `RoleController@update` | none |
| DELETE | `/api/roles/{role}` | `RoleController@destroy` | none |
| GET | `/api/users` | `UserController@index` | none |
| POST | `/api/users` | `UserController@store` | none |
| GET | `/api/users/{user}` | `UserController@show` | none |
| PUT/PATCH | `/api/users/{user}` | `UserController@update` | none |
| DELETE | `/api/users/{user}` | `UserController@destroy` | none |
| POST | `/api/otp/send` | `OtpController@send` | none, `throttle:otp-send` |
| POST | `/api/otp/verify` | `OtpController@verify` | none, `throttle:otp-verify` |
| GET | `/api/categories` | `CategoryController@index` | none |
| POST | `/api/categories` | `CategoryController@store` | none |
| GET | `/api/categories/{category}` | `CategoryController@show` | none |
| PUT/PATCH | `/api/categories/{category}` | `CategoryController@update` | none |
| DELETE | `/api/categories/{category}` | `CategoryController@destroy` | none |
| GET | `/api/brands` | `BrandController@index` | none |
| POST | `/api/brands` | `BrandController@store` | none (BROKEN, empty) |
| GET | `/api/brands/{brand}` | `BrandController@show` | none |
| PUT/PATCH | `/api/brands/{brand}` | `BrandController@update` | none (BROKEN) |
| DELETE | `/api/brands/{brand}` | `BrandController@destroy` | none |

`OtpController@show` exists but is **not routed**; it exposes OTP metadata and
its comment says to add auth before using it.

---

## 6. Response Conventions

Success envelope (keep it consistent):

```json
{
  "success": true,
  "message": "Categories retrieved successfully",
  "data": {}
}
```

- Deletes return `success` + `message` only.
- Validation failures are Laravel-native: HTTP `422` with
  `{ "message": "...", "errors": { "field": ["..."] } }`.
- Not found via `findOrFail` → `404`.
- OTP endpoints return `202 Accepted` for successful send (including the generic
  privacy-preserving response), `503` when mail delivery fails on non-sensitive
  flows, `429` when rate limited.
- Paginated index endpoints embed a Laravel paginator in `data`.

Conventions per controller:

- **Roles**: `index` returns all; validation `name` required/unique
  (update ignores own id), `description` nullable. Manual `404` JSON checks.
- **Users**: `index` returns all. Store requires `name`, unique `email`,
  optional unique `phone`, `password` min 8, `status` in `active|inactive`.
  Update uses `sometimes`; unique rules ignore the current id. Errors via manual
  `404` JSON. `User::$hidden` keeps `password`, `remember_token`, `google_id`,
  `facebook_id` out of JSON. The `password` cast is `hashed`, so pass plain text.
- **Categories**: `index` eager-loads `parent` + `children`, paginates 10,
  filters `status`, `search` (name LIKE), `root_only` (parent_id null).
  Store requires `name`, `slug` (unique), `status` (`active|inactive`), optional
  `parent_id` (must exist), `description`, `image`. Update uses `sometimes` and
  ignores own slug.
- **Brands**: `index` paginates 10, filters `search` on name and `status`.
  `show` uses `findOrFail`. `destroy` returns JSON. **`store` is empty and
  `update` wrongly calls `Brand::create`** — see Known Issues.

---

## 7. Models and Schema

| Model | Table | Fillable | Relations |
|-------|-------|----------|-----------|
| `Role` | `roles` | `name`, `description` | none defined |
| `User` | `users` | `role_id`, `name`, `email`, `phone`, `password`, `google_id`, `facebook_id`, `email_verified_at`, `phone_verified_at`, `status` | `role()` belongsTo |
| `Category` | `categories` | `parent_id`, `name`, `slug`, `description`, `image`, `status` | `parent()` belongsTo self, `children()` hasMany self |
| `Brand` | `brands` | `name`, `slug`, `logo_url`, `description`, `status` | none |
| `OtpVerification` | `otp_verifications` | `user_id`, `destination`, `purpose`, `code_hash`, `expires_at`, `verified_at`, `attempts` | `user()` belongsTo |

Notes:
- `User` extends `Authenticatable`, uses `HasApiTokens`, `HasFactory`,
  `Notifiable`. Casts: dates to datetime, `password` => `hashed`.
- `OtpVerification` sets `$timestamps = false` (table has only `created_at`),
  hides `code_hash`, casts `attempts` to int and dates to datetime.
- `Category::$casts` sets `status` to string (currently redundant).

Migrations applied (Laravel defaults + project):
- `2014_10_12_000000_create_users_table`
- `2014_10_12_100000_create_password_reset_tokens_table`
- `2019_08_19_000000_create_failed_jobs_table`
- `2019_12_14_000001_create_personal_access_tokens_table`
- `2026_10_22_073430_create_roles_table`
- `2026_10_22_100000_add_role_id_and_status_to_users_table`
- `2026_10_22_110000_add_user_profile_fields_to_users_table`
- `2026_10_22_120000_create_otp_verifications_table`
- `2026_10_22_141341_create_categories_table`
- `2026_10_22_154117_create_brands_table`

Constraints worth knowing:
- `users.role_id` nullable FK → `roles.id`, `nullOnDelete`.
- `users.phone`, `google_id`, `facebook_id` unique + nullable.
- `otp_verifications.user_id` FK → `users.id`, `cascadeOnDelete`;
  indexes on `(destination, purpose)` and `expires_at`.
- `categories.parent_id` self FK, `cascadeOnUpdate` + `nullOnDelete`;
  `slug` unique; `status` enum; index `idx_categories_status`.
- `brands.name` and `brands.slug` unique; `status` enum.

---

## 8. OTP Flow (security-sensitive)

`App\Service\OtpService` owns all OTP logic. Keep it there; do not duplicate in
controllers.

- Code: `random_int(100000, 999999)` → 6 digits, cryptographically secure.
- Storage: only `Hash::make($code)` in `code_hash`. **Never store/log plaintext.**
- TTL: 5 minutes (`expires_at = now()->addMinutes(5)`).
- Attempts: max 5 per challenge.
- Creating a new challenge deletes previous **unverified** OTPs with the same
  destination + purpose, and prunes expired rows older than 1 day.
- Verify runs in a DB transaction with `lockForUpdate()`; increments `attempts`
  and persists failures before throwing `ValidationException`. On success sets
  `verified_at`.
- `discard()` deletes an undelivered OTP.
- Email is sent via `Mail::raw()` from `OtpController@send` (subject
  "Your verification code").
- Purposes: `registration`, `login`, `password_reset`, `change_contact`.
- `login` and `password_reset` never reveal whether the account exists
  (generic `202` message), and also return the generic response if mail fails.
- Rate limiters in `RouteServiceProvider`:

| Limiter | Limits |
|---------|--------|
| `api` | 60/min per user id or IP |
| `otp-send` | 3/min per IP + 5/hour per destination |
| `otp-verify` | 10/min per IP + 10/hour per destination |

Mail config is real SMTP in local `.env` (Gmail). **Never print, copy, or commit
`.env` secrets.** `.env` is gitignored.

---

## 9. Known Issues / Landmines

Fix only when the task calls for it, but never copy these patterns:

1. **`BrandController@store` is an empty stub** — it returns no response.
2. **`BrandController@update` creates a new brand** (`Brand::create`) instead of
   updating `$id`; unique validation does not ignore the current record.
3. **Brand slug uniqueness handling** in `update()` can still collide; unify
   slug generation for store/update.
4. **Category `image` vs SQL `image_url`**: Laravel uses `image`; the reference
   SQL uses `image_url`.
5. **User status mismatch**: Laravel accepts any string (default `active`);
   reference SQL is `ENUM('active','inactive','suspended')`.
6. **No authentication/authorization anywhere**, despite Sanctum being installed
   and `personal_access_tokens` being migrated.
7. **`OtpController@show` is unrouted and unauthenticated** — do not expose it
   as-is.
8. **`DatabaseSeeder` is empty** (no roles/admin/bootstrap data). A fresh DB has
   no roles, so `users.role_id` will be null.
9. **Only the foundation tables are migrated.** Products, images, variants,
   warehouses, inventory, addresses, carts, orders, payments, shipments,
   coupons, reviews, audit logs exist only in `../Database/`.
10. **`OtpVerification` has no `updated_at`**; keep `$timestamps = false` or
    inserts will fail.
11. **Tests are not isolated to SQLite** (`phpunit.xml` leaves DB on MySQL), and
    feature tests do not use `RefreshDatabase`. Be careful running destructive
    tests against the local DB.

---

## 10. Database Sources

Three related sources, in priority order for the running app:

1. `Backend_Computer/database/migrations/` — what Laravel actually runs.
   **Source of truth for the app.**
2. `../Database/codesql.sql` — full target schema (catalog, inventory, orders,
   payments, reviews, audit). Not fully migrated.
3. `../Database/migration/V1..V5_*.sql` — hand-written incremental scripts.

When changing a shared table, update the Laravel migration **and** keep the
reference SQL in sync. Do not manually edit the DB as the only change.

Local connection (`.env`): MySQL, `127.0.0.1:3307`, database `sale_computer`,
user `root`, empty password. `DB_CONNECTION` defaults to `mysql`, strict mode on.

---

## 11. Coding Standards (match existing code)

- PSR-4 autoloading: `App\` → `app/`, `Database\Factories\` and
  `Database\Seeders\` mapped in `composer.json`.
- 4-space indentation in PHP; run `./vendor/bin/pint` before finishing.
- Controllers: thin, inline `$request->validate([...])`, return
  `response()->json([...], $status)`. No FormRequests/Resources yet.
- Complex or reusable business logic → a class under `app/Service/`
  (follow `OtpService`). Do not bloat controllers.
- Use Eloquent relationships already defined; eager-load (`with`) to avoid N+1.
- Prefer `findOrFail` for new code; existing manual `404` JSON is acceptable but
  stay internally consistent per controller.
- Keep secrets out of code; read from `config()` / `env()` only.
- Do not add comments unless requested.
- Use `$table->...` migrations; never edit an already-run migration on a shared
  branch — add a new one.

---

## 12. Testing

- PHPUnit 10; config in `phpunit.xml`.
- Test env: `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, array cache/session,
  `MAIL_MAILER=array`, sync queue. SQLite is **commented out**.
- `tests/Feature/CategoryApiTest.php` verifies category validation returns JSON
  `422` without an `Accept` header (relies on `Handler::shouldReturnJson`).
- Add tests for any new/changed endpoint, especially validation, 404, and
  envelope shape. Use `RefreshDatabase` for DB-touching tests and note that it
  will run against MySQL as configured.
- Run `php artisan test` after backend changes.

---

## 13. Commands

```powershell
# inside Backend_Computer/
composer install
php artisan serve                    # http://127.0.0.1:8000
php artisan migrate                  # apply pending migrations
php artisan migrate:fresh --seed     # DESTRUCTIVE: reset schema (seeder is empty)
php artisan test                     # PHPUnit
php artisan tinker
./vendor/bin/pint                    # code style (fix mode by default)
php artisan route:list               # inspect API routes
```

Frontend dev server (separate app): `http://localhost:5173`.

---

## 14. Definition of Done for Backend Changes

- Endpoint follows the `{ success, message, data }` envelope and correct status
  codes (`200`, `201`, `202`, `404`, `422`, `429`, `503`).
- Validation covers required fields, uniqueness (ignoring own id on update),
  foreign keys, and enums.
- Migration added for any schema change (do not edit applied migrations).
- Feature test added/updated for the behavior.
- `php artisan test` and `./vendor/bin/pint` pass.
- No secrets, no plaintext OTP, no auth changes made silently.
- Scope limited to the requested feature; do not refactor unrelated code.

---

## 15. Suggested Next Steps (context only)

1. Fix `BrandController@store` / `@update` and add brand tests.
2. Add authentication (Sanctum token or session) + role-based authorization.
3. Seed roles, categories, brands, and an admin user.
4. Migrate remaining target schema (products → inventory → cart → orders →
   payments) from `../Database/codesql.sql` into Laravel migrations.
5. Add products/catalog endpoints and align category `image` with `image_url`
   or document the divergence.
