import {test,expect,type Page} from '@playwright/test';
import {randomUUID} from 'node:crypto';

async function setup(page:Page,project:string){
 const code=`order-state-${project}-${Date.now()}`,session=await(await page.request.get('/api/v1/session')).json();
 const registration=await page.request.post('/api/v1/register',{headers:{'X-CSRF-TOKEN':session.csrf},data:{name:'Order Owner',email:code+'@example.test',password:'Secret123!',password_confirmation:'Secret123!',business_name:'Stable Order Cafe',business_code:code}});
 expect(registration.status()).toBe(201);const account=await registration.json();
 const post=async(route:string,data:object)=>{const response=await page.request.post('/api/v1/'+route,{headers:{'X-CSRF-TOKEN':account.csrf,'Idempotency-Key':randomUUID()},data});expect(response.ok(),await response.text()).toBeTruthy();return response.json();};
 await post('setup',{name:'Stable Order Cafe',currency:'MMK',timezone:'Asia/Yangon',other_payment_label:'QR / Card',takeaway_only:false,tables:[{name:'01',seats:4}],menu:[{name:'Rice',category:'Main dishes',price:5000},{name:'Tea',category:'Drinks',price:2000}]});
 const state=await(await page.request.get('/api/v1/state')).json();let order=await post('orders',{type:'dine_in',table_id:state.tables[0].id});
 for(let i=0;i<10;i++)order=await post(`orders/${order.id}/add-item`,{version:order.version,menu_item_id:state.menu[0].id,quantity:1});
 return {code,account,order,post};
}
async function retain(page:Page,phone:boolean,orderId:number){
 await page.goto('/orders/'+orderId);await expect(page.locator('.connection')).toContainText('Connected');
 await page.getByRole('textbox',{name:'Search food or drinks...',exact:true}).fill('Rice');
 await page.getByRole('button',{name:'Main dishes',exact:true}).click();
 if(phone)await page.getByRole('button',{name:/View order/}).click();
 await page.waitForLoadState('networkidle');
 await page.evaluate(()=>{(window as any).__orderNodes={menu:document.querySelector('.order-menu'),cart:document.querySelector('.order-cart'),items:document.querySelector('.cart-items')};});
}
async function retained(page:Page){
 expect(await page.evaluate(()=>{const saved=(window as any).__orderNodes;return saved.menu===document.querySelector('.order-menu')&&saved.cart===document.querySelector('.order-cart')&&saved.items===document.querySelector('.cart-items');})).toBeTruthy();
 await expect(page.getByRole('textbox',{name:'Search food or drinks...',exact:true})).toHaveValue('Rice');
 await expect(page.getByRole('button',{name:'Main dishes',exact:true})).toHaveClass(/selected/);
}

test('item edits reconcile locally without workspace fetches, page replacement or scroll reset',async({page,browser},info)=>{
 test.setTimeout(90000);
 const {code,order}=await setup(page,info.project.name),phone=info.project.name==='phone';
 const peer=await browser.newPage({baseURL:process.env.E2E_BASE_URL || 'http://127.0.0.1:8001',viewport:info.project.use.viewport});
 const session=await(await peer.request.get('/api/v1/session')).json();
 await peer.request.post('/api/v1/login',{headers:{'X-CSRF-TOKEN':session.csrf},data:{email:code+'@example.test',password:'Secret123!'}});
 await retain(peer,phone,order.id);await retain(page,phone,order.id);
 let stateReads=0,documents=0,edits=0;
 page.on('request',request=>{if(request.url().endsWith('/api/v1/state'))stateReads++;if(request.isNavigationRequest()&&request.resourceType()==='document')documents++;if(request.method()==='POST'&&request.url().includes('/edit-item'))edits++;});
 const row=page.locator('.cart-line').nth(6),plus=row.getByRole('button',{name:'Increase Rice quantity',exact:true});
 await plus.scrollIntoViewIfNeeded();await plus.focus();
 const scroll=await page.locator('.cart-items').evaluate(el=>el.scrollTop),top=await page.evaluate(()=>scrollY);
 await page.route(`**/api/v1/orders/${order.id}/edit-item`,async route=>{await new Promise(resolve=>setTimeout(resolve,250));await route.continue();});
 await plus.click();
 await expect(page.getByRole('button',{name:'Add Rice',exact:true})).toBeEnabled();
 await expect(page.getByRole('button',{name:/Send .* items to kitchen/})).not.toHaveText('Working...');
 await expect(row.locator('.quantity-control span')).toHaveText('2');await expect(plus).toBeEnabled();await expect(plus).toBeFocused();
 await expect(peer.locator('.cart-line').nth(6).locator('.quantity-control span')).toHaveText('2');
 await expect.poll(()=>page.locator('.cart-items').evaluate(el=>el.scrollTop)).toBe(scroll);expect(await page.evaluate(()=>scrollY)).toBe(top);
 await retained(page);expect(stateReads).toBe(0);expect(documents).toBe(0);expect(edits).toBe(1);
 await row.getByRole('button',{name:'Add note',exact:true}).click();await page.getByLabel('Preparation notes',{exact:true}).fill('No onion');await page.getByRole('button',{name:'Save note',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);await expect(row).toContainText('No onion');
 await row.getByRole('button',{name:'Edit price for Rice',exact:true}).click();await page.getByRole('spinbutton',{name:'New unit price (MMK)',exact:true}).fill('4500');await page.getByRole('button',{name:'Save price',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);await expect(row.locator('.cart-line-price')).toContainText('9,000 MMK');
 await expect(peer.locator('.cart-line').nth(6)).toContainText('No onion');await expect(peer.locator('.cart-line').nth(6).locator('.cart-line-price')).toContainText('9,000 MMK');
 await retained(page);expect(stateReads).toBe(0);expect(documents).toBe(0);
 await page.getByRole('button',{name:/Send .* items to kitchen/}).click();await expect(row.getByText('In queue',{exact:true})).toBeVisible();
 const peerSession=await(await peer.request.get('/api/v1/session')).json();
 const latest=await(await peer.request.get('/api/v1/orders/'+order.id)).json();
 const ready=await peer.request.post(`/api/v1/orders/${order.id}/ready`,{headers:{'X-CSRF-TOKEN':peerSession.csrf,'Idempotency-Key':randomUUID()},data:{version:latest.version,item_id:latest.items[6].id}});expect(ready.ok(),await ready.text()).toBeTruthy();
 await expect(row.getByRole('button',{name:'Served',exact:true})).toBeVisible();await page.waitForLoadState('networkidle');stateReads=0;
 await row.getByRole('button',{name:'Served',exact:true}).click();await expect(row.locator('.badge')).toHaveText('served');await expect(peer.locator('.cart-line').nth(6).locator('.badge')).toHaveText('served');expect(stateReads).toBe(0);await retained(page);
 const cancelled=page.locator('.cart-line').nth(7);await cancelled.getByRole('button',{name:'Cancel Rice',exact:true}).click();await page.getByLabel('Cancellation reason',{exact:true}).fill('Guest changed their mind');await page.getByRole('button',{name:'Confirm cancellation',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);await expect(cancelled.locator('.badge')).toContainText('cancelled');expect(stateReads).toBe(0);await retained(page);
 await page.screenshot({path:`test-results/${info.project.name}-stable-order-state.png`});
 await peer.close();
});

test('conflicts reload only the order and a failed background refresh retains the workspace',async({page},info)=>{
 test.setTimeout(60000);const {order}=await setup(page,info.project.name);await retain(page,info.project.name==='phone',order.id);
 let stateReads=0,orderReads=0;page.on('request',request=>{if(request.url().endsWith('/api/v1/state'))stateReads++;if(request.method()==='GET'&&request.url().endsWith('/api/v1/orders/'+order.id))orderReads++;});
 const row=page.locator('.cart-line').first();
 await page.route(`**/api/v1/orders/${order.id}/edit-item`,route=>route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({message:'This order changed on another device.'})}),{times:1});
 await row.getByRole('button',{name:'Increase Rice quantity',exact:true}).click();await expect(page.getByRole('alert')).toContainText('This order changed');await expect(row.locator('.quantity-control span')).toHaveText('1');await expect.poll(()=>orderReads).toBe(1);expect(stateReads).toBe(0);await retained(page);
 await row.getByRole('button',{name:'Increase Rice quantity',exact:true}).click();await expect(row.locator('.quantity-control span')).toHaveText('2');expect(stateReads).toBe(0);
 await page.route('**/api/v1/state',route=>route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Temporary network interruption'})}),{times:1});
 await page.evaluate(()=>window.dispatchEvent(new CustomEvent('realtime:business')));
 await expect(page.locator('.offline-banner')).toBeVisible();await retained(page);await expect(row.locator('.quantity-control span')).toHaveText('2');
 if(info.project.name==='phone')await page.getByRole('button',{name:'Close current order',exact:true}).click();
 await page.locator('.offline-banner').getByRole('button',{name:'Retry',exact:true}).click();await expect(page.locator('.offline-banner')).toHaveCount(0);if(info.project.name==='phone')await page.getByRole('button',{name:/View order/}).click();await retained(page);await expect(row.locator('.quantity-control span')).toHaveText('2');
});


test('an older workspace response cannot overwrite a newly saved item',async({page},info)=>{
 test.setTimeout(60000);const {order}=await setup(page,info.project.name);await retain(page,info.project.name==='phone',order.id);
 const oldState=await(await page.request.get('/api/v1/state')).json();let release!:()=>void,held=false;
 const gate=new Promise<void>(resolve=>{release=resolve;});
 await page.route('**/api/v1/state',async route=>{held=true;await gate;await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify(oldState)});},{times:1});
 await page.evaluate(()=>window.dispatchEvent(new CustomEvent('realtime:business')));await expect.poll(()=>held).toBeTruthy();
 const row=page.locator('.cart-line').first();await row.getByRole('button',{name:'Increase Rice quantity',exact:true}).click();await expect(row.locator('.quantity-control span')).toHaveText('2');
 release();await page.waitForLoadState('networkidle');await expect(row.locator('.quantity-control span')).toHaveText('2');await retained(page);
});
