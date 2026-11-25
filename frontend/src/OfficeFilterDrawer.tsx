import { useEffect, useLayoutEffect, useId, useRef, useState, type CSSProperties, type FormEvent } from 'react';
import { createPortal } from 'react-dom';
import { useLocation } from 'react-router-dom';
import { SlidersHorizontal, X } from 'lucide-react';
import { OfficePageActions, useOffice, useOfficeFormDraft } from './OfficeTools';
import './office-filter-drawer.css';

export interface OfficeFilterField {
  name: string;
  label: string;
  ariaLabel?: string;
  type?: 'text' | 'select' | 'date' | 'month' | 'number';
  options?: { value: string | number; label: string }[];
  defaultValue?: string;
  placeholder?: string;
  maxLength?: number;
  pattern?: string;
  min?: number;
  max?: number;
  hint?: string;
}

export function OfficeFilterDrawer({ params, fields, onApply, onReset, draftTag = 'filters' }: {
  params: URLSearchParams;
  fields: OfficeFilterField[];
  onApply: (values: Record<string, string>) => void;
  onReset: () => void;
  draftTag?: string;
}) {
  const [open, setOpen] = useState(false), trigger = useRef<HTMLButtonElement>(null), id = useId();
  const location = useLocation(), entry = useRef(location.key);
  useLayoutEffect(() => { if (entry.current !== location.key) { entry.current = location.key; setOpen(false); } }, [location.key]);
  const draft = useOfficeFormDraft(draftTag);
  const count = fields.filter(field => {
    const value = params.get(field.name);
    return value !== null && value.trim() !== '' && value !== (field.defaultValue ?? '');
  }).length;
  const initial = Object.fromEntries(fields.map(field => [field.name, draft.values[field.name] ?? params.get(field.name) ?? field.defaultValue ?? '']));
  return <>
    <OfficePageActions><button ref={trigger} type="button" className={'button office-filter-trigger' + (count ? ' is-active' : '')}
      aria-label="Filters" aria-describedby={`${id}-summary`} aria-expanded={open} aria-controls={open ? id : undefined} onClick={() => setOpen(true)}>
      <SlidersHorizontal size={16} aria-hidden="true"/>Filters{count > 0 && <span className="office-filter-count" aria-hidden="true">{count}</span>}
    </button><span id={`${id}-summary`} className="sr-only">{count ? `${count} filters applied` : 'No custom filters applied'}</span></OfficePageActions>
    {open && <OfficeFilterPanel id={id} fields={fields} initial={initial} onDraft={draft.save} onClose={() => setOpen(false)}
      returnFocus={() => trigger.current?.focus({ preventScroll: true })}
      onApply={values => { draft.save(values); onApply(values); setOpen(false); }}
      onReset={() => { draft.clear(); onReset(); setOpen(false); }}/>}
  </>;
}

function OfficeFilterPanel({ id, fields, initial, onDraft, onClose, returnFocus, onApply, onReset }: {
  id: string;
  fields: OfficeFilterField[];
  initial: Record<string, string>;
  onDraft: (values: Record<string, string>) => void;
  onClose: () => void;
  returnFocus: () => void;
  onApply: (values: Record<string, string>) => void;
  onReset: () => void;
}) {
  const { settings } = useOffice(), panel = useRef<HTMLElement>(null);
  const [values, setValues] = useState(initial);
  const channels = settings.theme_color.slice(1).match(/.{2}/g)?.map(value => parseInt(value, 16)) || [199, 169, 46];
  const dark = (channels[0] * 299 + channels[1] * 587 + channels[2] * 114) / 1000 < 145;
  useEffect(() => {
    const overflow = document.body.style.overflow, padding = document.body.style.paddingRight;
    const left = window.scrollX, top = window.scrollY;
    const scrollbar = window.innerWidth - document.documentElement.clientWidth;
    if (scrollbar > 0) document.body.style.paddingRight = `${parseFloat(getComputedStyle(document.body).paddingRight) + scrollbar}px`;
    document.body.style.overflow = 'hidden';
    const focusables = () => Array.from(panel.current?.querySelectorAll<HTMLElement>('button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href]') || []);
    panel.current?.querySelector<HTMLElement>('input,select')?.focus({ preventScroll: true });
    const key = (event: KeyboardEvent) => {
      if (event.key === 'Escape') { event.preventDefault(); onClose(); }
      if (event.key === 'Tab') {
        const elements = focusables(), first = elements[0], last = elements.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
      }
    };
    document.addEventListener('keydown', key);
    return () => {
      document.removeEventListener('keydown', key);
      document.body.style.overflow = overflow;
      document.body.style.paddingRight = padding;
      window.scrollTo(left, top);
      returnFocus();
    };
  }, []);
  const change = (name: string, value: string) => {
    const next = { ...values, [name]: value };
    setValues(next); onDraft(next);
  };
  const submit = (event: FormEvent) => { event.preventDefault(); onApply(values); };
  return createPortal(<div className="office-filter-overlay" style={{ '--office-accent': settings.theme_color, '--office-accent-text': dark ? '#fff' : '#17200f' } as CSSProperties}
    onMouseDown={event => { if (event.target === event.currentTarget) { event.preventDefault(); onClose(); } }}>
    <aside ref={panel} id={id} className="office-filter-drawer" role="dialog" aria-modal="true" aria-label="Page filters">
      <header className="office-filter-heading"><div><h2>Filters</h2><p>Choose your filters, then apply them.</p></div><button type="button" className="icon-button" aria-label="Close filters" onClick={onClose}><X size={20}/></button></header>
      <form className="office-filter-form" onSubmit={submit}>
        <div className="office-filter-fields">{fields.map(field => <label key={field.name}>{field.label}
          {field.type === 'select' ? <select aria-label={field.ariaLabel || field.label} name={field.name} value={values[field.name] ?? ''} onChange={event => change(field.name, event.target.value)}>
            {field.options?.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
          </select> : <input aria-label={field.ariaLabel || field.label} name={field.name} type={field.type || 'text'} value={values[field.name] ?? ''}
            placeholder={field.placeholder} maxLength={field.maxLength} pattern={field.pattern} min={field.min} max={field.max} step={field.type === 'number' ? 1 : undefined}
            onChange={event => change(field.name, event.target.value)}/>}
          {field.hint && <span className="field-hint">{field.hint}</span>}
        </label>)}</div>
        <footer className="office-filter-footer"><button type="button" className="button" onClick={onReset}>Reset filters</button><button type="submit" className="button primary">Apply filters</button></footer>
      </form>
    </aside>
  </div>, document.body);
}
