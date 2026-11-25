# Initial implementation status

Date: 10 November 2026

The first local implementation is functional. It is ready for restaurant workflow review; full production acceptance remains pending.

| Specification area | Current implementation |
|---|---|
| Laravel API + React TypeScript SPA | Implemented, served under the same origin |
| Yellow theme and phone layout | Implemented; browser checked at 360px and desktop widths |
| Authentication and roles | Implemented; API permissions tested |
| Tables and one active order | Implemented, including a database uniqueness constraint |
| Takeaway | Implemented, both payment-before-collection and collection-before-payment |
| Menu and administration | Implemented: categories, food, photo upload/replacement/removal, availability, tables, staff, restaurant |
| Kitchen tickets and preparation | Implemented with per-line status and cancellation acknowledgment |
| Serving and collection | Implemented |
| Payment and receipts | Implemented: whole currency units, cash / externally confirmed other payment |
| Receipt format settings | Implemented: title, multiline footer, 56/58/80/88/112 mm and custom widths, live preview, test print, and immutable historical format snapshots |
| History | Implemented: search, date/type/status filters, pagination, receipt reprint, events |
| Sales | Implemented: paid-sale records, daily/monthly/yearly calendar totals, record filters, matching amounts, pagination, receipt links; manager/cashier access |
| Duplicate and stale mutation protection | Implemented and covered by backend feature tests |
| Automatic device updates | Polling every 3 seconds; WebSocket broadcasting pending |
| Local database | SQLite configured; MySQL/MariaDB migrations provided but not yet tested on those engines |
| Backup scheduling and restoration | Deployment work pending |
| Target-network capacity and receipt printer | On-site verification pending |

## Deliberate first-version choices

- A line's full quantity moves through preparation together; use separate lines for independent preparation.
- Additional rounds create separate kitchen tickets on the same bill.
- Collected takeaway bill contents are locked; unpaid collected orders still accept settlement.
- Cancellation notices remain visible after order closure until acknowledged.
- Browser printing is provided, without direct device/printer integration.
- The phone interface has bottom navigation and a full order sheet; desktop uses a sidebar and an order panel.
- Branding uses “Little Lemon”; the restaurant name and receipt details are editable in Settings.
- Unsent order lines are server-saved after successful mutations. Failed modal input remains in the open form for retry; full offline synchronization and crash recovery are outside this increment.

## Validation

Price controls: **28 price-adjustment and order-workflow backend tests passed**, with 221 assertions. Both desktop/phone browser scenarios passed for price visibility persistence, order-only edits, checkout recalculation, manual cash received preservation, receipt totals, paid-order locking, and visible price-change history. The production frontend build passed.

Receipt settings checks: **34 receipt/settings and order-workflow backend tests passed**, with 219 assertions. New desktop/phone browser checks passed for live preview, persistence, custom widths, generated print-page sizing, 56 mm printed content width, and historical format retention. Existing desktop checkout/print scenarios passed. The frontend build passed. Physical printer verification remains pending.

Sales chart checks: **21 sales report tests and 111 assertions passed**, including zero intervals, local-hour grouping, leap-month day counts, and skipped/repeated daylight-saving hours. Both desktop/phone sales browser scenarios passed with chart switching, keyboard selection, chart-data tables, total reconciliation, and empty-period checks. The frontend production build passed.

Sales feature validation: **18 backend report tests and 73 assertions passed**, covering calendar boundaries, leap day, daylight-saving changes, payment amounts, filters, pagination, and access. Both desktop and phone sales browser scenarios passed, including tablet-width filter checks.

Latest run: **27 backend tests and 186 assertions passed**, including photo persistence, validation, access control, replacement/removal, and upload retries. Both new desktop/phone photo browser scenarios passed; the six original workflow scenarios passed in the previous run. The React TypeScript production build passed. The application is served at `http://localhost:8002`.

Backend coverage includes full dine-in flow, both takeaway settlement timings, kitchen submission batches, cancellation handling, role boundaries, availability, immutable snapshots, duplicate requests/payments, stale changes, last-manager protection, occupied-table protection, and zero-total settlement.

Browser coverage includes desktop and 360px phone ordering, kitchen notes, preparation, serving, checkout, payment before/after collection, receipt presentation/print styling, menu/settings navigation, and horizontal overflow checks. Tests use a separate database.

These checks do not replace simultaneous-device testing, backup restore verification, or a real receipt printer check before go-live.
