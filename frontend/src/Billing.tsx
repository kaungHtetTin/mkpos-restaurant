import { tr, useLocale, formatLocale , formatDate } from './locale';
import { useLiveRefresh } from './realtime';
import { useEffect, useState } from 'react';
import { api } from './api';
import type { Business, Subscription, Plan } from './types';
import { SubscriptionRequests } from './BusinessHelp';
import { PageHeading } from './components';

export interface SubscriptionPayment { id: number; amount: number; currency: string; reference: string; plan_name: string; period_start: string; period_end: string; created_at: string }
export const displayDate = (value: string) => formatDate(new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z'), { day: 'numeric', month: 'short', year: 'numeric' });
export const money = (value: number, currency: string) => Number(value).toLocaleString(formatLocale()) + ' ' + currency;
export function BillingPage() {
  useLocale();
  const [data, setData] = useState<{ business: Business; subscription: Subscription; payments: { data: SubscriptionPayment[]; current_page: number; last_page: number }; plans: Plan[] }>();
  const [error, setError] = useState(''), [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);
  useLiveRefresh(() => setRevision(value=>value+1));
  useEffect(() => { let current = true; api<NonNullable<typeof data>>("billing?page=" + page).then(d => { if (current) { setData(d); setError(''); } }).catch(e => { if (current) setError(e.message); }); return () => { current = false; }; }, [page, revision]);
  return <div className="platform-page"><PageHeading title={tr("Subscription")} subtitle={tr("Your business account and subscription history.")}/>
    {error && <div className="form-error" role="alert">{tr(error)}</div>}
    {data && <><section className="platform-card"><div className="office-title"><div><span className="eyebrow">{tr("YOUR BUSINESS")}</span><h2>{data.business.name}</h2><p>{tr("Staff business code:")} <strong>{data.business.code}</strong></p></div><span className={'platform-badge ' + (data.business.status === 'suspended' ? 'suspended' : data.subscription.status)}>{data.business.status === 'suspended' ? tr("Suspended") : tr(data.subscription.status)}</span></div>
      <div className="office-kpis"><div><span>{tr("Current plan")}</span><strong>{data.subscription.plan_name}</strong>{data.subscription.is_complimentary && <small>{tr("FOC · Free of charge")}</small>}</div><div><span>{tr("Plan price")}</span><strong>{money(data.subscription.price, data.subscription.currency)}</strong></div><div><span>{tr("Access until")}</span><strong>{displayDate(data.subscription.ends_at)}</strong></div></div>
      <p>{tr("Contact the platform office to choose a plan, arrange payment, or renew. The office records confirmed payments and updates your access.")}</p>
    </section>
    <SubscriptionRequests/>
    {data.plans.length > 0 && <section className="platform-card"><h2>{tr("Available plans")}</h2><div className="office-kpis">{data.plans.map(p => <div key={p.id}><span>{p.name}</span><strong>{money(p.price, p.currency)}</strong><small>{tr("per")} {tr(p.billing_period)}</small>{p.description && <p>{p.description}</p>}{p.features && <ul>{(Array.isArray(p.features)?p.features:JSON.parse(p.features)).map((feature:string)=><li key={feature}>{feature}</li>)}</ul>}</div>)}</div></section>}
    <section className="platform-card"><h2>{tr("Subscription payments")}</h2><PaymentTable payments={data.payments.data}/><div className="office-pagination"><button className="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>{tr("Previous")}</button><span>{tr("Page")} {page}  {tr("of")} {data.payments.last_page}</span><button className="button" disabled={page >= data.payments.last_page} onClick={() => setPage(page + 1)}>{tr("Next")}</button></div></section></>}
  </div>;
}
export function PaymentTable({ payments }: { payments: SubscriptionPayment[] }) {
  useLocale();
  if (!payments.length) return <p className="platform-empty">{tr("No subscription payments recorded.")}</p>;
  return <div className="office-table-wrap"><table className="office-table"><thead><tr><th>{tr("Date")}</th><th>{tr("Plan")}</th><th>{tr("Amount")}</th><th>{tr("Reference")}</th><th>{tr("Coverage")}</th></tr></thead><tbody>{payments.map(p => <tr key={p.id}><td>{displayDate(p.created_at)}</td><td>{p.plan_name}</td><td>{money(p.amount, p.currency)}</td><td>{p.reference}</td><td>{displayDate(p.period_start)} – {displayDate(p.period_end)}</td></tr>)}</tbody></table></div>;
}
