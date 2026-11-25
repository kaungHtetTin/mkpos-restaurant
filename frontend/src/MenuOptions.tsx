import { tr, useLocale } from './locale';
import { useRef, useState, type FormEvent } from 'react';
import { Check, Plus, Trash2 } from 'lucide-react';
import { api } from './api';
import { useApp, money } from './context';
import { Modal } from './components';
import type { Food, Item, MenuOption, OptionSnapshot } from './types';
import './menu-options.css';

export function OptionSummary({ options }: { options?: OptionSnapshot[] }) {
  useLocale();
  if (!options?.length) return null;
  return <p className="item-options">{options.map(option => option.kind === 'variant' ? option.name : tr("+ {value1}", {value1: option.name})).join(' · ')}</p>;
}
export function FoodPrice({ food, currency }: { food: Food; currency: string }) {
  useLocale();
  return <strong className="food-card-price">{food.display_price === null ? tr("No sizes available") : `${food.uses_variants ? tr("From ") : ''}${money(food.display_price ?? food.price, currency)}`}</strong>;
}
export function OptionsCount({ food }: { food: Food }) {
  useLocale();
  const sizes = food.options.filter(option => option.kind === 'variant').length, addons = food.options.filter(option => option.kind === 'addon').length;
  return <div className="options-count">{sizes} {sizes === 1 ? 'size' : 'sizes'} / {addons} {addons === 1 ? 'add-on' : 'add-ons'}</div>;
}

interface EditableOption { key: number; id?: number; name: string; price: string; active: boolean }
export function MenuOptionsEditor({ food, onClose }: { food: Food; onClose: () => void }) {
  useLocale();
  const { state, refresh, notify, connected } = useApp();
  const sequence = useRef(-1);
  const initial = (kind: 'variant' | 'addon') => food.options.filter(option => option.kind === kind).map(option => ({ key: option.id, id: option.id, name: option.name, price: String(option.price), active: !!option.active }));
  const [variants, setVariants] = useState<EditableOption[]>(() => initial('variant'));
  const [addons, setAddons] = useState<EditableOption[]>(() => initial('addon'));
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const latest = state.menu.find(item => item.id === food.id), stale = !latest || latest.options_version !== food.options_version;
  const clean = (rows: EditableOption[]) => rows.map(({ id, name, price, active }) => ({ ...(id ? { id } : {}), name: name.trim(), price: Number(price), active }));
  const save = async (event: FormEvent) => { event.preventDefault(); if (busy || stale) return; setBusy(true); setError(''); try { await api('manage/menu-options', { id: food.id, options_version: food.options_version, variants: clean(variants), addons: clean(addons) }); await refresh(); notify(tr("Sizes and add-ons saved.")); onClose(); } catch (error) { setError((error as Error).message); await refresh(); } finally { setBusy(false); } };
  const editor = (kind: 'variant' | 'addon', rows: EditableOption[], update: React.Dispatch<React.SetStateAction<EditableOption[]>>) => {
    const label = kind === 'variant' ? tr("Size") : tr("Add-on");
    return <section className="options-editor-section"><h3>{kind === 'variant' ? tr("Sizes") : tr("Optional add-ons")}</h3><p>{kind === 'variant' ? tr("A size price replaces the base item price. Leave this empty to use the base price.") : tr("Add-ons are charged per portion. Staff can select each extra once.")}</p>
      {rows.map((row, index) => <fieldset className="option-edit-row" key={row.key} disabled={busy}><legend>{label} {index + 1}</legend>
        <div className="form-grid"><label>{tr("Name")}<input aria-label={tr("{value1} {value2} name", {value1: label, value2: index + 1})} value={row.name} maxLength={60} required pattern=".*\S.*" onChange={event => update(previous => previous.map(option => option.key === row.key ? { ...option, name: event.target.value } : option))}/></label>
        <label>{kind === 'variant' ? tr("Price") : tr("Extra price")} ({state.settings.currency})<input aria-label={tr("{value1} {value2} price", {value1: label, value2: index + 1})} type="number" min={0} max={10000000} step={1} required value={row.price} onChange={event => update(previous => previous.map(option => option.key === row.key ? { ...option, price: event.target.value } : option))}/></label></div>
        <div className="option-row-actions"><label className="checkbox-line"><input type="checkbox" aria-label={tr("{value1} {value2} available", {value1: label, value2: index + 1})} checked={row.active} onChange={event => update(previous => previous.map(option => option.key === row.key ? { ...option, active: event.target.checked } : option))}/>{tr("Available")}</label><button type="button" className="text-button danger-text" aria-label={tr("Remove {value1} {value2}", {value1: label.toLowerCase(), value2: index + 1})} onClick={() => update(previous => previous.filter(option => option.key !== row.key))}><Trash2 size={15}/>{tr("Remove")}</button></div>
      </fieldset>)}
      <button className="button small" type="button" disabled={busy || rows.length >= (kind === 'variant' ? 20 : 30)} onClick={() => update(previous => [...previous, { key: sequence.current--, name: '', price: '', active: true }])}><Plus size={16}/>{kind === 'variant' ? tr("Add size") : tr("Add extra")}</button>
    </section>;
  };
  return <Modal title={tr("Sizes & add-ons: {value1}", {value1: food.name})} subtitle={tr("Changes apply to new selections. Existing items keep their saved names and prices.")} onClose={() => { if (!busy) onClose(); }}><form onSubmit={event => void save(event)}>
    {editor('variant', variants, setVariants)}{editor('addon', addons, setAddons)}
    {variants.length > 0 && !variants.some(option => option.active) && <p className="options-warning">{tr("All sizes are unavailable. Staff cannot order this item until a size is available.")}</p>}
    <p className="options-help">{tr("Removing or disabling an option blocks unsent items using it. Staff must update those choices before sending to the kitchen.")}</p>
    {stale && <div className="form-error" role="alert">{tr("These options changed on another device. Close and reopen this editor to review them.")}</div>}{error && <div className="form-error" role="alert">{tr(error)}</div>}
    <button className="button primary full" disabled={busy || stale || !connected || !state.writable}>{busy ? tr("Saving...") : tr("Save sizes & add-ons")}<Check size={17}/></button>
  </form></Modal>;
}

export interface ItemChoices { quantity: number; notes: string; variant_id: number | null; addon_ids: number[]; options_version: number }
export function FoodOptionsDialog({ food, item, onClose, onSave }: { food: Food; item?: Item; onClose: () => void; onSave: (choices: ItemChoices) => Promise<void> }) {
  useLocale();
  const { state, connected } = useApp();
  const previousUnavailable: MenuOption[] = (item?.options_snapshot ?? []).filter(option => !food.options.some(current => current.id === option.id)).map(option => ({ ...option, price: option.price ?? 0, active: false }));
  const sizes = [...food.options, ...previousUnavailable].filter(option => option.kind === 'variant'), extras = [...food.options, ...previousUnavailable].filter(option => option.kind === 'addon');
  const [size, setSize] = useState<number | null>(item?.options_snapshot.find(option => option.kind === 'variant')?.id ?? (sizes.filter(option => option.active).length === 1 ? sizes.find(option => option.active)!.id : null));
  const [selected, setSelected] = useState<number[]>(item?.options_snapshot.filter(option => option.kind === 'addon').map(option => option.id) ?? []);
  const [quantity, setQuantity] = useState(String(item?.quantity ?? 1)), [notes, setNotes] = useState(item?.notes ?? '');
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const latest = state.menu.find(menuItem => menuItem.id === food.id);
  const stale = !latest || latest.options_version !== food.options_version || !latest.active || !latest.orderable;
  const selectedSize = sizes.find(option => option.id === size && option.active);
  const unitPrice = (food.uses_variants ? selectedSize?.price ?? 0 : food.price) + extras.filter(option => selected.includes(option.id)).reduce((sum, option) => sum + option.price, 0);
  const valid = !stale && (!food.uses_variants || !!selectedSize) && selected.every(id => extras.some(option => option.id === id && option.active)) && unitPrice <= 10000000;
  const submit = async (event: FormEvent) => { event.preventDefault(); if (busy || !valid) return; setBusy(true); setError(''); try { await onSave({ quantity: Number(quantity), notes, variant_id: food.uses_variants ? size : null, addon_ids: selected, options_version: food.options_version }); } catch (error) { setError((error as Error).message); } finally { setBusy(false); } };
  return <Modal title={item ? tr("Edit choices: {value1}", {value1: food.name}) : tr("Choose options: {value1}", {value1: food.name})} subtitle={item ? tr("Updating choices uses the current menu prices.") : tr("Choose your size and any extras before adding to the order.")} onClose={() => { if (!busy) onClose(); }}><form onSubmit={event => void submit(event)}><fieldset className="options-fields" disabled={busy}>
    {food.uses_variants && <fieldset className="option-choice-group"><legend>{tr("Size")} <span>{tr("Choose one")}</span></legend>{sizes.map(option => <label className={`option-choice ${!option.active ? 'unavailable' : ''}`} key={option.id}><input type="radio" name="size" value={option.id} checked={size === option.id} onChange={() => setSize(option.id)} disabled={!option.active} required/><span>{option.name}{!option.active && <small>{tr("Unavailable")}</small>}</span><strong>{money(option.price, state.settings.currency)}</strong></label>)}</fieldset>}
    {extras.length > 0 && <fieldset className="option-choice-group"><legend>{tr("Add-ons")} <span>{tr("Optional, per portion")}</span></legend>{extras.map(option => <label className={`option-choice ${!option.active ? 'unavailable' : ''}`} key={option.id}><input type="checkbox" checked={selected.includes(option.id)} onChange={event => setSelected(previous => event.target.checked ? [...previous, option.id] : previous.filter(id => id !== option.id))} disabled={!option.active && !selected.includes(option.id)}/><span>{option.name}{!option.active && <small>{tr("Unavailable")}</small>}</span><strong>+{money(option.price, state.settings.currency)}</strong></label>)}</fieldset>}
    <div className="option-price-preview"><span>{food.uses_variants && !selectedSize ? tr("Choose a size") : tr("{value1} per portion", {value1: money(unitPrice, state.settings.currency)})}</span><strong>{money(unitPrice * (Number(quantity) || 0), state.settings.currency)}</strong></div>
    <label>{tr("Quantity")}<input type="number" min={1} max={99} step={1} required value={quantity} onChange={event => setQuantity(event.target.value)}/></label>
    <label>{tr("Preparation notes (optional)")}<textarea rows={2} maxLength={500} value={notes} onChange={event => setNotes(event.target.value)} placeholder={tr("For example, no onion")}/></label>
    <p className="options-help">{tr("Tax and service charges, if configured, are added to the full order.")}</p>
  </fieldset>{stale && <div className="form-error" role="alert">{tr("This menu item changed or is unavailable. Close this dialog and review the latest menu.")}</div>}{!stale && selected.some(id => !extras.some(option => option.id === id && option.active)) && <div className="form-error" role="alert">{tr("Remove unavailable extras or choose another option.")}</div>}{error && <div className="form-error" role="alert">{tr(error)}</div>}
    <button className="button primary full" disabled={busy || !connected || !state.writable || !valid}>{busy ? tr("Saving...") : item ? tr("Update item choices") : tr("Add to order")}<Check size={17}/></button>
  </form></Modal>;
}
