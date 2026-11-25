import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { once } from 'node:events';
import { WebSocket } from 'ws';
import { createRealtimeServer } from './server.mjs';

const secret='test-secret-for-realtime-with-at-least-32-characters';
const sign=value=>createHmac('sha256',secret).update(value).digest('hex');
const token=(channels,exp=Math.floor(Date.now()/1000)+60)=>{const data=Buffer.from(JSON.stringify({channels,exp})).toString('base64url');return data+'.'+sign(data);};
async function fixture(t) {
 const app=createRealtimeServer({secret,origins:['http://test.local']});
 app.server.listen(0,'127.0.0.1');await once(app.server,'listening');
 t.after(()=>app.close());
 const base='http://127.0.0.1:'+app.server.address().port;
 const connect=async channels=>{const client=new WebSocket(base.replace('http','ws')+'/socket',{origin:'http://test.local'});await once(client,'open');const ready=once(client,'message');client.send(JSON.stringify({type:'auth',token:token(channels)}));assert.equal(JSON.parse((await ready)[0]).type,'ready');return client;};
 const publish=async (data,valid=true)=>{const body=JSON.stringify(data),timestamp=String(Math.floor(Date.now()/1000));return fetch(base+'/publish',{method:'POST',headers:{'X-Realtime-Timestamp':timestamp,'X-Realtime-Signature':valid?sign(timestamp+'.'+body):'bad'},body});};
 return {base,connect,publish};
}
test('authenticated broadcasts isolate tenants and guest request channels',async t=>{
 const {connect,publish}=await fixture(t);
 const a=await connect(['business:1']),b=await connect(['business:2']),guest=await connect(['menu:1','guest:12']);
 let otherEvents=0,guestEvents=0;b.on('message',()=>{otherEvents++;});guest.on('message',()=>{guestEvents++;});
 const received=once(a,'message');
 assert.equal((await publish({id:'change-1',topic:'business',channels:['business:1'],disconnect:[]})).status,202);
 assert.deepEqual(JSON.parse((await received)[0]),{type:'changed',id:'change-1',topic:'business'});
 assert.equal(otherEvents,0);assert.equal(guestEvents,0);
 const guestReceived=once(guest,'message');
 assert.equal((await publish({id:'request-1',topic:'business',channels:['guest:12'],disconnect:[]})).status,202);
 await guestReceived;
 assert.equal(b.readyState,WebSocket.OPEN);
 assert.equal(otherEvents,0);assert.equal(guestEvents,1);
});
test('rejects unsigned publishing, bad tokens, expired tokens and foreign origins',async t=>{
 const {base,publish}=await fixture(t);
 assert.equal((await publish({id:'x',channels:['business:1'],topic:'business',disconnect:[]},false)).status,401);
 for(const credential of ['forged.token',token(['business:1'],Math.floor(Date.now()/1000)-1)]){
  const client=new WebSocket(base.replace('http','ws')+'/socket',{origin:'http://test.local'});await once(client,'open');const closed=once(client,'close');client.send(JSON.stringify({type:'auth',token:credential}));assert.equal((await closed)[0],4003);
 }
 const foreign=new WebSocket(base.replace('http','ws')+'/socket',{origin:'http://foreign.local'});
 foreign.on('error',()=>{});
 await new Promise(resolve=>foreign.once('unexpected-response',(_request,response)=>{assert.equal(response.statusCode,403);response.resume();foreign.terminate();resolve();}));
 foreign.on('error',()=>{});
});
test('revocation disconnects the matching session without closing another device',async t=>{
 const {connect,publish}=await fixture(t);
 const a=await connect(['business:1','session:first']),b=await connect(['business:1','session:second']);
 const closed=once(a,'close');
 await publish({id:'logout',channels:[],topic:'business',disconnect:['session:first']});
 assert.equal((await closed)[0],4001);assert.equal(b.readyState,WebSocket.OPEN);
});

test('request identity reaches every authorized device so only the originating client skips its echo',async t=>{
 const {connect,publish}=await fixture(t);
 const a=await connect(['business:1']),b=await connect(['business:1']);
 const receivedA=once(a,'message'),receivedB=once(b,'message');
 await publish({id:'local-edit',topic:'business',channels:['business:1'],disconnect:[],request_key:'local-request-key'});
 for(const received of [receivedA,receivedB])assert.equal(JSON.parse((await received)[0]).request_key,'local-request-key');
});
