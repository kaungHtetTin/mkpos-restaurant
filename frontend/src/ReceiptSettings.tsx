import { tr, useLocale } from './locale';
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { Check, Printer, ReceiptText } from 'lucide-react';
import { api } from './api';
import { useApp } from './context';
import { ReceiptDialog, ReceiptPaper, type ReceiptData } from './ReceiptPaper';
import { PrinterSettingsButton } from './DevicePrinterSettings';

const presets = [56, 58, 80, 88, 112];

export function ReceiptSettings() {
  const { locale } = useLocale();
  const { state, refresh, notify, connected } = useApp();
  const [title, setTitle] = useState(state.settings.receipt_title ?? tr("SALES RECEIPT"));
  const [footer, setFooter] = useState(state.settings.footer);
  const [width, setWidth] = useState<number | ''>(state.settings.receipt_paper_width ?? 80);
  const [choice, setChoice] = useState(presets.includes(Number(width)) ? String(width) : 'custom');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [test, setTest] = useState(false);
  const [logo, setLogo] = useState<File | null>(null), [logoPreview, setLogoPreview] = useState<string | null>(null), [removeLogo, setRemoveLogo] = useState(false);
  const logoInput = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (!logo) { setLogoPreview(null); return; }
    const url = URL.createObjectURL(logo); setLogoPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [logo]);
  const validWidth = typeof width === 'number' && Number.isInteger(width) && width >= 40 && width <= 120;
  const settings = { ...state.settings, logo_data_url: logoPreview || (removeLogo ? null : state.settings.logo_data_url), receipt_title: title, footer, receipt_paper_width: validWidth ? width : 80 };
  const data = useMemo<ReceiptData>(() => ({
    receiptNumber: 'SAMPLE-001', orderNumber: 'PREVIEW', paidAt: new Date().toISOString(), orderLabel: tr('Table {value1}', { value1: '01' }),
    items: [{ id: 1, name: tr('Chicken fried rice'), quantity: 2, price: 6500 }, { id: 2, name: tr('Fresh lime soda'), quantity: 1, price: 2500 }],
    total: 15500, paymentLabel: 'Cash', tendered: 20000, change: 4500,
  }), [locale]);
  const save = async (event: FormEvent) => {
    event.preventDefault(); if (!validWidth || busy) return;
    setBusy(true); setError('');
    try {
      const data = new FormData();
      data.set('receipt_title', title); data.set('footer', footer); data.set('receipt_paper_width', String(width)); data.set('remove_logo', removeLogo ? '1' : '0');
      if (logo) data.set('image', logo);
      await api('manage/receipts', data); await refresh();
      setLogo(null); setRemoveLogo(false); if (logoInput.current) logoInput.current.value = '';
      notify(tr("Receipt settings saved. New receipts will use this format."));
    }
    catch (exception) { setError((exception as Error).message); }
    finally { setBusy(false); }
  };
  return <>
    <PrinterSettingsButton/>
    <div className="panel-header"><div><h2><ReceiptText size={19}/>{tr("Receipt settings")}</h2><p>{tr("Choose your paper width and the messages your guests see.")}</p></div></div>
    <div className="receipt-settings-layout">
      <form className="receipt-settings-form" onSubmit={save}>
        <label>{tr('Business logo')}<input ref={logoInput} aria-label={tr('Upload business logo')} type="file" accept="image/jpeg,image/png,image/webp" disabled={busy} onChange={event => {
          const selected = event.target.files?.[0]; if (!selected) return;
          if (selected.size > 256 * 1024) { setError(tr('Choose a business logo smaller than 256 KB.')); event.target.value = ''; return; }
          if (!['image/jpeg', 'image/png', 'image/webp'].includes(selected.type)) { setError(tr('Choose a JPG, PNG, or WebP logo.')); event.target.value = ''; return; }
          setLogo(selected); setRemoveLogo(false); setError('');
        }}/><span className="field-hint">{tr('Used on receipts and printed QR sheets. JPG, PNG or WebP, up to 256 KB and 1024 pixels per side. Transparent PNG works well.')}</span></label>
        {settings.logo_data_url && <button className="text-button danger-text" type="button" disabled={busy} onClick={() => { setLogo(null); setRemoveLogo(true); if (logoInput.current) logoInput.current.value = ''; }}>{tr('Remove business logo')}</button>}
        {removeLogo && <p className="field-hint">{tr('The logo will be removed when you save. Existing receipts keep their original logo.')}</p>}
        <label>{tr("Receipt title")}<input aria-label={tr("Receipt title")} value={title} maxLength={100} onChange={event => setTitle(event.target.value)} placeholder={tr("e.g. SALES RECEIPT")}/><span className="field-hint">{tr("Printed below your restaurant name. Leave blank to hide it.")}</span></label>
        <label>{tr("Footer message")}<textarea aria-label={tr("Footer message")} rows={4} value={footer} maxLength={255} onChange={event => setFooter(event.target.value)} placeholder={tr("Thank you for dining with us!")}/><span className="field-hint">{tr("Line breaks are supported. Leave blank for no footer.")}</span></label>
        <label>{tr("Paper width")}<select aria-label={tr("Paper width")} value={choice} onChange={event => { const value = event.target.value; setChoice(value); if (value !== 'custom') setWidth(Number(value)); }}>
          <optgroup label={tr("Common receipt widths")}><option value="58">{tr("58 mm · Narrow")}</option><option value="80">{tr("80 mm · Standard")}</option><option value="112">{tr("112 mm · Wide")}</option></optgroup>
          <optgroup label={tr("Other printer widths")}><option value="56">{tr("56 mm")}</option><option value="88">{tr("88 mm")}</option><option value="custom">{tr("Custom width...")}</option></optgroup>
        </select><span className="field-hint">{tr("Choose the paper roll’s width, not its diameter. Check your printer’s supported size.")}</span></label>
        {choice === 'custom' && <label>{tr("Custom paper width (mm)")}<input aria-label={tr("Custom paper width (mm)")} type="number" inputMode="numeric" min={40} max={120} step={1} required value={width} onChange={event => setWidth(event.target.value === '' ? '' : Number(event.target.value))}/><span className="field-hint">{tr("Any whole width from 40 to 120 mm.")}</span></label>}
        <p className="receipt-settings-note">{tr("Saved settings apply to new payments. Completed receipts retain their original title, footer, and paper size.")}</p>
        {error && <div className="form-error" role="alert">{tr(error)}</div>}
        <div className="receipt-settings-actions"><button className="button" type="button" disabled={!validWidth || busy} onClick={() => setTest(true)}><Printer size={16}/>{tr("Test receipt")}</button><button className="button primary" disabled={busy || !connected || !validWidth}><Check size={17}/>{busy ? tr("Saving...") : tr("Save receipt settings")}</button></div>
      </form>
      <aside className="receipt-settings-preview"><div className="row-between"><h3>{tr("Live preview")}</h3><span>{validWidth ? width : '—'}  {tr("mm")}</span></div><p>{tr("Sample only · Not a sale")}</p><div className="receipt-preview-scroll"><ReceiptPaper settings={settings} data={data}/></div></aside>
    </div>
    {test && <ReceiptDialog settings={settings} data={data} onClose={() => setTest(false)} sample/>}
  </>;
}
