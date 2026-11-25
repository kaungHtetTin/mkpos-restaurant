import { tr, useLocale, paymentMethodText } from './locale';
import { useLiveRefresh } from './realtime';
import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, useLocation, useSearchParams } from 'react-router-dom';
import { ArrowDownLeft, ArrowUpRight, Banknote, Check, Clock3, Plus, RefreshCw } from 'lucide-react';
import { api } from './api';
import { useApp, money, dateTime } from './context';
import { Empty, Modal, PageHeading } from './components';
import './shifts.css';

interface ShiftAdjustment { id: number; kind: string; reference_number: string; reason: string; actor_name: string; cash_effect: number; amount: number; to_label: string; to_method: string }
interface CashMovement { id: number; amount: number; reason: string; created_at: string }
interface CashShift {
  id: number; opened_by: number; opener_name: string; closer_name: string | null; currency: string;
  opening_cash: number; expected_cash: number; cash_sales: number; other_sales: number; payment_count: number;
  closing_cash: number | null; variance: number | null; closing_note: string | null; cash_refunds: number; adjustments: ShiftAdjustment[];
  opened_at: string; closed_at: string | null; movements: CashMovement[];
}
interface ShiftResult { enabled: boolean; active: CashShift | null; history: { data: CashShift[]; current_page: number; last_page: number; total: number } }

export function ShiftsPage() {
  useLocale();
  const { state, user, can, connected, refresh, notify } = useApp();
  const [params, setParams] = useSearchParams(); const page = Number(params.get('page') || 1);
  const [result, setResult] = useState<ShiftResult | null>(null), [error, setError] = useState('');
  const [dialog, setDialog] = useState<'open' | 'movement' | 'close' | null>(null);
  const [detail, setDetail] = useState<CashShift | null>(null);
  const returnTo = (useLocation().state as { returnTo?: string } | null)?.returnTo;
  const load = useCallback(async () => { try { setResult(await api<ShiftResult>(`shifts?page=${page}`)); setError(''); } catch (error) { setError((error as Error).message); } }, [page]);
  useEffect(() => { void load(); }, [load]);
  useLiveRefresh(load);
  const saved = async (message: string) => { setDialog(null); await Promise.all([load(), refresh()]); notify(message); };
  const active = result?.active;
  const canManage = active && (can() || active.opened_by === user.id);
  const writable = connected && state.writable;
  return <><PageHeading eyebrow={tr("COUNT WITH CONFIDENCE")} title={tr("Cashier shifts")} subtitle={tr("One shared drawer for your team. Keep every cash movement accounted for.")}>
    <button className="button" onClick={() => void load()}><RefreshCw size={16}/>{tr("Refresh")}</button>
    {result && !active && <button className="button primary" disabled={!writable} onClick={() => setDialog('open')}><Plus size={17}/>{tr("Open shift")}</button>}
  </PageHeading>
  {returnTo && /^\/orders\/\d+$/.test(returnTo) && <Link className="back-link" to={returnTo}>{tr("Return to your order")}<ArrowUpRight size={16}/></Link>}
  {error && <div className="form-error" role="alert">{tr(error)}</div>}
  {!result && !error && <Empty title={tr("Loading shifts")} text={tr("Checking the current drawer.")}/>}
  {result && (active ? <section className="panel shift-active">
    <div className="panel-header"><div><h2><Banknote size={20}/>{tr("Shift #")}{active.id}<span className="shift-status">{tr("Open")}</span></h2><p>{tr("Opened by")} {active.opener_name}  {tr("at")} {dateTime(active.opened_at, state.settings.timezone)}</p></div>
      {canManage && <button className="button dark" disabled={!writable} onClick={() => setDialog('close')}>{tr("Close shift")}</button>}
    </div>
    {active.expected_cash < 0 && <p className="form-error" role="alert">{tr("Expected cash is below zero. Review the recorded cash movements and payment corrections before closing.")}</p>}<div className="shift-metrics"><Metric label={tr("Opening cash")} amount={active.opening_cash} currency={active.currency}/><Metric label={tr("Cash sales")} amount={active.cash_sales} currency={active.currency}/><Metric label={tr("Cash in / out")} amount={active.movements.reduce((sum, movement) => sum + movement.amount, 0)} currency={active.currency}/><Metric label={tr("Expected in drawer")} amount={active.expected_cash} currency={active.currency} highlight/></div>
    <div className="shift-payment-note"><span>{active.payment_count}  {tr("payments recorded")}</span><span>{tr("Cash refunds:")} {money(active.cash_refunds, active.currency)}</span><span>{money(active.other_sales, active.currency)}  {tr("paid by")} {state.settings.other_payment_label}{tr("; excluded from cash count.")}</span></div>
    <div className="shift-movement-heading"><h3>{tr("Cash movements")}</h3>{canManage && <button className="button small" disabled={!writable} onClick={() => setDialog('movement')}><Plus size={16}/>{tr("Record cash in / out")}</button>}</div>
    <Movements shift={active}/><ShiftAdjustments shift={active}/>
    {!canManage && <p className="shift-help">{tr("The shift opener or a manager can record cash movements and close this drawer.")}</p>}
  </section> : <section className="panel shift-empty"><div className="shift-empty-icon"><Banknote size={32}/></div><h2>{tr("No open shift")}</h2><p>{result.enabled ? tr("Open a new shift before taking payments. Start by counting the cash already in your drawer.") : tr("Start your first shift to enable cash tracking. After that, an open shift is required for checkout.")}</p><button className="button primary" disabled={!writable} onClick={() => setDialog('open')}><Plus size={17}/>{tr("Open shift")}</button></section>)}
  {result && <section className="panel shift-history"><div className="panel-header"><div><h2>{tr("Closed shifts")}</h2><p>{tr("Saved counts, differences and handover notes.")}</p></div></div>
    <div className="table-scroll"><table><thead><tr><th>{tr("Shift / opened by")}</th><th>{tr("Closed")}</th><th>{tr("Expected")}</th><th>{tr("Counted")}</th><th>{tr("Difference")}</th><th/></tr></thead><tbody>{result.history.data.map(shift => <tr key={shift.id}><td><strong>#{shift.id}</strong><small>{shift.opener_name}</small></td><td>{dateTime(shift.closed_at!, state.settings.timezone)}</td><td>{money(shift.expected_cash, shift.currency)}</td><td>{money(shift.closing_cash!, shift.currency)}</td><td><span className={shift.variance === 0 ? 'shift-balanced' : 'shift-variance'}>{shift.variance === 0 ? tr("Balanced") : `${shift.variance! > 0 ? '+' : ''}${money(shift.variance!, shift.currency)}`}</span></td><td><button className="button small" onClick={() => setDetail(shift)} aria-label={tr("View shift {value1}", {value1: shift.id})}>{tr("View")}<ArrowUpRight size={14}/></button></td></tr>)}</tbody></table></div>
    {!result.history.data.length && <Empty title={tr("No closed shifts yet")} text={tr("Your first closing report will appear here.")}/>}
    <div className="pagination"><span>{result.history.total}  {tr("shifts")}</span><div><button className="button small" disabled={page <= 1} onClick={() => setParams({ page: String(page - 1) })}>{tr("Previous")}</button><span>{page} / {result.history.last_page}</span><button className="button small" disabled={page >= result.history.last_page} onClick={() => setParams({ page: String(page + 1) })}>{tr("Next")}</button></div></div>
  </section>}
  {dialog === 'open' && <ShiftForm title={tr("Open cashier shift")} onClose={() => setDialog(null)} onSubmit={async form => { await api('shifts/open', { opening_cash: Number(form.get('opening_cash')) }); await saved(tr("Cashier shift opened.")); }} submitLabel={tr("Open shift")} writable={writable}>
    <p className="shift-help">{tr("Count the cash already in the drawer. All team payments will be linked to this shift.")}</p><label>{tr("Opening cash (")}{state.settings.currency})<input name="opening_cash" type="number" inputMode="numeric" min={0} max={1000000000} step={1} required placeholder="0"/></label>
  </ShiftForm>}
  {dialog === 'movement' && active && <ShiftForm title={tr("Record cash movement")} onClose={() => setDialog(null)} onSubmit={async form => { await api('shifts/movement', { shift_id: active.id, direction: form.get('direction'), amount: Number(form.get('amount')), reason: form.get('reason') }); await saved(tr("Cash movement recorded.")); }} submitLabel={tr("Record movement")} writable={writable}>
    <p className="shift-help">{tr("Sales, refunds and payment corrections are tracked automatically. Record only separate cash movements here.")}</p><label>{tr("Direction")}<select name="direction"><option value="in">{tr("Cash in")}</option><option value="out">{tr("Cash out")}</option></select></label><label>{tr("Amount (")}{active.currency})<input name="amount" type="number" inputMode="numeric" min={1} max={1000000000} step={1} required/></label><label>{tr("Reason")}<textarea name="reason" maxLength={255} required rows={2} placeholder={tr("For example, change float or cash banked")}/></label>
  </ShiftForm>}
  {dialog === 'close' && active && <CloseShift shift={active} onClose={() => setDialog(null)} onSaved={() => saved(tr("Shift closed. Closing report saved."))} onRefresh={load} writable={writable}/>}
  {detail && <Modal title={tr("Shift #{value1} closing report", {value1: detail.id})} subtitle={tr("{value1} · {value2}", {value1: detail.opener_name, value2: dateTime(detail.opened_at, state.settings.timezone)})} onClose={() => setDetail(null)}>
    <div className="shift-report"><Metric label={tr("Opening cash")} amount={detail.opening_cash} currency={detail.currency}/><Metric label={tr("Cash sales")} amount={detail.cash_sales} currency={detail.currency}/><Metric label={tr("Expected cash")} amount={detail.expected_cash} currency={detail.currency}/><Metric label={tr("Counted cash")} amount={detail.closing_cash!} currency={detail.currency}/><Metric label={tr("Difference")} amount={detail.variance!} currency={detail.currency} highlight/></div>
    <p className="shift-help">{tr("Closed by")} {detail.closer_name}  {tr("at")} {dateTime(detail.closed_at!, state.settings.timezone)}. {detail.closing_note}</p><h3>{tr("Cash movements")}</h3><Movements shift={detail}/><ShiftAdjustments shift={detail}/>
  </Modal>}
  </>;
}

function ShiftAdjustments({ shift }: { shift: CashShift }) {
  useLocale();
  return shift.adjustments.length ? <div className="shift-financial-adjustments"><h3>{tr("Refunds & payment corrections")}</h3>{shift.adjustments.map(adjustment => <article key={adjustment.id}><div><strong>{adjustment.kind === 'refund' ? tr("Refund {value1} via {value2}", {value1: money(adjustment.amount, shift.currency), value2: paymentMethodText(adjustment.to_label, adjustment.to_method)}) : tr("Payment method correction")}</strong><p>{adjustment.reason} ? {adjustment.actor_name}</p></div><b><small>{tr("Cash effect")}</small>{adjustment.cash_effect > 0 ? '+' : ''}{money(adjustment.cash_effect, shift.currency)}</b></article>)}</div> : null;
}
function Metric({ label, amount, currency, highlight = false }: { label: string; amount: number; currency: string; highlight?: boolean }) {
  useLocale(); return <div className={`shift-metric ${highlight ? 'highlight' : ''}`}><span>{label}</span><strong>{money(amount, currency)}</strong></div>; }
function Movements({ shift }: { shift: CashShift }) {
  useLocale();
  const { state } = useApp();
  return shift.movements.length ? <ul className="shift-movements">{shift.movements.map(movement => <li key={movement.id}>{movement.amount > 0 ? <ArrowDownLeft size={18}/> : <ArrowUpRight size={18}/>}<div><strong>{movement.reason}</strong><small><Clock3 size={12}/>{dateTime(movement.created_at, state.settings.timezone)}</small></div><b>{movement.amount > 0 ? '+' : ''}{money(movement.amount, shift.currency)}</b></li>)}</ul> : <p className="shift-help">{tr("No cash movements recorded.")}</p>;
}
function ShiftForm({ title, children, onClose, onSubmit, submitLabel, writable }: { title: string; children: React.ReactNode; onClose: () => void; onSubmit: (form: FormData) => Promise<void>; submitLabel: string; writable: boolean }) {
  useLocale();
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const submit = async (event: FormEvent<HTMLFormElement>) => { event.preventDefault(); if (busy) return; const form = new FormData(event.currentTarget); setBusy(true); setError(''); try { await onSubmit(form); } catch (error) { setError((error as Error).message); } finally { setBusy(false); } };
  return <Modal title={title} onClose={() => { if (!busy) onClose(); }}><form onSubmit={event => void submit(event)}><fieldset className="shift-fields" disabled={busy}>{children}</fieldset>{error && <div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy || !writable}>{busy ? tr("Saving...") : submitLabel}<Check size={17}/></button></form></Modal>;
}
function CloseShift({ shift, onClose, onSaved, onRefresh, writable }: { shift: CashShift; onClose: () => void; onSaved: () => Promise<void>; onRefresh: () => Promise<void>; writable: boolean }) {
  useLocale();
  const [expected, setExpected] = useState(shift.expected_cash), [counted, setCounted] = useState('');
  const changed = expected !== shift.expected_cash; const difference = counted === '' ? null : Number(counted) - expected;
  return <ShiftForm title={tr("Close shift #{value1}", {value1: shift.id})} onClose={onClose} submitLabel={tr("Confirm closing count")} writable={writable && !changed} onSubmit={async form => { try { await api('shifts/close', { shift_id: shift.id, expected_cash: expected, counted_cash: Number(form.get('counted_cash')), note: form.get('note') }); await onSaved(); } catch (error) { await onRefresh(); throw error; } }}>
    <p className="shift-help">{tr("Count the actual cash in the drawer before closing. This report cannot be edited after confirmation.")}</p><div className="shift-report"><Metric label={tr("Expected cash")} amount={expected} currency={shift.currency} highlight/></div>
    {changed && <div className="form-error" role="alert">{tr("Cash activity changed while you were counting.")}<button className="button small" type="button" onClick={() => setExpected(shift.expected_cash)}>{tr("Review updated expected cash")}</button></div>}
    <label>{tr("Counted cash (")}{shift.currency})<input name="counted_cash" type="number" inputMode="numeric" min={0} max={1000000000} step={1} required value={counted} onChange={event => setCounted(event.target.value)}/></label>
    {difference !== null && <div className={`shift-count-difference ${difference === 0 ? 'balanced' : ''}`} role="status"><span>{difference === 0 ? tr("Drawer balances") : difference < 0 ? tr("Cash short") : tr("Cash over")}</span><strong>{money(Math.abs(difference), shift.currency)}</strong></div>}
    <label>{tr("Closing note")} {difference !== null && difference !== 0 ? tr("(required for a difference)") : '(optional)'}<textarea name="note" maxLength={500} rows={3} required={difference !== null && difference !== 0} placeholder={tr("Explain any difference or leave a handover note")}/></label>
  </ShiftForm>;
}
