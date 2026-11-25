import { tr, useLocale } from './locale';
import { useState, type FormEvent } from 'react';
import { Check } from 'lucide-react';
import { money, useApp } from './context';
import type { Item } from './types';

export function ItemPriceEditor({ item, onSave, onCancel }: { item: Item; onSave: (price: number, reason: string) => Promise<void>; onCancel: () => void }) {
  useLocale();
  const { state, connected } = useApp();
  const [price, setPrice] = useState(String(item.price));
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const valid = price !== '' && Number.isInteger(Number(price)) && Number(price) >= 0 && Number(price) <= 10000000;
  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (!valid || busy) return;
    setBusy(true); setError('');
    try { await onSave(Number(price), reason); } catch (exception) { setError((exception as Error).message); } finally { setBusy(false); }
  };
  return <form className="item-price-editor" onSubmit={submit}>
    <div className="price-editor-current"><strong>{item.name}</strong><span>{tr("Current unit price:")} {money(item.price, state.settings.currency)}</span></div>
    <label>{tr("New unit price (")}{state.settings.currency})<input aria-label={tr("New unit price ({value1})", {value1: state.settings.currency})} type="number" inputMode="numeric" step={1} min={0} max={10000000} value={price} required onChange={event => setPrice(event.target.value)} disabled={busy}/></label>
    <label>{tr("Reason")} <span className="optional">{tr("Optional")}</span><input aria-label={tr("Price change reason")} value={reason} maxLength={255} placeholder={tr("e.g. Agreed customer price")} onChange={event => setReason(event.target.value)} disabled={busy}/></label>
    <div className="price-editor-total"><span>{item.quantity} × {valid ? money(Number(price), state.settings.currency) : '—'}</span><strong>{valid ? money(item.quantity * Number(price), state.settings.currency) : '—'}</strong></div>
    <p>{tr("This changes this order only. The menu price stays the same.")}</p>
    {error && <div className="form-error" role="alert">{tr(error)}</div>}
    <div className="price-editor-actions"><button className="button" type="button" disabled={busy} onClick={onCancel}>{tr("Cancel price edit")}</button><button className="button primary" disabled={busy || !connected || !valid}><Check size={16}/>{busy ? tr("Saving...") : tr("Save price")}</button></div>
  </form>;
}

export function PriceChangeDetails({ details, currency }: { details?: string | Record<string, unknown>; currency: string }) {
  useLocale();
  let data: Record<string, unknown>;
  try { data = typeof details === 'string' ? JSON.parse(details) : details || {}; } catch { return null; }
  if (typeof data.old_price !== 'number' || typeof data.new_price !== 'number') return null;
  return <small className="price-change-history">{String(data.item_name || tr("Item"))}: {money(data.old_price, currency)} → {money(data.new_price, currency)}{data.reason ? tr(" · {value1}", {value1: String(data.reason)}) : ''}</small>;
}
