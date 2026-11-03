# AGENTS_GUIDE.md (Backend)

Guidance for AI agents working on the **Backend_Computer** Laravel API. Read
this before changing any backend file. For the API consumer's view, see
`../Frontend_Computer/AGENTS_GUIDE.md`; for the repo-wide overview, see
`../AGENTS_GUIDE.md` (if present).

---

## 1. What This Backend Is

A Laravel 10 REST API for the Sale_Computer e-commerce / computer-shop project.
It implements:

- **Auth**: register, login, logout, current user, OTP verification, password
  reset (OTP-gated), and Google OAuth via Socialite. Sanctum personal access
  tokens are issued on login/Google.
- **Foundation**: roles, users.
- **Catalog**: categories, brands, products, product images, product variants.
- **Stock**: warehouses and inventories.
- **Cart**: authenticated users can create/read/clear a cart and add, update,
  or remove cart items.

Still only in the reference SQL (not in Laravel): orders, order items,
payments, shipments, coupons, reviews, audit logs, and admin
reporting/dashboard aggregates.

It is a **standalone git repository** nested inside the project root. Check
`git status` before editing; never commit unless explicitly asked.

---

## 2. Tech Stack

| Item | Value |
|------|-------|
| PHP | `^8.1` |
| Laravel | `^10.0` |
| Sanctum | `^3.3` (tokens enforced on `/user` and `/logout`) |
| Socialite | `^5.31` (Google OAuth) |
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
│   │   ├── AuthController.php        register, login, logout, user, reset
│   │   ├── GoogleAuthController.php  Google redirect + callback
│   │   ├── BrandController.php
│   │   ├── CategoryController.php
│   │   ├── InventoryController.php
│   │   ├── OtpController.php
│   │   ├── ProductController.php
│   │   ├── ProductImageController.php
│   │   ├── ProductVariantController.php
│   │   ├── RoleController.php
│   │   ├── UserController.php
│   │   └── WarehouseController.php
│   ├── Kernel.php                    Middleware groups + aliases
│   └── Middleware/
├── Models/
│   ├── Brand.php
│   ├── Category.php
│   ├── Inventory.php
│   ├── OtpVerification.php
│   ├── Product.php
│   ├── ProductImage.php
│   ├── ProductVariant.php
│   ├── Role.php
│   ├── User.php
│   └── Warehouse.php
├── Providers/
│   ├── AppServiceProvider.php
│   └── RouteServiceProvider.php      /api prefix + rate limiters
└── Service/
    └── OtpService.php                OTP logic (keep service logic here)

bootstrap/app.php
config/                               Laravel config (cors, sanctum, services, ...)
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
- Sanctum's `EnsureFrontendRequestsAreStateful` is commented out. Tokens are
  Bearer tokens, not SPA cookie auth.
- **Only `/api/logout` and `/api/user` use `auth:sanctum`.**
  `routes/api.php` also applies `throttle:6,1` to register/login/password reset.
- Google OAuth routes (`/api/auth/google*`) are wrapped in the **`web`**
  middleware group so the OAuth `state` value can use the session.
- **All CRUD routes for roles, users, categories, brands, products, images,
  variants, warehouses, and inventories are unauthenticated and lack admin
  authorization.** Adding auth changes every consumer's contract. Do it only
  when asked, and apply it deliberately (route groups + role checks/policies).

---

## 5. API Endpoints

All are defined in `routes/api.php` under `/api`.

### Auth

| Method | Path | Action | Middleware |
|--------|------|--------|------------|
| POST | `/api/register` | `AuthController@register` | `throttle:6,1` |
| POST | `/api/login` | `AuthController@login` | `throttle:6,1` |
| POST | `/api/logout` | `AuthController@logout` | `auth:sanctum` |
| GET | `/api/user` | `AuthController@user` | `auth:sanctum` |
| POST | `/api/password/reset` | `AuthController@resetPassword` | `throttle:6,1` |
| GET | `/api/auth/google` | `GoogleAuthController@redirect` | `web` |
| GET | `/api/auth/google/callback` | `GoogleAuthController@callback` | `web` |

### OTP

| Method | Path | Action | Middleware |
|--------|------|--------|------------|
| POST | `/api/otp/send` | `OtpController@send` | `throttle:otp-send` |
| POST | `/api/otp/verify` | `OtpController@verify` | `throttle:otp-verify` |

`OtpController@show` exists but is **not routed**; it exposes OTP metadata and
says to add auth/admin authorization first.

### Resources (all unauthenticated)

| Method | Path | Controller |
|--------|------|------------|
| GET/POST/GET/PUT/PATCH/DELETE | `/api/roles` | `RoleController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/users` | `UserController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/categories` | `CategoryController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/brands` | `BrandController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/products` | `ProductController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/products/{product}/images` | `ProductImageController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/products/{product}/variants` | `ProductVariantController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/warehouses` | `WarehouseController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/inventories` | `InventoryController` |

Authenticated cart routes are `GET/POST/DELETE /api/cart`, `POST
/api/cart/items`, `PATCH /api/cart/items/{item}`, and `DELETE
/api/cart/items/{item}`.

Note: `/api/products/{product}/images` and `/variants` are `apiResource`
routes, so item routes are `/images/{image}` and `/variants/{variant}`. The
controller methods scope each record to the parent product.

Frontend services expect `/user` (PUT), `/user/password`, `/user/addresses`,
`/orders*` and `/admin/*`; those do not exist. Cart routes are implemented
under Sanctum authentication.

---

## 6. Response Conventions

Success envelope (keep it consistent):

```json
{
  "success": true,
  "message": "Products retrieved successfully.",
  "data": {}
}
```

- Creates return `201`; updates/reads `200`; OTP send `202`; deletes return
  `success` + `message` only.
- Validation failures are Laravel-native: HTTP `422` with
  `{ "message": "...", "errors": { "field": ["..."] } }`.
- Not found via `findOrFail` → `404`; roles/users use manual JSON `404`.
- Inventory create can return `409` with
  `{ "success": false, "message": "Inventory record already exists..." }`.
- OTP: `202` on send (including generic privacy response), `429` rate limited,
  `503` when non-sensitive mail delivery fails.
- Google OAuth endpoints do not return JSON: they redirect to Google or to
  `FRONTEND_URL/auth/google/callback#token=...` / `#error=...`.
- Paginated index endpoints embed a Laravel paginator in `data`.

Conventions per controller:

- **Roles**: `index` returns all. `name` required/unique (update ignores own id),
  `description` nullable. Manual `404` JSON.
- **Users**: `index` returns all. Store requires `name`, unique `email`,
  optional unique `phone`, `password` min 8, `status` in `active|inactive`.
  Update uses `sometimes`; unique rules ignore the current id. Manual `404`
  JSON. `User::$hidden` hides `password`, `remember_token`, `google_id`,
  `facebook_id` (but **not** `avatar`). The `password` cast is `hashed`, so pass
  plain text.
- **Categories**: `index` eager-loads `parent` + `children`, paginates 10,
  filters `status`, `search` (name LIKE), `root_only` (parent_id null).
  Store requires `name`, `slug` (unique), `status` (`active|inactive`), optional
  `parent_id` (must exist), `description`, `image`. Update uses `sometimes` and
  ignores own slug.
- **Brands**: `index` paginates 10, filters `search` on name and `status`.
  Store/update generate a unique slug (with `-n` suffix) when missing; update
  ignores own name/slug. `store`/`update` are fixed (no longer stubs).
- **Products**: see §7.1.
- **Product images / variants**: see §7.2.
- **Warehouses**: paginated 10, `search` on name/code, `status` filter; `code`
  unique (update ignores own id); `status` `active|inactive`.
- **Inventories**: see §7.3.
- **Auth**: see §8.

---

## 7. Models and Schema

| Model | Table | Fillable | Relations |
|-------|-------|----------|-----------|
| `Role` | `roles` | `name`, `description` | none defined |
| `User` | `users` | `role_id`, `name`, `email`, `phone`, `password`, `google_id`, `avatar`, `facebook_id`, `email_verified_at`, `phone_verified_at`, `status`, `branch_id` | `role()` belongsTo |
| `Category` | `categories` | `parent_id`, `name`, `slug`, `description`, `image`, `status` | `parent()` belongsTo self, `children()` hasMany self |
| `Brand` | `brands` | `name`, `slug`, `logo_url`, `description`, `status` | none |
| `OtpVerification` | `otp_verifications` | `user_id`, `destination`, `purpose`, `code_hash`, `expires_at`, `verified_at`, `attempts` | `user()` belongsTo |
| `Product` | `products` | `category_id`, `brand_id`, `name`, `slug`, `sku`, `short_description`, `description`, `product_type`, `price`, `compare_at_price`, `cost_price`, `currency`, `warranty_months`, `specifications`, `track_inventory`, `allow_backorder`, `status` | `category()`, `brand()`, `images()` hasMany, `variants()` hasMany |
| `ProductImage` | `product_images` | `product_id`, `image_url`, `cloudinary_public_id`, `alt_text`, `is_primary`, `sort_order` | `product()` |
| `ProductVariant` | `product_variants` | `product_id`, `sku`, `name`, `attributes`, `price`, `cost_price`, `status` | `product()` |
| `Warehouse` | `warehouses` | `name`, `code`, `address`, `phone`, `status` | none defined |
| `Inventory` | `inventories` | `warehouse_id`, `product_id`, `variant_id`, `quantity`, `reserved_quantity`, `reorder_level` | `warehouse()`, `product()`, `variant()` |

Notes:

- `User` extends `Authenticatable`, uses `HasApiTokens`, `HasFactory`,
  `Notifiable`. Casts: dates to datetime, `password` => `hashed`.
- `Product` casts prices to `decimal:2`, `specifications` to array,
  `track_inventory`/`allow_backorder` to bool.
- `ProductImage` casts `is_primary` bool, `sort_order` int.
- `ProductVariant` casts `attributes` array, prices decimal.
- `Inventory` sets `$timestamps = false` (table has only `updated_at`),
  casts ints, casts `updated_at` to datetime. It exposes
  `available_quantity` and `is_low_stock` accessors, but they are **not** in
  `$appends`, so they are absent from JSON by default.
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
- `2026_10_24_075741_create_products_table`
- `2026_10_24_081654_create_product_images_table`
- `2026_10_24_082827_create_product_variants_table`
- `2026_10_24_083837_create_warehouses_table`
- `2026_10_24_084957_create_inventories_table`
- `2026_11_02_031728_add_google_fields_to_users_table` (guarded `google_id`,
  `avatar`; `google_id` is also created by the earlier profile migration)

Constraints worth knowing:

- `users.role_id` nullable FK → `roles.id`, `nullOnDelete`.
- `users.phone`, `google_id`, `facebook_id` unique + nullable.
- `otp_verifications.user_id` FK → `users.id`, `cascadeOnDelete`;
  indexes on `(destination, purpose)` and `expires_at`.
- `categories.parent_id` self FK, `cascadeOnUpdate` + `nullOnDelete`;
  `slug` unique; `status` enum; index `idx_categories_status`.
- `brands.name` and `brands.slug` unique; `status` enum.
- `products.category_id` FK `restrictOnDelete`; `brand_id` nullable
  `nullOnDelete`; `slug`/`sku` unique; `status` enum
  `draft|active|inactive`; `product_type` enum; indexes on
  `(category_id,status)`, `(brand_id,status)`, `(product_type,status)`, `name`;
  fulltext index `ft_products_search` (search code uses `LIKE`, not MATCH).
- `product_images.product_id` FK cascade delete/update; index
  `(product_id, sort_order)`.
- `product_variants.product_id` FK cascade delete/update; `sku` unique; index
  `(product_id,status)`.
- `warehouses.code` unique; `status` enum.
- `inventories` FKs: warehouse/product `restrictOnDelete`, variant nullable
  `cascadeOnDelete`; unique `(warehouse_id, product_id, variant_id)`; indexes
  on `product_id` and `(quantity, reorder_level)`.

### 7.1 Products

- `index` eager-loads `category`, `brand`, and `images` (primary first, then
  `sort_order`), filters `search` (name or sku LIKE), `category_id`,
  `brand_id`, `product_type`, `status`, and sorts by `price_asc`,
  `price_desc`, or default newest (`id desc`). Paginates 10 with query string.
- `store` requires `category_id` (exists), `name`, `sku` (unique), `price`;
  optional `brand_id`, `slug`, descriptions, `product_type`, prices,
  `currency`, `warranty_months`, `specifications` array,
  `track_inventory`, `allow_backorder`, `status` (`draft|active|inactive`).
  Generates a unique slug when absent. Returns `201`.
- `show` loads only `category` + `brand` (not images or variants).
- `update` uses `sometimes`; slug/sku uniqueness ignores own id; regenerates
  the slug when `name` changes without an explicit `slug`.
- `destroy` deletes the product (images/variants cascade).
- **Product responses have no `stock`/quantity field.** Stock lives in
  `inventories`. The frontend checks `product.stock`, so availability checks
  cannot rely on it until the API adds an aggregate.

### 7.2 Product images and variants

- Images `index` returns a plain array (not paginated). `store` requires
  `image_url` (valid URL), optional `cloudinary_public_id`, `alt_text`,
  `is_primary`, `sort_order`. The first image becomes primary automatically;
  setting a primary demotes the others. No file upload exists — clients must
  send a hosted URL.
- Image `show`/`update`/`destroy` are scoped to the parent product and return
  `404` via `firstOrFail` when the image does not belong to it.
- Variants `index` paginates 10 (newest first). `store` requires unique `sku`,
  `name`, `price`; optional `attributes` array, `cost_price`, `status`
  (`active|inactive`). Variant `update`/`destroy`/`show` are scoped to the
  product; sku uniqueness ignores own id.

### 7.3 Inventories

- `index` filters `warehouse_id`, `product_id`, `variant_id`, `low_stock`
  (`quantity <= reorder_level`), and `per_page` (1–100, default 10). Eager-loads
  trimmed `warehouse`, `product`, and `variant` records.
- `store` requires `warehouse_id`, `product_id`; validates `variant_id`
  belongs to the product; rejects `reserved_quantity > quantity`; returns
  `409` when the warehouse/product/variant combination already exists.
- `update` accepts `quantity`, `reserved_quantity`, `reorder_level` and applies
  the same reserved/quantity check.
- **MySQL unique indexes treat `NULL` variant ids as distinct**, so the DB
  constraint does not prevent duplicate rows with `variant_id = NULL`; the
  controller's manual existence check is what enforces it there.
- There is no `created_at`.

---

## 8. Auth, OTP, and Google Flow (security-sensitive)

### 8.1 Token auth

- `POST /register` lowercases/trims email, validates unique email and phone,
  auto-creates a `customer` role if missing, and creates an `active` user.
  It returns `201` **without** a token.
- `POST /login` rejects inactive users and bad credentials with a `422` generic
  credentials error. On success it issues a Sanctum token
  (`ComputerShop frontend`), expiring in **12 hours** unless `remember` is true
  (then `expires_at` is null/non-expiring). Returns `data.token`,
  `token_type: "Bearer"`, `expires_at`, and `user` (with `role`).
- `GET /user` returns the authenticated user with `role`.
- `POST /logout` deletes the current access token.
- Use `Authorization: Bearer <token>` from the frontend.

### 8.2 Password reset

`POST /password/reset` requires `email`, `password` (min 8), and
`password_confirmation`. It **consumes a previously verified OTP** for
`purpose=password_reset` via `OtpService::consumeVerified()` and deletes all of
the user's tokens. The client flow is: `otp/send` → `otp/verify` → password
reset.

### 8.3 Google OAuth

- `/api/auth/google` redirects to Google (or to the frontend with
  `#error=google_auth_unavailable` when `GOOGLE_CLIENT_ID/SECRET` are missing).
- Callback requires a verified Google email, finds/links by `google_id` or
  email, creates a `customer` user when new, rejects inactive users, issues a
  12-hour token, and redirects to
  `FRONTEND_URL/auth/google/callback#token=...` (or `#error=...`).
- Config lives in `config/services.php` (`GOOGLE_CLIENT_ID`,
  `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`) and `FRONTEND_URL`.
- **`FRONTEND_URL` must match the SPA origin** or the callback redirect breaks.

### 8.4 OTP internals

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
- `consumeVerified()` requires a verified, unexpired OTP that has a `user_id`,
  deletes all OTPs for that destination/purpose, and returns the user id.
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
`.env` secrets.** `.env` is gitignored. Note that `.env.example` currently
contains Google OAuth values — treat them as sensitive, rotate if they are real,
and replace them with placeholders rather than adding more secrets.

---

## 9. Known Issues / Landmines

Fix only when the task calls for it, but never copy these patterns:

1. **No authorization on CRUD.** Roles, users, catalog, and inventory routes are
   public, and there is no admin middleware/policy. Only `/user` and `/logout`
   are authenticated. Do not claim admin security.
2. **User CRUD is especially dangerous** when public: anyone can list users or
   create/delete accounts until auth + authorization is applied.
3. **`User::$fillable` includes `branch_id`, but no migration adds that
   column.** Passing it to `User::create`/`update` will fail at the DB level.
4. **Product responses omit stock.** `product.stock` is used by the frontend but
   not returned; availability must come from `inventories`.
5. **Product `show` does not load images/variants**, and `store`/`update` load
   only category/brand. Clients fetch images/variants separately.
6. **Images are URL-only** (`image_url` required, must be a valid URL). There is
   no upload/Cloudinary integration; `cloudinary_public_id` is just metadata.
7. **Inventory has no `created_at`** and Eager-loaded variants are trimmed to
   `id, product_id, name, sku`.
8. **Inventory nullable-variant uniqueness** is only enforced in the controller;
   MySQL allows multiple `NULL` variant rows.
9. **Category `image` vs SQL `image_url`**: Laravel uses `image`; the reference
   SQL uses `image_url`.
10. **User status mismatch**: Laravel accepts any string (default `active`);
    reference SQL is `ENUM('active','inactive','suspended')`.
11. **`OtpController@show` is unrouted and unauthenticated** — do not expose it
    as-is.
12. **`DatabaseSeeder` is empty.** Roles are created on demand (`customer` by
    register/Google), but a fresh DB has no admin/seed data.
13. **Order/payment/reporting tables are not migrated.** They
    exist only in `../Database/`.
14. **`OtpVerification` has no `updated_at`**; keep `$timestamps = false` or
    inserts will fail. Same idea for `Inventory` (only `updated_at`).
15. **Tests are not isolated to SQLite** (`phpunit.xml` leaves DB on MySQL), and
    feature tests do not use `RefreshDatabase`. Be careful running destructive
    tests against the local DB.
16. **`.env.example` contains Google OAuth credentials.** Do not treat
    `.env.example` as safe to publish; scrub values before committing.
17. **Product search uses `LIKE`**, even though the migration defines a
    MySQL fulltext index.
18. **Two migrations touch `google_id`.** Both guard with `Schema::hasColumn`,
    so order is safe, but avoid adding a third.

---

## 10. Database Sources

Three related sources, in priority order for the running app:

1. `Backend_Computer/database/migrations/` — what Laravel actually runs.
   **Source of truth for the app.**
2. `../Database/codesql.sql` — full target schema (catalog, inventory, orders,
   payments, reviews, audit). Partially migrated.
3. `../Database/migration/V1..V5_*.sql` — hand-written incremental scripts.

When changing a shared table, update the Laravel migration **and** keep the
reference SQL in sync. Do not manually edit the DB as the only change.

Local connection (`.env`): MySQL, `127.0.0.1:3307`, database `sale_computer`,
user `root`, empty password. `DB_CONNECTION` defaults to `mysql`, strict mode on.
(`.env.example` still shows port `3306`; the real local `.env` uses `3307`.)

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
- When adding a protected route, use `auth:sanctum`; when adding admin-only
  behavior, add real authorization (policy/middleware), not just UI gating.

---

## 12. Testing

- PHPUnit 10; config in `phpunit.xml`.
- Test env: `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, array cache/session,
  `MAIL_MAILER=array`, sync queue. SQLite is **commented out**.
- `tests/Feature/CategoryApiTest.php` verifies category validation returns JSON
  `422` without an `Accept` header (relies on `Handler::shouldReturnJson`).
- Encoding coverage for tests on this API: auth (login/register/reset), OTP
  (including rate limits), product validation/slug generation, nested
  image/variant ownership, duplicate inventory `409`, and inventory quantity
  rules.
- Add tests for any new/changed endpoint, especially validation, 404, 409, and
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
  codes (`200`, `201`, `202`, `404`, `409`, `422`, `429`, `503`).
- Validation covers required fields, uniqueness (ignoring own id on update),
  foreign keys, enums, and cross-field rules (e.g. reserved ≤ quantity).
- Auth/authorization is applied deliberately when the feature is sensitive; do
  not leave user/admin mutations public by accident.
- Migration added for any schema change (do not edit applied migrations).
- Feature test added/updated for the behavior.
- `php artisan test` and `./vendor/bin/pint` pass.
- No secrets, no plaintext OTP, no auth changes made silently.
- Scope limited to the requested feature; do not refactor unrelated code.

---

## 15. Suggested Next Steps (context only)

1. Add real authorization: protect user/role/catalog/inventory mutations and
   admin endpoints with Sanctum + role checks/policies.
2. Implement orders, order items, payments, and admin
   reporting/dashboard aggregates from `../Database/codesql.sql`.
3. Add a product stock aggregate (sum of inventories) or an inventory summary
   so storefront availability checks are accurate.
4. Add image upload/Cloudinary handling, or document URL-only behavior in the
   API contract.
5. Seed roles, categories, brands, warehouses, and an admin user.
6. Fix `User::$fillable` (`branch_id`) and align user status with the reference
   enum; align category `image` with `image_url` or document the divergence.
7. Add tests around auth, OTP, products, nested resources, and inventory.
8. Scrub secrets from `.env.example` and rotate any exposed Google credentials.
