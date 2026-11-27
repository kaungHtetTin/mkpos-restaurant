import { test, expect } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { myMessages } from '../src/locales/my';

test('Myanmar translations retain interpolation values and Unicode text', () => {
  for (const [english, myanmar] of Object.entries(myMessages)) {
    expect(myanmar, english).not.toMatch(/\?{3}|\uFFFD/);
    expect(myanmar, english).toMatch(/[\u1000-\u109f]/);
    const placeholders = (text: string) => [...text.matchAll(/\{(\w+)\}/g)].map(match => match[1]).sort();
    expect(placeholders(myanmar), english).toEqual(placeholders(english));
  }
});

test('language persists, syncs tabs, and preserves sign-in values while office stays English', async ({ page }) => {
  await page.goto('/');
  await page.getByLabel('Email', { exact: true }).fill('keep@example.test');
  await page.evaluate(() => { (window as any).__localeMount = 'retained'; });
  const other = await page.context().newPage(); await other.goto('/');
  await page.locator('.language-control select').selectOption('my');
  await expect(page.locator('html')).toHaveAttribute('lang', 'my');
  await expect(page.getByLabel(myMessages.Email, { exact: true })).toHaveValue('keep@example.test');
  await expect(other.locator('.language-control select')).toHaveValue('my');
  await expect(page.getByRole('button', { name: myMessages['Sign in'], exact: true })).toBeVisible();
  expect(await page.evaluate(() => (window as any).__localeMount)).toBe('retained');
  await page.reload(); await expect(page.locator('.language-control select')).toHaveValue('my');
  await page.goto('/office'); await expect(page.getByRole('heading', { name: 'Office sign in.', exact: true })).toBeVisible();
  await expect(page.locator('html')).toHaveAttribute('lang', 'en');
  await other.close();
});

test('required fields and server authentication errors use the selected language', async ({ page }) => {
  await page.goto('/');
  await page.locator('.language-control select').selectOption('my');
  await page.getByRole('button', { name: myMessages['Sign in'], exact: true }).click();
  await expect.poll(() => page.locator('input[name=email]').evaluate((input: HTMLInputElement) => input.validationMessage)).toBe(myMessages['Please fill out this field.']);
  await page.getByLabel(myMessages.Email, { exact: true }).fill('no-such-locale-user@example.test');
  await page.getByLabel(myMessages.Password, { exact: true }).fill('IncorrectPassword!');
  await page.getByRole('button', { name: myMessages['Sign in'], exact: true }).click();
  await expect(page.getByRole('alert')).toHaveText(myMessages['The email or password is incorrect.']);
  await page.locator('.language-control select').selectOption('en');
  await expect(page.getByRole('alert')).toHaveText('The email or password is incorrect.');
});

test('owner language changes preserve current orders, editable forms, and business data', async ({ page }, info) => {
  test.setTimeout(90000);
  const errors: string[] = []; page.on('pageerror', error => errors.push(error.message));
  const code = `locale-${info.project.name}-${Date.now()}`;
  const session = await (await page.request.get('/api/v1/session')).json();
  const registration = await page.request.post('/api/v1/register', { headers: { 'X-CSRF-TOKEN': session.csrf }, data: { name: 'Owner EN', email: code + '@example.test', password: 'Secret123!', password_confirmation: 'Secret123!', business_name: 'Owner Cafe EN', business_code: code } });
  expect(registration.status()).toBe(201); const account = await registration.json();
  const setup = await page.request.post('/api/v1/setup', { headers: { 'X-CSRF-TOKEN': account.csrf, 'Idempotency-Key': randomUUID() }, data: { name: 'Owner Cafe EN', currency: 'MMK', timezone: 'Asia/Yangon', other_payment_label: 'Custom Pay EN', takeaway_only: false, tables: [{ name: 'A1', seats: 4 }], menu: [{ name: 'Rice EN', category: 'Custom category EN', price: 6500 }] } });
  expect(setup.ok()).toBeTruthy();
  await page.goto('/tables'); await expect(page.locator('.connection')).toContainText('Connected');
  await page.locator('.language-control select').selectOption('my');
  await expect(page.locator('.breadcrumb strong')).toHaveText(myMessages.Tables);
  await page.getByRole('button', { name: `${myMessages['Table {value1}'].replace('{value1}', 'A1')}, ${myMessages.available}`, exact: true }).click();
  await page.getByRole('button', { name: myMessages['Add {value1}'].replace('{value1}', 'Rice EN'), exact: true }).click();
  await expect(page.locator('.cart-total')).toContainText('6,500 MMK');
  await expect(page.locator('.toast')).toContainText(myMessages['{value1} added.'].replace('{value1}', 'Rice EN'));
  await page.locator('.language-control select').selectOption('en');
  await expect(page.locator('.cart-total')).toContainText('6,500 MMK');
  await expect(page.locator('.toast')).toContainText('Rice EN added.');
  await expect(page.locator('.food-info h3')).toHaveText('Rice EN');
  await page.locator('.language-control select').selectOption('my');
  await expect(page.locator('.toast')).toContainText(myMessages['{value1} added.'].replace('{value1}', 'Rice EN'));
  await page.screenshot({ path: `test-results/${info.project.name}-myanmar-order.png`, fullPage: true, animations: 'disabled' });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
  await page.goto('/finance?tab=categories');
  await page.getByRole('button', { name: myMessages['Add category'], exact: true }).click();
  await page.getByLabel(myMessages['Category name'], { exact: true }).fill('Custom category EN');
  await page.getByRole('button', { name: myMessages['Save changes'], exact: true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await page.getByRole('button', { name: myMessages.Entries, exact: true }).click();
  await page.getByRole('button', { name: myMessages['Add entry'], exact: true }).click();
  await page.getByLabel(myMessages.Description, { exact: true }).fill('Unsent description EN');
  await page.evaluate(() => document.querySelector<HTMLSelectElement>('.language-control select')?.focus());
  await page.locator('.language-control select').selectOption('en', { force: true });
  await expect(page.getByLabel('Description', { exact: true })).toHaveValue('Unsent description EN');
  await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  const state = await (await page.request.get('/api/v1/state')).json();
  expect(state.business.name).toBe('Owner Cafe EN'); expect(state.menu[0].name).toBe('Rice EN');
  const finance = await (await page.request.get('/api/v1/business-finance')).json();
  expect(finance.categories[0].name).toBe('Custom category EN'); expect(finance.entries.total).toBe(0);
  await page.locator('.language-control select').selectOption('my');
  const screens = [
    ['/sales', 'Every sale, in one place'], ['/shifts', 'Cashier shifts'], ['/tables', 'Your floor, at a glance'],
    ['/takeaway', 'Takeaway orders'], ['/kitchen', 'Let’s get cooking'], ['/guest-orders?tab=links', 'Guest requests'],
    ['/menu', 'Made to make you hungry'], ['/finance', 'Income & expenses'], ['/history', 'Order history'],
    ['/help', 'Help & support'], ['/billing', 'Subscription'], ['/settings', 'Make yourself at home'],
  ];
  for (const [route, title] of screens) {
    await page.goto(route); await expect(page.locator('h1:visible')).toHaveText(myMessages[title] + '.');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), route).toBeTruthy();
    await expect(page.locator('html')).toHaveAttribute('lang', 'my');
  }
  for (const label of ['Restaurant', 'Receipts', 'Tax & charges', 'Tables', 'Categories', 'Staff access']) {
    await expect(page.locator('.settings-tabs').getByRole('button', { name: myMessages[label], exact: true })).toBeVisible();
  }
  await page.goto('/sales?period=year&date=2026-11-20');
  await expect(page.locator('.chart-x-label').first()).toHaveText(/[\u1000-\u109f]/);

  // Editors retain their configured labels as well as values while language changes.
  await page.goto('/menu');
  await page.getByRole('button', { name: myMessages['Add menu item'], exact: true }).click();
  await page.getByLabel(myMessages['Food name'], { exact: true }).fill('Unsent dish EN');
  await page.locator('.language-control select').selectOption('en', { force: true });
  await expect(page.getByRole('dialog')).toHaveAccessibleName('A new addition');
  await expect(page.getByLabel('Food name', { exact: true })).toHaveValue('Unsent dish EN');
  await expect(page.getByRole('spinbutton', { name: /^Base price \(MMK, whole units\)/ })).toBeVisible();
  await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  await page.locator('.language-control select').selectOption('my');

  // A failed server read stays canonical so an already-visible error can change language.
  const unavailable = '**/api/v1/business-finance?*';
  await page.route(unavailable, route => route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Server Error' }) }));
  await page.goto('/finance');
  await expect(page.getByRole('alert')).toContainText(myMessages['Server Error']);
  await page.locator('.language-control select').selectOption('en');
  await expect(page.getByRole('alert')).toContainText('Server Error');
  await page.unroute(unavailable);
  await page.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(page.getByRole('alert')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('Myanmar receipts and refund reports localize cash while retaining custom payment names', async ({ page }, info) => {
  const code = `locale-pay-${info.project.name}-${Date.now()}`;
  const session = await (await page.request.get('/api/v1/session')).json();
  const registration = await page.request.post('/api/v1/register', { headers: { 'X-CSRF-TOKEN': session.csrf }, data: { name: 'Owner EN', email: code + '@example.test', password: 'Secret123!', password_confirmation: 'Secret123!', business_name: 'Payment Cafe EN', business_code: code } });
  expect(registration.status()).toBe(201); const account = await registration.json();
  const post = async (path: string, data: object) => {
    const response = await page.request.post('/api/v1/' + path, { headers: { 'X-CSRF-TOKEN': account.csrf, 'Idempotency-Key': randomUUID() }, data });
    expect(response.ok(), await response.text()).toBeTruthy(); return response.json();
  };
  // A custom non-cash label may legitimately be the same English word as the built-in cash label.
  await post('setup', { name: 'Payment Cafe EN', currency: 'MMK', timezone: 'Asia/Yangon', other_payment_label: 'Cash', takeaway_only: true, tables: [], menu: [{ name: 'Rice EN', category: 'Main EN', price: 6500 }] });
  await post('shifts/open', { opening_cash: 10000 });
  const state = await (await page.request.get('/api/v1/state')).json();
  const paid = async (method: string) => {
    let order = await post('orders', { type: 'takeaway' });
    order = await post(`orders/${order.id}/add-item`, { version: order.version, menu_item_id: state.menu[0].id, quantity: 1 });
    order = await post(`orders/${order.id}/submit`, { version: order.version });
    return post(`orders/${order.id}/pay`, { version: order.version, method, tendered: 7000, confirmed: true });
  };
  const cashOrder = await paid('cash'); const otherOrder = await paid('other');
  await post(`payments/${cashOrder.payment.id}/refund`, { version: cashOrder.payment.adjustment_version, amount: 1000, method: 'cash', confirmed: true, reason: 'Agreed refund EN' });
  await page.goto(`/orders/${cashOrder.id}`);
  await page.locator('.language-control select').selectOption('my');
  await page.getByRole('button', { name: myMessages.Receipt, exact: true }).click();
  await expect(page.getByRole('dialog').locator('.receipt-paper')).toContainText(myMessages.Cash);
  await expect(page.getByRole('dialog').locator('.receipt-paper')).toContainText('Rice EN');
  await page.screenshot({ path: `test-results/${info.project.name}-myanmar-receipt.png`, fullPage: true });
  await page.getByRole('button', { name: myMessages['Close dialog'], exact: true }).click();
  await page.goto(`/orders/${otherOrder.id}`);
  await page.getByRole('button', { name: myMessages.Receipt, exact: true }).click();
  await expect(page.getByRole('dialog').locator('.receipt-paper-meta > span').filter({ hasText: /^Cash$/ })).toHaveCount(1);
  await page.getByRole('button', { name: myMessages['Close dialog'], exact: true }).click();
  await page.goto('/sales');
  await expect(page.locator('.adjustment-report tbody tr')).toContainText(myMessages.Cash);
  await page.goto('/shifts');
  await expect(page.locator('.shift-financial-adjustments')).toContainText(myMessages.Cash);
});
