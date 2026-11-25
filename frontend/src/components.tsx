import { tr, useLocale } from './locale';
import { useEffect, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X, Search, Plus, ArrowUpRight, UtensilsCrossed } from 'lucide-react';
import type { ItemStatus } from './types';
export function Badge({ status, children }: { status: string; children?: ReactNode }) {
  useLocale(); return <span className={`badge ${status.replaceAll(' ', '-')}`}><i />{children || tr(status.replaceAll('_', ' '))}</span>; }
export function ItemBadge({ status }: { status: ItemStatus }) {
  useLocale(); return <Badge status={status}>{status === 'sent' ? tr("In queue") : status === 'draft' ? tr("Not sent") : tr(status)}</Badge>; }
export function SearchBox({ value, onChange, placeholder = tr("Search...") }: { value: string; onChange: (value: string) => void; placeholder?: string }) {
  useLocale(); return <label className="search"><Search size={18}/><input aria-label={placeholder} value={value} onChange={e => onChange(e.target.value)} placeholder={placeholder}/>{value && <button aria-label={tr("Clear search")} onClick={() => onChange('')}><X size={15}/></button>}</label>; }
export function PageHeading({ eyebrow, title, subtitle, children }: { eyebrow?: string; title: string; subtitle: string; children?: ReactNode }) {
  useLocale(); return <div className="page-heading"><div>{eyebrow && <div className="eyebrow">{eyebrow}</div>}<h1>{title}<span className="title-dot">.</span></h1><p>{subtitle}</p></div><div className="heading-actions">{children}</div></div>; }
export function Empty({ title, text, children }: { title: string; text: string; children?: ReactNode }) {
  useLocale(); return <div className="empty"><div className="empty-icon"><UtensilsCrossed size={27}/></div><h3>{title}</h3><p>{text}</p>{children}</div>; }
export function AddButton({ children, onClick }: { children: ReactNode; onClick: () => void }) {
  useLocale(); return <button className="button primary" onClick={onClick}><Plus size={18}/>{children}</button>; }
export function Modal({ title, subtitle, children, onClose, wide = false }: { title: string; subtitle?: string; children: ReactNode; onClose: () => void; wide?: boolean }) {
  useLocale();
  const ref = useRef<HTMLDivElement>(null); const close = useRef(onClose); close.current = onClose;
  useEffect(() => {
    const previous = document.activeElement as HTMLElement;
    const previousOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
    const focusables = () => Array.from(ref.current?.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),select,textarea,a[href]') || []);
    focusables()[0]?.focus();
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') close.current(); if (e.key === 'Tab') { const els = focusables(); const first = els[0], last = els.at(-1); if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); } } };
    document.addEventListener('keydown', key);
    return () => { document.body.style.overflow = previousOverflow; document.removeEventListener('keydown', key); previous?.focus(); };
  }, []);
  return createPortal(<div className="modal-overlay" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}><div className={`modal ${wide ? 'wide' : ''}`} role="dialog" aria-modal="true" aria-label={title} ref={ref}><div className="modal-heading"><div><h2>{title}</h2>{subtitle && <p>{subtitle}</p>}</div><button className="icon-button" aria-label={tr("Close dialog")} onClick={onClose}><X size={20}/></button></div>{children}</div></div>, document.body);
}
export function GoIcon() {
  useLocale(); return <ArrowUpRight size={17}/>; }
