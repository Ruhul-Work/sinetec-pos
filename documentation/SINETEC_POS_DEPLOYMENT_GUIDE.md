# Sinetec POS — Developer Deployment Guide

Updated 25 November 2026 · Laravel 13 · MySQL

## 1. Prepare the release

1. Check out the approved commit or release tag. Use the PHP version supported by the committed lock file (PHP 8.3 or newer per `core/composer.json`) and Composer. Verify the PHP extensions for the web server and CLI, including `pdo_mysql`, `bcmath`, `mbstring` and `fileinfo`.
2. Configure HTTPS and Apache rewriting for the project-root `index.php`. This repository serves assets from root-level `theme/` and stores application uploads under root-level `uploads/`. Keep these paths reachable as required by the app. Give the web worker write access to `core/storage`, `core/bootstrap/cache` and `uploads`.
3. At the web-server/reverse-proxy layer, deny direct HTTP access to private paths such as `core/`, `documentation/`, `refarence/`, `backup/`, `log/`, dotfiles and Git metadata. Disable directory listing and script execution under `uploads/`. Restrict the whole application to the approved network or authenticated ingress when it handles internal files.
4. This release disables guest registration. The handler and form are retained and the GET/POST route definitions are commented out in `core/routes/core.php`. Rebuild the route cache after deployment and verify both methods are unavailable. Provision the first admin in a trusted console, then create staff through the permission-protected Users screen. Review server-controlled role and branch assignment before any future reactivation.
5. Work inside `core/` for Composer and Artisan. Create `.env` from `.env.example` for a new environment. Set `APP_ENV=production`, `APP_DEBUG=false`, the correct HTTPS `APP_URL`, `APP_TIMEZONE=Asia/Dhaka`, secure cookies (`SESSION_SECURE_COOKIE=true` for HTTPS), and `DB_CONNECTION=mysql` with the target host, database and restricted credentials. Store the environment file outside source control.

```sh
cd core
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

## 2. Choose ONE database path

| Scenario | Procedure |
| --- | --- |
| **A. Fresh setup** | Create an empty UTF-8 MySQL database. Point `core/.env` to it. Generate a new `APP_KEY`, migrate and run `DatabaseSeeder` as shown below. Then create the first admin and follow the user setup guide. |
| **B. Keep existing business history** | Back up the current Sinetec database and restore a **full, consistent dump** to a separate target database. Keep the original `migrations` records, primary keys, sequence rows, users, permissions, posted documents, ledgers, balances and frozen snapshots together. Restore matching uploaded files and any used persistent storage. Preserve the existing `APP_KEY` via secure configuration. Review `migrate:status` and run pending forward migrations against the restored database, then refresh the permission catalogue. |
| **C. Fresh release with masters only** | Start with path A, then transfer reviewed master data from the current database using the checklist below. Enter opening stock and finance balances through the application. Source backups stay available for reference. |

**Path A commands** from `core/` after verifying the empty target database:

```sh
php artisan config:clear
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --class=DatabaseSeeder --force
```

The default seeder installs voucher types, a starter fiscal year when none exists, Finance foundation accounts and three default sales mappings, Production Cost Categories and the route permission catalogue. Configure the five purchase mappings before posting the first receipt. Create the first admin, branches, warehouses, payment methods and inventory through the setup steps. `InventoryCategorySeeder` is optional demo data and is outside the production baseline.

**Path B commands** from `core/` after restoring the dump and setting the target environment:

```sh
php artisan config:clear
php artisan migrate:status
php artisan migrate --force
php artisan db:seed --class=CorePermissionSeeder --force
```

The permission seeder refreshes route mappings for current modules. Review the restored role grants and any retired custom rules after it runs. Review the release migration and seed instructions before applying any additional seeders. Finance and production starter seeders can update existing records by code.

### Masters-only transfer checklist

Export records from the **current Sinetec schema** as a reference. Import data after the destination schema is migrated. Match business records by code and remap every foreign key to the destination IDs. The seeders already create some finance masters.

| Keep when approved | Dependency and check |
| --- | --- |
| `branches`, `warehouses`, `company_settings`, optional location tables | Branch before warehouse. Verify active/default warehouse, company identity and logo file. Import location hierarchy parent-first. |
| `roles`, `users`, applicable `user_branches` | Roles and branches before users. Keep reviewed password hashes via a secure transfer or reset passwords. Rebuild `role_permissions` and `user_permissions` against the new catalogue. Review firewall rules before enabling them on a new network. |
| Units, unit conversions, brands, payment types, suppliers and customers | Base units before conversions and inventory masters. Keep approved contact and payment data. |
| Raw Material, Part and Finished Product categories and item masters | Parent categories before children, then units/supplier mappings, warehouse reorder levels and reviewed BOMs/recipes. Copy referenced images and label data with the item records. |
| Chart of Accounts, Fiscal Year, Branch Accounts, Finance Account Mapping and expense categories | Match starter accounts/voucher types by code. Review active fiscal dates, branch assignments and company defaults/overrides before posting. |

For a clean start skip test transactions, direct copies of stock balances/movements, journals, dues, snapshots, session/cache/job rows and permission-route tables. Record opening warehouse quantities/cost and finance balances via the application. Any inherited open supplier/customer documents that need future payment or collection require a reconciled cutover decision with the accountant. A master import alone cannot preserve document-level dues. If that history must carry forward, use the full-restore path.

### First admin for a fresh database

1. In a trusted CLI with `php artisan tinker`, create or look up an active `App\Models\backend\Branch` using the approved name/code, then create or look up the authorized admin role in `App\Models\backend\Role` with a unique `key` and `is_super=true`.
2. Create the `App\Models\backend\User` with an approved unique email or username, hashed password, that role ID, the branch ID and `status=true`. Use an approved password provisioning process and `Hash::make()` for the hash. Keep clear-text credentials out of command history, logs and shared messages.
3. For a branch created or imported outside the application, assign starter accounts before posting. In Tinker, with the branch record loaded as `$branch`, run `app(\App\Services\Finance\FinanceFoundationService::class)->assignStarterAccountsToBranch($branch)`. Review mappings and branch-specific accounts after import.
4. Sign in at `/backend/login` over HTTPS. Set the active default warehouse and create business roles/users in the application. Reserve Super Admin for named administrators.

## 3. Verify before handover

1. In `core/`, recheck `php artisan migrate:status` and run `php artisan optimize` after environment settings and data are in place. Verify the login page and assets, and confirm the private paths and registration endpoint return a denied/not-found response externally.
2. Verify admin login, ordinary-user branch isolation, route permissions, selected warehouse, fiscal year, active account mappings and payment types. Check upload visibility and A4/thermal print on the target devices.
3. In staging, run the purchase → stock → assembly → sale/return and month-close checks in the user guide. Reconcile opening stock, customer/supplier dues and the Trial Balance before production sign-off.
4. Record the release commit, database backup and restore point, uploaded-file backup and recovery steps. Rehearse restore before cutover. For rollback, pair the compatible code version with the corresponding verified database/file backup.

Continue with the [User Setup and Operations Guide](SINETEC_POS_USER_GUIDE.md).
