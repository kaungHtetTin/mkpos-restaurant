import { tr, useLocale } from './locale';
import { useState, type FormEvent } from 'react';
import { Check, Percent } from 'lucide-react';
import { api } from './api';
import { useApp, money } from './context';
import { Modal } from './components';
import type { ChargeTotals, Order } from './types';
import './charges.css';

const percentage = (value: number) => `${value / 100}%`;
const charge = (amount: number, basisPoints: number) => Math.floor((amount * basisPoints + 5000) / 10000);

export function DiscountDetails({ details, currency }: { details?: string | Record<string, unknown>; currency: string }) {
  useLocale();
  let data: Record<string, unknown>;
  try { data = typeof details === 'string' ? JSON.parse(details) : details || {}; } catch { return null; }
  return <small className="discount-history">{data.discount_type === 'none' ? tr("Discount removed") : data.discount_type === 'percent' ? tr("{value1}% discount", {value1: Number(data.discount_value) / 100}) : tr("{value1} discount", {value1: money(Number(data.discount_value), currency)})}{data.reason ? tr(": {value1}", {value1: String(data.reason)}) : ''}</small>;
}

export function BillBreakdown({ totals, currency, showTotal = false }: { totals: ChargeTotals; currency: string; showTotal?: boolean }) {
  useLocale();
  if (!showTotal && !totals.discount_amount && !totals.tax_rate_bps && !totals.service_rate_bps && !totals.charge_lines?.length) return null;
  const lines = totals.charge_lines ?? [
    ...(totals.service_rate_bps > 0 ? [{ kind: 'service', label: totals.service_charge_label, rate_bps: totals.service_rate_bps, amount: totals.service_amount }] : []),
    ...(totals.tax_rate_bps > 0 ? [{ kind: 'tax', label: totals.tax_label, rate_bps: totals.tax_rate_bps, amount: totals.tax_amount }] : []),
  ];
  return <div className="bill-breakdown">
    <div><span>{tr("Item subtotal")}</span><strong>{money(totals.subtotal, currency)}</strong></div>
    {totals.discount_amount > 0 && <div className="bill-discount"><span>{tr("Discount")}</span><strong>-{money(totals.discount_amount, currency)}</strong></div>}
    {lines.map((line, index) => <div key={index}><span>{line.label} ({percentage(line.rate_bps)})</span><strong>{money(line.amount, currency)}</strong></div>)}
    {showTotal && <div className="bill-grand-total"><span>{tr("Total")}</span><strong>{money(totals.total, currency)}</strong></div>}
  </div>;
}

export function ChargeSettings() {
  useLocale();
  const { state, refresh, notify, connected } = useApp();
  const [tax, setTax] = useState(String(state.settings.tax_rate_bps / 100));
  const [service, setService] = useState(String(state.settings.service_rate_bps / 100));
  const [taxLabel, setTaxLabel] = useState(state.settings.tax_label), [serviceLabel, setServiceLabel] = useState(state.settings.service_charge_label);
  const [taxOnService, setTaxOnService] = useState(!!state.settings.tax_on_service), [dineInOnly, setDineInOnly] = useState(!!state.settings.service_dine_in_only);
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const taxRate = Math.round((Number(tax) || 0) * 100), serviceRate = Math.round((Number(service) || 0) * 100);
  const serviceAmount = charge(10000, serviceRate), taxAmount = charge(10000 + (taxOnService ? serviceAmount : 0), taxRate);
  const preview: ChargeTotals = { subtotal: 10000, discount_amount: 0, service_amount: serviceAmount, tax_amount: taxAmount, tax_rate_bps: taxRate, service_rate_bps: serviceRate, tax_label: taxLabel || tr("Tax"), service_charge_label: serviceLabel || tr("Service charge"), tax_on_service: taxOnService, total: 10000 + serviceAmount + taxAmount };
  const submit = async (event: FormEvent) => { event.preventDefault(); setBusy(true); setError(''); try { await api('manage/charges', { tax_rate_bps: taxRate, service_rate_bps: serviceRate, tax_label: taxLabel, service_charge_label: serviceLabel, tax_on_service: taxOnService, service_dine_in_only: dineInOnly }); await refresh(); notify(tr("Charge settings saved. New orders will use these rates.")); } catch (error) { setError((error as Error).message); } finally { setBusy(false); } };
  return <><div className="panel-header"><div><h2>{tr("Tax & service charges")}</h2><p>{tr("Configure additions to your menu prices.")}</p></div><Percent size={23}/></div>
    <div className="charge-settings-note">{tr("Rates apply to new orders. Open orders and paid receipts keep their existing rates.")}</div>
    <form className="restaurant-form" onSubmit={event => void submit(event)}><fieldset className="charge-fields" disabled={busy}>
      <div className="form-grid"><label>{tr("Tax name")}<input value={taxLabel} onChange={event => setTaxLabel(event.target.value)} maxLength={40} required/></label><label>{tr("Tax rate (%)")}<input type="number" min={0} max={100} step="0.01" value={tax} onChange={event => setTax(event.target.value)} required/></label></div>
      <div className="form-grid"><label>{tr("Service charge name")}<input value={serviceLabel} onChange={event => setServiceLabel(event.target.value)} maxLength={40} required/></label><label>{tr("Service charge rate (%)")}<input type="number" min={0} max={100} step="0.01" value={service} onChange={event => setService(event.target.value)} required/></label></div>
      <label className="checkbox-line"><input type="checkbox" checked={dineInOnly} onChange={event => setDineInOnly(event.target.checked)}/>{tr("Apply service charge to dine-in orders only")}</label>
      <label className="checkbox-line"><input type="checkbox" checked={taxOnService} onChange={event => setTaxOnService(event.target.checked)}/>{tr("Include service charge in the taxable amount")}</label>
      <p className="charge-help">{tr("Discounts reduce the item subtotal first. Service charge and tax are then added, rounded to the nearest whole")} {state.settings.currency}  {tr("unit. A 0% rate adds no charge.")}</p>
      <section className="charge-preview"><h3>{tr("Example dine-in bill")}</h3><BillBreakdown totals={preview} currency={state.settings.currency} showTotal/></section>
    </fieldset>{error && <div className="form-error" role="alert">{tr(error)}</div>}<div className="form-actions"><button className="button primary" disabled={busy || !connected || !state.writable}>{busy ? tr("Saving...") : tr("Save charge settings")}<Check size={17}/></button></div></form>
  </>;
}

export function DiscountModal({ order, onClose, onSave }: { order: Order; onClose: () => void; onSave: (type: string, value: number, reason: string, version: number, groupId: number) => Promise<void> }) {
  useLocale();
  const { state, connected } = useApp();
  const [groupId, setGroupId] = useState(order.id);
  const group = order.billing_groups?.find(group => group.id === groupId) ?? order;
  const [version] = useState(order.version), [type, setType] = useState<Order['discount_type']>(group.discount_type === 'none' ? 'percent' : group.discount_type);
  const [value, setValue] = useState(group.discount_type === 'percent' ? String(group.discount_value / 100) : group.discount_type === 'fixed' ? String(group.discount_value) : '');
  const [reason, setReason] = useState(''), [busy, setBusy] = useState(false), [error, setError] = useState('');
  const discountValue = type === 'none' ? 0 : Math.round(Number(value) * (type === 'percent' ? 100 : 1));
  const discountAmount = type === 'none' ? 0 : type === 'percent' ? charge(group.subtotal, discountValue) : discountValue;
  const net = Math.max(0, group.subtotal - discountAmount), serviceAmount = charge(net, group.service_rate_bps), taxAmount = charge(net + (group.tax_on_service ? serviceAmount : 0), group.tax_rate_bps);
  const preview: ChargeTotals = { ...group, charge_lines: undefined, billing_groups: undefined, discount_amount: discountAmount || 0, service_amount: serviceAmount, tax_amount: taxAmount, total: net + serviceAmount + taxAmount };
  const stale = version !== order.version;
  const submit = async (event: FormEvent) => { event.preventDefault(); if (stale || busy) return; setBusy(true); setError(''); try { await onSave(type, discountValue, reason, version, groupId); } catch (error) { setError((error as Error).message); } finally { setBusy(false); } };
  return <Modal title={tr("Order discount")} subtitle={tr("Manager approval is recorded with your name and reason.")} onClose={() => { if (!busy) onClose(); }}><form onSubmit={event => void submit(event)}><fieldset className="charge-fields" disabled={busy}>
    {order.billing_groups && order.billing_groups.length > 1 && <><label>{tr("Bill portion")}<select value={groupId} onChange={event => { const id = Number(event.target.value); const selected = order.billing_groups!.find(group => group.id === id)!; setGroupId(id); setType(selected.discount_type === 'none' ? 'percent' : selected.discount_type); setValue(selected.discount_type === 'percent' ? String(selected.discount_value / 100) : selected.discount_type === 'fixed' ? String(selected.discount_value) : ''); setReason(''); }}>
      {order.billing_groups.map(group => <option key={group.id} value={group.id}>{group.label} · {group.number}</option>)}</select></label><p className="charge-help">{tr("Discounts and rates stay with each original bill. This preview changes only the selected portion.")}</p></>}
    <label>{tr("Discount type")}<select value={type} onChange={event => { setType(event.target.value as typeof type); setValue(''); }}><option value="percent">{tr("Percentage")}</option><option value="fixed">{tr("Fixed amount")}</option><option value="none">{tr("Remove discount")}</option></select></label>
    {type !== 'none' && <label>{type === 'percent' ? tr("Discount (%)") : tr("Discount amount ({value1})", {value1: state.settings.currency})}<input type="number" min={type === 'percent' ? 0.01 : 1} max={type === 'percent' ? 100 : group.subtotal} step={type === 'percent' ? '0.01' : '1'} value={value} onChange={event => setValue(event.target.value)} required/></label>}
    <label>{tr("Approval reason")}<textarea maxLength={255} rows={2} value={reason} onChange={event => setReason(event.target.value)} required placeholder={tr("For example, approved promotion or service recovery")}/></label>
    <BillBreakdown totals={preview} currency={state.settings.currency} showTotal/>
  </fieldset>{stale && <div className="form-error" role="alert">{tr("This order changed. Close this dialog and review the latest order before applying a discount.")}</div>}{error && <div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy || stale || !connected || !state.writable}>{busy ? tr("Saving...") : type === 'none' ? tr("Remove discount") : tr("Approve discount")}<Check size={17}/></button></form></Modal>;
}
