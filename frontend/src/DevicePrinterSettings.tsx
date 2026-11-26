import { useEffect, useRef, useState } from 'react';
import { Modal } from './components';
import { tr, useLocale } from './locale';
import { defaultPrinter, devicePrinter, isPrintActive, printCurrentDocument, printTestReceipt, type PrinterConfig, type PrinterDevice } from './device-printing';
import './device-printer.css';

declare global { interface Window { dismissRestaurantPrinterSettings?: () => boolean } }
export function PrinterSettingsButton() {
  useLocale();
  if (!devicePrinter()) return null;
  return <button className="button" type="button" onClick={() => window.openRestaurantPrinterSettings?.()}>{tr('Printer settings on this device')}</button>;
}
export function DevicePrinterSettingsHost() {
  useLocale();
  const [open, setOpen] = useState(false), [message, setMessage] = useState(''), [failed, setFailed] = useState(false);
  const openRef = useRef(false), busyRef = useRef(false);
  useEffect(() => {
    window.openRestaurantPrinterSettings = () => { openRef.current = true; setOpen(true); };
    window.dismissRestaurantPrinterSettings = () => { if (!openRef.current) return false; if (!busyRef.current) { openRef.current = false; setOpen(false); } return true; };
    window.restaurantPrintCurrentDocument = printCurrentDocument;
    const status = (event: Event) => { const detail = (event as CustomEvent<{ message: string; error: boolean }>).detail; setMessage(detail.message); setFailed(detail.error); };
    window.addEventListener('restaurant:print-status', status);
    return () => { delete window.openRestaurantPrinterSettings; delete window.dismissRestaurantPrinterSettings; delete window.restaurantPrintCurrentDocument; window.removeEventListener('restaurant:print-status', status); };
  }, []);
  return <>{open && <Modal title={tr('Printer settings on this device')} onClose={() => { window.dismissRestaurantPrinterSettings?.(); }}><DevicePrinterForm onBusy={value => { busyRef.current = value; }}/></Modal>}{message && <div className={`device-print-notice ${failed ? 'failed' : ''}`} role={failed ? 'alert' : 'status'}><span>{tr(message)}</span><button type="button" aria-label={tr('Dismiss print message')} onClick={() => setMessage('')}>×</button></div>}</>;
}
export function DevicePrinterForm({ onBusy }: { onBusy?: (busy: boolean) => void }) {
  useLocale();
  const bridge = devicePrinter();
  const [config, setConfig] = useState<PrinterConfig>(defaultPrinter), [saved, setSaved] = useState('');
  const [devices, setDevices] = useState<PrinterDevice[]>([]), [ports, setPorts] = useState<PrinterDevice[]>([]);
  const [busy, setBusy] = useState(true), [message, setMessage] = useState(''), [error, setError] = useState('');
  const pending = useRef(false), mounted = useRef(true), discovery = useRef(0), current = useRef(config);
  current.current = config;
  const setWorking = (value: boolean) => { pending.current = value; setBusy(value); onBusy?.(value); };
  useEffect(() => {
    mounted.current = true; onBusy?.(true);
    if (!bridge) { setBusy(false); onBusy?.(false); return; }
    bridge.getConfig().then(value => { if (mounted.current) { const full = { ...defaultPrinter(), ...value }; setConfig(full); setSaved(JSON.stringify(full)); } }).catch(e => { if (mounted.current) setError(e.message); }).finally(() => { if (mounted.current) { setBusy(false); onBusy?.(false); } });
    return () => { mounted.current = false; discovery.current++; onBusy?.(false); };
  }, []);
  if (!bridge) return <p>{tr('Open the Android or Windows app to configure a printer.')}</p>;
  const dirty = JSON.stringify(config) !== saved;
  const patch = <K extends keyof PrinterConfig>(key: K, value: PrinterConfig[K]) => setConfig(c => ({ ...c, [key]: value }));
  const action = async (callback: () => Promise<string | undefined>) => {
    if (isPrintActive()) { setError(tr('Finish the active print job before changing printer settings.')); return; }
    if (pending.current) return; setWorking(true); setError(''); setMessage('');
    try { const result = await callback(); if (mounted.current && result) setMessage(result); }
    catch (e) { if (mounted.current) setError((e as Error).message.replace(/^Error invoking remote method '[^']+': (?:Error: )?/, '')); }
    finally { if (mounted.current) setWorking(false); }
  };
  const refresh = () => action(async () => {
    const request = ++discovery.current, connection = current.current.connection;
    if (connection === 'bluetooth') {
      const items: PrinterDevice[] = [];
      for (let offset = 0; ; offset += 20) {
        const result = await bridge.listPaired({ offset, limit: 20 }); items.push(...result.items);
        if (!result.total || items.length >= result.total) break;
      }
      if (request === discovery.current && current.current.connection === connection) setPorts(items);
    } else {
      const result = await bridge.listDevices(); if (request === discovery.current && current.current.connection === connection) setDevices(result.items);
    }
    return tr('Printer list refreshed.');
  });
  return <form className="device-printer-form" onSubmit={event => { event.preventDefault(); void action(async () => { const result = await bridge.saveConfig({ config }); const full = { ...defaultPrinter(), ...result }; setConfig(full); setSaved(JSON.stringify(full)); return tr('Printer settings saved on this device.'); }); }}>
    <p>{tr('These settings apply only to this device. Print sends immediately to the saved printer.')}</p>
    <fieldset disabled={busy}>
      <label>{tr('Connection type')}<select aria-label={tr('Connection type')} value={config.connection} onChange={e => { discovery.current++; setConfig(c => ({ ...c, connection: e.target.value as PrinterConfig['connection'], paper: e.target.value !== 'system' && ['a4', 'a5'].includes(c.paper) ? '80mm' : c.paper })); }}><option value="system">{bridge.platform === 'windows' ? tr('Installed Windows printer / PDF') : tr('Android print dialog / PDF')}</option><option value="tcp">{tr('Network thermal printer (ESC/POS)')}</option><option value="bluetooth">{tr('Bluetooth Classic thermal printer')}</option></select></label>
      <label>{tr('Printer paper')}<select aria-label={tr('Printer paper')} value={config.paper} onChange={e => patch('paper', e.target.value)}>{['50mm', '58mm', '80mm', '85mm', 'a4', ...(bridge.platform === 'windows' ? ['a5'] : [])].map(paper => <option key={paper} value={paper} disabled={config.connection !== 'system' && ['a4', 'a5'].includes(paper)}>{paper.toUpperCase()}</option>)}</select></label>
      {config.connection === 'system' && bridge.platform === 'windows' && <><label>{tr('Installed printer')}<input list="installed-printers" value={config.printerName} onChange={e => patch('printerName', e.target.value)} placeholder={tr('Blank uses the Windows default printer')}/><datalist id="installed-printers">{devices.map(d => <option key={d.address} value={d.address}>{d.name}</option>)}</datalist></label><button type="button" className="button" onClick={() => void refresh()}>{tr('Refresh printers')}</button><button type="button" className="button" onClick={() => void action(async () => { await bridge.openSystemSettings(); return undefined; })}>{tr('Open Windows printer settings')}</button></>}
      {config.connection === 'system' && bridge.platform === 'android' && <p>{tr('Android system printing requires the system dialog. Use Bluetooth or network thermal for immediate printing.')}</p>}
      {config.connection === 'tcp' && <><label>{tr('Printer IP / hostname')}<input required value={config.host} maxLength={253} onChange={e => patch('host', e.target.value)} placeholder="192.168.1.100"/></label><label>{tr('Printer port')}<input type="number" required min={1} max={65535} value={config.port} onChange={e => patch('port', Number(e.target.value))}/></label></>}
      {config.connection === 'bluetooth' && <><p>{tr('Pair a Bluetooth Classic SPP printer first. BLE-only printers are not supported.')}</p><div className="device-printer-actions"><button className="button" type="button" onClick={() => void action(async () => { await bridge.openBluetoothSettings(); return undefined; })}>{tr('Open Bluetooth pairing')}</button><button className="button" type="button" onClick={() => void refresh()}>{tr('Refresh paired printers')}</button></div><label>{bridge.platform === 'windows' ? tr('Outgoing Bluetooth COM port') : tr('Paired Bluetooth printer')}<select aria-label={bridge.platform === 'windows' ? tr('Outgoing Bluetooth COM port') : tr('Paired Bluetooth printer')} value={config.address} onChange={e => patch('address', e.target.value)}><option value="">{tr('Choose a printer')}</option>{config.address && !ports.some(p => p.address === config.address) && <option value={config.address}>{config.address}</option>}{ports.map(p => <option key={p.address} value={p.address}>{p.name} ({p.address})</option>)}</select></label>{bridge.platform === 'windows' && <label>{tr('Manual outgoing COM port')}<input value={config.address} onChange={e => patch('address', e.target.value.toUpperCase())} placeholder="COM5"/></label>}<label>{tr('Bluetooth print mode')}<select aria-label={tr('Bluetooth print mode')} value={config.bluetooth_mode} onChange={e => patch('bluetooth_mode', e.target.value)}><option value="standard">{tr('Standard')}</option><option value="compatibility">{tr('Compatibility (slower)')}</option></select></label></>}
      {(bridge.platform === 'windows' || config.connection !== 'system') && <label>{tr('Printer copies')}<input type="number" min={1} max={10} required value={config.copies} onChange={e => patch('copies', Number(e.target.value))}/></label>}
      {config.connection !== 'system' && <label className="checkbox-line"><input type="checkbox" checked={config.cut} onChange={e => patch('cut', e.target.checked)}/>{tr('Cut paper automatically')}</label>}
      {bridge.platform === 'windows' && <label>{tr('A4 document printer')}<input list="installed-printers" value={config.documentPrinterName} onChange={e => patch('documentPrinterName', e.target.value)} placeholder={tr('Blank uses the Windows default printer')}/></label>}
      <p>{tr('Save before test printing. PDF output asks where to save the file.')}</p>
      <div className="device-printer-actions"><button className="button primary" type="submit">{tr('Save printer settings')}</button><button className="button" type="button" onClick={() => void action(async () => (await bridge.checkConnection({ config })).message)}>{tr('Check connection')}</button><button className="button" type="button" disabled={dirty} onClick={() => void action(async () => { await printTestReceipt(); return tr('Test print submitted. Check the paper output.'); })}>{tr('Test saved printer')}</button></div>
    </fieldset>
    {busy && <p role="status">{tr('Working...')}</p>}{message && <p role="status">{tr(message)}</p>}{error && <p className="form-error" role="alert">{tr(error)}</p>}
  </form>;
}
