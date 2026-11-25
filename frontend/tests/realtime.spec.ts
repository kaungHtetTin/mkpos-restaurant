import { test, expect, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import path from 'node:path';

test.beforeAll(()=>{
 const backend=path.resolve('..','backend');
 const result=spawnSync('php',['artisan','platform:admin','--no-ansi'],{cwd:backend,input:'Realtime QA\nrealtime@example.test\nRealtimePass123!\n',encoding:'utf8',env:{...process.env,DB_DATABASE:process.env.E2E_DATABASE || path.join(backend,'database','office-appbar-test.sqlite')}});
 if(result.status!==0&&!result.stdout.includes('already been taken'))throw new Error(result.stdout+result.stderr);
});
async function register(page:Page,code:string){
 const session=await(await page.request.get('/api/v1/session')).json();
 const response=await page.request.post('/api/v1/register',{headers:{'X-CSRF-TOKEN':session.csrf},data:{name:'Live Owner',email:code+'@example.test',password:'Secret123!',password_confirmation:'Secret123!',business_name:code,business_code:code}});
 expect(response.status()).toBe(201);const account=await response.json();
 const post=async(route:string,data:object)=>{const result=await page.request.post('/api/v1/'+route,{headers:{'X-CSRF-TOKEN':account.csrf,'Idempotency-Key':randomUUID()},data});expect(result.ok(),await result.text()).toBeTruthy();return result.json();};
 await post('setup',{name:code,currency:'MMK',timezone:'Asia/Yangon',other_payment_label:'QR / Card',takeaway_only:false,tables:[{name:'A1',seats:4}],menu:[{name:'Realtime Rice',category:'Main',price:6500}]});
 return {account,post};
}

test('WebSocket pushes orders across devices, isolates businesses, recovers missed changes and has no idle polling',async({page,browser},info)=>{
 test.setTimeout(90000);
 const baseURL=process.env.E2E_BASE_URL || 'http://127.0.0.1:8001';
 const code=`live-${info.project.name}-${Date.now()}`;
 const {post}=await register(page,code);
 const second=await browser.newPage({baseURL});
 await register(second,code+'-other');await second.goto('/kitchen');
 await expect(second.locator('.connection')).toContainText('Connected');
 await page.goto('/tables');await expect(page.locator('.connection')).toContainText('Connected');
 const kitchen=await browser.newPage({baseURL,viewport:info.project.use.viewport});
 await kitchen.addInitScript(()=>{const Native=window.WebSocket; window.WebSocket=class extends Native {constructor(url:string|URL, protocols?:string|string[]){super(url,protocols);(window as any).__liveSocket=this;}};});
 const session=await(await kitchen.request.get('/api/v1/session')).json();
 await kitchen.request.post('/api/v1/login',{headers:{'X-CSRF-TOKEN':session.csrf},data:{email:code+'@example.test',password:'Secret123!'}});
 let frames=0;kitchen.on('websocket',socket=>socket.on('framereceived',()=>frames++));
 await kitchen.goto('/kitchen');await expect(kitchen.locator('.connection')).toContainText('Connected');
 await kitchen.waitForLoadState('networkidle');await second.waitForLoadState('networkidle');
 let otherReads=0;second.on('request',request=>{if(request.url().endsWith('/api/v1/state'))otherReads++;});
 const state=await(await page.request.get('/api/v1/state')).json();
 let order=await post('orders',{type:'dine_in',table_id:state.tables[0].id});
 await expect(page.locator('.table-card').filter({hasText:'A1'})).toContainText('Occupied');
 order=await post(`orders/${order.id}/add-item`,{version:order.version,menu_item_id:state.menu[0].id,quantity:1});
 order=await post(`orders/${order.id}/submit`,{version:order.version});
 await expect(kitchen.locator('.kitchen-ticket')).toContainText('Realtime Rice');
 expect(frames).toBeGreaterThan(1);expect(otherReads).toBe(0);
 await kitchen.evaluate(()=>(window as any).__liveSocket.close());
 await post(`orders/${order.id}/ready`,{version:order.version,item_id:order.items[0].id});
 await expect(kitchen.locator('.connection')).toContainText('Connected');
 await expect(kitchen.getByText('A clean slate, chef',{exact:true})).toBeVisible();
 await expect(page.locator('.table-card').filter({hasText:'A1'})).toContainText('Food ready');
 await kitchen.waitForLoadState('networkidle');await page.waitForLoadState('networkidle');
 let reads=0;
 const count=(request:any)=>{if(request.url().includes('/api/v1/')&&request.method()==='GET')reads++;};
 kitchen.on('request',count);page.on('request',count);
 await page.waitForTimeout(7000);
 expect(reads).toBe(0);expect(otherReads).toBe(0);
 await kitchen.screenshot({path:`test-results/${info.project.name}-websocket-kitchen.png`,fullPage:true});
 await kitchen.close();await second.close();
});

test('office FOC grants reach the owner immediately over WebSocket',async({page,browser},info)=>{
 const code=`live-foc-${info.project.name}-${Date.now()}`;
 const {account}=await register(page,code);
 await page.goto('/billing');await expect(page.locator('.connection')).toContainText('Connected');
 const office=await browser.newPage({baseURL:process.env.E2E_BASE_URL || 'http://127.0.0.1:8001'});
 const session=await(await office.request.get('/api/v1/office/session')).json();
 const login=await office.request.post('/api/v1/office/login',{headers:{'X-CSRF-TOKEN':session.csrf},data:{email:'realtime@example.test',password:'RealtimePass123!'}});
 expect(login.ok()).toBeTruthy();const auth=await login.json();
 const post=async(route:string,data:object)=>{const response=await office.request.post('/api/v1/'+route,{headers:{'X-CSRF-TOKEN':auth.csrf,'Idempotency-Key':randomUUID()},data});expect(response.ok(),await response.text()).toBeTruthy();return response.json();};
 const plans=await post('office/plans',{name:code,price:30000,currency:'MMK',billing_period:'month',active:true,is_public:false});
 const plan=plans.plans.find((row:any)=>row.name===code);
 await post(`office/businesses/${account.user.business_id}/subscription`,{action:'foc',plan_id:plan.id,reason:'Realtime complimentary grant',confirmed:true});
 await expect(page.getByText('FOC \u00b7 Free of charge',{exact:true})).toBeVisible();
 await expect(page.locator('.office-kpis').first()).toContainText(code);
 await office.close();
});
