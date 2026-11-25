import { tr, useLocale, formatLocale, businessLocale, formatCompactNumber , formatDate } from './locale';
import { useEffect, useRef, useState } from 'react';
import { ChartNoAxesCombined, ChevronDown } from 'lucide-react';
import { money, useApp } from './context';

export interface SalesChartData { interval: 'hour' | 'day' | 'month'; points: { key: string; label: string; description: string; amount: number; count: number }[] }
type Period = 'day' | 'month' | 'year';

export function SalesChart({ chart, period, label, currency, loading, dailyOnly = false, onPeriodChange }: {
  chart?: SalesChartData; period: Period; label: string; currency: string; loading: boolean; dailyOnly?: boolean; onPeriodChange: (period: Period) => void;
}) {
  useLocale();
  const { state } = useApp();
  const [selected, setSelected] = useState(0);
  const [showData, setShowData] = useState(false);
  const buttons = useRef<(HTMLButtonElement | null)[]>([]);
  const points = (chart?.points || []).map(point => {
    if (businessLocale() !== 'my') return point;
    const date = chart?.interval === 'hour' ? new Date(Number(point.key) * 1000) : new Date(point.key + (chart?.interval === 'month' ? '-01T12:00:00Z' : 'T12:00:00Z'));
    if (Number.isNaN(date.getTime())) return point;
    const options: Intl.DateTimeFormatOptions = chart?.interval === 'hour'
      ? { timeZone: state.settings.timezone, year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'shortOffset' }
      : { timeZone: 'UTC', year: 'numeric', month: 'long', ...(chart?.interval === 'day' ? { day: 'numeric' } : {}) };
    return { ...point, description: formatDate(date, options), label: chart?.interval === 'month' ? formatDate(date, { timeZone: 'UTC', month: 'short' }) : point.label };
  });
  const maximum = Math.max(0, ...points.map(point => point.amount));
  useEffect(() => { setSelected(Math.max(0, points.findIndex(point => point.amount === maximum))); }, [chart]);
  const rawStep = maximum > 0 ? maximum / 4 : 1;
  const magnitude = 10 ** Math.floor(Math.log10(rawStep));
  const fraction = rawStep / magnitude;
  const step = (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * magnitude;
  const ceiling = step * 4;
  const active = points[selected] || points[0];
  const total = points.reduce((sum, point) => sum + point.amount, 0);
  const count = points.reduce((sum, point) => sum + point.count, 0);
  const title = tr("{value1} sales chart", {value1: period === 'day' ? tr('Daily') : period === 'month' ? tr('Monthly') : tr('Yearly')});
  const shortNumber = formatCompactNumber;
  const everyLabel = period === 'day' ? 3 : period === 'month' ? 2 : 1;
  return <section className="panel sales-chart-panel" aria-label={title} aria-busy={loading}>
    <div className="panel-header"><div><h2><ChartNoAxesCombined size={19}/>{title}</h2><p>{label} · {period === 'day' ? tr("Sales by hour") : period === 'month' ? tr("Sales by day") : tr("Sales by month")}</p></div>
      {!dailyOnly && <div className="tabs chart-period-tabs" aria-label={tr("Chart period")}>{(['day', 'month', 'year'] as const).map(value => <button key={value} className={period === value ? 'selected' : ''} aria-pressed={period === value} disabled={loading} onClick={() => onPeriodChange(value)}>{value === 'day' ? tr("Daily") : value === 'month' ? tr("Monthly") : tr("Yearly")}</button>)}</div>}
    </div>
    {loading || !chart ? <div className="chart-loading" role="status">{loading ? tr("Loading sales chart...") : tr("Chart unavailable. Retry the sales report.")}</div> : <>
      <div className="chart-summary"><div><span>{tr("Chart total")}</span><strong data-testid="sales-chart-total">{money(total, currency)}</strong><small>{count}  {tr("payments")}</small></div><div className="chart-selected" aria-live="polite"><span>{active?.description}</span><strong>{money(active?.amount || 0, currency)}</strong><small>{active?.count || 0}  {tr("payments")}</small></div></div>
      <div className="chart-axis-caption">{tr("Sale amount (")}{currency})<span>{tr("Tap a bar for details · Arrow keys move between bars")}</span></div>
      <div className="sales-chart-plot" role="group" aria-label={tr("{value1} bars", {value1: title})}>
        <div className="chart-y-axis" aria-hidden="true">{[4, 3, 2, 1, 0].map((multiple, index) => <span key={multiple} style={{ top: `${index * 25}%` }}>{shortNumber(step * multiple)}</span>)}</div>
        <div className="chart-scroll"><div className="chart-inner" style={{ minWidth: `${points.length * 27}px` }}>
          <div className="chart-gridlines" aria-hidden="true">{[0, 1, 2, 3, 4].map(index => <i key={index} style={{ top: `${index * 25}%` }}/>)}</div>
          <div className="sales-chart-bars" style={{ gridTemplateColumns: `repeat(${points.length}, minmax(0, 1fr))` }}>
            {points.map((point, index) => <button key={point.key} ref={node => { buttons.current[index] = node; }} className={`sales-chart-bar ${selected === index ? 'selected' : ''}`} tabIndex={selected === index ? 0 : -1} aria-label={tr("{value1}: {value2}, {value3} payments", {value1: point.description, value2: money(point.amount, currency), value3: point.count})} aria-pressed={selected === index} onMouseEnter={() => setSelected(index)} onFocus={() => setSelected(index)} onClick={() => setSelected(index)} onKeyDown={event => {
              let next: number | null = null;
              if (event.key === 'ArrowRight') next = Math.min(points.length - 1, index + 1);
              if (event.key === 'ArrowLeft') next = Math.max(0, index - 1);
              if (event.key === 'Home') next = 0;
              if (event.key === 'End') next = points.length - 1;
              if (next !== null) { event.preventDefault(); setSelected(next); buttons.current[next]?.focus({ preventScroll: true }); buttons.current[next]?.scrollIntoView({ block: 'nearest', inline: 'nearest' }); }
            }}><span className="chart-bar-column"><span className={`chart-bar-fill ${point.amount === 0 ? 'zero' : ''}`} style={{ height: `${point.amount / ceiling * 100}%` }}/></span><span className="chart-x-label" aria-hidden="true">{index % everyLabel === 0 || index === points.length - 1 ? point.label : ''}</span></button>)}
          </div>
        </div></div>
      </div>
      <p className="chart-footnote">{count === 0 ? tr("No payments in this period. ") : ''}{tr("All payments in the selected calendar period. Record filters do not change this chart.")}<span className="chart-swipe-note">  {tr("Swipe sideways to see every bar.")}</span></p>
      <button className="text-button chart-data-toggle" aria-expanded={showData} onClick={() => setShowData(!showData)}><ChevronDown size={15}/>{showData ? tr("Hide chart data") : tr("View chart data")}</button>
      {showData && <div className="table-scroll chart-data-table"><table aria-label={tr("Sales chart data")}><thead><tr><th>{chart.interval === 'hour' ? tr("Hour") : chart.interval === 'day' ? tr("Day") : tr("Month")}</th><th>{tr("Payments")}</th><th>{tr("Sale amount")}</th></tr></thead><tbody>{points.map(point => <tr key={point.key}><td>{point.description}</td><td>{point.count}</td><td>{money(point.amount, currency)}</td></tr>)}</tbody></table></div>}
    </>}
  </section>;
}
