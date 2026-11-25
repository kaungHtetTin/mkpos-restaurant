import { tr, useLocale } from './locale';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import { Banknote, CheckCheck, CreditCard, Pencil, Printer } from 'lucide-react';
import { useApp, money, tableLabel } from './context';
import { Modal } from './components';
import { BillBreakdown } from './Charges';
import { CombinedBillDetails } from './TableActions';
import { OptionSummary } from './MenuOptions';
import { ItemPriceEditor } from './ItemPriceEditor';
import { ReceiptDialog } from './ReceiptPaper';
import type { Order, Item } from './types';
import './split-checkout.css';

export function SplitCheckout({ order, onClose, onPay, onPriceChange }: { order: Order; onClose: () => void; onPay: (data: Record<string, unknown>) => Promise<void>; onPriceChange: (id: number, price: number, reason: string, version: number) => Promise<void> }) {
  useLocale();
  const { state, connected } = useApp();
  const [mode, setMode] = useState(order.equal_shares_left ? 'equal' : 'full'), [method, setMethod] = useState('cash');
  const [amount, setAmount] = useState(String(order.balance_due)), [shares, setShares] = useState(String(order.equal_shares_left || 2));
  const [quantities, setQuantities] = useState<Record<number, number>>({}), [payer, setPayer] = useState('');
  const [cashPart, setCashPart] = useState(''), [tendered, setTendered] = useState(String(order.equal_shares_left ? Math.ceil(order.balance_due / order.equal_shares_left) : order.balance_due)), [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false), [error, setError] = useState(''), [editing, setEditing] = useState<{ item: Item; version: number } | null>(null);
  const units = (id: number) => order.settlement_units.filter(unit => unit.item_id === id && unit.due > 0);
  const itemAmount = Object.entries(quantities).reduce((sum, [id, quantity]) => sum + units(Number(id)).slice(0, quantity).reduce((sum, unit) => sum + unit.due, 0), 0);
  const payAmount = mode === 'amount' ? Number(amount) : mode === 'equal' ? Math.ceil(order.balance_due / Math.max(1, Number(shares))) : mode === 'items' ? itemAmount : order.balance_due;
  const cash = method === 'cash' ? payAmount : method === 'mixed' ? Number(cashPart) : 0;
  const other = method === 'other' ? payAmount : method === 'mixed' ? payAmount - cash : 0;
  const validAmount = Number.isInteger(payAmount) && payAmount >= (order.total === 0 ? 0 : 1) && payAmount <= order.balance_due;
  const validMix = method !== 'mixed' || Number.isInteger(cash) && cash > 0 && cash < payAmount;
  const needsShift = !!state.settings.shifts_enabled && !state.active_shift_id;
  const previousAmount = useRef(payAmount);
  useEffect(() => { setTendered(previous => Number(previous) === previousAmount.current ? String(cash) : previous); previousAmount.current = cash; setConfirmed(false); }, [cash]);
  const receivedAtOpen = useRef(order.received_amount), cancelledAtOpen = useRef(order.cancelled_balance);
  const stale = receivedAtOpen.current !== order.received_amount || cancelledAtOpen.current !== order.cancelled_balance;
  const submit = async (event: FormEvent) => {
    event.preventDefault(); if (busy || editing || !validAmount || !validMix || stale) return;
    setBusy(true); setError('');
    const tenders = [...(cash > 0 || payAmount === 0 && method === 'cash' ? [{ method: 'cash', amount: cash, tendered: Number(tendered) }] : []), ...(other > 0 || payAmount === 0 && method === 'other' ? [{ method: 'other', amount: other, confirmed }] : [])];
    try { await onPay({ mode, payer_label: payer, tenders, ...(mode === 'amount' ? { amount: payAmount } : mode === 'equal' ? { shares: Number(shares) } : mode === 'items' ? { items: Object.entries(quantities).filter(([, quantity]) => quantity > 0).map(([id, quantity]) => ({ item_id: Number(id), quantity })) } : {}) }); }
    catch (error) { setError((error as Error).message); } finally { setBusy(false); }
  };
  return <Modal title={tr("Let’s settle up")} subtitle={tr("{value1} · {value2}", {value1: order.number, value2: order.table_name ? tableLabel(order.table_name) : tr('Takeaway')})} onClose={() => { if (!busy) onClose(); }}>
    {needsShift && <div className="form-error" role="alert">{tr("Open a cashier shift before taking payment.")} <Link to="/shifts" state={{ returnTo: `/orders/${order.id}` }} onClick={onClose}>{tr("Open shift")}</Link></div>}
    <div className="checkout-bill-details"><CombinedBillDetails order={order}/><BillBreakdown totals={order} currency={state.settings.currency}/></div>
    <div className="settlement-balance"><span>{tr("Order total:")} {money(order.total, state.settings.currency)}</span><span>{tr("Already received:")} {money(order.received_amount, state.settings.currency)}</span><strong>{tr("Balance due:")} {money(order.balance_due, state.settings.currency)}</strong></div>
    {!order.payments_started && <details className="checkout-lines" open><summary>{tr("Items & prices")}</summary>{order.items.filter(item => item.status !== 'cancelled').map(item => <div className="checkout-line" key={item.id}><div className="row-between"><h3>{item.name}</h3><strong>{money(item.quantity * item.price, state.settings.currency)}</strong></div><OptionSummary options={item.options_snapshot}/><div className="checkout-line-info"><span>{item.quantity} × {money(item.price, state.settings.currency)}</span><button className="text-button" type="button" aria-label={tr("Edit checkout price for {value1}", {value1: item.name})} disabled={busy || !!editing || !connected} onClick={() => setEditing({ item, version: order.version })}><Pencil size={14}/>{tr("Edit price")}</button></div>{editing?.item.id === item.id && <ItemPriceEditor item={editing.item} onCancel={() => setEditing(null)} onSave={async (price, reason) => { await onPriceChange(item.id, price, reason, editing.version); setEditing(null); }}/>}</div>)}</details>}
    <form onSubmit={event => void submit(event)}><fieldset className="settlement-fields" disabled={busy}>
      <label>{tr("Pay for")}<select aria-label={tr("Pay for")} value={mode} onChange={event => { setMode(event.target.value); setConfirmed(false); }}><option value="full">{tr("Remaining balance")}</option><option value="amount">{tr("Custom amount")}</option><option value="equal">{tr("Equal shares")}</option><option value="items">{tr("Selected items")}</option></select></label>
      {mode === 'amount' && <label>{tr("Payment amount (")}{state.settings.currency})<input type="number" min={1} max={order.balance_due} step={1} required value={amount} onChange={event => setAmount(event.target.value)}/></label>}
      {mode === 'equal' && <><label>{tr("People still to pay")}<input type="number" min={1} max={100} step={1} value={shares} onChange={event => setShares(event.target.value)} required/></label><p className="settlement-help">{tr("This records one share. The number of people left decreases after payment; any rounding remainder stays with the remaining shares.")}</p></>}
      {mode === 'items' && <div className="settlement-items"><p className="settlement-help">{tr("Choose portions to settle. Previous amount payments are credited against these items.")}</p>{order.items.filter(item => units(item.id).length > 0).map(item => <label key={item.id} className="settlement-item"><span><strong>{item.name}</strong><OptionSummary options={item.options_snapshot}/><small>{units(item.id).length}  {tr("portions left ·")} {money(units(item.id).reduce((sum, unit) => sum + unit.due, 0), state.settings.currency)}  {tr("due")}</small></span><input aria-label={tr("Pay quantity for {value1} line {value2}", {value1: item.name, value2: item.id})} type="number" min={0} max={units(item.id).length} step={1} value={quantities[item.id] || 0} onChange={event => setQuantities(previous => ({ ...previous, [item.id]: Number(event.target.value) }))}/></label>)}</div>}
      <label>{tr("Payer name (optional)")}<input value={payer} maxLength={100} onChange={event => setPayer(event.target.value)} placeholder={tr("For example, Guest 1")}/></label>
      <div className="payment-total"><span>{tr("Amount to pay")}</span><strong>{money(validAmount ? payAmount : 0, state.settings.currency)}</strong></div>
      <div className="payment-methods split-methods"><button type="button" className={method === 'cash' ? 'selected' : ''} onClick={() => { setMethod('cash'); setConfirmed(false); }}><Banknote size={20}/>{tr("Cash")}</button><button type="button" className={method === 'other' ? 'selected' : ''} onClick={() => { setMethod('other'); setConfirmed(false); }}><CreditCard size={20}/>{state.settings.other_payment_label}</button><button type="button" className={method === 'mixed' ? 'selected' : ''} onClick={() => { setMethod('mixed'); setConfirmed(false); }}>{tr("Cash + other")}</button></div>
      {method === 'mixed' && <><label>{tr("Cash portion (")}{state.settings.currency})<input type="number" min={1} max={Math.max(0, payAmount - 1)} step={1} required value={cashPart} onChange={event => setCashPart(event.target.value)}/></label><p className="settlement-help">{state.settings.other_payment_label}  {tr("portion:")} {money(Math.max(0, other), state.settings.currency)}</p></>}
      {method !== 'other' && <><label>{tr("Cash received (")}{state.settings.currency})<input type="number" min={Math.max(0, cash)} max={1000000000} step={1} required value={tendered} onChange={event => setTendered(event.target.value)}/></label><div className="change-row"><span>{tr("Change")}</span><strong>{money(Math.max(0, Number(tendered) - cash), state.settings.currency)}</strong></div></>}
      {(other > 0 || method === 'other' && payAmount === 0) && <label className="checkbox-line"><input type="checkbox" checked={confirmed} onChange={event => setConfirmed(event.target.checked)} required/>{tr("I have confirmed the")} {state.settings.other_payment_label}  {tr("portion was received.")}</label>}
      <p className="settlement-help">{tr("The first payment locks bill contents, prices and discounts. Refunds do not reopen settled portions. Kitchen preparation can continue.")}</p>
      {stale && <div className="form-error" role="alert">{tr("Another payment changed this order. Close checkout and review the latest balance.")}</div>}
      <p className="settlement-after">{tr("Balance after this payment:")} <strong>{money(validAmount ? order.balance_due - payAmount : order.balance_due, state.settings.currency)}</strong></p>
    </fieldset>{error && <div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy || !!editing || stale || needsShift || !connected || !validAmount || !validMix}>{busy ? tr("Recording payment...") : order.total === 0 ? tr("Settle zero-total order") : tr("Confirm payment")}<CheckCheck size={18}/></button></form>
  </Modal>;
}

export function ReceiptList({ order, onSelect }: { order: Order; onSelect: (id: number | 'statement') => void }) {
  useLocale();
  if (!order.receipts.length) return null;
  return <section className="receipt-list panel"><h3>{tr("Payment receipts")}</h3><div>{order.receipts.map(receipt => <button className="button small" key={receipt.id} onClick={() => onSelect(receipt.id)}><Printer size={15}/>{receipt.payer_label || receipt.receipt_number} · {money(receipt.amount, receipt.restaurant_snapshot.currency)}</button>)}<button className="button small" onClick={() => onSelect('statement')}>{tr("Full bill statement")}</button></div></section>;
}

export function BillReceipt({ order, choice, onClose }: { order: Order; choice?: number | 'statement'; onClose: () => void }) {
  useLocale();
  const { state } = useApp();
  const receipt = choice === 'statement' ? null : choice ? order.receipts.find(receipt => receipt.id === choice) : order.receipts.at(-1);
  const payment = order.payment!;
  if (!receipt && choice !== 'statement') return <ReceiptDialog settings={payment.restaurant_snapshot} onClose={onClose} data={{ receiptNumber: payment.receipt_number, orderNumber: order.number, paidAt: payment.created_at, orderLabel: order.table_name ? tr("Table {value1}", {value1: order.table_name}) : tr("Takeaway"), items: payment.items_snapshot, totals: payment.totals_snapshot ?? order, total: payment.amount, paymentLabel: payment.method_label, paymentMethod: payment.method, tendered: payment.tendered, change: payment.change }}/>;
  if (choice === 'statement') return <ReceiptDialog settings={order.receipts[0]?.restaurant_snapshot ?? state.settings} onClose={onClose} data={{ documentTitle: tr('Bill statement'), receiptNumber: tr("STATEMENT-{value1}", {value1: order.number}), orderNumber: order.number, paidAt: order.created_at, orderLabel: order.table_name ? tr("Table {value1}", {value1: order.table_name}) : tr("Takeaway"), items: order.bill_items_snapshot, totals: order, total: order.total, paymentLabel: '', tendered: 0, change: 0, showPayment: false, note: tr("Received: {value1}\nRemaining balance: {value2}\nRefunded: {value3}\nThis is a bill overview. Individual payment receipts are listed separately.", {value1: money(order.received_amount, state.settings.currency), value2: money(order.balance_due, state.settings.currency), value3: money(order.total_refunded_amount, state.settings.currency)}) }}/>;
  return <ReceiptDialog settings={receipt!.restaurant_snapshot} onClose={onClose} data={{ documentTitle: receipt!.balance_after > 0 ? tr("Payment recorded") : undefined, receiptNumber: receipt!.receipt_number, orderNumber: order.number, paidAt: receipt!.created_at, orderLabel: receipt!.table_label ? tr("Table {value1}", {value1: receipt!.table_label}) : tr("Takeaway"), items: receipt!.items_snapshot, totals: receipt!.totals_snapshot ?? undefined, creditAmount: receipt!.credited_amount, total: receipt!.amount, totalLabel: tr('Payment this receipt'), paymentLabel: '', tendered: 0, change: 0, paymentLines: receipt!.payments,
    note: tr("{value1}Order total: {value2}\nPreviously received: {value3}\nRemaining balance: {value4}", {value1: receipt!.payer_label ? tr("Payer: {value1}\n", {value1: receipt!.payer_label}) : '', value2: money(receipt!.order_total, receipt!.restaurant_snapshot.currency), value3: money(receipt!.prior_paid, receipt!.restaurant_snapshot.currency), value4: money(receipt!.balance_after, receipt!.restaurant_snapshot.currency)}),
  }}/>;
}
