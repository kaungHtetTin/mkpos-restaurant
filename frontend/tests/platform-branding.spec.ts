import { test, expect } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import path from 'node:path';

test.beforeAll(() => {
  if (!process.env.E2E_DATABASE?.includes('test.sqlite')) throw new Error('Use an isolated test database.');
  const result = spawnSync('D:/xampp/php/php.exe', ['artisan', 'platform:admin', '--no-ansi'], { cwd:path.resolve('../backend'),input:'Branding QA\nbranding-qa@example.test\nBranding123!\n',encoding:'utf8',env:{...process.env,DB_DATABASE:process.env.E2E_DATABASE} });
  if(result.status!==0 && !result.stdout.includes('already been taken')) throw new Error(result.stdout+result.stderr);
});

test('office name changes reach open apps without reloading or clearing forms and carts', async({page,browser},info)=>{
 test.setTimeout(90000);
 const original=(await(await page.request.get('/api/v1/platform/branding')).json()).name;
 const session=await(await page.request.get('/api/v1/office/session')).json();
 const login=await page.request.post('/api/v1/office/login',{headers:{'X-CSRF-TOKEN':session.csrf},data:{email:'branding-qa@example.test',password:'Branding123!'}});expect(login.ok()).toBeTruthy();
 const office=await login.json();
 const owner=await browser.newPage({baseURL:new URL(test.info().project.use.baseURL || process.env.E2E_BASE_URL!).origin,viewport:info.project.use.viewport});
 const auth=await browser.newPage({baseURL:process.env.E2E_BASE_URL,viewport:info.project.use.viewport});
 const officeAuth=await browser.newPage({baseURL:process.env.E2E_BASE_URL,viewport:info.project.use.viewport});
 const guest=await browser.newPage({baseURL:process.env.E2E_BASE_URL,viewport:info.project.use.viewport});
 try {
  const code=`brand-${info.project.name}-${Date.now()}`;
  const userSession=await(await owner.request.get('/api/v1/session')).json();
  const registered=await owner.request.post('/api/v1/register',{headers:{'X-CSRF-TOKEN':userSession.csrf},data:{name:'Owner',email:code+'@example.test',password:'Secret123!',password_confirmation:'Secret123!',business_name:'Branding Cafe',business_code:code}});expect(registered.status()).toBe(201);
  const account=await registered.json();
  const post=async(route:string,data:object)=>{const response=await owner.request.post('/api/v1/'+route,{headers:{'X-CSRF-TOKEN':account.csrf,'Idempotency-Key':randomUUID()},data});expect(response.ok(),await response.text()).toBeTruthy();return response.json();};
  await post('setup',{name:'Branding Cafe',currency:'MMK',timezone:'Asia/Yangon',other_payment_label:'QR / Card',takeaway_only:false,tables:[{name:'A1',seats:4}],menu:[{name:'Rice',category:'Main',price:6500}]});
  await post('guest-orders/configure',{action:'enable'});
  const links=await(await owner.request.get('/api/v1/guest-orders')).json();
  await owner.goto('/tables');await expect(owner.locator('.connection')).toContainText('Connected');
  await auth.goto('/');await auth.getByLabel('Email',{exact:true}).fill('keep-me@example.test');
  await officeAuth.goto('/office');await officeAuth.getByLabel('Email',{exact:true}).fill('keep-office@example.test');
  await guest.goto('/menu/'+links.links[0].token);await guest.getByRole('button',{name:'Add Rice',exact:true}).click();
  await expect(guest.getByRole('button',{name:/View order/})).toContainText('6,500');
  for(const open of [owner,auth,officeAuth,guest]) await open.evaluate(()=>{(window as any).__brandingMount='retained';});
  await page.goto('/office/settings');await expect(page.getByLabel('Live connection')).toContainText('Live');
  const name=`Shared Platform ${info.project.name}`;
  await page.getByRole('button',{name:'Edit platform settings',exact:true}).click();
  await page.getByLabel('Platform name',{exact:true}).fill(name);
  await page.getByRole('button',{name:'Save changes',exact:true}).click();
  await expect(page.getByRole('dialog')).toHaveCount(0);
  for(const open of [page,owner,auth,officeAuth,guest]) await expect(open).toHaveTitle(new RegExp(name));
  await expect(auth.locator('.brand')).toContainText(name);await expect(officeAuth.locator('.office-brand')).toContainText(name);
  await expect(owner.locator('.workspace-footer')).toContainText(name);await expect(owner.locator('.brand')).toContainText('Branding Cafe');
  await expect(guest.locator('.guest-platform-brand')).toContainText(name);await expect(guest.getByRole('heading',{name:'Branding Cafe',exact:true})).toBeVisible();
  await expect(auth.getByLabel('Email',{exact:true})).toHaveValue('keep-me@example.test');await expect(officeAuth.getByLabel('Email',{exact:true})).toHaveValue('keep-office@example.test');
  await expect(guest.getByRole('button',{name:/View order/})).toContainText('6,500');
  for(const open of [owner,auth,officeAuth,guest]) expect(await open.evaluate(()=>(window as any).__brandingMount)).toBe('retained');
  await guest.reload();await expect(guest).toHaveTitle(new RegExp(name));await expect(guest.getByRole('button',{name:/View order/})).toContainText('6,500');
 } finally {
  const current=await(await page.request.get('/api/v1/office/manage/settings')).json();
  const restored=await page.request.post('/api/v1/office/manage/settings',{headers:{'X-CSRF-TOKEN':office.csrf,'Idempotency-Key':randomUUID()},data:{...current,name:original}});expect(restored.ok(),await restored.text()).toBeTruthy();
  await Promise.all([owner,auth,officeAuth,guest].map(open=>open.close()));
 }
});
