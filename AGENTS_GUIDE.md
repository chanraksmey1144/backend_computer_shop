# AGENTS_GUIDE.md (Backend)

Guidance for AI agents working on the **Backend_Computer** Laravel API. Read
this before changing any backend file. For the API consumer's view, see
`../Frontend_Computer/AGENTS_GUIDE.md`.

---

## 1. What This Backend Is

A Laravel 10 REST API for the Sale_Computer e-commerce / computer-shop project.
It implements:

- **Auth**: register, login, logout, current user, OTP verification, password
  reset (OTP-gated), and Google OAuth via Socialite. Sanctum personal access
  tokens are issued on login/Google.
- **Foundation**: roles, users, addresses.
- **Catalog**: categories, brands, products, product images, product variants.
- **Stock**: warehouses, inventories, inventory movements, and per-unit product
  serial numbers.
- **Commerce**: authenticated carts (one per user) and coupons (CRUD + a
  validation/quote endpoint).

Still only in the reference SQL (not in Laravel): orders, order items,
payments, shipments, reviews, audit logs, and admin reporting/dashboard
aggregates.

It is a **standalone git repository** nested inside the project root. Check
`git status` before editing; never commit unless explicitly asked.

---

## 2. Tech Stack

| Item | Value |
|------|-------|
| PHP | `^8.1` |
| Laravel | `^10.0` |
| Sanctum | `^3.3` (Bearer tokens; guards `/user`, `/logout`, carts, coupons) |
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
├── Exceptions/Handler.php                    JSON errors for /api paths
├── Http/
│   ├── Controllers/API/
│   │   ├── AuthController.php                register, login, logout, user, reset
│   │   ├── GoogleAuthController.php          Google redirect + callback
│   │   ├── AddressController.php             user addresses + default switching
│   │   ├── BrandController.php
│   │   ├── CartController.php                cart + cart items (routed)
│   │   ├── CartItemController.php            duplicate cart logic (NOT routed)
│   │   ├── CategoryController.php
│   │   ├── CouponController.php              CRUD + validateCoupon
│   │   ├── InventoryController.php
│   │   ├── InventoryMovementController.php   index, store, show only
│   │   ├── OtpController.php
│   │   ├── ProductController.php
│   │   ├── ProductImageController.php
│   │   ├── ProductSerialNumberController.php
│   │   ├── ProductVariantController.php
│   │   ├── RoleController.php
│   │   ├── UserController.php
│   │   └── WarehouseController.php
│   ├── Kernel.php                            Middleware groups + aliases
│   └── Middleware/
├── Models/
│   ├── Address.php
│   ├── Brand.php
│   ├── Cart.php
│   ├── CartItem.php
│   ├── Category.php
│   ├── Coupon.php
│   ├── Inventory.php
│   ├── InventoryMovement.php
│   ├── OtpVerification.php
│   ├── Product.php
│   ├── ProductImage.php
│   ├── ProductSerialNumber.php
│   ├── ProductVariant.php
│   ├── Role.php
│   ├── User.php
│   └── Warehouse.php
├── Providers/
│   ├── AppServiceProvider.php
│   └── RouteServiceProvider.php              /api prefix + rate limiters
└── Service/
    └── OtpService.php                        OTP logic (keep service logic here)

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
- **Authenticated (`auth:sanctum`)**: `/api/logout`, `/api/user`, all
  `/api/cart*` routes, and all `/api/coupons*` routes (including
  `/api/coupons/validate`).
- **Unauthenticated**: roles, users, categories, brands, products, images,
  variants, warehouses, inventories, inventory movements, product serial
  numbers, and **addresses**.
- `routes/api.php` applies `throttle:6,1` to register/login/password reset.
- OTP routes use the custom `throttle:otp-send` / `throttle:otp-verify`
  limiters.
- Google OAuth routes (`/api/auth/google*`) are wrapped in the **`web`**
  middleware group so the OAuth `state` value can use the session.
- **Addresses are public and are not scoped to the authenticated caller.**
  `AddressController::resolveUser` prefers the authenticated user, then a
  `user_id` request field, then the oldest user in the DB. Do not add more
  consumers that rely on this fallback.
- `/api/addresses` and `/api/user/addresses` both point at the same
  `AddressController` and both expose the same route names (`addresses.*`).

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
| GET/POST/GET | `/api/inventory-movements` | `InventoryMovementController` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/product-serial-numbers` | `ProductSerialNumberController` |

Note: `/api/inventory-movements` is registered with `apiResource`, so
`PUT/PATCH/DELETE /api/inventory-movements/{inventory_movement}` exist in the
route table, **but the controller has no `update`/`destroy` methods**. Those
requests fail with a controller method error. Add the methods or stop using
`apiResource` for it.

`/api/products/{product}/images` and `/variants` are `apiResource` routes, so
item routes are `/images/{image}` and `/variants/{variant}`. The controller
methods scope each record to the parent product.

### Addresses (unauthenticated)

| Method | Path | Action |
|--------|------|--------|
| GET/POST/GET/PUT/PATCH/DELETE | `/api/addresses` | `AddressController` |
| PATCH | `/api/addresses/{address}/default` | `AddressController@setDefault` |
| GET/POST/GET/PUT/PATCH/DELETE | `/api/user/addresses` | same controller |
| PATCH | `/api/user/addresses/{address}/default` | same controller |

Both prefixes are public and register the same route names; the later
`/user/addresses` registration wins for `route('addresses.*')`.

### Cart (authenticated)

| Method | Path | Action |
|--------|------|--------|
| GET | `/api/cart` | `CartController@index` |
| POST | `/api/cart` | `CartController@store` |
| DELETE | `/api/cart` | `CartController@destroy` |
| POST | `/api/cart/items` | `CartController@addItem` |
| PATCH | `/api/cart/items/{item}` | `CartController@updateItem` |
| DELETE | `/api/cart/items/{item}` | `CartController@removeItem` |

`CartItemController` (index/store/update/destroy/clear) duplicates much of this
but is **not routed**. Treat it as dead code unless a task asks to consolidate.

### Coupons (authenticated)

| Method | Path | Action |
|--------|------|--------|
| GET/POST/GET/PUT/PATCH/DELETE | `/api/coupons` | `CouponController` |
| POST | `/api/coupons/validate` | `CouponController@validateCoupon` |

Any authenticated user can manage coupons; there is no admin authorization.

Frontend services still expect `/user` (PUT), `/user/password`, `/orders*` and
`/admin/*`; those do not exist. Addresses now exist, but only under the public
routes above.

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
- Not found via `findOrFail` → `404`; roles/users/coupons use manual JSON `404`.
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
  filters `status`, `search` (name LIKE), `root_only` (parent_id null). Store
  requires `name`, `slug` (unique), `status` (`active|inactive`), optional
  `parent_id` (must exist), `description`, `image`. Update uses `sometimes` and
  ignores own slug.
- **Brands**: `index` paginates 10, filters `search` on name and `status`.
  Store/update generate a unique slug (with `-n` suffix) when missing; update
  ignores own name/slug.
- **Products**: see §7.1.
- **Product images / variants**: see §7.2.
- **Warehouses**: paginated 10, `search` on name/code, `status` filter; `code`
  unique (update ignores own id); `status` `active|inactive`.
- **Inventories**: see §7.3.
- **Inventory movements**: see §7.4.
- **Product serial numbers**: see §7.5.
- **Addresses**: see §7.6.
- **Carts**: see §7.7.
- **Coupons**: see §7.8. Note `index` and `show` omit the `message` key.
- **Auth**: see §8.

---

## 7. Models and Schema

| Model | Table | Fillable | Relations |
|-------|-------|----------|-----------|
| `Role` | `roles` | `name`, `description` | none defined |
| `User` | `users` | `name`, `email`, `password`, `google_id`, `avatar`, `role_id`, `branch_id`, `phone`, `status` | `role()`, `inventoryMovements()`, `addresses()`, `cart()` |
| `Address` | `addresses` | `user_id`, `recipient_name`, `phone`, `province`, `district`, `commune`, `village`, `address_line`, `delivery_note`, `is_default` | `user()` |
| `Category` | `categories` | `parent_id`, `name`, `slug`, `description`, `image`, `status` | `parent()` belongsTo self, `children()` hasMany self |
| `Brand` | `brands` | `name`, `slug`, `logo_url`, `description`, `status` | none |
| `OtpVerification` | `otp_verifications` | `user_id`, `destination`, `purpose`, `code_hash`, `expires_at`, `verified_at`, `attempts` | `user()` belongsTo |
| `Product` | `products` | `category_id`, `brand_id`, `name`, `slug`, `sku`, `short_description`, `description`, `product_type`, `price`, `compare_at_price`, `cost_price`, `currency`, `warranty_months`, `specifications`, `track_inventory`, `allow_backorder`, `status` | `category()`, `brand()`, `images()`, `variants()`, `inventoryMovements()`, `serialNumbers()` |
| `ProductImage` | `product_images` | `product_id`, `image_url`, `cloudinary_public_id`, `alt_text`, `is_primary`, `sort_order` | `product()` |
| `ProductVariant` | `product_variants` | `product_id`, `sku`, `name`, `attributes`, `price`, `cost_price`, `status` | `product()`, `serialNumbers()` |
| `Warehouse` | `warehouses` | `name`, `code`, `address`, `phone`, `status` | none defined |
| `Inventory` | `inventories` | `warehouse_id`, `product_id`, `variant_id`, `quantity`, `reserved_quantity`, `reorder_level` | `warehouse()`, `product()`, `variant()` |
| `InventoryMovement` | `inventory_movements` | `warehouse_id`, `product_id`, `variant_id`, `user_id`, `movement_type`, `quantity_change`, `reference_type`, `reference_id`, `notes`, `created_at` | `warehouse()`, `product()`, `variant()`, `user()` |
| `ProductSerialNumber` | `product_serial_numbers` | `product_id`, `variant_id`, `warehouse_id`, `serial_number`, `status`, `order_item_id` | `product()`, `variant()`, `warehouse()` |
| `Cart` | `carts` | `user_id` | `user()`, `items()` |
| `CartItem` | `cart_items` | `cart_id`, `product_id`, `variant_id`, `quantity` | `cart()`, `product()`, `variant()` |
| `Coupon` | `coupons` | `code`, `description`, `discount_type`, `discount_value`, `minimum_order_amount`, `maximum_discount_amount`, `usage_limit`, `usage_count`, `starts_at`, `expires_at`, `status` | none |

Notes:

- `User` extends `Authenticatable`, uses `HasApiTokens`, `HasFactory`,
  `Notifiable`. Casts: dates to datetime, `password` => `hashed`. It has no
  `facebook_id` in `$fillable` even though the column and hidden entry exist.
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
- `InventoryMovement` sets `$timestamps = false`; `created_at` is inserted by
  the DB default. Order by `created_at` explicitly.
- `ProductSerialNumber` casts the FK ids to int. `order_item_id` is a plain
  unsigned bigint with **no FK** (the `order_items` table does not exist yet).
- `Cart`/`CartItem`/`Address`/`Coupon` use default timestamps and int/decimal
  casts.
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
- `2026_11_02_035717_create_inventory_movements_table`
- `2026_11_03_075025_create_product_serial_numbers_table`
- `2026_11_03_080320_create_addresses_table`
- `2026_11_03_092420_create_carts_table`
- `2026_11_03_100000_create_cart_items_table`
- `2026_11_03_155720_create_coupons_table`

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
- `inventory_movements` FKs: warehouse/product `restrictOnDelete`, variant/user
  nullable `nullOnDelete`; `movement_type` enum
  `stock_in|stock_out|sale|return|adjustment|reservation|release`;
  `quantity_change` int; `reference_type` varchar(80) + `reference_id`
  unsigned bigint (no FK); `created_at` nullable with `useCurrent`; **no
  `updated_at`**; indexes `(product_id, created_at)` and
  `(reference_type, reference_id)`.
- `product_serial_numbers` FKs: product `restrictOnDelete`, variant/warehouse
  nullable `nullOnDelete`; `serial_number` unique; `status` enum
  `in_stock|reserved|sold|returned|repair|written_off` default `in_stock`;
  `order_item_id` nullable unsigned bigint (no FK); index
  `(product_id, status)`.
- `addresses.user_id` FK cascade delete/update; `is_default` bool default
  false; index `user_id`. Only one default is enforced in the controller, not
  the DB.
- `carts.user_id` FK unique cascade delete/update (one cart per user).
- `cart_items` FKs: cart cascade, product `restrictOnDelete`, variant nullable
  `nullOnDelete`; `quantity` unsigned int default 1; index `cart_id`.
- `coupons.code` unique; `discount_type` enum `fixed|percentage`;
  `discount_value` decimal(12,2); `minimum_order_amount` decimal(12,2) default
  0; `maximum_discount_amount` nullable; `usage_limit` nullable;
  `usage_count` default 0; `starts_at`/`expires_at` datetime nullable; `status`
  enum `active|inactive` default `active`; index
  `(status, starts_at, expires_at)`.

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
  setting a primary demotes the others in a transaction. No file upload exists
  — clients must send a hosted URL.
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
  the same reserved/quantity check. It does **not** recompute or log movements.
- **MySQL unique indexes treat `NULL` variant ids as distinct**, so the DB
  constraint does not prevent duplicate rows with `variant_id = NULL`; the
  controller's manual existence check is what enforces it there.
- There is no `created_at`.
- Quantity changes should normally go through inventory movements (§7.4) so
  the audit trail stays correct; direct `PATCH` bypasses that.

### 7.4 Inventory movements

- `index` filters `warehouse_id`, `product_id`, `movement_type`, and `per_page`
  (1–100, default 10). Eager-loads trimmed `warehouse:id,name,code`,
  `product:id,name,sku`, `variant:id,product_id,name,sku`, and
  `user:id,name,email`. Orders by `created_at` desc.
- `store` requires `warehouse_id`, `product_id`, `movement_type`, and a non-zero
  `quantity_change`; optional `variant_id` (must belong to the product),
  `reference_type`, `reference_id`, `notes`.
- Sign rules: `stock_in`, `return`, `reservation`, `release` require positive
  changes; `stock_out`, `sale` require negative changes; `adjustment` may be
  either sign but not zero.
- The inventory row must already exist (`422` otherwise). The operation runs in
  a transaction with `lockForUpdate`, applies the change, saves inventory, then
  writes the movement. `user_id` is the authenticated user if any (routes are
  public, so it will usually be `null`).
- Business rules enforced: stock-out/sale cannot exceed available
  (`quantity - reserved_quantity`); adjustments cannot drop below reserved or
  below zero; reservations cannot exceed available; releases cannot exceed
  reserved.
- Response is `201` with
  `data: { movement, inventory: { quantity, reserved_quantity, available_quantity, reorder_level } }`.
- `show` returns one movement with the same trimmed relations.
- **`update`/`destroy` are routed by `apiResource` but not implemented.**

### 7.5 Product serial numbers

- `index` filters `product_id`, `warehouse_id`, `status`, `search` (serial
  number LIKE), and `per_page` (1–100, default 10). Eager-loads trimmed
  `product`, `variant`, `warehouse`. Orders newest id first.
- `store` requires `product_id`, a globally unique `serial_number`; optional
  `variant_id` (must belong to the product), `warehouse_id`, `status`
  (default `in_stock`), `order_item_id` (no FK).
- `update` uses `sometimes`; revalidates the product/variant relationship using
  the existing values when only one side changes.
- `destroy` returns `422` for `sold` or `reserved` serials; otherwise deletes.
- Valid statuses: `in_stock`, `reserved`, `sold`, `returned`, `repair`,
  `written_off`.

### 7.6 Addresses

- Public CRUD. `resolveUser` order: authenticated user → `user_id` input →
  oldest user. This means a caller can read/write another user's addresses.
- `index` paginates 10, default addresses first, then newest.
- `store` requires `recipient_name`, `phone`, `province`, `address_line`;
  optional district/commune/village/`delivery_note`/`is_default`. The first
  address becomes default automatically. Setting a default demotes the rest
  inside a transaction that locks the user's address rows.
- `update` uses `sometimes`; setting `is_default` true demotes the rest.
- `setDefault` clears defaults and sets one.
- `destroy` deletes and promotes the oldest remaining address to default when
  the removed address was default.

### 7.7 Carts

- One cart per user (`carts.user_id` unique). All routes are `auth:sanctum`.
- `index` returns a custom summary (not a raw model): `id`, `user_id`, `items`,
  `item_count`, `subtotal`, `discount`, `delivery_fee`, `total`. Empty carts
  return `id: null`, empty items, and zero totals.
- Each item includes `unit_price` (variant price if a variant is selected,
  else product price), a trimmed `product` object (id, name, sku, price,
  primary image URL), and a trimmed `variant` object (id, sku, name, price).
- `store` uses `firstOrCreate` for the cart (`201` when created, else `200`).
- `addItem` requires `product_id`, optional `variant_id` (must belong to the
  product), and `quantity` (1–100000, default 1). Adding an existing
  product/variant combination increments the quantity.
- `updateItem` requires `quantity` 1–100000 and is scoped to the caller's cart
  (manual `404` when not found). `removeItem` deletes one line and returns the
  refreshed cart.
- `destroy` deletes the cart row entirely (cascade removes items) and reports
  "already empty" when no cart exists.
- **Cart totals ignore coupons (discount is always 0) and shipping (delivery_fee
  is always 0). Quantities are not checked against stock.**

### 7.8 Coupons

- All routes require `auth:sanctum`; there is no admin/role check.
- `index` paginates 10 newest first; it omits `message`.
- `store` requires `code` (unique), `discount_type` (`fixed|percentage`),
  `discount_value` > 0; optional description, min/max amounts, usage limit,
  start/expiry dates, status. Codes are upper-cased/trimmed;
  `minimum_order_amount` defaults to 0. Percentage discounts above 100 are
  rejected with `422`.
- `show`/`update`/`destroy` use manual `404` JSON. Update uses `sometimes`,
  ignores its own code for uniqueness, and rechecks the >100% percentage rule
  using existing values.
- `validateCoupon` requires `code` and `order_amount` > 0. It returns `404` for
  unknown codes and `422` for inactive, not-yet-started, expired, exhausted
  (`usage_count >= usage_limit`), or below-minimum codes. It computes a fixed
  or percentage discount (capped by `maximum_discount_amount` and by the order
  amount) and returns `discount_amount` / `final_amount` as 2-decimal strings.
- **`usage_count` is never incremented** (no order flow yet), so the usage
  limit is advisory only. There is no per-user redemption tracking.

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
  12-hour token (`computer-shop-api`), and redirects to
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
`.env` secrets.** `.env` is gitignored. `.env.example` currently uses placeholder
Google credentials — keep it that way.

---

## 9. Known Issues / Landmines

Fix only when the task calls for it, but never copy these patterns:

1. **No authorization on most CRUD.** Roles, users, catalog, inventory,
   inventory movements, serial numbers, and addresses are public, and there is
   no admin middleware/policy. Carts/coupons require login but coupons have no
   admin check.
2. **User CRUD is especially dangerous** when public: anyone can list users or
   create/delete accounts until auth + authorization is applied.
3. **Addresses are public and cross-user.** `resolveUser` falls back to a
   supplied `user_id` or the oldest user, so addresses are readable/writable by
   anyone. Both `/api/addresses` and `/api/user/addresses` are exposed, and they
   register duplicate `addresses.*` route names.
4. **`User::$fillable` includes `branch_id`, but no migration adds that
   column.** Passing it to `User::create`/`update` will fail at the DB level.
5. **Product responses omit stock.** `product.stock` is used by the frontend but
   not returned; availability must come from `inventories`.
6. **Product `show` does not load images/variants**, and `store`/`update` load
   only category/brand. Clients fetch images/variants separately.
7. **Images are URL-only** (`image_url` required, must be a valid URL). There is
   no upload/Cloudinary integration; `cloudinary_public_id` is just metadata.
8. **Inventory has no `created_at`** and eager-loaded variants are trimmed to
   `id, product_id, name, sku`. `available_quantity`/`is_low_stock` are not
   appended to JSON.
9. **Inventory nullable-variant uniqueness** is only enforced in the controller;
   MySQL allows multiple `NULL` variant rows.
10. **Direct inventory updates skip the audit trail.** `PATCH /inventories/*`
    changes quantities without writing an `inventory_movements` row; prefer the
    movement endpoint for real stock changes.
11. **`inventory-movements` update/destroy are routed but missing.** The
    `apiResource` registration exposes them, but the controller has no such
    methods, so those requests error. Add methods or narrow the routes.
12. **`CartItemController` is dead code.** It duplicates cart item logic but is
    not routed; keep changes in `CartController` unless consolidating.
13. **Cart totals are not commerce-accurate.** `discount` and `delivery_fee`
    are hard-coded to 0, coupons are not applied, and quantity is not validated
    against stock.
14. **Coupons never increment `usage_count`.** Validation only quotes a
    discount; nothing marks a redemption, and there is no per-user limit.
15. **Serial `order_item_id` has no FK/target table.** `order_items` is still
    unmigrated, so the reference is unenforced and currently meaningless.
16. **Category `image` vs SQL `image_url`**: Laravel uses `image`; the reference
    SQL uses `image_url`.
17. **User status mismatch**: Laravel accepts any string (default `active`);
    reference SQL is `ENUM('active','inactive','suspended')`.
18. **`OtpController@show` is unrouted and unauthenticated** — do not expose it
    as-is.
19. **`DatabaseSeeder` is empty.** Roles are created on demand (`customer` by
    register/Google), but a fresh DB has no admin/seed data.
20. **Order/payment/reporting tables are not migrated.** They exist only in
    `../Database/`, despite the newer cart/coupon/address/serial tables being
    migrated.
21. **`OtpVerification` has no `updated_at`**; keep `$timestamps = false` or
    inserts will fail. Same idea for `Inventory` (only `updated_at`) and
    `InventoryMovement` (only `created_at`).
22. **Tests are not isolated to SQLite** (`phpunit.xml` leaves DB on MySQL), and
    feature tests do not use `RefreshDatabase`. Be careful running destructive
    tests against the local DB. Only `CategoryApiTest` exists.
23. **Product search uses `LIKE`**, even though the migration defines a
    MySQL fulltext index.
24. **Two migrations touch `google_id`.** Both guard with `Schema::hasColumn`,
    so order is safe, but avoid adding a third.
25. **Frontend contract lag.** The frontend still expects `/orders*`, `/admin/*`,
    profile update, and password change routes that the backend does not expose.

---

## 10. Database Sources

Three related sources, in priority order for the running app:

1. `Backend_Computer/database/migrations/` — what Laravel actually runs.
   **Source of truth for the app.**
2. `../Database/codesql.sql` — full target schema (catalog, inventory, carts,
   coupons, addresses, orders, payments, reviews, audit). Partially migrated.
3. `../Database/migration/V1..V16_*.sql` — hand-written incremental scripts
   (`V11` inventory movements, `V12` serial numbers, `V13` addresses, `V14`
   carts, `V15` cart items, `V16` coupons).

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
- For anything user-scoped (addresses, carts, orders), scope queries through
  `$request->user()` rather than trusting request ids.

---

## 12. Testing

- PHPUnit 10; config in `phpunit.xml`.
- Test env: `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, array cache/session,
  `MAIL_MAILER=array`, sync queue. SQLite is **commented out**.
- `tests/Feature/CategoryApiTest.php` verifies category validation returns JSON
  `422` without an `Accept` header (relies on `Handler::shouldReturnJson`).
- There is no test coverage yet for auth, OTP, carts, coupons, addresses,
  inventory movements, serial numbers, or nested images/variants.
- Add tests for any new/changed endpoint, especially validation, 404, 409, 422,
  and envelope shape. Use `RefreshDatabase` for DB-touching tests and note that
  it will run against MySQL as configured.
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
php artisan route:list --path=api    # inspect API routes (88 today)
```

Frontend dev server (separate app): `http://localhost:5173`.

---

## 14. Definition of Done for Backend Changes

- Endpoint follows the `{ success, message, data }` envelope and correct status
  codes (`200`, `201`, `202`, `404`, `409`, `422`, `429`, `503`).
- Validation covers required fields, uniqueness (ignoring own id on update),
  foreign keys, enums, and cross-field rules (e.g. reserved ≤ quantity,
  variant belongs to product, movement sign rules).
- Auth/authorization is applied deliberately when the feature is sensitive; do
  not leave user/admin/address mutations public by accident.
- User-scoped data is scoped to `$request->user()`, never to a client-supplied
  id.
- Migration added for any schema change (do not edit applied migrations).
- Feature test added/updated for the behavior.
- `php artisan test` and `./vendor/bin/pint` pass.
- No secrets, no plaintext OTP, no auth changes made silently.
- Scope limited to the requested feature; do not refactor unrelated code.

---

## 15. Suggested Next Steps (context only)

1. Add real authorization: protect user/role/catalog/inventory/movement/serial
   mutations and coupon management with Sanctum + role checks/policies.
2. Lock down addresses: require auth, scope to the current user, remove the
   `user_id`/oldest-user fallback, and resolve the duplicate
   `/api/addresses` vs `/api/user/addresses` routes.
3. Implement orders, order items, payments, shipments, and admin
   reporting/dashboard aggregates from `../Database/codesql.sql`; give
   `product_serial_numbers.order_item_id` a real FK then.
4. Wire coupons into the cart/checkout: apply discounts to cart totals, increment
   `usage_count` on redemption, and track per-user usage.
5. Add a product stock aggregate (sum of inventories) or an inventory summary
   so storefront availability checks are accurate, and validate cart quantity
   against stock.
6. Finish or remove the inventory-movement endpoint (implement
   `update`/`destroy` or stop using `apiResource`), and route or delete
   `CartItemController`.
7. Add image upload/Cloudinary handling, or document URL-only behavior in the
   API contract.
8. Seed roles, categories, brands, warehouses, coupons, and an admin user.
9. Fix `User::$fillable` (`branch_id`) and align user status with the reference
   enum; align category `image` with `image_url` or document the divergence.
10. Add tests around auth, OTP, carts, coupons, addresses, products, nested
    resources, inventory movements, and serial numbers.
