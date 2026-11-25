import { tr, useLocale } from './locale';
import { useState, type FormEvent } from 'react';
import { ArrowRight, Check, Merge, MoveRight } from 'lucide-react';
import { Modal, Empty } from './components';
import { useApp, money, tableLabel } from './context';
import { BillBreakdown } from './Charges';
import type { Order } from './types';
import './table-actions.css';

export function CombinedBillDetails({ order }: { order: Order }) {
  useLocale();
  const { state } = useApp();
  if (!order.billing_groups || order.billing_groups.length < 2) return null;
  return <details className="combined-bill-details"><summary>{tr("Original bill breakdown (")}{order.billing_groups.length})</summary><p>{tr("Discounts, rates and rounding are kept separately for each original order. New items are added to the")} {order.number}  {tr("portion.")}</p>{order.billing_groups.map(group => <section key={group.id}><h4>{group.label} <small>{group.number}</small></h4><BillBreakdown totals={group} currency={order.payment?.restaurant_snapshot.currency ?? state.settings.currency} showTotal/></section>)}</details>;
}

export function TableActionDialog({ order, onClose, onSave }: { order: Order; onClose: () => void; onSave: (action: 'transfer' | 'merge', data: Record<string, unknown>) => Promise<void> }) {
  useLocale();
  const { state, connected } = useApp();
  const [mode, setMode] = useState<'transfer' | 'merge'>('transfer'), [source, setSource] = useState(order);
  const [tableId, setTableId] = useState<number | null>(null), [target, setTarget] = useState<Order | null>(null);
  const [reason, setReason] = useState(''), [confirmed, setConfirmed] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState('');
  const current = state.orders.find(current => current.id === source.id && current.status === 'open' && !current.paid);
  const currentTarget = target && state.orders.find(order => order.id === target.id && order.status === 'open' && !order.paid && !order.payments_started);
  const sourceChanged = !current || current.version !== source.version;
  const targetChanged = mode === 'merge' && target && (!currentTarget || currentTarget.version !== target.version);
  const available = state.tables.filter(table => table.active && !state.orders.some(order => order.table_ids.includes(table.id)));
  const targets = state.orders.filter(other => other.id !== order.id && other.type === 'dine_in' && other.status === 'open' && !other.paid && !other.payments_started);
  const table = available.find(table => table.id === tableId);
  const valid = !sourceChanged && (mode === 'transfer' ? !!table : !!target && !targetChanged && !current?.payments_started && confirmed);
  const submit = async (event: FormEvent) => { event.preventDefault(); if (!valid || busy) return; setBusy(true); setError(''); try { await onSave(mode, { version: source.version, reason, ...(mode === 'transfer' ? { table_id: tableId } : { target_order_id: target!.id, target_version: target!.version }) }); } catch (error) { setError((error as Error).message); } finally { setBusy(false); } };
  return <Modal title={tr("Move or combine tables")} subtitle={tr("Current tables: {value1} · {value2}", {value1: source.table_name, value2: source.number})} onClose={() => { if (!busy) onClose(); }}><form onSubmit={event => void submit(event)}><fieldset className="table-action-fields" disabled={busy}>
    <div className="table-action-tabs"><button className={mode === 'transfer' ? 'selected' : ''} type="button" onClick={() => { setMode('transfer'); setError(''); setConfirmed(false); }}><MoveRight size={18}/>{tr("Move table")}</button><button className={mode === 'merge' ? 'selected' : ''} type="button" disabled={order.payments_started} onClick={() => { setMode('merge'); setError(''); setConfirmed(false); }}><Merge size={18}/>{tr("Combine bills")}</button></div>
    {mode === 'transfer' ? <><p className="table-action-help">{tr("Move this entire order to a free table. Its current tables become available; kitchen tickets keep their preparation status and update to the new table.")}</p>
      {available.length ? <fieldset className="table-choice-list"><legend>{tr("Choose an available table")}</legend>{available.map(table => <label key={table.id} className="table-choice"><input type="radio" name="destination" checked={tableId === table.id} onChange={() => setTableId(table.id)} required/><span><strong>{tableLabel(table.name)}</strong><small>{table.seats}  {tr("seats")}</small></span><small>{tr("Available")}</small></label>)}</fieldset> : <Empty title={tr("No free tables")} text={tr("Free a table before moving this order, or combine it with another open bill.")}/>}
      {table && <div className="table-action-preview"><span>{source.table_name}</span><ArrowRight size={18}/><strong>{tableLabel(table.name)}</strong><p>{tr("All items and the current bill total are kept.")}</p></div>}
    </> : <><p className="table-action-help">{tr("Move this order onto another open dine-in bill. Both sets of tables stay occupied and open the same combined order. Kitchen tickets retain their table labels.")}</p>
      {targets.length ? <fieldset className="table-choice-list"><legend>{tr("Choose the bill to keep")}</legend>{targets.map(other => <label key={other.id} className="table-choice"><input type="radio" name="target" checked={target?.id === other.id} onChange={() => { setTarget(other); setConfirmed(false); }} required/><span><strong>{tableLabel(other.table_name)}</strong><small>{other.number} · {other.items.filter(item => item.status !== 'cancelled').reduce((sum, item) => sum + item.quantity, 0)}  {tr("items")}</small></span><b>{money(other.total, state.settings.currency)}</b></label>)}</fieldset> : <Empty title={tr("No other open dine-in bills")} text={tr("Open an order on another table before combining bills.")}/>}
      {target && <div className="table-action-preview"><div><span>{source.number}</span><strong>{money(source.total, state.settings.currency)}</strong></div><div><span>{target.number}</span><strong>{money(target.total, state.settings.currency)}</strong></div><div className="table-action-total"><span>{tr("Combined total")}</span><strong>{money(source.total + target.total, state.settings.currency)}</strong></div><p>{tr("Original discounts, charge rates and rounding stay with each bill.")}</p></div>}
      {target && <label className="checkbox-line table-action-confirm"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} required/>{tr("I confirm these tables will share one bill.")}</label>}
    </>}
    <label>{tr("Reason")}<input maxLength={255} value={reason} onChange={event => setReason(event.target.value)} required placeholder={mode === 'transfer' ? tr("For example, guests requested another table") : tr("For example, guests are dining together")}/></label>
    {sourceChanged && <div className="form-error" role="alert">{tr("This order changed.")} {current ? <button type="button" className="button small" onClick={() => { setSource(current); setConfirmed(false); }}>{tr("Review updated current bill")}</button> : tr("Close this dialog and review the order.")}</div>}
    {targetChanged && <div className="form-error" role="alert">{tr("The other bill changed.")} {currentTarget ? <button type="button" className="button small" onClick={() => { setTarget(currentTarget); setConfirmed(false); }}>{tr("Review updated destination bill")}</button> : tr("Choose another open bill.")}</div>}
  </fieldset>{error && <div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy || !valid || !connected || !state.writable}>{busy ? tr("Updating tables...") : mode === 'transfer' ? tr("Move order") : tr("Confirm and combine bills")}<Check size={17}/></button></form></Modal>;
}
