import receiptCss from './receipt.css?raw';
import regular from './print-fonts/noto-sans-myanmar-myanmar-400-normal.woff2?inline';
import bold from './print-fonts/noto-sans-myanmar-myanmar-700-normal.woff2?inline';
import latin from './print-fonts/noto-sans-myanmar-latin-400-normal.woff2?inline';
import latinBold from './print-fonts/noto-sans-myanmar-latin-700-normal.woff2?inline';

export interface PrinterConfig {
  connection: 'system' | 'tcp' | 'bluetooth'; paper: string; host: string; port: number; address: string;
  printerName: string; documentPrinterName: string; copies: number; cut: boolean; color: boolean; landscape: boolean; bluetooth_mode: string;
}
export interface PrintResult { status?: string; message?: string; ok?: boolean; cancelled?: boolean }
export interface PrinterDevice { name: string; address: string }
export interface PrinterBridge {
  platform: 'windows' | 'android';
  getConfig(): Promise<PrinterConfig>;
  saveConfig(value: { config: PrinterConfig }): Promise<PrinterConfig>;
  listDevices(): Promise<{ items: PrinterDevice[] }>;
  listPaired(value?: { offset?: number; limit?: number }): Promise<{ items: PrinterDevice[]; total?: number }>;
  checkConnection(value: { config: PrinterConfig }): Promise<PrintResult>;
  openBluetoothSettings(): Promise<unknown>;
  openSystemSettings(): Promise<unknown>;
  print(value: { html: string; title: string; kind: 'receipt' | 'document' }): Promise<PrintResult>;
}
declare global {
  interface Window {
    RestaurantPrinter?: PrinterBridge;
    Capacitor?: { getPlatform?: () => string; PluginHeaders?: { name: string }[]; nativePromise?: (plugin: string, method: string, options: unknown) => Promise<unknown> };
    openRestaurantPrinterSettings?: () => void;
    restaurantPrintCurrentDocument?: () => Promise<unknown>;
  }
}
export const defaultPrinter = (): PrinterConfig => ({ connection: 'system', paper: '80mm', host: '', port: 9100, address: '', printerName: '', documentPrinterName: '', copies: 1, cut: false, color: false, landscape: false, bluetooth_mode: 'standard' });
export function devicePrinter(): PrinterBridge | null {
  if (window.RestaurantPrinter) return window.RestaurantPrinter;
  const cap = window.Capacitor;
  if (cap?.getPlatform?.() !== 'android' || !cap.nativePromise || !cap.PluginHeaders?.some(p => p.name === 'MkposPrinter')) return null;
  const call = <T>(method: string, value = {}): Promise<T> => cap.nativePromise!('MkposPrinter', method, value) as Promise<T>;
  return { platform: 'android', getConfig: () => call<PrinterConfig>('getConfig'), saveConfig: value => call('saveConfig', value),
    listPaired: value => call('listPaired', value), listDevices: async () => ({ items: [] }),
    openBluetoothSettings: () => call('openBluetoothSettings'), openSystemSettings: () => call('openBluetoothSettings'),
    checkConnection: value => call('checkConnection', value), print: value => call('print', value) };
}
const browserPrint = window.print.bind(window);
let active = false;
export const isPrintActive = () => active;
const notify = (message: string, error = false) => window.dispatchEvent(new CustomEvent('restaurant:print-status', { detail: { message, error } }));
const encodeImage = (blob: Blob) => new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = reject; reader.readAsDataURL(blob); });

export async function printElement(element: HTMLElement, kind: 'receipt' | 'document' = 'receipt'): Promise<void> {
  if (active) throw new Error('Another print job is active. Finish it before printing again.');
  const bridge = devicePrinter();
  if (!bridge) { browserPrint(); return; }
  active = true;
  try {
    const config = { ...defaultPrinter(), ...await bridge.getConfig() };
    const paper = kind === 'document' ? 'a4' : config.paper;
    const documentPaper = ['a4', 'a5'].includes(paper);
    const width = paper === 'a4' ? 210 : paper === 'a5' ? 148 : [50, 58, 80, 85].includes(Number.parseInt(paper)) ? Number.parseInt(paper) : 80;
    const clone = element.cloneNode(true) as HTMLElement;
    clone.removeAttribute('id');
    if (clone.classList.contains('receipt-paper')) { clone.classList.toggle('receipt-narrow', width <= 58); clone.style.setProperty('--receipt-paper-width', width + 'mm'); }
    await Promise.all(Array.from(clone.querySelectorAll('img'), async image => {
      if (!image.src.startsWith('data:')) {
        const response = await fetch(image.src, { credentials: 'same-origin' });
        if (!response.ok) throw new Error('Could not load a print image. Try again.');
        image.src = await encodeImage(await response.blob());
      }
      image.removeAttribute('srcset'); image.removeAttribute('loading');
    }));
    const fonts = `@font-face{font-family:RestaurantPrint;src:url('${regular}');font-weight:400;unicode-range:U+1000-109F,U+200C-200D,U+25CC,U+A9E0-A9FF,U+AA60-AA7F}@font-face{font-family:RestaurantPrint;src:url('${bold}');font-weight:700;unicode-range:U+1000-109F,U+200C-200D,U+25CC,U+A9E0-A9FF,U+AA60-AA7F}@font-face{font-family:RestaurantPrint;src:url('${latin}');font-weight:400;unicode-range:U+0000-00FF,U+2000-206F}@font-face{font-family:RestaurantPrint;src:url('${latinBold}');font-weight:700;unicode-range:U+0000-00FF,U+2000-206F}`;
    const css = `${receiptCss}\n${fonts}\n*{box-sizing:border-box}html,body{width:${width}mm;margin:0;padding:0;color:#000;background:#fff}body{font-family:RestaurantPrint,sans-serif}.receipt-shell{width:${width}mm;margin:0}.receipt-paper{width:${width}mm!important;max-width:100%;margin:0;box-shadow:none}.receipt-paper,.receipt-paper *{font-family:RestaurantPrint,sans-serif!important;color:#000}.bill-breakdown{display:flex;flex-direction:column;gap:7px;margin:12px 0;font-size:inherit}.bill-breakdown>div{display:flex;justify-content:space-between;gap:8px}.bill-breakdown strong{white-space:nowrap}.item-options{font-size:inherit;padding-left:8px}.qr-sheet{text-align:center;width:100%;padding:4mm;overflow-wrap:anywhere}.qr-sheet img{display:block;margin:3mm auto;width:100%;max-width:55mm;height:auto}.qr-sheet .business-print-logo{width:auto;max-width:35mm;max-height:20mm}.qr-batch{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8mm}.qr-batch article{break-inside:avoid;border:1px solid #ddd;padding:4mm}.qr-sheet h2{font-size:17px}.qr-sheet h3{font-size:15px}.qr-sheet p{font-size:12px}.qr-url{font-size:9px!important}@page{size:${documentPaper ? paper.toUpperCase() : width + 'mm auto'};margin:0}`;
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>MKPOS restaurant</title><style>${css}</style></head><body><div class="receipt-shell">${clone.outerHTML}</div></body></html>`;
    const result = await bridge.print({ html, title: 'MKPOS restaurant', kind });
    if (result.cancelled || result.ok === false) throw new Error('Printing cancelled. No automatic retry was made.');
    notify(result.message || (result.status === 'saved' ? 'PDF saved.' : 'Print job submitted. Check the printer output.'));
  } catch (error) { const message = (error as Error).message.replace(/^Error invoking remote method '[^']+': (?:Error: )?/, ''); notify(message, true); throw new Error(message); }
  finally { active = false; window.dispatchEvent(new Event('afterprint')); }
}
export async function printCurrentDocument(): Promise<void> {
  const receipt = document.querySelector<HTMLElement>('#printable-receipt');
  const qr = document.querySelector<HTMLElement>('.modal .qr-sheet');
  const element = receipt || qr;
  if (!element) { const message = 'Open a bill, receipt or QR preview before printing.'; notify(message, true); throw new Error(message); }
  await printElement(element, qr?.classList.contains('qr-batch') ? 'document' : 'receipt');
}
export async function printTestReceipt(): Promise<void> {
  const paper = document.createElement('div');
  paper.className = 'receipt-paper';
  paper.innerHTML = '<h2>MKPOS restaurant</h2><p>TEST / စမ်းသပ်ပုံနှိပ်ခြင်း</p><p>1 × Test item — 1,000 MMK</p><p>Not a sale.</p>';
  await printElement(paper);
}
