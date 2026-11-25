import { tr, useLocale } from './locale';
import { useLiveRefresh } from './realtime';
import { createContext, useContext, useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Link, useLocation } from 'react-router-dom';
import { api } from './api';
import { Modal } from './components';
import type { User } from './types';
import './office-tools.css';

export type OfficeRow = Record<string, any>;
export interface OfficePage<T = OfficeRow> { data: T[]; current_page: number; last_page: number; per_page: number; total: number; from: number | null; to: number | null }
export interface OfficeSettings { name: string; timezone: string; theme_color: string; support_email: string; support_phone: string; support_url: string; logo_url: string | null; version: number }
export const OfficeContext = createContext<{ user: User; settings: OfficeSettings; reloadSettings: () => Promise<void> }>(null!);
export const useOffice = () => useContext(OfficeContext);
const cache = new Map<string, unknown>();
const formDrafts = new Map<string, Record<string, string>>();
window.addEventListener('office-write', event => {
  const path = (event as CustomEvent<{path:string}>).detail?.path || '';
  const prefixes = ['office/events'];
  if (path.startsWith('office/finance')) prefixes.push('office/finance');
  if (path.startsWith('office/requests/')) prefixes.push('office/requests','office/payments','office/finance','office/overview','office/calendar');
  if (path.startsWith('office/businesses/')) prefixes.push('office/overview','office/calendar','office/payments','office/finance','office/requests','office/manage/distribution','office/manage/reports');
  if (path === 'office/businesses') prefixes.push('office/businesses?','office/overview','office/calendar','office/manage/distribution');
  if (path === 'office/plans') prefixes.push('office/overview');
  if (path.startsWith('office/manage/')) prefixes.push(path);
  if (path === 'office/manage/settings') prefixes.push('office/overview','office/calendar');
  if (path === 'office/manage/reports') prefixes.push('office/overview');
  if (path === 'office/account') prefixes.push('office/manage/admins');
  for (const key of cache.keys()) if (prefixes.some(prefix=>key.includes(prefix))) cache.delete(key);
});
export const clearOfficeCache = () => { cache.clear(); formDrafts.clear(); };
export function useOfficeFormDraft(tag: string) {
  const { user } = useOffice(), location = useLocation(), key = `${user.id}|${location.key}|${tag}`;
  return { values: formDrafts.get(key) || {}, save: (values: Record<string, string>) => formDrafts.set(key, { ...values }), clear: () => formDrafts.delete(key), capture: (form: HTMLFormElement) => formDrafts.set(key, Object.fromEntries(Array.from(new FormData(form), ([name,value])=>[name,String(value)]))) };
}
export function reconcileOfficeBusiness(detail: OfficeRow) {
  for (const [key, value] of cache) {
    if (!key.includes('office/businesses?')) continue;
    const data = value as OfficeRow;
    if (!data.businesses?.data) continue;
    if (detail.summary) data.summary = detail.summary;
    const params = new URLSearchParams(key.split('?')[1] || '');
    const oldCount = data.businesses.data.length;
    data.businesses.data = data.businesses.data.map((row: OfficeRow) => row.id === detail.business.id ? { ...row, ...detail.business, owner_name: detail.owner?.name, owner_email: detail.owner?.email, owner_active: detail.owner?.active, plan_name: detail.subscription.plan_name, is_complimentary: detail.subscription.is_complimentary, subscription_status: detail.subscription.status, ends_at: detail.subscription.ends_at } : row).filter((row: OfficeRow) => {
      if (row.id !== detail.business.id) return true;
      const status = params.get('status'), search = (params.get('q') || '').toLowerCase();
      return (!search || [row.name,row.code,row.owner_name,row.owner_email].some(value=>String(value || '').toLowerCase().includes(search))) && (!status || (status==='suspended'?row.status==='suspended':row.status==='active'&&row.subscription_status===status));
    });
    data.businesses.total -= oldCount - data.businesses.data.length;
    data.businesses.last_page = Math.max(1, Math.ceil(data.businesses.total / data.businesses.per_page));
  }
}
export function useOfficeRecords<T>(path: string) {
  const { user } = useOffice(), key = `${user.id}|${path}`;
  const [data, setData] = useState<T | undefined>(() => cache.get(key) as T | undefined), [error, setError] = useState(''), [loading, setLoading] = useState(!cache.has(key)), [revision, setRevision] = useState(0);
  const request = useRef(0);
  useEffect(() => {
    const sequence = ++request.current;
    if (cache.has(key)) { setData(cache.get(key) as T); setLoading(false); setError(''); return; }
    setLoading(true); setError(''); setData(undefined);
    void api<T>(path).then(result => { if (request.current === sequence) { cache.set(key, result); setData(result); } }).catch(e => { if (request.current === sequence) setError(e.message); }).finally(() => { if (request.current === sequence) setLoading(false); });
    return () => { request.current++; };
  }, [key, path, revision]);
  useLiveRefresh(() => {
    const sequence = ++request.current;
    void api<T>(path).then(result => { if (sequence === request.current) { cache.set(key, result); setData(result); setError(''); } }).catch(e => { if (sequence === request.current) setError(e.message); });
  }, 'office');
  return { data, error, loading, reload: () => { cache.delete(key); setRevision(value => value + 1); } };
}
export const OfficeAppBarContext = createContext<HTMLDivElement | null>(null);
export function OfficePageActions({ children }: { children: ReactNode }) {
  const target = useContext(OfficeAppBarContext);
  return target ? createPortal(children, target) : null;
}
export function OfficeHeading({ title, text, children }: { title: string; text: string; children?: ReactNode }) { return <div className="office-title"><div><span className="eyebrow">PLATFORM OFFICE</span><h1>{title}</h1><p>{text}</p></div><OfficePageActions>{children}</OfficePageActions></div>; }
export function OfficeFeedback({ error, loading, onRetry }: { error: string; loading: boolean; onRetry: () => void }) { return error ? <div className="form-error" role="alert">{error}<button className="text-button" onClick={onRetry}>Retry</button></div> : loading ? <p className="platform-empty" role="status">Loading records...</p> : null; }
export function OfficePagination({ data, onPage, onSize, busy = false }: { data: OfficePage; onPage: (page: number) => void; onSize?: (size: number) => void; busy?: boolean }) { return <div className="office-pagination"><span>{data.from ?? 0}–{data.to ?? 0} of {data.total}</span><div className="office-actions">{onSize && <label>Rows per page<select aria-label="Rows per page" value={data.per_page} disabled={busy} onChange={e => onSize(Number(e.target.value))}>{[10,25,50,100].map(size => <option key={size}>{size}</option>)}</select></label>}<button className="button small" disabled={busy || data.current_page <= 1} onClick={() => onPage(1)}>First</button><button className="button small" disabled={busy || data.current_page <= 1} onClick={() => onPage(data.current_page - 1)}>Previous</button><span>{data.current_page} / {data.last_page}</span><button className="button small" disabled={busy || data.current_page >= data.last_page} onClick={() => onPage(data.current_page + 1)}>Next</button><button className="button small" disabled={busy || data.current_page >= data.last_page} onClick={() => onPage(data.last_page)}>Last</button></div></div>; }
export function OfficeBusinessLink({ id, children }: { id: number; children: ReactNode }) { const location = useLocation(); return <Link to={`/office/businesses/${id}`} state={{ from: location.pathname + location.search }}>{children}</Link>; }
export function OfficeTable({ headings, children, empty = false }: { headings: string[]; children: ReactNode; empty?: boolean }) { return <div className="office-table-wrap"><table className="office-table"><thead><tr>{headings.map((title, index) => <th key={index} className={!title ? 'office-action-column' : ''}>{title || <span className="sr-only">Actions</span>}</th>)}</tr></thead><tbody>{children}</tbody></table>{empty && <p className="platform-empty">No records match these filters.</p>}</div>; }
export function OfficeRowActions({ label, children }: { label: string; children: ReactNode }) {
  const ref = useRef<HTMLDetailsElement>(null);
  useEffect(() => { const close = (event: MouseEvent) => { if(ref.current?.open && !ref.current.contains(event.target as Node))ref.current.open=false; };const escape=(event:KeyboardEvent)=>{if(event.key==='Escape'&&ref.current?.open){ref.current.open=false;ref.current.querySelector('summary')?.focus();}};document.addEventListener('mousedown',close);document.addEventListener('keydown',escape);return()=>{document.removeEventListener('mousedown',close);document.removeEventListener('keydown',escape);}; },[]);
  return <td className="office-action-column"><details ref={ref} className="office-row-menu"><summary aria-label={`Actions for ${label}`}>{'\u2022\u2022\u2022'}</summary><div onClick={e => { const details = e.currentTarget.closest('details'); if (details) { details.open = false; details.querySelector('summary')?.focus(); } }}>{children}</div></details></td>;
}

export function OfficeDate({ value }: { value: string }) { const { settings } = useOffice(); return <>{new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z').toLocaleString('en-GB', { timeZone: settings.timezone, day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</>; }
export function exportOfficeCsv(filename: string, headings: string[], rows: unknown[][]) {
  const safe = (value: unknown) => { let text = String(value ?? ''); if (/^[=+@\-\t\r]/.test(text)) text = "'" + text; return '"' + text.replaceAll('"', '""') + '"'; };
  const url = URL.createObjectURL(new Blob(['\ufeff' + [headings, ...rows].map(row => row.map(safe).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a'); link.href = url; link.download = filename; link.click(); URL.revokeObjectURL(url);
}
export interface OfficeField { name: string; label: string; type?: 'text' | 'number' | 'textarea' | 'checkbox' | 'password' | 'email' | 'date' | 'color' | 'file' | 'select'; options?: { value: string | number; label: string }[]; required?: boolean; min?: number; max?: number; step?: number; hint?: string }
export function OfficeEditor({ title, fields, initial, onClose, onSave, children, submitLabel = tr('Save changes') }: { title: string; fields: OfficeField[]; initial: OfficeRow; onClose: () => void; onSave: (values: OfficeRow) => Promise<void>; children?: ReactNode; submitLabel?: string }) {
  useLocale();
  const [values, setValues] = useState<OfficeRow>(initial), [busy, setBusy] = useState(false), [error, setError] = useState('');
  const submit = async (e: FormEvent) => { e.preventDefault(); if (busy) return; setBusy(true); setError(''); try { const data = { ...values }; for (const field of fields) { if (field.type === 'number' && data[field.name] !== '') data[field.name] = Number(data[field.name]); if (!field.required && (data[field.name] === '' || data[field.name] === undefined)) delete data[field.name]; } await onSave(data); } catch (e) { setError((e as Error).message); } finally { setBusy(false); } };
  return <Modal title={title} onClose={() => { if (!busy) onClose(); }}><form className="office-form" onSubmit={e => void submit(e)}><fieldset disabled={busy} className="office-fields">{children}{fields.map(field => { const value = values[field.name] ?? ''; return <label key={field.name} className={field.type === 'checkbox' ? 'checkbox-line' : ''}>{field.type === 'checkbox' ? <><input aria-label={field.label} type="checkbox" required={field.required} checked={!!value} onChange={e => setValues(previous => ({ ...previous, [field.name]: e.target.checked }))}/>{field.label}</> : <>{field.label}{field.type === 'select' ? <select aria-label={field.label} value={value} required={field.required} onChange={e => setValues(previous => ({ ...previous, [field.name]: e.target.value }))}>{field.options?.map(option => <option value={option.value} key={option.value}>{option.label}</option>)}</select> : field.type === 'textarea' ? <textarea aria-label={field.label} required={field.required} value={value} maxLength={field.max} rows={4} onChange={e => setValues(previous => ({ ...previous, [field.name]: e.target.value }))}/> : field.type === 'file' ? <input aria-label={field.label} type="file" accept="image/png,image/jpeg,image/webp" onChange={e => setValues(previous => ({ ...previous, [field.name]: e.target.files?.[0] }))}/> : <input aria-label={field.label} type={field.type || 'text'} required={field.required} value={value} min={field.min} max={field.type === 'number' ? field.max : undefined} maxLength={field.type !== 'number' ? field.max : undefined} step={field.step} autoComplete={field.type === 'password' ? field.name === 'current_password' ? 'current-password' : 'new-password' : undefined} minLength={field.type === 'password' ? field.min : undefined} onChange={e => setValues(previous => ({ ...previous, [field.name]: e.target.value }))}/>}</>}{field.hint && <span className="field-hint">{field.hint}</span>}</label>; })}</fieldset>{error && <div className="form-error" role="alert">{tr(error)}</div>}<button type="submit" className="button primary full" disabled={busy}>{busy ? tr('Saving...') : submitLabel}</button></form></Modal>;
}
export function officeMultipart(values: OfficeRow): FormData { const data = new FormData(); for (const [key, value] of Object.entries(values)) { if (value !== undefined && value !== null) data.append(key, value instanceof File ? value : typeof value === 'boolean' ? value ? '1' : '0' : String(value)); } return data; }
