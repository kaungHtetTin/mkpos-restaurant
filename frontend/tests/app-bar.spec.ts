import { setLanguage, setTheme } from './toggle-controls';
import { test, expect, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { myMessages } from '../src/locales/my';

async function account(page: Page) {
  const session = await (await page.request.get('/api/v1/session')).json();
  const code = 'appbar-' + randomUUID();
  const response = await page.request.post('/api/v1/register', { headers: { 'X-CSRF-TOKEN': session.csrf }, data: { name: 'App bar Owner', email: code + '@example.test', password: 'Secret123!', password_confirmation: 'Secret123!', business_name: 'Modern Cafe', business_code: code } });
  expect(response.status()).toBe(201);
  const registered = await response.json();
  const setup = await page.request.post('/api/v1/setup', { headers: { 'X-CSRF-TOKEN': registered.csrf, 'Idempotency-Key': randomUUID() }, data: { name: 'Modern Cafe', currency: 'MMK', timezone: 'Asia/Yangon', other_payment_label: 'QR', takeaway_only: false, tables: [{ name: '01', seats: 4 }], menu: [{ name: 'Rice', category: 'Main', price: 6500 }] } });
  expect(setup.ok(), await setup.text()).toBeTruthy();
}

test('app bar actions fit small screens and Settings Back retains the source menu', async ({ page }, info) => {
  await account(page);
  await page.goto('/menu');
  const search = page.getByRole('textbox', { name: 'Find something delicious...' });
  await search.fill('Rice');
  const retained = await search.elementHandle();
  let stateReads = 0;
  page.on('request', request => { if (request.url().includes('/api/v1/state')) stateReads++; });
  const bar = page.locator('.business-app-bar');
  for (const width of info.project.name === 'phone' ? [320, 360, 393, 760] : [1440, 1024]) {
    await page.setViewportSize({ width, height: 900 });
    for (const language of ['en', 'my']) {
      await setLanguage(page, language);
      const controls = [bar.locator('.language-control'), bar.locator('.appearance-control'), bar.getByRole('button', { name: language === 'my' ? myMessages['App menu'] : 'App menu', exact: true })];
      for (const control of controls) {
        const rect = await control.boundingBox();
        expect(rect).not.toBeNull(); expect(rect!.x).toBeGreaterThanOrEqual(0); expect(rect!.x + rect!.width).toBeLessThanOrEqual(width);
        expect(rect!.height).toBeGreaterThanOrEqual(width <= 760 ? 44 : 36);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    }
  }
  await page.setViewportSize(info.project.name === 'phone' ? { width:360, height:800 } : { width:1440, height:1000 });
  await setLanguage(page, 'en');
  await setTheme(page, 'dark');
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
  await setTheme(page, 'light');
  const menuButton = bar.getByRole('button', { name: 'App menu', exact: true });
  await menuButton.focus(); await page.keyboard.press('Enter');
  const menu = page.getByRole('dialog', { name: 'App menu', exact: true });
  await expect(menu).toContainText('App bar Owner');
  await expect(menu.getByRole('link', { name: 'Settings', exact: true })).toBeVisible();
  await expect(menu.getByRole('button', { name: 'Sign out', exact: true })).toBeVisible();
  await page.keyboard.press('Escape'); await expect(menu).toBeHidden(); await expect(menuButton).toBeFocused();
  await expect(search).toHaveValue('Rice');
  await menuButton.click(); await menu.getByRole('button', {name:'Close dialog',exact:true}).click(); await expect(menu).toBeHidden();
  await menuButton.click(); await page.mouse.click(2, 2); await expect(menu).toBeHidden();
  if (info.project.name === 'phone') await menuButton.tap(); else await menuButton.click();
  expect(await page.evaluate(() => !window.dispatchEvent(new Event('restaurant:back-request', { cancelable:true })))).toBeTruthy();
  await expect(menu).toBeHidden();
  await menuButton.click();
  const beforeSettings = stateReads;
  await menu.getByRole('link', { name: 'Settings', exact: true }).click();
  await expect(page).toHaveURL(/\/settings$/);
  await expect(page.getByRole('button', { name: 'Printing', exact: true })).toHaveCount(0);
  await page.goBack();
  await expect(search).toHaveValue('Rice');
  expect(await retained!.evaluate(node => node.isConnected)).toBeTruthy();
  expect(stateReads).toBe(beforeSettings);
  await page.goForward(); await expect(page.getByRole('heading', { name: 'Make yourself at home.' })).toBeVisible();
  await page.goBack(); await expect(search).toHaveValue('Rice');
  await setLanguage(page, 'my');
  await bar.screenshot({ path: `../artifacts/modern-app-bar-${info.project.name}.png` });
  await setLanguage(page, 'en');
  if (info.project.name === 'phone') await bar.getByRole('link', { name:'Home', exact:true }).click();
  else await page.locator('.sidebar .brand').click();
  await expect(page).toHaveURL(/\/tables$/);
  await menuButton.click();
  let logouts=0;
  await page.route('**/api/v1/logout', route => { logouts++; return route.fulfill({ status:200, contentType:'application/json', body:'{}' }); });
  await menu.getByRole('button', { name:'Sign out', exact:true }).click();
  await expect(page.getByRole('button', { name:'Sign in', exact:true })).toBeVisible();
  expect(logouts).toBe(1);
});

test('native cashier has only device Printing settings; browser manager has no Printing tab', async ({ page }, info) => {
  await account(page);
  await page.goto('/settings?tab=printing');
  await expect(page.locator('.settings-tabs').getByRole('button', {name:'Printing',exact:true})).toHaveCount(0);
  await expect(page.getByLabel('Restaurant name', { exact:true })).toBeVisible();
  await page.addInitScript(({platform}) => {
    const config={ connection:'system', paper:'80mm', host:'', port:9100, address:'', printerName:'', documentPrinterName:'', copies:1,cut:false,color:false,landscape:false,bluetooth_mode:'standard' };
    window.RestaurantPrinter={ platform, getConfig:async()=>config, saveConfig:async({config})=>config, listDevices:async()=>({items:[]}),listPaired:async()=>({items:[]}),checkConnection:async()=>({ok:true}),openBluetoothSettings:async()=>{},openSystemSettings:async()=>{},print:async()=>({ok:true}) };
  }, {platform:info.project.name === 'phone' ? 'android' as const : 'windows' as const});
  for (const url of ['**/api/v1/session', '**/api/v1/state']) await page.route(url, async route => { const response=await route.fetch(); const data=await response.json(); if (data.user) data.user.roles=['cashier']; await route.fulfill({response,json:data}); });
  await page.reload();
  await expect(page.locator('.settings-tabs button')).toHaveCount(1);
  await expect(page.locator('.settings-tabs button')).toHaveText('Printing');
  await expect(page.getByLabel('Connection type', {exact:true})).toBeEnabled();
  await expect(page.getByLabel('Restaurant name', {exact:true})).toHaveCount(0);
  await expect(page.getByRole('button', {name:'Save restaurant details',exact:true})).toHaveCount(0);
  await page.screenshot({path:`../artifacts/native-printing-tab-${info.project.name}.png`,fullPage:true});
});
