import {test,expect} from '@playwright/test';
import {randomUUID} from 'node:crypto';

test('inventory is removed from navigation and menu availability remains manual',async({page},info)=>{
 const code=`no-inventory-${info.project.name}-${Date.now()}`,session=await(await page.request.get('/api/v1/session')).json();
 const registration=await page.request.post('/api/v1/register',{headers:{'X-CSRF-TOKEN':session.csrf},data:{name:'Owner',email:code+'@example.test',password:'Secret123!',password_confirmation:'Secret123!',business_name:'Simple Menu Cafe',business_code:code}});
 expect(registration.status()).toBe(201);const account=await registration.json();
 const post=async(route:string,data:object)=>{const response=await page.request.post('/api/v1/'+route,{headers:{'X-CSRF-TOKEN':account.csrf,'Idempotency-Key':randomUUID()},data});expect(response.ok(),await response.text()).toBeTruthy();return response.json();};
 await post('setup',{name:'Simple Menu Cafe',currency:'MMK',timezone:'Asia/Yangon',other_payment_label:'QR / Card',takeaway_only:false,tables:[{name:'01',seats:4}],menu:[{name:'Rice',category:'Main',price:6500}]});
 await page.goto('/inventory');await expect(page).toHaveURL(/\/tables$/);await expect(page.locator('.connection')).toContainText('Connected');await expect(page.getByRole('link',{name:'Inventory',exact:true})).toHaveCount(0);
 if(info.project.name==='phone'){await page.getByRole('button',{name:'More',exact:true}).click();await expect(page.getByRole('dialog').getByRole('link',{name:'Inventory',exact:true})).toHaveCount(0);await page.getByRole('button',{name:'Close dialog',exact:true}).click();}
 expect((await page.request.get('/api/v1/inventory')).status()).toBe(404);
 await page.goto('/menu');await expect(page.locator('.food-card')).toContainText('Rice');await expect(page.locator('.food-card')).not.toContainText('portions left');
 const available=page.getByRole('switch',{name:'Rice available',exact:true});await expect(available).toBeChecked();await available.click();await expect(available).not.toBeChecked();await expect(page.locator('.food-card')).toContainText('Sold out');
 await available.click();await expect(available).toBeChecked();
 const state=await(await page.request.get('/api/v1/state')).json();expect(state.menu[0].orderable).toBe(true);expect(state.menu[0]).not.toHaveProperty('track_stock');
 let order=await post('orders',{type:'dine_in',table_id:state.tables[0].id});order=await post(`orders/${order.id}/add-item`,{version:order.version,menu_item_id:state.menu[0].id,quantity:3});order=await post(`orders/${order.id}/submit`,{version:order.version});expect(order.items[0].status).toBe('sent');
 await page.screenshot({path:`test-results/${info.project.name}-inventory-removed.png`,fullPage:true});
});
