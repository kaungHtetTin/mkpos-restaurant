import { tr, formatLocale, businessLocale , formatDate } from './locale';
import { createContext, useContext } from 'react';
import type { State, User, Role, Order } from './types';
export interface AppContext { user: User; state: State; connected: boolean; refresh: () => Promise<void>; reconcileOrder: (order: Order) => void; notify: (message: string, error?: boolean) => void; can: (...roles: Role[]) => boolean }
export const Context = createContext<AppContext>(null!);
export const useApp = () => useContext(Context);
export function tableLabel(name: string | null | undefined, compact = false) {
  const label = (name || '').trim();
  if (compact) return label.replace(/^table(?:\s+|(?=\d))/i, '').trim() || label;
  if (businessLocale() === 'en' && /^table(?:\s|\d|$)/i.test(label)) return label;
  return tr('Table {value1}', { value1: label.replace(/^table(?:\s+|(?=\d))/i, '').trim() || label }).trim();
}
export function money(amount: number, currency = 'MMK') { return tr("{value1} {value2}", {value1: Number(amount || 0).toLocaleString(formatLocale()), value2: currency}); }
export function elapsed(date: string) { const minutes = Math.max(0, Math.floor((Date.now() - new Date(date.replace(' ', 'T') + (/[Z+]/.test(date) ? '' : 'Z')).getTime()) / 60000)); return minutes < 1 ? tr("Just now") : minutes < 60 ? tr("{value1} min", {value1: minutes}) : tr("{value1}h {value2}m", {value1: Math.floor(minutes / 60), value2: minutes % 60}); }
export function dateTime(date: string, timezone: string) { return formatDate(new Date(date.replace(' ', 'T') + (/[Z+]/.test(date) ? '' : 'Z')), { timeZone: timezone, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); }
