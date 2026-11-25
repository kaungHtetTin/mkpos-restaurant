# Mini Restaurant POS — Software Specification

Version: 1.0 draft  
Date: 10 November 2026  
Scope: Single restaurant, dine-in and takeaway

## 1. Purpose

Build a small restaurant operations system that allows staff to take orders on phones or tablets, send food to a kitchen display, track preparation and serving, and collect payment.

The system is one responsive React single-page application backed by Laravel and a shared database. Staff see interfaces according to their permissions. Separate mobile applications and a customer application are outside this version.

## 2. Agreed scope

- Dine-in and takeaway orders.
- One active order and one combined bill per table.
- Kitchen screen for receiving and updating orders.
- Waiters use phones or tablets through a browser.
- Full payment for an order; no split bills or partial payments.
- Menu, table, and staff administration.
- Searchable receipt and order history.
- Paid sale records and daily, monthly, and yearly sales amounts.

### Outside version 1

Accounting, expense tracking, profit/loss reports, HR, payroll, inventory, ingredients, purchasing, suppliers, loyalty, customer accounts, online ordering, delivery management, multiple branches, table reservations, table transfers, combining tables, splitting bills, refunds, payment gateway integration, offline order synchronization, and kitchen printers.

Menu variants, paid modifiers, discounts, service charges, and tax calculation are also excluded from the initial baseline. Different sizes can be separate menu items; preparation preferences use free-text notes. Menu prices are the final amounts charged. Any tax or receipt obligations must be resolved before production if they require changing this scope.

## 3. Users and permissions

Use individual staff accounts with one or more predefined roles. A custom permission editor is unnecessary for version 1.

| Action | Waiter | Cashier | Kitchen | Manager |
|---|---|---|---|---|
| View tables and active orders | Yes | Yes | Kitchen view only | Yes |
| Create orders, add items, send to kitchen | Yes | Yes | No | Yes |
| Edit or remove unsent items | Yes | Yes | No | Yes |
| Mark dine-in items served / takeaway collected | Yes | Yes | No | Yes |
| Record payment and print receipts | No | Yes | No | Yes |
| Adjust unpaid order-item prices | No | Yes | No | Yes |
| Set items preparing or ready | No | No | Yes | Yes |
| Mark menu items sold out / available | No | No | Yes | Yes |
| Cancel already-sent items or orders | No | No | No | Yes |
| Manage menu, tables, staff, and restaurant settings | No | No | No | Yes |
| Search completed order history | No | Yes | No | Yes |

A staff member can have both waiter and cashier roles. Managers can perform all operational actions. Shared kitchen devices use a kitchen-only account. Inactive staff cannot log in or continue using authenticated sessions.

## 4. Application screens

### 4.1 Login

- Login using username and password; logout from every staff interface.
- Redirect to an appropriate screen based on roles.
- Display clear authentication and session-expiration messages.
- No public registration. A manager creates staff accounts and resets passwords.

### 4.2 Tables

- Mobile-friendly table cards with table name, status, active order number, elapsed time, and current total.
- Statuses: Available, Occupied, and Waiting for payment.
- Tap an available table to start an order; tap an occupied table to open its active order.
- Offer a Request bill action that sets Waiting for payment.
- Adding food after requesting the bill returns the table to Occupied.
- Show a ready-food indicator when a table has ready items awaiting service.
- A paid and closed dine-in order releases its table automatically.

### 4.3 Takeaway list

- Create takeaway orders with an automatic order number.
- Optional customer name and phone; no customer account or customer directory.
- Show active orders with preparation, payment, and collection status.
- Search active orders by number or customer name.
- Completed orders move to history.

### 4.4 Order editor

- Show order type, table or takeaway number, creator, and creation time.
- Browse categories and search food by name.
- Display price and availability; sold-out items cannot be added.
- Offer a Show/Hide prices button for all food cards, shared with the menu screen and remembered per browser. Billing totals, checkout, and receipts remain visible.
- Add items, change draft quantities, and enter notes such as “no onion.”
- Show draft items separately from items already sent to the kitchen.
- Show the full order total and a clear Send to kitchen action.
- Allow additional rounds on an unpaid order.
- Sent quantities and notes are locked. Corrections require an authorized cancellation and a replacement item.
- After payment, no food can be added and bill contents cannot be edited.

### 4.5 Kitchen display

- Tablet/desktop-friendly queue of submitted tickets, oldest first.
- Each submission creates a ticket containing only newly submitted items.
- Show table number or a prominent Takeaway label, order number, ticket number, submission time, and elapsed time.
- Show item names, quantities, notes, and status.
- Kitchen staff mark individual lines Preparing and Ready. Provide a convenient ticket-wide action for eligible lines.
- Show cancelled submitted items clearly until the kitchen acknowledges the cancellation.
- Active tickets leave the main queue when all lines are ready or cancelled and cancellation notices have been acknowledged. Retain a recent-ticket view for reference.
- Automatically update without manual refresh. Provide an optional sound for new tickets and a visible connection indicator.
- Kitchen users can toggle food availability without changing menu prices or names.

### 4.6 Ready food and fulfillment

- Waiters can see ready items for active orders, with table or takeaway number.
- Mark dine-in lines Served.
- Mark takeaway orders Collected after all non-cancelled lines are ready.
- Collected takeaway orders can remain open while awaiting payment.
- Version 1 tracks a whole order line together. If identical dishes need independent preparation tracking, enter them as separate lines before submission.

### 4.7 Checkout

- Show item names, quantities, unit prices, line amounts, and total. Exclude cancelled lines from the amount due.
- Managers and cashiers may change any non-cancelled unpaid line's unit price, including served dine-in food and collected unpaid takeaway food. Recalculate the bill and cash change immediately. The change affects the current order only; it must not change the menu price or preparation/fulfillment status.
- Record old/new unit prices, quantity, item, actor, timestamp, and optional reason. Reject stale edits and price changes after payment. Require completion or cancellation of a pending price edit before payment confirmation.
- Payment methods: Cash and Other. A manager can set a label for Other, such as QR or Card.
- For cash, enter amount received and calculate change. Reject an amount received below the total.
- For Other, staff confirm payment externally and then record the full amount. The app does not process or verify the transfer.
- Allow payment only when there are no unsent draft lines or unacknowledged kitchen cancellations.
- Dine-in payment requires all non-cancelled lines to be served; payment closes the order and releases the table.
- Takeaway can be paid after submission, including before preparation finishes. It closes only after both payment and collection.
- Support a zero-total order with an explicit settlement action and record who settled it; empty orders cannot be paid.
- Produce a printable receipt and allow reprinting from history.

### 4.8 History

- Search by order or receipt number; filter by date, order type, and completed/cancelled status.
- Show original item names and prices, cancellations and reasons, payment details, and relevant staff/timestamps.
- Paid orders are read-only. Reprinting does not create another payment.
- This screen retains completed/cancelled order records. The separate Sales page shows paid-sale amounts and calendar-period totals.

### 4.9 Settings

- Categories: name, display order, active status.
- Menu items: category, name, final selling price, available/sold-out state, active status, display order. Description and a food photo are optional. Managers can upload, preview, replace, or remove JPG, PNG, or WebP photos up to 5 MB and 6000px per dimension. Show photos in both menu and ordering cards, with an icon fallback when absent.
- Tables: unique name or number, display order, active status.
- Staff: name, unique username, roles, password reset, active status.
- Restaurant: name, receipt contact/address text, currency and precision, timezone, and other-payment label.
- Receipts: manager-only settings for optional title, optional multiline footer, and paper width. Offer 58/80/112 mm common-width presets, 56/88 mm options, and custom integer widths from 40–120 mm. Include a live preview and sample print. New payments snapshot the format; historical receipts keep their original format, with an 80 mm fallback for older snapshots without a width. Browser print layout follows the width and measured content height; printer settings must match.
- A table with an active order cannot be deactivated. Referenced menu items and staff are deactivated rather than deleted.
- Currency is fixed after transactional use; changing it must not reinterpret historical amounts.

### 4.10 Sales

- Managers and cashiers can view paid-sale records and amounts.
- Show daily, monthly, and yearly totals for a selected reference date; clicking a total opens records for that calendar period.
- Calculate using payment timestamps in the restaurant timezone, with calendar boundaries converted to UTC for database queries.
- Use the saved payment amount, excluding cash received for change. Include paid takeaway orders awaiting collection; exclude unpaid and cancelled orders.
- Show order/receipt number, payment date/time, table or customer, payment method, cashier, amount, and a link to the order/receipt.
- Search records by order, receipt, table, or customer; filter by order type and payment method; paginate records and show the full matching amount.
- Calendar summary cards always show all paid sales for their periods. Record filters affect the matching amount and list.
- Add interactive charts: a selected day's sales by hour, a month's sales by day, and a year's sales by month. Include zero-sale intervals, exact amounts/counts on selection, and an accessible chart-data table. Chart totals match their calendar summary, independent of record filters. Distinguish repeated local hours with their UTC offsets.
- This is sales visibility; expense accounting, profit calculations, refunds, and financial management remain outside scope.

## 5. Order and status rules

### 5.1 Order lifecycle

Use separate fields for lifecycle, payment, and fulfillment so a paid takeaway order can still be preparing.

| Field | Values | Meaning |
|---|---|---|
| Lifecycle | Open, Closed, Cancelled | Whether operations can continue |
| Payment | Unpaid, Paid | Whether full settlement has been recorded |
| Bill requested | Yes / No | Dine-in request for checkout |
| Takeaway collected timestamp | Empty / Timestamp | Whether the customer received the order |

Derive preparation summaries from item states instead of independently editing an order-level preparation status. Derive table status from its active dine-in order and bill-request flag.

### 5.2 Item lifecycle

- Normal dine-in flow: Draft → Sent → Preparing → Ready → Served.
- Normal takeaway flow: Draft → Sent → Preparing → Ready; collection is recorded on the order.
- Ready may be set directly from Sent for food requiring no preparation.
- A draft can be edited or removed by order-taking staff.
- A manager may cancel an unpaid, unserved line with a mandatory reason. Submitted-line cancellations notify the kitchen and require acknowledgment.
- Served lines and lines on paid orders cannot be cancelled in version 1. Payment corrections/refunds require a later feature.
- If only part of a submitted line must be cancelled, cancel the line and submit a replacement for the remaining quantity.
- Once an unpaid order is fully cancelled, mark it Cancelled, retain its history, and release its table. Kitchen cancellation notices remain visible independently of the order closing.
- A manager can cancel a whole unpaid order only when it contains no served items. Paid orders cannot be cancelled.
- An empty draft order can be discarded and its table released without a kitchen notice.

### 5.3 Billing and menu rules

- The server calculates all monetary totals using fixed-precision values; never use binary floating-point arithmetic for money.
- Capture the item name and price when it is added. Later menu changes do not alter an existing order line.
- Authorized manual price adjustments may replace an unpaid line's captured unit price, with the prior and new values retained in the operational event history. New lines continue to use the current menu price.
- The order total is the sum of non-cancelled quantity × captured unit price. Draft lines are included in the displayed prospective total but must be submitted or removed before payment.
- Quantity must be a positive integer; price cannot be negative.
- Verify availability when adding and submitting food. If a draft item becomes sold out, block submission of that item with a clear message. Existing submitted tickets remain valid.
- Restaurant timezone controls displayed times and date filters; persist timestamps consistently in UTC.

## 6. Technical architecture

### Backend

- Laravel provides the JSON API, authentication, authorization, validation, business rules, database transactions, and real-time events.
- MySQL or MariaDB is the baseline relational database.
- Use Laravel-supported session authentication for a first-party SPA, with CSRF protection. Prefer serving the SPA and API under the same origin.
- Enforce permissions and state transitions on the server, including for direct API requests.
- Broadcast committed operational updates over an authenticated WebSocket channel using a Laravel-compatible implementation. Fall back to periodic refresh while disconnected.
- Select supported framework/runtime versions during project initialization and record them in dependency manifests.

### Frontend

- React SPA with TypeScript, client-side routing, API-backed state, and responsive layouts.
- Role-aware navigation for waiter/POS, kitchen, checkout/history, and settings.
- Server state is authoritative; after an event or reconnect, refresh affected records.
- Preserve an unsent local input buffer during transient network failures and reconcile it against server state before retrying. A draft is saved only when the server acknowledges it.
- Display pending, successful, and failed actions clearly. Never indicate a successful kitchen submission or payment before confirmation.

### Deployment

- One application server, shared database, and a real-time service process; a queue worker is required if the broadcast implementation queues events.
- Either a restaurant-local server or an internet-hosted server. Devices must be able to reach that server.
- A local installation can operate without internet while its server and local network are available. An internet-hosted installation requires internet access.
- Full offline operation and automatic replay of orders/payments are outside scope.
- Use HTTPS in production. Receipt printing uses browser print with a narrow receipt layout and a regular-page fallback; direct printer control is outside scope.

## 7. Core data model

| Entity | Main fields |
|---|---|
| Users | Name, username, password hash, active status |
| User roles | User, predefined role |
| Categories | Name, display order, active status |
| Menu items | Category, name, optional description and image path, price, availability, active status, display order |
| Restaurant tables | Unique name/number, display order, active status |
| Orders | Unique order number, type, table reference when active, table-name snapshot, optional takeaway contact, lifecycle, bill-request flag, created by, version, created/closed/collected timestamps |
| Order items | Order, menu reference, name/price snapshots, quantity, notes, status, kitchen ticket, state timestamps, cancellation reason and actor |
| Kitchen tickets | Order, ticket number, submitted by, submitted timestamp |
| Kitchen cancellation acknowledgments | Item, acknowledged by, acknowledged timestamp |
| Payments | Unique order reference, unique receipt number, amount, method/label snapshot, cash tendered/change when applicable, recorded by, timestamp, idempotency reference |
| Operational events | Order/item reference, action, actor, timestamp, relevant status change and reason |
| Restaurant settings | Restaurant identity, currency/precision, timezone, receipt text |

Use database constraints and transactions to ensure one active order per table, unique order/receipt identifiers, and at most one full payment per order. Preserve table and restaurant receipt information needed for historical reprints. Do not permanently delete completed orders or payments through the application.

## 8. API boundaries

Use versioned JSON endpoints such as `/api/v1`. These resource groups define the implementation boundary; exact route names can be finalized during development.

| Group | Operations |
|---|---|
| Authentication | Login, logout, current user |
| Tables | List with status, create/update/deactivate, open current order |
| Menu | List/search categories and items, maintain menu, update availability |
| Orders | Create, list/filter, get detail, request bill, cancel/discard |
| Order items | Add/edit/remove draft, submit draft batch, authorized cancellation, mark served |
| Kitchen | Active/recent tickets, preparation transitions, acknowledge cancellation |
| Takeaway | Active orders, mark collected |
| Payments | Record full payment/zero-total settlement, retrieve receipt |
| History | Search completed/cancelled orders and receipt details |
| Sales | Read paid-sale records, daily/monthly/yearly totals, and filtered amounts |
| Administration | Staff access and restaurant settings |

Use consistent JSON validation errors and permission responses. Detect stale edits using a version field or equivalent concurrency mechanism, return a conflict response, and refresh the current order rather than overwriting newer changes.

Kitchen submission, payment, and order-creation retries require idempotency keys. A retry must return the original result without creating a duplicate. Transactionally validate and lock relevant records before committing changes; broadcast only after commit. Events include order changes, ticket submission, preparation changes, cancellation/acknowledgment, payment/closure, and availability changes.

## 9. Usability, reliability, and security

- Waiter screens work at 360px viewport width without horizontal page scrolling; touch targets are at least 44px where practical.
- Kitchen labels remain readable at typical tablet/monitor distance. Statuses use text as well as color.
- Preserve the selected table, menu category, and search when navigating back from an order where practical.
- Connected kitchen and waiter screens should receive committed updates within 3 seconds under the agreed deployment conditions. During real-time failure, show disconnection and refresh at least every 10 seconds while API access remains available.
- Initial sizing assumption: one restaurant, up to 30 tables and 15 concurrent staff devices. Validate this workload before release; revise if the restaurant needs more capacity.
- Prevent duplicate payments, duplicate submissions, and two active orders for the same table even when requests arrive simultaneously.
- Hash passwords, throttle login attempts, protect authenticated routes and events, validate all writes, and avoid logging passwords or session tokens.
- Operational events retain the actor and timestamp for submissions, cancellations, preparation, fulfillment, and payments.
- Back up the database automatically each day, retain at least 7 daily backups outside the application directory, and verify a restore before go-live. Limit backup access to authorized operators.
- Display clear network failures and retry controls. Block server-dependent actions while unreachable; never silently queue a payment.

## 10. Acceptance criteria

The first release is complete when the following scenarios pass:

1. A waiter logs in on a phone, selects a table, adds food with notes, and sends it once to the kitchen.
2. Kitchen staff see the correct table, quantities, notes, and submission time without refreshing.
3. A second round of food creates a new ticket containing only the new items.
4. Kitchen staff mark lines preparing/ready; waiters see the update and mark dine-in lines served.
5. A cashier receives full payment, prints a receipt, and the dine-in table becomes available.
6. A takeaway order can be paid before preparation; it stays operationally open until collected.
7. A takeaway order can be collected before payment; it remains open until paid.
8. A manager cancels a submitted unpaid line with a reason, the kitchen acknowledges it, and the total updates correctly.
9. A sold-out item cannot be added or newly submitted, and existing submitted orders remain intact.
10. Menu price/name changes leave existing order amounts and historical receipts unchanged.
11. A double tap or request retry cannot duplicate a ticket, order, or payment.
12. Two devices opening the same free table receive the same active order or a clear conflict; only one active order exists.
13. Stale changes from one device cannot overwrite newer changes from another without a conflict response.
14. Kitchen accounts cannot access checkout or administrative actions, including through direct API requests.
15. A connection failure preserves unsent input, shows that the action failed or is unconfirmed, and reconnecting reconciles server state safely.
16. Paid order contents cannot be changed, and receipt reprints do not create new payments.
17. Order history can find a completed receipt, and a database backup can be restored successfully.

## 11. Suggested implementation sequence

1. Foundation: Laravel API, React SPA, authentication, roles, settings, schema.
2. Setup: menu categories/items, tables, staff administration.
3. Ordering: dine-in/takeaway, drafts, item notes, submission, concurrency protections.
4. Kitchen and serving: ticket display, real-time updates, preparation, cancellations, fulfillment.
5. Checkout: payment, receipts, table release, takeaway closure, history.
6. Release verification: complete acceptance scenarios, device checks, deployment, backup and restore.

## 12. Baseline assumptions to revisit before go-live

- One restaurant, one currency, and one configured local timezone.
- Final menu prices require no additional checkout calculations in this baseline.
- Cash and one externally confirmed payment category are sufficient.
- Dine-in customers settle after food is served; takeaway customers may settle before or after preparation.
- Browser receipt printing is sufficient.
- No payment reversal/refund workflow is included; if routine operations require it, specify it before enabling production payments.
- Retention policy for optional takeaway contact information and completed operational records will be set for the restaurant's deployment.
