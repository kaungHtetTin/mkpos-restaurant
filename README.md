# Little Lemon — Mini Restaurant POS

A yellow-themed, phone-responsive restaurant POS platform built with a Laravel JSON backend and React + TypeScript frontend. Independent businesses keep their staff, menus, orders, sales, receipts, settings, and images separate. Each business has one restaurant, dine-in and takeaway, a kitchen screen, and full, partial, split or mixed payments per order.

## Open the local application

Start Laravel from the `backend` directory:

```powershell
php artisan serve --host=0.0.0.0 --port=8002
```

Open **http://localhost:8002**. The built React application is served by Laravel, with the API on the same origin. Keep that terminal running.

Development accounts, created only outside production:

| Email | Password | Access |
|---|---|---|
| manager@littlelemon.local | Lemon2026! | All operations and settings |
| waiter@littlelemon.local | Lemon2026! | Tables, takeaway, ordering, serving/collection |
| cashier@littlelemon.local | Lemon2026! | Ordering, payment, receipts, history |
| kitchen@littlelemon.local | Lemon2026! | Kitchen tickets and food availability |

Use the shared email-and-password sign-in form for these local demonstration accounts. The migrated owner is managed through the platform office. Replace their passwords or deactivate them before a real deployment. Manager → Settings → Staff access supports these changes.

The local database starts with 12 tables and 12 sample menu items. It contains no fabricated customer orders. Browser tests use a separate database.

## First-time installation on another checkout

Requirements: PHP compatible with the locked Laravel dependencies, Composer, Node.js 22.12+ (or compatible supported Node release), and npm. Enable PHP SQLite for the local setup, or PDO MySQL for MySQL/MariaDB.

From `backend`:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File database/database.sqlite
php artisan migrate --seed
```

Do not overwrite an existing `.env`, application key, or database. These initialization steps are for a new installation.

From `frontend`:

```powershell
npm.cmd ci
npm.cmd run build
```

Then start Laravel as above. The frontend build goes into `backend/public/app`; it is generated output, excluded from Git, and must be rebuilt after frontend changes.

### Realtime updates (Node.js WebSocket)

Use Node.js 22.16 or newer. Install and run the WebSocket service alongside Laravel:

```powershell
npm.cmd --prefix realtime ci
npm.cmd --prefix realtime start
```

`start-local.cmd` starts this service automatically on port 8081. It runs in the background and writes logs to `backend/storage/logs/realtime*.log`.

Laravel publishes signed invalidations after successful database commits. Authenticated WebSocket connections receive only their business, office or guest-request notifications. The frontend fetches current authorized data when notified and once after reconnecting. There is no recurring data polling, long polling, SSE, or polling fallback. WebSocket ping/pong frames detect broken connections; reconnect uses backoff. Initial page loads, explicit actions, and expiry deadlines still use normal API requests.

The service reads `backend/.env`. By default Laravel and Node derive the same signing secret from `APP_KEY`. For separate hosts set the same strong `REALTIME_SECRET` on both services. Configure `REALTIME_PUBLISH_URL` for Laravel's internal signed publish endpoint, `REALTIME_PUBLIC_URL` for the browser socket URL, and `REALTIME_ORIGINS` as comma-separated allowed frontend origins. With HTTPS, reverse proxy `/socket` to Node with WebSocket upgrade support and use a `wss://` public URL. The default service binds to loopback; use `REALTIME_HOST` when intentionally exposing it behind a proxy or on a LAN. Run one Node instance unless a shared fan-out layer is added. Existing connections resynchronize after a Node restart; failed publish attempts are logged without undoing committed operations.

### Frontend development with hot reload

Run Laravel on port 8002 and, in a second terminal from `frontend`:

```powershell
npm.cmd run dev
```

Open the URL printed by Vite, usually **http://localhost:5173**. API requests are proxied to Laravel. Use `npm.cmd run build` before checking the app served directly by Laravel.

## Business accounts and platform office

- **/register**: create an owner account and business, then start a 1-month trial immediately. Email verification is not required.
- **/**: owners and staff sign in with email and password using the same form. The account determines the business and access roles automatically. Staff emails must be unique across all businesses.
- **/billing**: owners see their subscription, available plans, and payment history.
- **/office**: platform administrators manage owners, business suspension, plans, renewals, extensions, cancellations, subscription payments, and activity history.

The office now includes MKPOS-inspired operations adapted to this restaurant platform:

- Overview with access counts, payment/support queues, and subscription-income charts by month and currency.
- Expiry calendar, business locations, office onboarding, and searchable business/owner management. New owners still complete business setup before operational access.
- Public/private plans with descriptions, feature lists, display order, and retained subscription price snapshots.
- Owner payment-proof requests, configurable external payment instructions, office approval/rejection, a global payment register, and per-currency receiving bank books. Approval is manual confirmation of an external payment, not a payment gateway.
- Platform financial book for income, expenses, opening adjustments, capital contributions, transfers, founder profit payments, voids, exports of the displayed page, and monthly/yearly reports. Restaurant sales never enter this ledger. Transfers and capital are not earned profit; founder allocations require both earned profit and funded books. Historical allocation names and percentages are immutable.
- Ordinary and super administrators, self-service profile/password changes, account-version session revocation, and audited changes. Only super administrators manage the team, founder settings, branding and system health. The bootstrap command creates a super administrator.
- Private business support reports and screenshots, published tutorials/announcements, platform logo/color/contact settings, and read-only system health. Business staff see their own reports; owners can see reports for their business. Internal support notes remain office-only.
- Cached list data, URL filters, draft search/filter fields, pagination and scroll on return from business details. Explicit Refresh reloads records. Office code loads separately from the restaurant workspace.

Before applying the office migration, back up the database. Existing subscription receipts are copied once into currency-specific legacy receiving books without changing the original payments. Unsafe rollback is blocked to protect financial history. The payment methods and Help contacts start empty; configure them in the office before accepting owner payment requests.

Office interaction verification:

| Controls / flow | Handler | Observed result | Verification |
| --- | --- | --- | --- |
| Submit payment proof; approve payment; reject request | BusinessSupportController / OfficeOperationsController | Submission leaves access unchanged; approval credits the selected book once; rejection preserves access and returns a reason | Desktop/phone approval flow; backend rejection, stale-plan and retry tests |
| Record financial entry; View entry; Confirm void; Save founder shares; Export this page | OfficeFinanceController / OfficeFinancePage | Correct balances and rounding; original entry retained after void; saved allocations and displayed-page CSV preserved | Desktop/phone flows and backend ledger tests |
| Manage business; source Back; browser Back/Forward | OfficeBusinessLink / useOfficeRecords / office scroll and form-draft stores | Applied filters, unsubmitted search draft, page size, rows and scroll return without a new initial list request | Desktop/phone navigation regression |
| Apply/Reset filters; Rows per page; First/Previous/Next/Last; action-menu Enter/Escape; editor Escape | OfficeFilters / OfficePagination / OfficeRowActions / Modal | Filters and page size remain consistent, boundaries disable correctly, and closing returns focus without saving | Desktop/phone interaction regression |
| Add/edit administrator; Edit my account; deactivate account | OfficeManagementController / OfficeAccess | Business and office roles remain separate; self-service retains the current session; old/deactivated access is revoked | Desktop/phone role flow and backend tests |
| Add business; Save account; search expiry calendar; location links; branding upload; publish tutorial; health checks | OfficeController / OfficeManagementController / OfficeOperationsController | Setup remains mandatory; location and local-day expiry are retained; content and branding persist; health remains read-only | Desktop/phone onboarding flow; backend private-upload and migration tests |

Tests use isolated fixtures and do not execute external bank settlements. Bank archival and content unpublishing use their saved status flags; those two toggles were not separately exercised in the browser. The backend enforces default-book protection and retains historical balances and snapshots.


Create the first office administrator from the backend directory:

    php artisan platform:admin

The command asks for a name, email, and a hidden password of at least 12 characters. Office authentication is separate from business authentication. Alternatively, set `OFFICE_ADMIN_NAME`, `OFFICE_ADMIN_EMAIL`, and `OFFICE_ADMIN_PASSWORD` in `backend/.env` before running `php artisan migrate --seed` (or `php artisan db:seed` on an existing database). The password must be at least 12 characters. Seeding creates the initial office super administrator in any environment only when no office administrator exists; rerunning it preserves existing accounts and passwords. Leave both email and password empty to disable this bootstrap.

Create actual monthly or yearly prices in **Office > Plans**. To renew, open a business, choose **Record payment & renew**, select a plan, and enter the full confirmed amount, payment reference, and reason. This records a manual subscription payment; it does not collect money through a payment gateway. Plan edits preserve existing subscription prices and historical payments. Assigning a plan keeps the current access dates and status.

Expired or cancelled subscriptions block operational writes on the server, including existing sessions. Historical records remain readable. Suspension also blocks staff access; the owner retains read access and billing access. Renewal restores operational access when the business is active. The office manages owner identity and access; ordinary staff administration cannot change the owner account.

The conversion migration assigns existing restaurant data and accounts to business code **little-lemon**, using its first manager as owner. Existing staff passwords and stored email addresses stay the same. Managers can view or update staff login emails under Settings > Staff access; older generated addresses also work for sign-in. The migrated business receives a 1-month trial. Set its owner's real email through the office to enable email sign-in and recovery.

Back up the database and uploaded menu images before migrating another installation. The conversion migration refuses rollback because merging separated businesses would be unsafe; restore the pre-migration backup if needed.

Owner and staff password recovery is available at **/forgot-password**. Configure Laravel mail settings and APP_URL for real delivery; the local setup currently logs mail. The Laravel scheduler checks subscription notices daily at 09:00 Asia/Yangon and sends one reminder per period within seven days of expiry. Start the scheduler locally with:

    php artisan schedule:work

On hosting, configure the normal minute-by-minute Laravel scheduler. Registration and subscription access checks work without it.

## Phones and tablets

Use the same Wi-Fi as the server. With Laravel listening on `0.0.0.0`, open `http://<server-LAN-IP>:8002` on the phone. Windows Firewall must allow the chosen private-network port. `localhost` on a phone points to the phone itself.

The UI adapts at 360px width, uses touch controls, and provides bottom navigation and an order sheet on phones. Fonts and assets are bundled locally; no external font request is needed. All devices must be able to reach the server. Full offline operation is not implemented.

## Implemented operations

- Login, individual staff accounts, combined roles, server-side authorization, inactive account enforcement.
- Available / occupied / waiting-for-payment table cards; one active order per table.
- Takeaway orders with optional customer name and phone.
- Menu category search, availability, quantities, preparation notes, server-saved drafts.
- Show/Hide prices button on the food menu and order screen. The choice is remembered on that browser and hides food-card prices; order totals, checkout, and receipts continue to show amounts.
- Managers/cashiers can edit an unpaid order item's unit price from the order panel or during checkout. Changes affect that order only, recalculate totals and change, and record old/new prices, quantity, actor, time, and an optional reason. Paid/cancelled items stay locked. Collected unpaid takeaway orders can still be repriced before settlement; their food contents stay locked.
- Optional food photos: upload, preview, replace, or remove a JPG, PNG, or WebP image (up to 5 MB and 6000px per dimension) in the menu editor. Photos appear in both the menu and order-taking screens.
- Send only newly added lines to a new kitchen ticket.
- Kitchen queue, preparing/ready actions, optional new-ticket sound, recent ready tickets.
- Dine-in serving and takeaway collection.
- Manager cancellations with reasons and persistent kitchen acknowledgment notices.
- Cash with change calculation; another externally confirmed payment method.
- Full and partial payments, equal shares, selected item portions, and mixed cash/other tenders. The first payment locks bill contents, prices and discounts. Each collection has its own immutable receipt; refunds do not reopen settled portions.
- Printable receipts with restaurant snapshots, searchable/paginated order history and activity records.
- Settings → Receipts: edit the receipt title and multiline footer, choose 56/58/80/88/112 mm paper or a custom whole width from 40–120 mm, preview changes, and print a sample. New payments capture the format; historical receipts retain their saved title, footer, and width. Select the same width in the printer dialog, use 100% scale, and disable browser headers/footers. Browser page sizing uses the selected width and measured receipt height; the actual printer/driver must support that paper.
- Sales page for managers/cashiers: daily, monthly, and yearly amounts; searchable payment records; order/payment filters; full matching amount; receipt navigation. Select a reference date to view its day, calendar month, or calendar year. Amounts use payment dates in the restaurant timezone and exclude cash change and unreceived balances. Partial payments and takeaway payments count when received, including before collection. Refunds are reported separately by their issue date; corrected methods affect payment grouping without rewriting original receipts.
- Cashier shifts: opening cash, cash movements, expected cash, closing counts and variance.
- Manager-approved discounts, tax and service charges saved with each order; menu sizes and priced add-ons.
- Table transfers and combined bills, preserving original charge groups and table occupancy until settlement.
- Manager refunds and payment-method corrections with reasons, audit records, original receipts, and cash reconciliation.
- Guest menu and table/takeaway QR codes under Guest requests > Menu & QR codes. Public browsing and order requests are disabled by default. Staff review requests before sending them to the kitchen by default. Managers can enable Send customer orders directly to kitchen to skip approval for new requests. Prices, choices and charges are checked again on acceptance. Requests expire after two hours. QR codes are generated locally. Open the workspace using an address guests can reach before printing; localhost links only work on the host computer. Guest payment remains with staff.
- Menu, category, table, staff, and restaurant settings.
- Idempotent mutations, stale-order conflicts, database uniqueness constraints and transactions.
- Automatic refresh every 3 seconds while the tab is visible; refresh on return to the tab; connection-failure indication.

Collected takeaway contents are locked, but an unpaid collected order can still be settled. Paid takeaway orders remain open until collected. Dine-in payment requires all food served.

Prices use **whole currency units**, initially MMK. Percentage calculations and split allocations use whole-unit rounding, preserving the exact bill total.

## Database

The Sales page includes interactive charts: daily sales by hour, monthly sales by day, and yearly sales by month. Select a bar for its amount and sale count, or open “View chart data” for the exact values. Charts show all received payments for the selected calendar period; record filters affect the list below. Zero-sale intervals remain visible, and phone users can swipe across the chart.

Food photos are stored in `backend/storage/app/private/menu-images` and served through authenticated Laravel routes. Enabled guest menus also serve published dish images through their scoped menu links. No public storage link is needed. Include this directory with database backups so uploaded photos can be restored too.

SQLite is configured for local development. To use MySQL/MariaDB, create an empty database, set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `backend/.env`, then run `php artisan migrate --seed`. Migrations support both engines; the current automated test run uses SQLite.

Do not change an existing restaurant to an empty database without migrating its data. Production web servers must point at **backend/public**, never the repository root.

In production, set `APP_ENV=production`, `APP_DEBUG=false`, the actual `APP_URL`, and HTTPS/session-cookie configuration. The production seeder creates restaurant settings and the initial office administrator when configured, but no demonstration staff or menu. Create the initial manager with:

```powershell
php artisan restaurant:manager
```

The password is entered through a hidden prompt. Add menu items, categories, tables, and staff through Settings.

## Verification

Backend feature tests run against a fresh in-memory database:

```powershell
cd backend
php artisan test
```

Frontend production/type check:

```powershell
cd frontend
npm.cmd run build
```

Browser tests require Microsoft Edge and an isolated Laravel server on port 8001. From `backend`, in a dedicated terminal:

```powershell
$env:DB_DATABASE = (Join-Path (Get-Location) 'database/browser-test.sqlite')
if (-not (Test-Path $env:DB_DATABASE)) { New-Item -ItemType File $env:DB_DATABASE }
php artisan migrate --seed
$env:CACHE_STORE = 'array' # Test server only; backend tests cover authentication rate limits.
php artisan serve --host=127.0.0.1 --port=8001
```

Then, from `frontend`:

```powershell
npm.cmd run test:e2e
```

The test configuration uses port 8001, not the main restaurant database. Platform browser tests create a development office administrator only in this isolated database. For another test server or database, set E2E_BASE_URL and E2E_DATABASE in the frontend test terminal to match it. Tests finish their orders and can run repeatedly. Screenshots and failure traces go into ignored `frontend/test-results`. Change the Playwright browser channel if Edge is unavailable.

## Android and portable Windows apps

The standalone realtime Node.js deployment bundle is built with `cd realtime` then `npm ci`, `npm test`, and `npm run build`. Output: `artifacts/realtime/Restaurant-Realtime-1.0.0-node.zip`. It includes production dependencies, `index.js` as the hosting startup file, an environment template, PM2/Docker configurations, and an Nginx TLS/WebSocket proxy example. Extract the bundle, set `REALTIME_SECRET` to match Laravel, and start it with `npm start` on Node.js 22.16 or newer. Follow the included `DEPLOY.txt` for the production addresses.

Native wrapper source and build commands are in `apps/`. Android opens `https://restaurant.mkposmyanmar.com` by default; the production Laravel configuration template is `backend/.env.production.example`, with realtime at `wss://restaurant-socket.mkposmyanmar.com/socket`. Use the same `REALTIME_SECRET` on both production services. The socket reverse proxy must forward `/socket` WebSocket upgrades and `/publish` HTTP requests to the Node service.

Windows offers a production-server connection or an included local PHP 8.5/SQLite/realtime server. These are separate databases; no automatic cloud/local synchronization is implemented. Local mode listens on loopback only. Portable Windows executables keep their data in `Little-Lemon-POS-Data` beside the executable. Keep that folder when moving or upgrading the app. The Restaurant menu provides Server Settings, the data folder, printing, and the initial local office-administrator credentials. Change the initial password after first sign-in.

From `apps/`:

```powershell
npm ci
npm test
npm run android:build
npm run windows:build
```

Android builds need Java 21 and an Android SDK with API/build tools 36. Set `JAVA_HOME` and `ANDROID_HOME` as needed. The Android App Menu provides navigation, printing and server settings. APKs are written to `artifacts/android/`: the debug-signed APK is installable for testing; the release APK is unsigned and must be signed with the release owner’s keystore before distribution. Physical camera and receipt-printer testing is still required on target devices.

Windows builds need Composer/PHP on the build computer. The build scripts download PHP and Electron archives, verify their published SHA-256 checksums, and prepare production backend dependencies; build output is in `artifacts/windows/`. Electron archives are fetched from a mirror and verified against the official Electron release checksums. Electron 43 is used for Windows 32-bit compatibility; [that branch’s 32-bit support ends in January 2027](https://www.electronjs.org/blog/electron-43-0). The 64-bit build uses Electron 45. The Windows executables are unsigned.

## Remaining before production acceptance

- Replace three-second polling with the specification's authenticated WebSocket delivery, retaining reconnect refresh.
- Configure automated backups outside the application directory and verify restoration on the chosen database/server.
- Run the agreed multi-device/concurrent workload on the target network and database; automated workflow tests do not establish a capacity guarantee.
- Confirm actual receipt printer/browser behavior, final currency rules, and any required receipt details.
- Set the restaurant's data retention policy for optional customer contact information.

See [the full specification](docs/software-specification.md) and [implementation status](docs/implementation-status.md).
