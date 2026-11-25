import { tr, useLocale } from './locale';
import { useLiveRefresh } from './realtime';
import { useEffect, useState, type FormEvent } from 'react';
import { RefreshCw, Upload, Clock3, CheckCircle2, XCircle } from 'lucide-react';
import { api } from './api';
import { Modal, PageHeading } from './components';
import { money, dateTime, useApp } from './context';
import type { Plan } from './types';
import { officeMultipart, type OfficeRow } from './OfficeTools';

export function SubscriptionRequests() {
  useLocale();
  const [data,setData]=useState<OfficeRow>(),[error,setError]=useState(''),[open,setOpen]=useState(false);
  const load=()=>api<OfficeRow>('billing/requests').then(result=>{setData(result);setError('');}).catch(e=>setError(e.message));
  useEffect(()=>{void load();},[]);
  useLiveRefresh(load);
  return <section className="platform-card subscription-payment-requests"><div className="subscription-payment-header"><div className="subscription-payment-copy"><h2>{tr("Subscription payment requests")}</h2><p>{tr("Send proof after making an external payment. Office approval is required before access changes.")}</p></div><div className="subscription-payment-actions" role="group" aria-label={tr("Subscription payment actions")}><button type="button" className="button" aria-label={tr("Refresh payment requests")} onClick={()=>void load()}><RefreshCw size={16} aria-hidden="true"/>{tr("Refresh")}</button>{data&&<button type="button" className="button primary" disabled={data.requests.some((row:OfficeRow)=>row.status==='pending')||!data.methods.length||!data.plans.length} onClick={()=>setOpen(true)}><Upload size={16} aria-hidden="true"/>{tr("Submit payment proof")}</button>}</div></div>{error&&<div className="form-error" role="alert">{tr(error)}</div>}{data&&<>{data.settings.support_email&&<p className="office-note">{tr("Support:")} <a href={`mailto:${data.settings.support_email}`}>{data.settings.support_email}</a>{data.settings.support_phone?' · '+data.settings.support_phone:''}</p>}{!data.methods.length&&<p className="office-note">{tr("Payment methods are not configured yet. Contact support to arrange your renewal.")}</p>}{data.requests.length?<PaymentRequestList requests={data.requests} timezone={data.settings.timezone}/>:<p className="platform-empty">{tr("No payment requests submitted.")}</p>}{open&&<PaymentProofForm plans={data.plans} methods={data.methods} onClose={()=>setOpen(false)} onSaved={async()=>{setOpen(false);await load();}}/>}</>}</section>;
}
function PaymentRequestList({ requests, timezone }: { requests: OfficeRow[]; timezone: string }) {
  useLocale();
  return <div className="office-content-list subscription-request-list">
    <table className="subscription-request-table" aria-label={tr("Subscription payment requests")}>
      <thead><tr><th>{tr("Plan")}</th><th>{tr("Payment method / transaction ID")}</th><th title={tr("Times in {value1}", {value1: timezone})}>{tr("Submitted")}</th><th className="request-amount">{tr("Amount")}</th><th>{tr("Status")}</th></tr></thead>
      {requests.map(row => <tbody key={row.id}>
        <tr className="subscription-request-row">
          <td className="request-plan" data-label={tr("Plan")}><strong>{row.plan_snapshot.name}</strong><small>{tr("Request #")}{row.id}</small></td>
          <td className="request-method" data-label={tr("Payment method")}><span>{row.method_snapshot.name}</span><small>{row.reference ? tr("ID: {value1}", {value1: row.reference}) : tr("No transaction ID")}</small></td>
          <td className="request-date" data-label={tr("Submitted")}>{dateTime(row.created_at, timezone)}</td>
          <td className="request-amount" data-label={tr("Amount")}><strong>{money(row.amount, row.currency)}</strong></td>
          <td className="request-status"><span className={`subscription-request-status ${row.status}`}>
            {row.status === 'approved' ? <CheckCircle2 size={14} aria-hidden="true"/> : row.status === 'rejected' ? <XCircle size={14} aria-hidden="true"/> : <Clock3 size={14} aria-hidden="true"/>}
            {row.status === 'pending' ? tr("Pending review") : row.status === 'approved' ? tr("Approved") : row.status === 'rejected' ? tr("Rejected") : tr(row.status)}
          </span></td>
        </tr>
        {row.review_reason && <tr className="subscription-request-review"><td colSpan={5}><div><strong>{tr("Office response")}</strong><p>{row.review_reason}</p></div></td></tr>}
      </tbody>)}
    </table>
  </div>;
}
function PaymentProofForm({plans,methods,onClose,onSaved}:{plans:Plan[];methods:OfficeRow[];onClose:()=>void;onSaved:()=>Promise<void>}) {
  useLocale();
  const [planId,setPlanId]=useState(String(plans[0]?.id||'')),[methodId,setMethodId]=useState(''),[reference,setReference]=useState(''),[receipt,setReceipt]=useState<File>(),[confirmed,setConfirmed]=useState(false),[busy,setBusy]=useState(false),[error,setError]=useState('');
  const plan=plans.find(plan=>String(plan.id)===planId),available=methods.filter(method=>method.currency===plan?.currency),method=available.find(method=>String(method.id)===methodId);
  const submit=async(e:FormEvent)=>{e.preventDefault();if(busy||!plan||!method)return;setBusy(true);setError('');try{await api('billing/requests',officeMultipart({plan_id:plan.id,method_id:method.id,amount:plan.price,reference,receipt,confirmed}));await onSaved();}catch(e){setError((e as Error).message);}finally{setBusy(false);}};
  return <Modal title={tr("Submit subscription payment")} onClose={()=>{if(!busy)onClose();}}><form className="office-form" onSubmit={e=>void submit(e)}><fieldset disabled={busy} className="office-fields"><label>{tr("Subscription plan")}<select aria-label={tr("Subscription plan")} value={planId} onChange={e=>{setPlanId(e.target.value);setMethodId('');setConfirmed(false);}} required>{plans.map(plan=><option key={plan.id} value={plan.id}>{plan.name} · {money(plan.price,plan.currency)} / {tr(plan.billing_period)}</option>)}</select></label><label>{tr("Payment method")}<select aria-label={tr("Payment method")} value={methodId} onChange={e=>{setMethodId(e.target.value);setConfirmed(false);}} required><option value="">{tr("Choose a payment method")}</option>{available.map(method=><option key={method.id} value={method.id}>{method.name}</option>)}</select></label>{method&&<section className="panel" style={{padding:16}}><strong>{method.name}</strong><p className="office-long-text">{method.instructions}</p><p>{tr("Amount:")} <strong>{money(plan?.price||0,plan?.currency)}</strong></p></section>}<label>{tr("Bank transaction reference (optional)")}<input maxLength={150} value={reference} onChange={e=>setReference(e.target.value)}/></label><label>{tr("Payment proof (optional, up to 4 MB)")}<input type="file" accept="image/png,image/jpeg,image/webp" onChange={e=>setReceipt(e.target.files?.[0])}/></label><label className="checkbox-line"><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked)} required/>{tr("I already sent the exact plan amount using this payment method.")}</label><p className="office-note">{tr("This form records a request. It does not charge a card or transfer money. Please wait for office confirmation before making another payment.")}</p></fieldset>{error&&<div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy||!confirmed||!method}>{busy?tr("Submitting..."):tr("Submit for approval")}</button></form></Modal>;
}

export function BusinessHelpPage() {
  useLocale();
  const {state}=useApp(),[data,setData]=useState<OfficeRow>(),[error,setError]=useState(''),[open,setOpen]=useState(false);
  const load=()=>api<OfficeRow>('help').then(result=>{setData(result);setError('');}).catch(e=>setError(e.message));
  useEffect(()=>{void load();},[]);
  useLiveRefresh(load);
  return <><PageHeading title={tr("Help & support")} subtitle={tr("Guides, platform announcements, and support for your business.")}><button className="button" onClick={()=>void load()}>{tr("Refresh help")}</button><button className="button primary" onClick={()=>setOpen(true)}>{tr("Report an issue")}</button></PageHeading>{error&&<div className="form-error" role="alert">{tr(error)}</div>}{data&&<><section className="platform-card"><h2>{tr("Contact support")}</h2><p className="office-note">{data.settings.support_email&&<><a href={`mailto:${data.settings.support_email}`}>{data.settings.support_email}</a><br/></>}{data.settings.support_phone&&<>{data.settings.support_phone}<br/></>}{data.settings.support_url&&<a href={data.settings.support_url} target="_blank" rel="noreferrer">{tr("Support website")}</a>}{!data.settings.support_email&&!data.settings.support_phone&&!data.settings.support_url&&tr("Support contact details have not been published yet.")}</p></section><div className="office-content-list">{data.content.map((row:OfficeRow)=><section className="platform-card" key={row.id}><span className="eyebrow">{tr(row.kind)}</span><h2>{row.title}</h2><p className="office-long-text">{row.body}</p>{row.url&&<a className="button" href={row.url} target="_blank" rel="noreferrer">{tr("Open guide")}</a>}</section>)}</div><section className="platform-card"><h2>{tr("Your support reports")}</h2>{data.reports.map((row:OfficeRow)=><article className="platform-card" key={row.id}><div className="row-between"><strong>{row.title}</strong><span className="platform-badge">{tr(row.status.replaceAll('_',' '))}</span></div><p className="office-note">{dateTime(row.created_at,state.settings.timezone)} · {tr(row.priority)}</p><p className="office-long-text">{row.description}</p>{row.resolution&&<><h3>{tr("Response from support")}</h3><p className="office-long-text">{row.resolution}</p></>}</article>)}{!data.reports.length&&<p className="platform-empty">{tr("No reports submitted yet.")}</p>}</section></>}{open&&<IssueForm onClose={()=>setOpen(false)} onSaved={async()=>{setOpen(false);await load();}}/>}</>;
}
function IssueForm({onClose,onSaved}:{onClose:()=>void;onSaved:()=>Promise<void>}) {
  useLocale();
  const [busy,setBusy]=useState(false),[error,setError]=useState('');
  const submit=async(e:FormEvent<HTMLFormElement>)=>{e.preventDefault();if(busy)return;const data=new FormData(e.currentTarget);if(!(data.get('screenshot') as File)?.size)data.delete('screenshot');setBusy(true);setError('');try{await api('help/reports',data);await onSaved();}catch(e){setError((e as Error).message);}finally{setBusy(false);}};
  return <Modal title={tr("Report an issue")} onClose={()=>{if(!busy)onClose();}}><form className="office-form" onSubmit={e=>void submit(e)}><fieldset disabled={busy} className="office-fields"><label>{tr("Issue title")}<input name="title" required maxLength={150}/></label><label>{tr("What happened?")}<textarea name="description" rows={5} required maxLength={5000} placeholder={tr("Describe the steps, what you expected, and what happened.")}/></label><label>{tr("Priority")}<select name="priority"><option value="normal">{tr("Normal")}</option><option value="low">{tr("Low")}</option><option value="high">{tr("High")}</option></select></label><label>{tr("Screenshot (optional, up to 4 MB)")}<input type="file" name="screenshot" accept="image/png,image/jpeg,image/webp"/></label><p className="office-note">{tr("Include enough detail for support to reproduce the issue. Avoid including passwords or payment card details.")}</p></fieldset>{error&&<div className="form-error" role="alert">{tr(error)}</div>}<button className="button primary full" disabled={busy}>{busy?tr("Sending..."):tr("Send support report")}</button></form></Modal>;
}
