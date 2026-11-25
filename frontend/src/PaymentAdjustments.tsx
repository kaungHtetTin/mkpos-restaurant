import { tr, useLocale, paymentMethodText } from './locale';
import { useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRightLeft, Check, Printer, Undo2 } from 'lucide-react';
import { api } from './api';
import { useApp, money, dateTime } from './context';
import { Modal } from './components';
import { ReceiptDialog } from './ReceiptPaper';
import type { Order, PaymentAdjustment } from './types';
import './payment-adjustments.css';

export function PaymentPanel({ order, onChanged }: { order: Order; onChanged: () => Promise<Order> }) {
  useLocale();
  const { state } = useApp();
  const payments = order.payments.length ? order.payments : order.payment ? [order.payment] : [];
  return <>{payments.length > 0 && <div className="order-payment-progress">{tr("Received")} {money(order.received_amount, state.settings.currency)}  {tr("of")} {money(order.total, state.settings.currency)}{tr(". Remaining balance:")} <strong>{money(order.balance_due, state.settings.currency)}</strong>.{order.total_refunded_amount > 0 && tr(" Refunds recorded: {value1}. Refunds do not reopen settled portions.", {value1: money(order.total_refunded_amount, state.settings.currency)})}{order.cancelled_balance > 0 && tr(" Unpaid balance canceled: {value1}.", {value1: money(order.cancelled_balance, state.settings.currency)})}</div>}{payments.map(payment => <PaymentEntry key={payment.id} order={{ ...order, payment }} onChanged={onChanged}/>)}</>;
}

function PaymentEntry({ order, onChanged }: { order: Order; onChanged: () => Promise<Order> }) {
  useLocale();
  const { state, can, connected, notify } = useApp();
  const [mode, setMode] = useState<'refund' | 'correct-method' | null>(null), [document, setDocument] = useState<PaymentAdjustment | null>(null);
  const payment = order.payment;
  if (!payment) return null;
  return <section className="panel payment-management">
    <div className="payment-management-heading"><div><h3>{order.payments.length > 1 ? tr("{value1} payment portion", {value1: paymentMethodText(payment.method_label, payment.method)}) : tr("Payment record")}</h3><p>{payment.receipt_number}  {tr("· Recorded as")} {paymentMethodText(payment.effective_method_label, payment.effective_method)}{payment.effective_method !== payment.method && <span>  {tr("(original receipt:")} {paymentMethodText(payment.method_label, payment.method)})</span>}</p></div>
    {can() && <div className="payment-management-actions"><button className="button small" disabled={!connected || !state.writable} onClick={() => setMode('correct-method')}><ArrowRightLeft size={16}/>{tr("Correct method")}</button><button className="button small" disabled={!connected || !state.writable || payment.refundable_amount === 0} onClick={() => setMode('refund')}><Undo2 size={16}/>{tr("Record refund")}</button></div>}</div>
    <div className="payment-balance"><div><span>{tr("Original payment")}</span><strong>{money(payment.amount, payment.restaurant_snapshot.currency)}</strong></div><div><span>{tr("Refunded")}</span><strong>{money(payment.refunded_amount, payment.restaurant_snapshot.currency)}</strong></div><div><span>{tr("Retained")}</span><strong>{money(payment.refundable_amount, payment.restaurant_snapshot.currency)}</strong></div></div>
    {payment.refunded_amount > 0 && <p className="payment-record-note">{payment.refundable_amount === 0 ? tr("Fully refunded.") : tr("Partially refunded.")}  {tr("The original sale receipt is preserved.")} {order.status === 'open' && tr("Preparation and collection remain unchanged unless explicitly stopped with a full refund.")}</p>}
    {!!payment.adjustments.length && <details className="adjustment-history"><summary>{tr("Refunds & corrections (")}{payment.adjustments.length})</summary>{payment.adjustments.map(adjustment => <article key={adjustment.id}><div><strong>{adjustment.kind === 'refund' ? tr("Refund: {value1}", {value1: money(adjustment.amount, payment.restaurant_snapshot.currency)}) : tr("{value1} → {value2}", {value1: paymentMethodText(adjustment.from_label, adjustment.from_method), value2: paymentMethodText(adjustment.to_label, adjustment.to_method)})}</strong><p>{adjustment.reason}</p><small>{adjustment.actor_name} · {dateTime(adjustment.created_at, state.settings.timezone)}</small></div><button className="button small" aria-label={tr("Print adjustment {value1}", {value1: adjustment.reference_number})} onClick={() => setDocument(adjustment)}><Printer size={15}/>{tr("Receipt")}</button></article>)}</details>}
    {mode && <AdjustmentForm key={mode} mode={mode} order={order} onClose={() => setMode(null)} onSave={async data => { let result: { adjustment_id: number }; try { result = await api<{ adjustment_id: number }>(`payments/${payment.id}/${mode}`, data); } catch (error) { await onChanged().catch(() => {}); throw error; } const updated = await onChanged(); setMode(null); setDocument((updated.payments.find(part => part.id === payment.id) ?? updated.payment)?.adjustments.find(adjustment => adjustment.id === result.adjustment_id) ?? null); notify(mode === 'refund' ? tr("Refund recorded. Original receipt preserved.") : tr("Payment method correction recorded.")); }}/>} 
    {document && <AdjustmentDocument adjustment={document} onClose={() => setDocument(null)}/>}
  </section>;
}

function AdjustmentForm({ mode, order, onClose, onSave }: { mode: 'refund' | 'correct-method'; order: Order; onClose: () => void; onSave: (data: Record<string, unknown>) => Promise<void> }) {
  useLocale();
  const { state, connected } = useApp(); const payment = order.payment!;
  const [version] = useState(payment.adjustment_version), [amount, setAmount] = useState(String(payment.refundable_amount));
  const [shiftOpen] = useState(payment.original_shift_open), [shiftId] = useState(state.active_shift_id);
  const [method, setMethod] = useState(mode === 'refund' ? payment.effective_method : payment.effective_method === 'cash' ? 'other' : 'cash');
  const [reason, setReason] = useState(''), [confirmed, setConfirmed] = useState(false), [stop, setStop] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState('');
  const refund = mode === 'refund', stale = version !== payment.adjustment_version || (!refund && shiftOpen !== payment.original_shift_open) || (refund && method === 'cash' && shiftId !== state.active_shift_id);
  const cashEffect = refund ? (method === 'cash' ? -Number(amount) : 0) : shiftOpen ? ((method === 'cash' ? 1 : 0) - (payment.effective_method === 'cash' ? 1 : 0)) * payment.amount : 0;
  const needsShift = refund && method === 'cash' && !!state.settings.shifts_enabled && !state.active_shift_id;
  const canStop = refund && Number(amount) === order.payments.reduce((sum, part) => sum + part.refundable_amount, 0) && Number(amount) === payment.refundable_amount && order.type === 'takeaway' && !order.collected_at && order.status === 'open';
  const submit = async (event: FormEvent) => { event.preventDefault(); if (busy || stale || needsShift) return; setBusy(true); setError(''); try { await onSave({ version, method, reason, confirmed, ...(refund ? { amount: Number(amount), stop_preparation: canStop && stop, expected_shift_id: shiftId } : { expected_cash_effect: cashEffect }) }); } catch (error) { setError((error as Error).message); } finally { setBusy(false); } };
  return <Modal title={refund ? tr("Record a refund") : tr("Correct payment method")} subtitle={tr("{value1} · Manager approval is recorded with your name.", {value1: payment.receipt_number})} onClose={() => { if (!busy) onClose(); }}><form onSubmit={event => void submit(event)}><fieldset className="adjustment-fields" disabled={busy}>
    <p className="payment-record-note">{refund ? tr("Record money you have returned to the customer. This form does not transfer funds.") : tr("Correct how the original payment was recorded. This does not collect or refund money, and the original receipt stays unchanged.")}</p>
    {refund && <><div className="adjustment-limit"><span>{tr("Available to refund")}</span><strong>{money(payment.refundable_amount, payment.restaurant_snapshot.currency)}</strong></div><label>{tr("Refund amount (")}{payment.restaurant_snapshot.currency})<input type="number" min={1} max={payment.refundable_amount} step={1} value={amount} onChange={event => { setAmount(event.target.value); setConfirmed(false); setStop(false); }} required/></label></>}
    <label>{refund ? tr("Refund method") : tr("Correct method")}<select value={method} onChange={event => { setMethod(event.target.value as 'cash' | 'other'); setConfirmed(false); }}><option value="cash">{tr("Cash")}</option><option value="other">{state.settings.other_payment_label}</option></select></label>
    {!refund && <p className="payment-record-note">{tr("Currently recorded as")} {paymentMethodText(payment.effective_method_label, payment.effective_method)}. {payment.original_shift_open ? tr("The original drawer is open; its expected cash will be adjusted.") : tr("The original drawer is closed or was not tracked. This correction affects reporting only; saved closing counts are unchanged.")}</p>}
    <div className="adjustment-impact"><span>{refund ? tr("Cash returned") : tr("Change to expected cash")}</span><strong>{money(refund ? Math.abs(cashEffect) : cashEffect, payment.restaurant_snapshot.currency)}</strong></div>
    {needsShift && <div className="form-error" role="alert">{tr("Open a shift before recording a cash refund.")} <Link to="/shifts" state={{ returnTo: `/orders/${order.id}` }} onClick={onClose}>{tr("Open shift")}</Link></div>}
    <label>{tr("Reason")}<textarea rows={3} maxLength={500} required value={reason} onChange={event => setReason(event.target.value)} placeholder={refund ? tr("For example, agreed service recovery or returned order") : tr("Explain why the payment method was recorded incorrectly")}/></label>
    {canStop && <label className="checkbox-line"><input type="checkbox" checked={stop} onChange={event => setStop(event.target.checked)}/>{tr("Stop preparation and close this uncollected takeaway. The kitchen must acknowledge any sent-item cancellation.")}</label>}
    <label className="checkbox-line"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} required/>{refund ? tr("I have returned this refund to the customer or confirmed the external refund.") : tr("I have verified the actual payment method and the cash impact shown above.")}</label>
  </fieldset>{stale && <div className="form-error" role="alert">{tr("This payment changed. Close the dialog and review its latest balance.")}</div>}{error && <div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy || stale || needsShift || !connected || !state.writable || (!refund && method === payment.effective_method)}>{busy ? tr("Recording...") : refund ? tr("Confirm refund record") : tr("Confirm method correction")}<Check size={17}/></button></form></Modal>;
}

export function AdjustmentDocument({ adjustment, onClose }: { adjustment: PaymentAdjustment; onClose: () => void }) {
  useLocale();
  const refund = adjustment.kind === 'refund';
  return <ReceiptDialog settings={adjustment.snapshot.restaurant} onClose={onClose} data={{ documentTitle: refund ? tr("Refund receipt") : tr("Payment method correction"), originalReceipt: adjustment.snapshot.original_receipt, receiptNumber: adjustment.reference_number, orderNumber: adjustment.snapshot.order_number, paidAt: adjustment.created_at,
    orderLabel: refund ? tr("Refund via {value1}", {value1: paymentMethodText(adjustment.to_label, adjustment.to_method)}) : tr("{value1} → {value2}", {value1: paymentMethodText(adjustment.from_label, adjustment.from_method), value2: paymentMethodText(adjustment.to_label, adjustment.to_method)}), items: [], total: adjustment.amount, totalLabel: refund ? tr("Refund amount") : tr("Payment reclassified"), paymentLabel: adjustment.to_label, tendered: adjustment.amount, change: 0, showPayment: false,
    note: tr("Reason: {value1}\nApproved by {value2}\n{value3}", {value1: adjustment.reason, value2: adjustment.actor_name, value3: refund ? tr("Remaining refundable: {value1}", {value1: money(adjustment.snapshot.remaining_after, adjustment.snapshot.restaurant.currency)}) : tr("Method correction only. No new payment or refund.")}),
  }}/>;
}
