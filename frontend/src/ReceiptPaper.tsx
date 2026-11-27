import { tr, useLocale, paymentMethodText } from './locale';
import { useRef, useState, type CSSProperties } from 'react';
import { Printer } from 'lucide-react';
import { dateTime, money } from './context';
import { Modal } from './components';
import type { Settings, ChargeTotals, OptionSnapshot } from './types';
import { BillBreakdown } from './Charges';
import { OptionSummary } from './MenuOptions';
import { BusinessLogo } from './BusinessLogo';
import { devicePrinter, printElement } from './device-printing';
import { PrinterSettingsButton } from './DevicePrinterSettings';

export interface ReceiptData {
  receiptNumber: string; orderNumber: string; paidAt: string; orderLabel: string;
  items: { id: string | number; name: string; quantity: number; price: number; options_snapshot?: OptionSnapshot[] }[];
  total: number; paymentLabel: string; paymentMethod?: string; tendered: number; change: number;
  totals?: ChargeTotals;
  documentTitle?: string; originalReceipt?: string; totalLabel?: string; note?: string; showPayment?: boolean;
  isBill?: boolean;
  creditAmount?: number; paymentLines?: { method?: string; method_label: string; amount: number; tendered: number; change: number }[];
}

export function receiptWidth(settings: Settings) {
  const width = Number(settings.receipt_paper_width ?? 80);
  return Number.isInteger(width) && width >= 40 && width <= 120 ? width : 80;
}

export function ReceiptPaper({ settings, data, printable = false }: { settings: Settings; data: ReceiptData; printable?: boolean }) {
  useLocale();
  const width = receiptWidth(settings);
  return <div className={`receipt-paper ${width <= 58 ? 'receipt-narrow' : ''}`} id={printable ? 'printable-receipt' : undefined} style={{ '--receipt-paper-width': `${width}mm` } as CSSProperties} data-paper-width={width}>
    <header className="receipt-paper-header"><BusinessLogo source={settings.logo_data_url}/><h2>{settings.name}</h2>{(data.documentTitle || settings.receipt_title) && <h3>{data.documentTitle || settings.receipt_title}</h3>}{settings.contact && <p>{settings.contact}</p>}<p>{dateTime(data.paidAt, settings.timezone)}</p></header>
    <div className="receipt-paper-divider"/>
    <div className="receipt-paper-meta"><span>{data.isBill ? tr("Bill") : tr("Receipt")}</span><span>{data.receiptNumber}</span></div>
    <div className="receipt-paper-meta"><span>{tr("Order")}</span><span>{data.orderNumber}</span></div>
    {data.originalReceipt && <div className="receipt-paper-meta"><span>{tr("Original receipt")}</span><span>{data.originalReceipt}</span></div>}
    <div className="receipt-paper-meta"><span>{tr("Service")}</span><span>{data.orderLabel}</span></div>
    <div className="receipt-paper-divider"/>
    {data.items.map(item => <div className="receipt-options-line" key={item.id}><div className="receipt-paper-item"><span>{item.quantity} × {item.name}</span><span>{money(item.quantity * item.price, settings.currency)}</span></div><OptionSummary options={item.options_snapshot}/></div>)}
    <div className="receipt-paper-divider"/>
    {data.totals && <BillBreakdown totals={data.totals} currency={settings.currency}/>}
    {!!data.creditAmount && <div className="receipt-paper-meta"><span>{tr("Previously credited")}</span><span>-{money(data.creditAmount, settings.currency)}</span></div>}
    <div className="receipt-paper-meta receipt-paper-total"><strong>{data.totalLabel || tr("Total")}</strong><strong>{money(data.total, settings.currency)}</strong></div>
    {data.paymentLines ? data.paymentLines.map((line, index) => <div key={index}><div className="receipt-paper-meta"><span>{paymentMethodText(line.method_label, line.method)}</span><span>{money(line.amount, settings.currency)}</span></div>{(line.method === 'cash' || !line.method && line.method_label === 'Cash') && <><div className="receipt-paper-meta"><span>{tr("Cash received")}</span><span>{money(line.tendered, settings.currency)}</span></div><div className="receipt-paper-meta"><span>{tr("Change")}</span><span>{money(line.change, settings.currency)}</span></div></>}</div>) : data.showPayment !== false && <><div className="receipt-paper-meta"><span>{paymentMethodText(data.paymentLabel, data.paymentMethod)}</span><span>{money(data.tendered, settings.currency)}</span></div>
    <div className="receipt-paper-meta"><span>{tr("Change")}</span><span>{money(data.change, settings.currency)}</span></div></>}
    {data.note && <p className="receipt-paper-footer">{data.note}</p>}
    {settings.footer && <><div className="receipt-paper-divider"/><p className="receipt-paper-footer">{settings.footer}</p></>}
  </div>;
}

async function printPaper(element: HTMLElement, width: number) {
  if (devicePrinter()) { await printElement(element); return; }
  await document.fonts.ready;
  await Promise.all(Array.from(element.querySelectorAll('img'), image => image.decode()));
  const measurement = element.cloneNode(true) as HTMLElement;
  measurement.removeAttribute('id');
  measurement.setAttribute('aria-hidden', 'true');
  Object.assign(measurement.style, { position: 'fixed', left: '-10000px', top: '0', width: `${width}mm`, maxWidth: 'none', visibility: 'hidden' });
  document.body.append(measurement);
  const height = Math.max(30, Math.ceil(measurement.getBoundingClientRect().height * 25.4 / 96) + 2);
  measurement.remove();
  document.getElementById('receipt-page-size')?.remove();
  const pageStyle = document.createElement('style');
  pageStyle.id = 'receipt-page-size';
  pageStyle.textContent = `@media print { @page { size: ${width}mm ${height}mm; margin: 0; } }`;
  document.head.append(pageStyle);
  const cleanup = () => pageStyle.remove();
  window.addEventListener('afterprint', cleanup, { once: true });
  try { window.print(); } catch (error) { cleanup(); throw error; }
}

export function ReceiptDialog({ settings, data, onClose, sample = false }: { settings: Settings; data: ReceiptData; onClose: () => void; sample?: boolean }) {
  useLocale();
  const ref = useRef<HTMLDivElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const width = receiptWidth(settings);
  return <Modal title={data.documentTitle || (sample ? tr("Test receipt") : tr("Payment complete"))} subtitle={sample ? tr("Sample only. This does not create a sale.") : data.isBill ? tr("Bill ready · {value1} mm paper", {value1: width}) : tr("Receipt ready · {value1} mm paper", {value1: width})} onClose={onClose}>
    <div className="receipt-dialog-paper" ref={ref}><ReceiptPaper settings={settings} data={data} printable/></div>
    {devicePrinter() ? <><p className="receipt-print-help">{tr('Print sends immediately using this device’s saved printer and paper size.')}</p><PrinterSettingsButton onOpen={onClose}/></> : <p className="receipt-print-help">{tr("Select")} {width}  {tr("mm paper in your printer’s print dialog. Use 100% scale and turn off browser headers and footers.")}</p>}
    {error && <div className="form-error" role="alert">{tr(error)}</div>}
    <button className="button primary full" disabled={busy} onClick={async () => {
      const paper = ref.current?.querySelector<HTMLElement>('.receipt-paper'); if (!paper) return;
      setBusy(true); setError('');
      try { await printPaper(paper, width); } catch (exception) { setError(tr((exception as Error).message)); } finally { setBusy(false); }
    }}><Printer size={18}/>{busy ? tr("Preparing receipt...") : data.isBill ? tr("Print bill") : sample ? tr("Print test receipt") : tr("Print receipt")}</button>
  </Modal>;
}
