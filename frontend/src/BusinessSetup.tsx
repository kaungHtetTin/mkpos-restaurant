import { tr, useLocale } from './locale';
import { LanguageControl } from './locale';
import { PlatformLogo } from './PlatformLogo';
import { AppearanceControl } from './Appearance';
import { useRef, useState, type FormEvent } from 'react';
import { ArrowLeft, ArrowRight, Check, LogOut, Plus, Trash2 } from 'lucide-react';
import { api } from './api';
import type { State } from './types';

type SetupTable = { key: number; name: string; seats: string };
type SetupItem = { key: number; name: string; category: string; price: string; description: string };

export function BusinessSetup({ state, onComplete, onLogout }: { state: State; onComplete: () => Promise<void>; onLogout: () => Promise<void> }) {
  useLocale();
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [step, setStep] = useState(0);
  const [details, setDetails] = useState({ name: state.settings.name, currency: state.settings.currency, timezone: state.settings.timezone, contact: state.settings.contact, other_payment_label: state.settings.other_payment_label });
  const [tables, setTables] = useState<SetupTable[]>([{ key: 0, name: '01', seats: '4' }]);
  const [menu, setMenu] = useState<SetupItem[]>([{ key: 1, name: '', category: tr('Main dishes'), price: '', description: '' }]);
  const [takeawayOnly, setTakeawayOnly] = useState(false);
  const nextKey = useRef(2), heading = useRef<HTMLHeadingElement>(null);
  const owner = state.business.owner_id === state.user.id;
  const changeStep = (next: number) => { setStep(next); setError(''); requestAnimationFrame(() => { heading.current?.focus(); heading.current?.scrollIntoView({ block: 'start' }); }); };
  const changeDetail = (name: keyof typeof details, value: string) => setDetails(previous => ({ ...previous, [name]: value }));
  const changeTable = (key: number, name: 'name' | 'seats', value: string) => setTables(previous => previous.map(table => table.key === key ? { ...table, [name]: value } : table));
  const changeItem = (key: number, name: keyof Omit<SetupItem, 'key'>, value: string) => setMenu(previous => previous.map(item => item.key === key ? { ...item, [name]: value } : item));
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setError('');
    if (step === 1 && !takeawayOnly && new Set(tables.map(table => table.name.trim().toLowerCase())).size !== tables.length) { setError(tr("Each table needs a different name.")); return; }
    if (step < 2) { changeStep(step + 1); return; }
    setBusy(true);
    try {
      await api('setup', { ...details, takeaway_only: takeawayOnly, tables: takeawayOnly ? [] : tables.map(({ name, seats }) => ({ name: name.trim(), seats: Number(seats) })), menu: menu.map(({ name, category, price, description }) => ({ name: name.trim(), category: category.trim(), price: Number(price), description })) });
      await onComplete();
    } catch (error) { setError((error as Error).message); }
    finally { setBusy(false); }
  };
  return <div className="business-setup"><LanguageControl publicPage/><AppearanceControl publicPage/><section className="setup-card">
    <div className="setup-brand"><PlatformLogo/><strong>{state.business.name}</strong></div>
    <span className="eyebrow">{tr("LET’S GET YOU READY")}</span>
    <h1 ref={heading} tabIndex={-1}>{owner ? tr("Set up your business.") : tr("Your business is being set up.")}</h1>
    <p>{owner ? tr("Set your business details, tables and menu to open your workspace. You can update them later in Settings and Food menu.") : tr("Your business owner needs to finish setup before your team can use the workspace.")}</p>
    {owner && <><ol className="setup-steps" aria-label={tr("Setup progress")}>{[tr("Business details"), tr("Tables"), tr("Menu")].map((label, index) => <li key={label} aria-current={step === index ? 'step' : undefined} className={step >= index ? 'current' : ''}><span>{step > index ? <Check size={14}/> : index + 1}</span>{label}</li>)}</ol>
    <form className="restaurant-form" onSubmit={event => void submit(event)}>
      <fieldset disabled={busy} className="setup-fields">
      {step === 0 && <><h2>{tr("Business details")}</h2>
        <label>{tr("Business name")}<input value={details.name} onChange={event => changeDetail('name', event.target.value)} maxLength={100} required/></label>
        <div className="form-grid"><label>{tr("Currency")}<input aria-label={tr("Currency")} value={details.currency} onChange={event => changeDetail('currency', event.target.value.toUpperCase())} minLength={3} maxLength={3} pattern="[A-Z]{3}" required/><span className="field-hint">{tr("Three-letter code, such as MMK. Locked after the first order.")}</span></label>
        <label>{tr("Timezone")}<select value={details.timezone} onChange={event => changeDetail('timezone', event.target.value)} required>{Array.from(new Set([details.timezone, 'Asia/Yangon', 'Asia/Bangkok', 'Asia/Singapore', 'UTC'])).map(timezone => <option key={timezone}>{timezone}</option>)}</select></label></div>
        <label>{tr("Receipt address / contact")}<input value={details.contact} onChange={event => changeDetail('contact', event.target.value)} maxLength={255} placeholder={tr("Address or phone number (optional)")}/></label>
        <label>{tr("Other payment label")}<input aria-label={tr("Other payment label")} value={details.other_payment_label} onChange={event => changeDetail('other_payment_label', event.target.value)} maxLength={60} required/><span className="field-hint">{tr("For payments confirmed outside the app, such as QR or card.")}</span></label>
      </>}
      {step === 1 && <><h2>{tr("Set up your tables")}</h2><p className="setup-help">{tr("Add each dine-in table and its number of seats.")}</p>
        <label className="checkbox-line"><input type="checkbox" checked={takeawayOnly} onChange={event => setTakeawayOnly(event.target.checked)}/>{tr("Takeaway only (no dine-in tables)")}</label>
        {!takeawayOnly && <>{tables.map((table, index) => <section className="setup-entry" key={table.key} aria-label={tr("Table {value1}", {value1: index + 1})}>
          <div className="setup-entry-heading"><strong>{tr("Table")} {index + 1}</strong><button className="icon-button" type="button" aria-label={tr("Remove table {value1}", {value1: index + 1})} disabled={tables.length === 1} onClick={() => setTables(previous => previous.filter(row => row.key !== table.key))}><Trash2 size={16}/></button></div>
          <div className="form-grid"><label>{tr("Table name")}<input aria-label={tr("Table {value1} name", {value1: index + 1})} value={table.name} onChange={event => changeTable(table.key, 'name', event.target.value)} maxLength={30} pattern=".*\S.*" required/></label><label>{tr("Seats")}<input aria-label={tr("Table {value1} seats", {value1: index + 1})} type="number" min={1} max={30} step={1} value={table.seats} onChange={event => changeTable(table.key, 'seats', event.target.value)} required/></label></div>
        </section>)}<button className="button" type="button" disabled={tables.length >= 100} onClick={() => setTables(previous => [...previous, { key: nextKey.current++, name: '', seats: '4' }])}><Plus size={16}/>{tr("Add table")}</button></>}
      </>}
      {step === 2 && <><h2>{tr("Build your menu")}</h2><p className="setup-help">{tr("Add at least one item. Items with the same category are grouped together. Prices are in")} {details.currency}{tr(", in whole units.")}</p>
        <datalist id="setup-categories">{Array.from(new Set(menu.map(item => item.category.trim()).filter(Boolean))).map(category => <option key={category} value={category}/>)}</datalist>
        {menu.map((item, index) => <section className="setup-entry" key={item.key} aria-label={tr("Menu item {value1}", {value1: index + 1})}>
          <div className="setup-entry-heading"><strong>{tr("Menu item")} {index + 1}</strong><button className="icon-button" type="button" aria-label={tr("Remove menu item {value1}", {value1: index + 1})} disabled={menu.length === 1} onClick={() => setMenu(previous => previous.filter(row => row.key !== item.key))}><Trash2 size={16}/></button></div>
          <label>{tr("Item name")}<input aria-label={tr("Item {value1} name", {value1: index + 1})} value={item.name} onChange={event => changeItem(item.key, 'name', event.target.value)} maxLength={100} pattern=".*\S.*" required/></label>
          <div className="form-grid"><label>{tr("Category")}<input aria-label={tr("Item {value1} category", {value1: index + 1})} list="setup-categories" value={item.category} onChange={event => changeItem(item.key, 'category', event.target.value)} maxLength={80} pattern=".*\S.*" required/></label><label>{tr("Price (")}{details.currency})<input aria-label={tr("Item {value1} price", {value1: index + 1})} type="number" min={0} max={10000000} step={1} value={item.price} onChange={event => changeItem(item.key, 'price', event.target.value)} required/></label></div>
          <label>{tr("Description (optional)")}<input aria-label={tr("Item {value1} description", {value1: index + 1})} value={item.description} onChange={event => changeItem(item.key, 'description', event.target.value)} maxLength={255}/></label>
        </section>)}
        <button className="button" type="button" disabled={menu.length >= 200} onClick={() => setMenu(previous => [...previous, { key: nextKey.current++, name: '', category: previous[previous.length - 1]?.category || tr("Main dishes"), price: '', description: '' }])}><Plus size={16}/>{tr("Add menu item")}</button>
      </>}
      </fieldset>
      {error && <div className="form-error" role="alert">{tr(error)}</div>}
      <div className="setup-actions">{step > 0 && <button className="button" type="button" disabled={busy} onClick={() => changeStep(step - 1)}><ArrowLeft size={16}/>{tr("Back")}</button>}
        <button className="button primary" type="submit" disabled={busy}>{busy ? tr("Saving...") : step === 0 ? tr("Continue to tables") : step === 1 ? tr("Continue to menu") : tr("Complete setup & open workspace")}{step === 2 ? <Check size={17}/> : <ArrowRight size={17}/>}</button>
      </div>
    </form></>}
    <button className="text-button setup-logout" disabled={busy} onClick={() => void onLogout()}><LogOut size={16}/>{tr("Sign out")}</button>
  </section></div>;
}
