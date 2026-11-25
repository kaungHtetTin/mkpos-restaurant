import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';

test('table bills print before payment and preserve checkout and partial balances', async ({ page }, info) => {
  if (info.project.name === 'phone') await page.setViewportSize({ width: 390, height: 844 });
  await page.addInitScript(() => {
    Object.assign(window, { billPrintCalls: 0 });
    window.print = () => {
      const target = window as unknown as { billPrintCalls: number };
      target.billPrintCalls++;
      window.dispatchEvent(new Event('afterprint'));
    };
  });
  const session = await (await page.request.get('/api/v1/session')).json();
  const code = `print-bill-${info.project.name}-${Date.now()}`;
  const registered = await page.request.post('/api/v1/register', { headers: { 'X-CSRF-TOKEN': session.csrf }, data: {
    name: 'Bill Owner', email: `${code}@example.test`, password: 'BillPassword123!', password_confirmation: 'BillPassword123!', business_name: 'Bill Cafe', business_code: code,
  } });
  expect(registered.status()).toBe(201);
  const account = await registered.json();
  const action = async (path: string, data: Record<string, unknown>) => {
    const response = await page.request.post(`/api/v1/${path}`, { headers: { 'X-CSRF-TOKEN': account.csrf, 'Idempotency-Key': randomUUID() }, data });
    expect(response.ok(), await response.text()).toBeTruthy();
    return response.json();
  };
  await action('setup', { name: 'Bill Cafe', currency: 'MMK', timezone: 'Asia/Yangon', other_payment_label: 'QR / Card', takeaway_only: false, tables: [{ name: 'A1', seats: 4 }], menu: [{ name: 'Rice', category: 'Main', price: 6500 }] });
  const state = await (await page.request.get('/api/v1/state')).json();
  let order = await action('orders', { type: 'dine_in', table_id: state.tables[0].id });
  const command = async (name: string, data: Record<string, unknown> = {}) => {
    order = await action(`orders/${order.id}/${name}`, { version: order.version, ...data });
  };
  await command('add-item', { menu_item_id: state.menu[0].id, quantity: 2 });
  await page.goto(`/orders/${order.id}`);
  const openCart = async () => { if (info.project.name === 'phone') await page.getByRole('button', { name: /View order ·/ }).click(); };
  await openCart();
  await expect(page.getByRole('button', { name: 'Print bill', exact: true })).toBeDisabled();
  await command('submit');
  await command('discount', { discount_type: 'fixed', discount_value: 500, reason: 'Manager approved discount' });
  await page.reload(); await openCart();
  await expect(page.getByRole('button', { name: 'Collect payment', exact: true })).toBeDisabled();
  const beforeVersion = order.version;
  await page.getByRole('button', { name: 'Print bill', exact: true }).click();
  const dialog = page.getByRole('dialog', { name: 'UNPAID BILL', exact: true });
  const paper = dialog.locator('#printable-receipt');
  await expect(paper).toContainText('2 × Rice');
  await expect(paper).toContainText('13,000 MMK');
  await expect(paper).toContainText('12,500 MMK');
  await expect(paper).toContainText('Remaining balance: 12,500 MMK');
  await expect(paper).toContainText('This bill is not proof of payment.');
  await expect(paper).not.toContainText('Cash received');
  await expect(paper).toHaveAttribute('data-paper-width', '80');
  await dialog.getByRole('button', { name: 'Print bill', exact: true }).click();
  await expect.poll(() => page.evaluate(() => (window as unknown as { billPrintCalls: number }).billPrintCalls)).toBe(1);
  await dialog.getByRole('button', { name: 'Close dialog', exact: true }).click();
  const unchanged = await (await page.request.get(`/api/v1/orders/${order.id}`)).json();
  expect(unchanged.version).toBe(beforeVersion); expect(unchanged.paid).toBe(false);
  expect(unchanged.payments).toHaveLength(0); expect(unchanged.status).toBe('open'); expect(unchanged.table_ids).toHaveLength(1);
  await command('ready', { item_id: order.items[0].id });
  await command('serve', { item_id: order.items[0].id });
  await command('pay', { mode: 'amount', amount: 5000, tenders: [{ method: 'cash', amount: 5000, tendered: 5000 }] });
  await page.reload(); await openCart();
  await page.getByRole('button', { name: 'Print bill', exact: true }).click();
  await expect(paper).toContainText('Received: 5,000 MMK');
  await expect(paper).toContainText('Remaining balance: 7,500 MMK');
  await dialog.getByRole('button', { name: 'Close dialog', exact: true }).click();
  await page.getByRole('button', { name: 'Collect payment', exact: true }).click();
  await expect(page.getByRole('dialog', { name: 'Let’s settle up', exact: true })).toBeVisible();
});
