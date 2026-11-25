import { PlatformLogo } from './PlatformLogo';
import { AppearanceControl } from './Appearance';
import { usePlatformName } from './PlatformBranding';
import { useLiveRefresh, useRealtime, useDeadline } from './realtime';
import { useEffect, useState, type FormEvent } from 'react';
import { api, setCsrf } from './api';
import { Armchair, ShoppingBag, ShoppingCart, ReceiptText, UtensilsCrossed } from 'lucide-react';
import { Modal, SearchBox } from './components';
import { money, tableLabel } from './context';
import { OptionSummary } from './MenuOptions';
import { FoodPhoto } from './FoodPhoto';
import type { Category, Food, OptionSnapshot } from './types';
import './guest-menu.css';

interface Charges { tax_rate_bps: number; service_rate_bps: number; tax_on_service: boolean; tax_label: string; service_charge_label: string }
interface TableOrder { number: string; bill_requested: boolean; can_request_bill: boolean; items: { name: string; quantity: number; price: number; status: 'sent' | 'preparing' | 'ready' | 'served'; options_snapshot: OptionSnapshot[] }[] }
interface GuestMenu { direct_to_kitchen: boolean; name: string; currency: string; table_name: string | null; table_order: TableOrder | null; ordering_enabled: boolean; categories: Category[]; menu: Food[]; charges: Charges }
interface CartLine { menu_item_id: number; name: string; price: number; quantity: number; notes: string; variant_id: number | null; addon_ids: number[]; options_version: number; options_snapshot: OptionSnapshot[] }
interface RequestStatus { auto_accepted: boolean; created_at?: string; token: string; reference: string; status: string; reason: string | null }

export function GuestMenuApp() {
  const platformName = usePlatformName();
  const token = window.location.pathname.split('/')[2] || '';
  const storageKey = `guest-menu-${token}`;
  const [menu, setMenu] = useState<GuestMenu | null>(null), [error, setError] = useState(''), [busy, setBusy] = useState(false), [ready, setReady] = useState(false);
  const [cart, setCart] = useState<CartLine[]>(() => { try { const saved = JSON.parse(localStorage.getItem(storageKey + '-cart') || '[]'); return Array.isArray(saved) && saved.every(item => item && Number.isInteger(item.menu_item_id) && Number.isInteger(item.quantity) && item.quantity > 0 && Array.isArray(item.addon_ids)) ? saved : []; } catch { return []; } });
  const [requestToken, setRequestToken] = useState(() => localStorage.getItem(storageKey + '-request') || ''), [status, setStatus] = useState<RequestStatus | null>(null);
  const [currentOrderOpen, setCurrentOrderOpen] = useState(false), [billBusy, setBillBusy] = useState(false), [billError, setBillError] = useState('');
  const [search, setSearch] = useState(''), [category, setCategory] = useState(0), [selected, setSelected] = useState<Food | null>(null), [review, setReview] = useState(false);
  const load = () => api<GuestMenu>(`guest/menu/${token}`).then(data => { setMenu(data); setError(''); }).catch(e => { setError(e.message); setMenu(null); });
  useEffect(() => {
    if (menu && !menu.table_order) setCurrentOrderOpen(false);
  }, [menu?.table_order]);
  useEffect(() => { void api<{ csrf: string }>('session').then(session => { setCsrf(session.csrf); setReady(true); }).catch(e => setError(e.message)); void load();  }, [token]);
  useEffect(() => { localStorage.setItem(storageKey + '-cart', JSON.stringify(cart)); }, [cart, storageKey]);
  useEffect(() => {
    if (!requestToken) { setStatus(null); return; }
    let live = true;
    const update = () => api<RequestStatus>(`guest/requests/${requestToken}`).then(data => { if (live) setStatus(data); }).catch(e => { if (live) setError(e.message); });
    void update();
    const listener = () => { void update(); };
    window.addEventListener('realtime:guest',listener);
    return () => { live = false; window.removeEventListener('realtime:guest',listener); };
  }, [requestToken]);
  useDeadline(status?.status === 'pending' && status.created_at ? new Date(new Date(status.created_at.replace(' ', 'T') + (status.created_at.includes('T') ? '' : 'Z')).getTime()+7200000).toISOString() : null, () => window.dispatchEvent(new Event('realtime:guest')));
  const realtimeConnected = useRealtime('guest', new URLSearchParams({menu_token:token,...(requestToken?{request_token:requestToken}:{})}).toString());
  useLiveRefresh(load, 'guest');
  const stale = cart.some(line => { const food = menu?.menu.find(food => food.id === line.menu_item_id); if (!food || !food.orderable || food.options_version !== line.options_version) return true; const price = (food.uses_variants ? food.options.find(option => option.id === line.variant_id)?.price ?? -1 : food.price) + food.options.filter(option => line.addon_ids.includes(option.id)).reduce((sum, option) => sum + option.price, 0); return price !== line.price; });
  const subtotal = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  const percentage = (amount: number, bps: number) => Math.floor((amount * bps + 5000) / 10000);
  const service = percentage(subtotal, menu?.charges.service_rate_bps || 0), tax = percentage(subtotal + (menu?.charges.tax_on_service ? service : 0), menu?.charges.tax_rate_bps || 0);
  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (!menu || !ready || busy || stale || requestToken) return; setBusy(true); setError('');
    try { const result = await api<RequestStatus>(`guest/menu/${token}/requests`, { items: cart.map(({ name: _name, options_snapshot: _snapshot, ...item }) => item), charges: menu.charges }); localStorage.setItem(storageKey + '-request', result.token); setRequestToken(result.token); setStatus(result); setCart([]); setReview(false); void load(); }
    catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };
  const plainIndex = (food: Food) => cart.findIndex(line => line.menu_item_id === food.id && line.variant_id === null && !line.addon_ids.length && !line.notes && line.options_version === food.options_version && line.price === food.price);
  const quantity = (index: number, delta: number) => setCart(previous => previous.flatMap((line, i) => i !== index ? [line] : line.quantity + delta <= 0 ? [] : [{...line, quantity: Math.min(20, line.quantity + delta)}]));
  const add = (food: Food) => {
    const index = plainIndex(food);
    if (index >= 0) { quantity(index, 1); return; }
    if (cart.length >= 30) return;
    setCart(previous=>[...previous,{menu_item_id:food.id,name:food.name,price:food.price,quantity:1,notes:'',variant_id:null,addon_ids:[],options_version:food.options_version,options_snapshot:[]}]);
  };
  const visible = menu?.menu.filter(food => (!category || category === food.category_id) && food.name.toLowerCase().includes(search.toLowerCase())) || [];
  const pending = !!requestToken;
  const requestBill = async () => {
    const order = menu?.table_order;
    if (!order || billBusy || !ready || order.bill_requested) return;
    setBillBusy(true); setBillError('');
    try {
      await api(`guest/menu/${token}/bill`, { order_number: order.number });
      setMenu(previous => previous?.table_order?.number === order.number ? { ...previous, table_order: { ...previous.table_order, bill_requested: true } } : previous);
    } catch (exception) { setBillError((exception as Error).message); }
    finally { setBillBusy(false); }
  };
  const mode = menu ? menu.table_name !== null ? 'table' : 'takeaway' : 'loading';
  const isTable = mode === 'table';
  return <div className={`guest-shell guest-${mode}`}>
    <div className="guest-platform-toolbar"><span className="guest-platform-brand"><PlatformLogo/>Powered by {platformName}</span><div className="guest-appbar-actions"><AppearanceControl/>{menu?.table_order && <button aria-label="View current order" aria-haspopup="dialog" className="icon-button guest-current-cart" title="Current order" type="button" onClick={() => { setBillError(''); setCurrentOrderOpen(true); }}><ShoppingCart size={23}/><span className="guest-current-count">{menu.table_order.items.reduce((sum, item) => sum + item.quantity, 0)}</span>{menu.table_order.bill_requested && <span className="guest-bill-dot" aria-label="Bill requested"/>}</button>}</div></div>
    <header className="guest-header"><div className="guest-hero-copy"><div className="guest-menu-kind"><span className="guest-mode-icon" aria-hidden="true">{mode === 'takeaway' ? <ShoppingBag size={24}/> : <UtensilsCrossed size={24}/>}</span><span>{mode === 'loading' ? 'WELCOME' : isTable ? 'TABLE SERVICE' : 'TAKEAWAY PICKUP'}</span></div><h1>{menu?.name || 'Restaurant menu'}</h1>{menu && <p className="guest-table-label">{isTable ? <Armchair size={18} aria-hidden="true"/> : <ShoppingBag size={18} aria-hidden="true"/>}<span>{isTable ? tableLabel(menu.table_name) : 'Takeaway menu'}</span></p>}<p>{mode === 'loading' ? 'Fresh food, just a few taps away.' : isTable ? 'Settle in and choose something delicious.' : 'Your favourites, made to go. Collect at the restaurant.'}</p></div>{menu && <GuestHeroArt takeaway={!isTable}/>}</header>
    {!realtimeConnected && <p className="guest-note" role="status">Reconnecting. Your order status will update when connected.</p>}
    {error && <div className="form-error" role="alert">{error}<button className="text-button" onClick={() => void load()}>Refresh menu</button></div>}
    {requestToken && <section className="panel guest-status" aria-live="polite"><span className="eyebrow">YOUR ORDER</span><h2>{!status ? 'Checking your order...' : status.status === 'pending' ? 'Order sent' : status.status === 'accepted' ? status.auto_accepted ? 'Sent to kitchen' : 'Order confirmed' : status.status === 'expired' ? 'This order has expired' : 'Please speak with a staff member'}</h2>{status && <><strong>{status.reference}{menu ? ` · ${isTable ? tableLabel(menu.table_name) : 'Takeaway'}` : ''}</strong><p>{status.status === 'pending' ? isTable ? 'The team is confirming your order. Your table is already included. You can leave this page open for updates.' : 'The team is confirming your takeaway order. Leave this page open for updates.' : status.status === 'accepted' ? 'Your food is on its way to the kitchen. Pay with staff when you are ready.' : status.reason || 'Please order again or speak with staff.'}</p>{status.status !== 'pending' && <button className="button" onClick={() => { localStorage.removeItem(storageKey + '-request'); setRequestToken(''); setCategory(0); }}>Order more</button>}</>}</section>}
    {!menu && !error && <p role="status">Loading the menu...</p>}
    {menu && <><p className="guest-note">{menu.ordering_enabled ? menu.direct_to_kitchen ? 'Add your favourites, then submit your order directly to the kitchen.' : 'Add your favourites, then submit your order. Staff will confirm it shortly.' : 'Browse the menu and order with a staff member.'}</p><SearchBox value={search} onChange={setSearch} placeholder="Search dishes..."/><div className="category-tabs" aria-label="Menu sections"><button aria-pressed={category === 0} className={!category ? 'selected' : ''} onClick={() => setCategory(0)}>All dishes</button>{menu.categories.map(item => <button key={item.id} aria-pressed={category === item.id} className={category === item.id ? 'selected' : ''} onClick={() => setCategory(item.id)}>{item.name}</button>)}</div>
    {currentOrderOpen && menu.table_order && <Modal title="Current table order" subtitle={tableLabel(menu.table_name)} onClose={() => setCurrentOrderOpen(false)}><section className="panel guest-table-order" aria-label="Current table order">
      <div className="guest-table-order-heading"><div><span className="eyebrow">ALREADY ORDERED</span><h2>Current table order</h2></div><strong>{menu.table_order.number}</strong></div>
      <p className="guest-note">Confirmed items for {tableLabel(menu.table_name)}. New selections are added to your cart separately.</p>
      {menu.table_order.items.length ? <ul className="guest-table-order-lines">{menu.table_order.items.map((item, index) => <li key={index}>
        <span className="guest-table-order-quantity">{item.quantity}×</span><div><strong>{item.name}</strong><OptionSummary options={item.options_snapshot}/><span className={`guest-item-status guest-item-${item.status}`}>{({ sent: 'Sent to kitchen', preparing: 'Preparing', ready: 'Ready to serve', served: 'Served' })[item.status]}</span></div><strong>{money(item.price * item.quantity, menu.currency)}</strong>
      </li>)}</ul> : <p className="guest-note">No confirmed items yet. Your items will appear here after they are sent to the kitchen.</p>}
    </section><div className="guest-bill-action">{menu.table_order.bill_requested ? <p className="guest-bill-confirmation" role="status"><ReceiptText size={19}/>Bill requested. A staff member will bring your bill.</p> : menu.table_order.can_request_bill && <button className="button primary full" type="button" disabled={billBusy || !ready || !menu.table_order.items.length} onClick={() => void requestBill()}><ReceiptText size={18}/>{billBusy ? 'Requesting bill...' : 'Request bill'}</button>}{billError && <div className="form-error" role="alert">{billError}</div>}</div></Modal>}

      <div className="guest-food-grid">{visible.map(food => {
        const index = plainIndex(food), count = index < 0 ? 0 : cart[index].quantity, simple = !food.uses_variants && !food.options.length;
        return <article className="panel guest-food" key={food.id}><div className="guest-food-art"><FoodPhoto url={food.image_url} emoji={food.emoji} name={food.name}/></div><div><h2>{food.name}</h2>{food.description && <p>{food.description}</p>}<strong>{food.display_price === null ? 'Unavailable' : `${food.uses_variants ? 'From ' : ''}${money(food.display_price, menu.currency)}`}</strong>{!food.orderable && <p className="menu-unavailable">Currently unavailable</p>}{menu.ordering_enabled && <div className="guest-food-actions">{simple && count > 0 ? <div className="guest-quantity" aria-label={`Quantity for ${food.name}`}><button type="button" aria-label={`Remove one ${food.name}`} disabled={pending} onClick={()=>quantity(index,-1)}>−</button><span>{count}</span><button type="button" aria-label={`Add one ${food.name}`} disabled={pending || !food.orderable || count>=20} onClick={()=>add(food)}>+</button></div> : <button className="button small primary" disabled={!food.orderable || pending || cart.length>=30} onClick={()=>simple ? add(food) : setSelected(food)} aria-label={`Add ${food.name}`}>{simple ? '+ Add' : 'Choose options'}</button>}{simple && <button className="text-button" disabled={!food.orderable || pending || cart.length>=30} onClick={()=>setSelected(food)} aria-label={`Choose ${food.name}`}>Add a note</button>}</div>}</div></article>;
      })}</div>{!visible.length && <p className="guest-note guest-empty">No dishes found. Try another category or search.</p>}

      {!!cart.length && <button className="button primary guest-cart-button" onClick={() => setReview(true)}>{isTable ? <Armchair size={19} aria-hidden="true"/> : <ShoppingBag size={19} aria-hidden="true"/>}View order ({cart.reduce((sum, line) => sum + line.quantity, 0)}) · {money(subtotal + service + tax, menu.currency)}</button>}
      {selected && <GuestChoices food={selected} currency={menu.currency} onClose={() => setSelected(null)} onAdd={line => { setCart(previous => [...previous, line]); setSelected(null); }}/ >}
      {review && <Modal title="Your order" subtitle={menu.table_name ? tableLabel(menu.table_name) : 'Takeaway'} onClose={() => { if (!busy) setReview(false); }}><form onSubmit={event => void submit(event)}><div className="guest-cart-lines">{cart.map((line, index) => <article key={index}><div><strong>{line.name}</strong><OptionSummary options={line.options_snapshot}/><small>{line.notes}</small><div className="guest-quantity"><button type="button" aria-label={`Decrease ${line.name} line ${index+1}`} disabled={busy} onClick={()=>quantity(index,-1)}>−</button><span>{line.quantity}</span><button type="button" aria-label={`Increase ${line.name} line ${index+1}`} disabled={busy || line.quantity>=20} onClick={()=>quantity(index,1)}>+</button></div></div><div><strong>{money(line.price * line.quantity, menu.currency)}</strong><button className="text-button danger-text" type="button" disabled={busy} aria-label={`Remove ${line.name} line ${index + 1}`} onClick={() => setCart(previous => previous.filter((_, i) => i !== index))}>Remove</button></div></article>)}</div>{!cart.length && <p className="guest-note">Your order is empty. Add a dish from the menu.</p>}<div className="guest-totals"><p><span>Items</span><strong>{money(subtotal, menu.currency)}</strong></p>{menu.charges.service_rate_bps > 0 && <p><span>{menu.charges.service_charge_label} ({menu.charges.service_rate_bps / 100}%)</span><strong>{money(service, menu.currency)}</strong></p>}{menu.charges.tax_rate_bps > 0 && <p><span>{menu.charges.tax_label} ({menu.charges.tax_rate_bps / 100}%)</span><strong>{money(tax, menu.currency)}</strong></p>}<p><span>Total</span><strong>{money(subtotal + service + tax, menu.currency)}</strong></p></div><p className="guest-note">{menu.table_name ? `Your order goes to ${tableLabel(menu.table_name)}. ` : ''}{menu.direct_to_kitchen ? 'Your order will go straight to the kitchen.' : 'Staff will confirm it.'} Pay at the restaurant. For allergies, please speak with staff.</p>{stale && <div className="form-error" role="alert">A dish or price changed. Remove changed items and choose again from the current menu.</div>}{!menu.ordering_enabled && <div className="form-error" role="alert">Ordering is paused. Please order with staff.</div>}{error && <div className="form-error" role="alert">{error}</div>}<button className="button primary full" disabled={busy || stale || !cart.length || !ready || !menu.ordering_enabled || !!requestToken}>{busy ? 'Sending...' : 'Submit order'}</button><button type="button" className="text-button guest-continue" disabled={busy} onClick={()=>setReview(false)}>Keep browsing</button></form></Modal>}
    </>}</div>;
}

function GuestChoices({ food, currency, onClose, onAdd }: { food: Food; currency: string; onClose: () => void; onAdd: (line: CartLine) => void }) {
  const variants = food.options.filter(option => option.kind === 'variant');
  const [size, setSize] = useState<number | null>(variants.length === 1 ? variants[0].id : null), [addons, setAddons] = useState<number[]>([]), [quantity, setQuantity] = useState('1'), [notes, setNotes] = useState('');
  const choices = food.options.filter(option => option.kind === 'variant' ? option.id === size : addons.includes(option.id));
  const price = (food.uses_variants ? variants.find(option => option.id === size)?.price ?? 0 : food.price) + choices.filter(option => option.kind === 'addon').reduce((sum, option) => sum + option.price, 0);
  return <Modal title={food.name} onClose={onClose}><form onSubmit={e => { e.preventDefault(); onAdd({ menu_item_id: food.id, name: food.name, price, quantity: Number(quantity), notes, variant_id: size, addon_ids: addons, options_version: food.options_version, options_snapshot: choices }); }}>{food.uses_variants && <fieldset className="option-choice-group"><legend>Choose a size</legend>{variants.map(option => <label className="option-choice" key={option.id}><input required type="radio" name="guest-size" checked={size === option.id} onChange={() => setSize(option.id)}/><span>{option.name}</span><strong>{money(option.price, currency)}</strong></label>)}</fieldset>}{food.options.some(option => option.kind === 'addon') && <fieldset className="option-choice-group"><legend>Optional extras</legend>{food.options.filter(option => option.kind === 'addon').map(option => <label className="option-choice" key={option.id}><input type="checkbox" checked={addons.includes(option.id)} onChange={e => setAddons(previous => e.target.checked ? [...previous, option.id] : previous.filter(id => id !== option.id))}/><span>{option.name}</span><strong>+{money(option.price, currency)}</strong></label>)}</fieldset>}<label>Quantity<input type="number" min={1} max={20} step={1} required value={quantity} onChange={e => setQuantity(e.target.value)}/></label><label>Preparation notes (optional)<textarea maxLength={500} rows={2} value={notes} onChange={e => setNotes(e.target.value)}/></label><button className="button primary full" disabled={food.uses_variants && size === null || price > 10000000}>Add to order · {money(price * Number(quantity), currency)}</button></form></Modal>;
}


function GuestHeroArt({ takeaway }: { takeaway: boolean }) {
  return <div className="guest-hero-art" aria-hidden="true"><svg viewBox="0 0 260 190" fill="none" focusable="false">
    <circle cx="135" cy="92" r="75" fill="#fff1b4"/>
    <ellipse cx="133" cy="175" rx="101" ry="9" fill="#bfa155" opacity=".15"/>
    <path d="M29 46h12m-6-6v12M222 140h12m-6-6v12" stroke="#b99635" strokeWidth="2" strokeLinecap="round"/>
    <circle cx="217" cy="38" r="4" fill="#e7c764"/><circle cx="37" cy="137" r="3" fill="#e7c764"/>
    {takeaway ? <>
      <path d="M184 83h33l-5 66h-23l-5-66Z" fill="#fffdf2" stroke="#826b35" strokeWidth="3" strokeLinejoin="round"/>
      <path d="m199 80 7-38h20" stroke="#826b35" strokeWidth="3" strokeLinecap="round"/><path d="M182 83h37" stroke="#826b35" strokeWidth="5" strokeLinecap="round"/>
      <path d="M53 70h105l9 94H44l9-94Z" fill="#f4d16a" stroke="#826b35" strokeWidth="3" strokeLinejoin="round"/>
      <path d="M79 75V56a26 26 0 0 1 52 0v19" stroke="#826b35" strokeWidth="4" strokeLinecap="round"/>
      <path d="M88 102v16m-7-16v8a7 7 0 0 0 14 0v-8m-7 16v18m30-33c-9 0-10 18 0 18v15m0-33v18" stroke="#826b35" strokeWidth="3" strokeLinecap="round"/>
      <rect x="126" y="131" width="96" height="36" rx="9" fill="#fffdf2" stroke="#826b35" strokeWidth="3"/>
      <path d="m124 132 10-12h77l13 12m-58 4v15h18v-15" fill="#fae5a6" stroke="#826b35" strokeWidth="3" strokeLinejoin="round"/>
      <path d="M154 108c-9-8 7-12 0-21m20 20c-9-8 7-12 0-21" stroke="#ad903c" strokeWidth="2" strokeLinecap="round"/>
    </> : <>
      <rect x="68" y="28" width="39" height="27" rx="9" fill="#f4d16a" stroke="#826b35" strokeWidth="3"/>
      <rect x="151" y="28" width="39" height="27" rx="9" fill="#f4d16a" stroke="#826b35" strokeWidth="3"/>
      <rect x="68" y="144" width="39" height="27" rx="9" fill="#f4d16a" stroke="#826b35" strokeWidth="3"/>
      <rect x="151" y="144" width="39" height="27" rx="9" fill="#f4d16a" stroke="#826b35" strokeWidth="3"/>
      <rect x="42" y="49" width="174" height="100" rx="24" fill="#f9e5a9" stroke="#826b35" strokeWidth="3"/>
      <path d="M58 62h141" stroke="#fff8de" strokeWidth="3" strokeLinecap="round"/>
      <circle cx="128" cy="101" r="34" fill="#fffdf4" stroke="#826b35" strokeWidth="3"/>
      <circle cx="128" cy="101" r="24" stroke="#dfcf98" strokeWidth="2"/>
      <path d="M116 105c0-11 10-18 23-13-2 15-12 22-23 13Z" fill="#d2b765"/><path d="m119 108 16-14" stroke="#99823e" strokeWidth="2" strokeLinecap="round"/>
      <path d="M73 79v16m-6-16v10a6 6 0 0 0 12 0V79m-6 16v29m105-45v45m0-45c-10 9-10 21 0 21" stroke="#826b35" strokeWidth="3" strokeLinecap="round"/>
      <path d="M195 72h11a6 6 0 0 1 0 12h-10" stroke="#826b35" strokeWidth="3"/>
      <rect x="186" y="68" width="16" height="22" rx="5" fill="#fffdf2" stroke="#826b35" strokeWidth="3"/>
    </>}
  </svg></div>;
}
