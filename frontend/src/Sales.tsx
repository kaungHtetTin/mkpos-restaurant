import { tr, useLocale, paymentMethodText, formatLocale , formatDate } from './locale';
import { useLiveRefresh } from './realtime';
import { useEffect, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowUpRight, CalendarDays, CalendarRange, ChartNoAxesCombined, RefreshCw, Search, SlidersHorizontal } from 'lucide-react';
import { api } from './api';
import { dateTime, money, useApp, tableLabel } from './context';
import { Badge, Empty, PageHeading } from './components';
import './sales.css';
import './payment-adjustments.css';
import { SalesChart, type SalesChartData } from './SalesChart';

type Period = 'day' | 'month' | 'year';
interface Filters { date: string; period: Period; q: string; type: string; method: string }
interface Totals { amount: number; count: number }
interface Sale { id: number; order_id: number; order_number: string; receipt_number: string; amount: number; refunded_amount: number; retained_amount: number; method_label: string; method: string; paid_at: string; type: string; table_name: string | null; customer_name: string | null; cashier_name: string }
interface SalesReport { date: string; period: Period; timezone: string; currency: string; from: string; to: string; summary: Record<Period, Totals>; refund_summary: Record<Period, { refunded: number; net: number }>; adjustments: { data: { id: number; kind: string; reference_number: string; order_id: number; order_number: string; created_at: string; amount: number; from_label: string; to_label: string; from_method: string; to_method: string; reason: string; actor_name: string }[]; current_page: number; last_page: number; total: number }; chart: SalesChartData; filtered: Totals; sales: { data: Sale[]; current_page: number; last_page: number; total: number; from: number | null; to: number | null } }

function restaurantDate(timezone: string) {
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  const part = (type: string) => parts.find(p => p.type === type)?.value;
  return `${part('year')}-${part('month')}-${part('day')}`;
}
function periodLabel(date: string, period: Period) {
  const parsed = new Date(`${date}T12:00:00Z`);
  if (Number.isNaN(parsed.getTime())) return tr("Select a valid date");
  return formatDate(parsed, { timeZone: 'UTC', ...(period === 'day' ? { day: 'numeric', month: 'short', year: 'numeric' } : period === 'month' ? { month: 'long', year: 'numeric' } : { year: 'numeric' }) });
}

export function SalesPage() {
  useLocale();
  const { state, connected, user } = useApp();
  const canViewAllPeriods = user.roles.includes('manager');
  const [params, setParams] = useSearchParams();
  const applied: Filters = {
    date: params.get('date') || restaurantDate(state.settings.timezone),
    period: canViewAllPeriods ? (params.get('period') || 'day') as Period : 'day',
    q: params.get('q') || '', type: params.get('type') || '', method: params.get('method') || '',
  };
  const page = params.get('page') || '1';
  const query = new URLSearchParams({ ...applied, page, adjustment_page: params.get('adjustment_page') || '1' }).toString();
  const [draft, setDraft] = useState<Filters>(applied);
  const [result, setResult] = useState<SalesReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [revision, setRevision] = useState(0);

  useLiveRefresh(() => setRevision(value=>value+1));
  useEffect(() => { setDraft(applied); }, [query]);
  useEffect(() => {
    let cancelled = false;
    setLoading(true); setError('');
    api<SalesReport>(`sales?${query}`).then(report => { if (!cancelled) setResult(report); })
      .catch(exception => { if (!cancelled) setError(exception.message); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [query, revision]);

  const update = (name: keyof Filters, value: string) => setDraft(previous => ({ ...previous, [name]: value }));
  const commit = (filters: Filters, nextPage = '1') => {
    const next = new URLSearchParams({ ...filters, period: canViewAllPeriods ? filters.period : 'day', page: nextPage, adjustment_page: '1' });
    setParams(next);
    if (next.toString() === query) setRevision(value => value + 1);
  };
  const submit = (event: FormEvent) => { event.preventDefault(); commit(draft); };
  const summaryCards = [
    { period: 'day' as Period, title: tr("Daily sales"), icon: CalendarDays },
    { period: 'month' as Period, title: tr("Monthly sales"), icon: CalendarRange },
    { period: 'year' as Period, title: tr("Yearly sales"), icon: ChartNoAxesCombined },
  ].filter(card => canViewAllPeriods || card.period === 'day');
  const selectedLabel = periodLabel(applied.date, applied.period);

  return <>
    <PageHeading eyebrow={tr("A CLEAR VIEW OF YOUR SALES")} title={tr("Every sale, in one place")} subtitle={canViewAllPeriods ? tr("Paid sale records and daily, monthly, and yearly amounts.") : tr("Paid sale records and daily amounts.")}>
      <button className="button" onClick={() => setRevision(value => value + 1)} disabled={loading || !connected}><RefreshCw size={17}/>{tr("Refresh sales")}</button>
    </PageHeading>
    <div className={`sales-summary ${canViewAllPeriods ? '' : 'daily-only'}`} aria-label={tr("Sales totals")} aria-busy={loading}>
      {summaryCards.map(card => <button key={card.period} className={`sale-summary-card ${applied.period === card.period ? 'selected' : ''}`} aria-pressed={applied.period === card.period} aria-label={tr("Show {value1}", {value1: card.title.toLowerCase()})} disabled={loading} onClick={() => commit({ ...applied, period: card.period })}>
        <span className="sale-summary-top"><span>{card.title}</span><card.icon size={21}/></span>
        <strong>{result ? money(result.summary[card.period].amount, result.currency) : '—'}</strong>
        <span className="sale-summary-date">{periodLabel(applied.date, card.period)}</span>
        <span className="sale-summary-bottom"><span>{result ? tr("{value1} payments", {value1: result.summary[card.period].count}) : loading ? tr("Loading totals...") : tr("Totals unavailable")}</span><ArrowUpRight size={16}/></span>
      </button>)}
    </div>
    <p className="sales-basis">{tr("Cards and chart show gross payments using the payment date in")} {result?.timezone || state.settings.timezone}{tr(". Refunds use their issue date; net collections subtract refunds issued in the selected period. Paid takeaway orders count before collection. Search and filters below apply to the sale records.")}</p>
    {result && <div className="refund-summary" aria-label={tr("Refund and net totals")}><div><span>{tr("Gross payments in this period")}</span><strong>{money(result.summary[applied.period].amount, result.currency)}</strong></div><div><span>{tr("Refunds issued in this period")}</span><strong>{money(result.refund_summary[applied.period].refunded, result.currency)}</strong></div><div><span>{tr("Net collections in this period")}</span><strong>{money(result.refund_summary[applied.period].net, result.currency)}</strong></div></div>}
    <SalesChart chart={result?.chart} period={applied.period} label={selectedLabel} currency={result?.currency || state.settings.currency} loading={loading} dailyOnly={!canViewAllPeriods} onPeriodChange={period => commit({ ...applied, period })}/>
    <section className="panel sales-panel">
      <div className="panel-header"><div><h2>{tr("Sale records")} <span className="count-pill">{result?.filtered.count ?? '—'}</span></h2><p>{selectedLabel} · {applied.period === 'day' ? tr("Daily") : applied.period === 'month' ? tr("Monthly") : tr("Yearly")}  {tr("view")}</p></div><Badge status="paid">{tr("Received payments")}</Badge></div>
      <form className="sales-filters" aria-label={tr("Sales filters")} onSubmit={submit}>
        <div className="sales-filter-scroll"><div className="sales-filter-fields">
          <label className="sales-search-field">{tr("Search sales")}<span className="search"><Search size={17}/><input value={draft.q} onChange={event => update('q', event.target.value)} placeholder={tr("Order, receipt, table or customer")} maxLength={100}/></span></label>
          <label className="sales-date-field">{tr("Reference date")}<input type="date" required max="9998-12-31" value={draft.date} onChange={event => update('date', event.target.value)}/></label>
          {canViewAllPeriods && <label className="sales-period-field">{tr("Period")}<select value={draft.period} onChange={event => update('period', event.target.value)}><option value="day">{tr("Daily")}</option><option value="month">{tr("Monthly")}</option><option value="year">{tr("Yearly")}</option></select></label>}
          <label className="sales-type-field">{tr("Order type")}<select value={draft.type} onChange={event => update('type', event.target.value)}><option value="">{tr("All types")}</option><option value="dine_in">{tr("Dine-in")}</option><option value="takeaway">{tr("Takeaway")}</option></select></label>
          <label className="sales-method-field">{tr("Payment")}<select value={draft.method} onChange={event => update('method', event.target.value)}><option value="">{tr("All payments")}</option><option value="cash">{tr("Cash")}</option><option value="other">{tr("Other payment")}</option></select></label>
        </div></div>
        <div className="sales-filter-actions"><button className="button primary" disabled={loading || !connected}><SlidersHorizontal size={16}/>{tr("Apply")}</button><button className="button" type="button" disabled={loading} onClick={() => commit({ date: restaurantDate(state.settings.timezone), period: 'day', q: '', type: '', method: '' })}>{tr("Reset")}</button></div>
      </form>
      {error ? <div className="sales-error" role="alert"><h3>{tr("Sales could not be loaded")}</h3><p>{tr(error)}</p><button className="button" onClick={() => setRevision(value => value + 1)}><RefreshCw size={16}/>{tr("Retry sales")}</button></div>
        : loading ? <div role="status"><Empty title={tr("Loading sales...")} text={tr("Calculating payment totals for your restaurant.")}/></div>
        : result && <>
          <div className="sales-record-summary"><span>{result.filtered.count}  {tr("matching payments")}</span><div><span>{tr("Matching sales amount")}</span><strong data-testid="filtered-sales-amount">{money(result.filtered.amount, result.currency)}</strong></div></div>
          {result.sales.data.length ? <>
            <div className="table-scroll sales-desktop-records"><table><thead><tr><th>{tr("Sale / Receipt")}</th><th>{tr("Paid at")}</th><th>{tr("Table / Customer")}</th><th>{tr("Payment")}</th><th>{tr("Cashier")}</th><th className="sale-amount-cell">{tr("Sale amount")}</th><th/></tr></thead><tbody>
              {result.sales.data.map(sale => <tr key={sale.id}><td><strong>{sale.order_number}</strong><small>{sale.receipt_number}</small></td><td>{dateTime(sale.paid_at, result.timezone)}</td><td>{sale.table_name ? tableLabel(sale.table_name) : sale.customer_name || tr("Walk-in customer")}<small>{sale.type === 'dine_in' ? tr("Dine-in") : tr("Takeaway")}</small></td><td>{paymentMethodText(sale.method_label, sale.method)}</td><td>{sale.cashier_name}</td><td className="sale-amount-cell"><strong>{money(sale.amount, result.currency)}</strong>{sale.refunded_amount > 0 && <small className="refund-sale-note">{tr("Refunded")} {money(sale.refunded_amount, result.currency)}</small>}</td><td><Link className="button small" to={`/orders/${sale.order_id}`} aria-label={tr("View sale {value1}", {value1: sale.order_number})}>{tr("View")}<ArrowUpRight size={14}/></Link></td></tr>)}
            </tbody></table></div>
            <div className="sales-mobile-records">{result.sales.data.map(sale => <article className="mobile-sale-record" key={sale.id}><div className="row-between"><strong>{sale.order_number}</strong><strong className="mobile-sale-amount">{money(sale.amount, result.currency)}</strong></div><p>{sale.receipt_number} · {dateTime(sale.paid_at, result.timezone)}</p><dl><div><dt>{sale.type === 'dine_in' ? tr("Table") : tr("Customer")}</dt><dd>{sale.table_name || sale.customer_name || tr("Walk-in customer")}</dd></div><div><dt>{tr("Payment")}</dt><dd>{paymentMethodText(sale.method_label, sale.method)}</dd></div>{sale.refunded_amount > 0 && <div><dt>{tr("Refunded")}</dt><dd>{money(sale.refunded_amount, result.currency)}</dd></div>}<div><dt>{tr("Cashier")}</dt><dd>{sale.cashier_name}</dd></div></dl><Link className="button full" to={`/orders/${sale.order_id}`} aria-label={tr("View sale {value1}", {value1: sale.order_number})}>{tr("View sale & receipt")}<ArrowUpRight size={15}/></Link></article>)}</div>
          </> : <Empty title={tr("No payments found")} text={tr("Try a different date, period, or search. Only recorded payment amounts are included. Unreceived balances are excluded.")}/>}
          <div className="pagination"><span>{result.sales.from || 0}–{result.sales.to || 0}  {tr("of")} {result.sales.total}  {tr("sales")}</span><div><button className="button small" disabled={result.sales.current_page <= 1} onClick={() => commit(applied, String(result.sales.current_page - 1))}>{tr("Previous")}</button><span>{result.sales.current_page} / {result.sales.last_page}</span><button className="button small" disabled={result.sales.current_page >= result.sales.last_page} onClick={() => commit(applied, String(result.sales.current_page + 1))}>{tr("Next")}</button></div></div>
        </>}
    </section>
    {result && <section className="panel adjustment-report"><div className="panel-header"><div><h2>{tr("Refunds & corrections")}</h2><p>{tr("Issued in")} {selectedLabel}{tr(". Payment filters use the refund or corrected method.")}</p></div></div><div className="table-scroll"><table><thead><tr><th>{tr("Adjustment")}</th><th>{tr("Order")}</th><th>{tr("Recorded")}</th><th>{tr("Method")}</th><th>{tr("Amount")}</th><th>{tr("Approved by")}</th></tr></thead><tbody>{result.adjustments.data.map(adjustment => <tr key={adjustment.id}><td><strong>{adjustment.kind === 'refund' ? tr("Refund") : tr("Method correction")}</strong><small>{adjustment.reference_number}</small><small>{adjustment.reason}</small></td><td><Link to={`/orders/${adjustment.order_id}`}>{adjustment.order_number}</Link></td><td>{dateTime(adjustment.created_at, result.timezone)}</td><td>{adjustment.kind === 'refund' ? paymentMethodText(adjustment.to_label, adjustment.to_method) : tr("{value1} to {value2}", {value1: paymentMethodText(adjustment.from_label, adjustment.from_method), value2: paymentMethodText(adjustment.to_label, adjustment.to_method)})}</td><td>{money(adjustment.amount, result.currency)}<small>{adjustment.kind === 'correction' ? tr("Reclassified; no new payment") : tr("Returned to customer")}</small></td><td>{adjustment.actor_name}</td></tr>)}</tbody></table></div>{!result.adjustments.data.length && <Empty title={tr("No adjustments in this period")} text={tr("Recorded refunds and method corrections appear here.")}/>}<div className="pagination"><span>{result.adjustments.total}  {tr("adjustments")}</span><div><button className="button small" disabled={result.adjustments.current_page <= 1} onClick={() => setParams({ ...applied, page, adjustment_page: String(result.adjustments.current_page - 1) })}>{tr("Previous adjustments")}</button><span>{result.adjustments.current_page} / {result.adjustments.last_page}</span><button className="button small" disabled={result.adjustments.current_page >= result.adjustments.last_page} onClick={() => setParams({ ...applied, page, adjustment_page: String(result.adjustments.current_page + 1) })}>{tr("Next adjustments")}</button></div></div></section>}
  </>;
}
