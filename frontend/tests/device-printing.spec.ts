import { test, expect, type Page } from '@playwright/test';

async function prepare(page: Page, platform: 'windows' | 'android' = 'windows') {
  await page.addInitScript(({ platform }) => {
    const saved = { connection: 'system', paper: '80mm', host: '', port: 9100, address: '', printerName: '', documentPrinterName: '', copies: 1, cut: false, color: false, landscape: false, bluetooth_mode: 'standard' };
    const state = { saved, printCalls: [] as { html: string; kind: string }[], saveCalls: 0, failNext: false, pairing: 0, systemSettings: 0 };
    Object.assign(window, { printerTest: state });
    window.RestaurantPrinter = {
      platform, getConfig: async () => ({ ...state.saved }) as never,
      saveConfig: async ({ config }) => { state.saved = { ...config }; state.saveCalls++; return { ...config }; },
      listDevices: async () => ({ items: [{ name: 'Receipt USB', address: 'Receipt USB' }] }),
      listPaired: async () => ({ items: [{ name: 'Thermal 58', address: platform === 'windows' ? 'COM5' : '00:11:22:33:44:55' }] }),
      openBluetoothSettings: async () => { state.pairing++; }, openSystemSettings: async () => { state.systemSettings++; },
      checkConnection: async () => ({ message: 'Connection opened. No print data was sent.' }),
      print: async payload => { state.printCalls.push(payload); await new Promise(resolve => setTimeout(resolve, 250)); if (state.failNext) { state.failNext = false; throw new Error('Printer unavailable. Check power.'); } return { status: 'sent', message: 'Receipt data sent. Check the printer before printing again.' }; },
    };
  }, { platform });
  const session = await (await page.request.get('/api/v1/session')).json();
  const id = `printer-${platform}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
  const registration = await page.request.post('/api/v1/register', { headers: { 'X-CSRF-TOKEN': session.csrf }, data: { name: 'Printing QA', email: id + '@example.test', password: 'PrinterPassword123!', password_confirmation: 'PrinterPassword123!', business_name: 'Printer Cafe', business_code: id } });
  expect(registration.status()).toBe(201);
  const account = await registration.json();
  const setup = await page.request.post('/api/v1/setup', { headers: { 'X-CSRF-TOKEN': account.csrf, 'Idempotency-Key': crypto.randomUUID() }, data: { name: 'Printer Cafe', currency: 'MMK', timezone: 'Asia/Yangon', other_payment_label: 'QR / Card', takeaway_only: false, tables: [{ name: '01', seats: 4 }], menu: [{ name: 'Rice', category: 'Main', price: 6500 }] } });
  expect(setup.ok(), await setup.text()).toBeTruthy();
  await page.goto('/settings');
  await page.getByRole('button', { name: 'Receipts', exact: true }).click();
  return account;
}
test('device-only save, failed print retry and Back preserve the business receipt draft', async ({ page }, info) => {
  await prepare(page);
  await page.getByLabel('Receipt title', { exact: true }).fill('Unsaved business receipt title');
  const title = await page.getByLabel('Receipt title', { exact: true }).elementHandle();
  let writes = 0, reads = 0;
  page.on('request', request => { if (request.url().includes('/api/v1/') && request.method() !== 'GET') writes++; if (request.url().includes('/api/v1/state')) reads++; });
  await page.locator('.settings-tabs').getByRole('button', { name: 'Printing', exact: true }).click();
  const dialog = page.locator('[data-settings-pane=printing]');
  await expect(dialog.getByLabel('Connection type')).toBeEnabled();
  await dialog.getByRole('button', { name: 'Refresh printers', exact: true }).click();
  await expect(dialog.locator('datalist option')).toHaveAttribute('value', 'Receipt USB');
  await dialog.getByRole('button', { name: 'Open Windows printer settings', exact: true }).click();
  expect(await page.evaluate(() => (window as unknown as {printerTest:{systemSettings:number}}).printerTest.systemSettings)).toBe(1);
  await dialog.getByLabel('Connection type').selectOption('tcp');
  await dialog.getByLabel('Printer paper', { exact: true }).selectOption('58mm');
  await dialog.getByLabel('Printer IP / hostname', { exact: true }).fill('192.168.1.101');
  await dialog.getByLabel('Printer port', { exact: true }).fill('9100');
  await dialog.getByLabel('Cut paper automatically').check();
  await expect(dialog.getByRole('button', { name: 'Test saved printer' })).toBeDisabled();
  await dialog.getByRole('button', { name: 'Check connection', exact: true }).click();
  await expect(dialog.getByRole('status')).toContainText('No print data was sent');
  await dialog.getByRole('button', { name: 'Save printer settings', exact: true }).click();
  await expect(dialog.getByRole('status')).toContainText('Printer settings saved');
  await dialog.screenshot({ path: `../artifacts/device-printer-${info.project.name}.png` });
  await page.evaluate(() => { (window as unknown as { printerTest: { failNext: boolean } }).printerTest.failNext = true; });
  await dialog.getByRole('button', { name: 'Test saved printer', exact: true }).click();
  await expect(dialog.getByRole('button', { name: 'Test saved printer', exact: true })).toBeDisabled();
  await expect(dialog.locator('[role=alert]')).toContainText('Printer unavailable');
  await expect(dialog.getByLabel('Printer IP / hostname')).toHaveValue('192.168.1.101');
  await dialog.getByRole('button', { name: 'Test saved printer', exact: true }).click();
  await expect(dialog.getByRole('status')).toContainText('Test print submitted');
  const output = await page.evaluate(() => (window as unknown as { printerTest: { printCalls: { html: string; kind: string }[]; saveCalls: number } }).printerTest);
  expect(output.saveCalls).toBe(1); expect(output.printCalls).toHaveLength(2);
  expect(output.printCalls[1].kind).toBe('receipt');
  expect(output.printCalls[1].html).toContain('data:font/woff2;base64,');
  expect(output.printCalls[1].html).toContain('စမ်းသပ်ပုံနှိပ်ခြင်း');
  expect(output.printCalls[1].html).toContain('width:58mm');
  const beforeClose = reads;
  await page.goBack();
  await expect(dialog).not.toBeVisible();
  expect(await title!.evaluate(node => node.isConnected)).toBe(true);
  await expect(page.getByLabel('Receipt title', { exact: true })).toHaveValue('Unsaved business receipt title');
  expect(reads).toBe(beforeClose); expect(writes).toBe(0);
  await page.locator('.settings-tabs').getByRole('button', { name: 'Printing', exact: true }).click();
  await expect(dialog.getByLabel('Printer IP / hostname')).toHaveValue('192.168.1.101');
  await page.goBack();
  for (const label of ['Restaurant', 'Tax & charges', 'Tables', 'Categories', 'Staff access', 'Receipts']) {
    const tab = page.locator('.settings-tabs').getByRole('button', { name:label, exact:true });
    await tab.click(); await expect(tab).toHaveClass('selected');
  }
  await expect(page.getByLabel('Receipt title', { exact:true })).toHaveValue('Unsaved business receipt title');
  expect(await title!.evaluate(node => node.isConnected)).toBe(true);
  expect(reads).toBe(beforeClose); expect(writes).toBe(0);
  await page.getByRole('button', { name:'Test receipt', exact:true }).click();
  const receiptUrl = page.url();
  expect(await page.evaluate(() => !window.dispatchEvent(new Event('restaurant:back-request', { cancelable:true })))).toBeTruthy();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  expect(page.url()).toBe(receiptUrl);
  await page.getByRole('button', { name:'Test receipt', exact:true }).click();
  await page.getByRole('dialog').getByRole('button', { name:'Printer settings on this device', exact:true }).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  await expect(dialog).toBeVisible();
  await page.goBack();
  await expect(page.getByLabel('Receipt title', {exact:true})).toHaveValue('Unsaved business receipt title');
  expect(await title!.evaluate(node => node.isConnected)).toBe(true);
  expect(writes).toBe(0);
});
test('Android printer discovery and system fallback settings remain device-local', async ({ page }) => {
  await prepare(page, 'android');
  await page.locator('.settings-tabs').getByRole('button', { name: 'Printing', exact: true }).click();
  const dialog = page.locator('[data-settings-pane=printing]');
  await expect(dialog.getByLabel('Connection type')).toBeEnabled();
  await expect(dialog).toContainText('Android system printing requires the system dialog');
  await expect(dialog.getByLabel('Printer copies')).toHaveCount(0);
  await dialog.getByLabel('Connection type').selectOption('bluetooth');
  await expect(dialog.getByLabel('Printer copies')).toBeVisible();
  await dialog.getByRole('button', { name: 'Open Bluetooth pairing', exact: true }).click();
  await expect(dialog.getByRole('button', { name: 'Refresh paired printers' })).toBeEnabled();
  await dialog.getByRole('button', { name: 'Refresh paired printers', exact: true }).click();
  await dialog.getByLabel('Paired Bluetooth printer', { exact: true }).selectOption('00:11:22:33:44:55');
  await dialog.getByLabel('Bluetooth print mode', { exact: true }).selectOption('compatibility');
  await dialog.getByRole('button', { name: 'Save printer settings', exact: true }).click();
  await expect(dialog.getByRole('status')).toContainText('Printer settings saved');
  await page.goBack();
  await expect(dialog).not.toBeVisible();
  expect(await page.evaluate(() => (window as unknown as { printerTest: { pairing: number } }).printerTest.pairing)).toBe(1);
});
test('single table QR uses the receipt printer and batch QR uses an A4 document job', async ({ page }, info) => {
  test.skip(info.project.name === 'phone', 'Shared print-document routing is checked once; device settings and bill controls are covered on both layouts.');
  const account = await prepare(page);
  const enabled = await page.request.post('/api/v1/guest-orders/configure', { headers: { 'X-CSRF-TOKEN': account.csrf, 'Idempotency-Key': crypto.randomUUID() }, data: { action: 'enable' } });
  expect(enabled.ok(), await enabled.text()).toBeTruthy();
  await page.goto('/guest-orders');
  await page.getByRole('button', { name: 'Menu & QR codes', exact: true }).click();
  await page.getByRole('button', { name: 'View / print QR', exact: true }).first().click();
  await page.getByRole('button', { name: 'Print QR code', exact: true }).click();
  await expect.poll(() => page.evaluate(() => (window as unknown as { printerTest: { printCalls: unknown[] } }).printerTest.printCalls.length)).toBe(1);
  await page.getByRole('button', { name: 'Close dialog', exact: true }).click();
  await page.getByRole('button', { name: 'Print table QR codes', exact: true }).click();
  await page.getByRole('button', { name: 'Print all tables', exact: true }).click();
  await expect.poll(() => page.evaluate(() => (window as unknown as { printerTest: { printCalls: unknown[] } }).printerTest.printCalls.length)).toBe(2);
  const jobs = await page.evaluate(() => (window as unknown as { printerTest: { printCalls: { kind: string; html: string }[] } }).printerTest.printCalls);
  expect(jobs[0].kind).toBe('receipt'); expect(jobs[0].html).toContain('data:image/png;base64,');
  expect(jobs[1].kind).toBe('document'); expect(jobs[1].html).toContain('@page{size:A4;');
});
