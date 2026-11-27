import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { myMessages } from './locales/my';
import { translateValidation } from './locale-validation';
import { formatLocalizedDate } from './locale-dates';

export type Locale = 'en' | 'my';
const storageKey = 'restaurant-pos:business-locale';
const valid = (value: string | null): Locale => value === 'my' ? 'my' : 'en';
let selected: Locale = 'en';
try { selected = valid(localStorage.getItem(storageKey)); } catch { /* Use English when browser storage is unavailable. */ }
const LocaleContext = createContext<{ locale: Locale; change: (locale: Locale) => void }>({ locale: 'en', change: () => {} });
const englishByMyanmar = new Map<string, string>();
for (const [english, myanmar] of Object.entries(myMessages)) {
  if (!english.includes('{') && !englishByMyanmar.has(myanmar)) englishByMyanmar.set(myanmar, english.split('|')[0]);
}
const escapePattern = (value: string) => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const messageTemplates = Object.entries(myMessages)
  .filter(([key]) => key.includes('{') && /[a-z]/i.test(key.replace(/\{\w+\}/g, '')))
  .sort(([a], [b]) => b.replace(/\{\w+\}/g, '').length - a.replace(/\{\w+\}/g, '').length)
  .flatMap(([english, myanmar]) => [myanmar, english].map(template => {
    const names: string[] = []; let source = '', cursor = 0;
    for (const match of template.matchAll(/\{(\w+)\}/g)) {
      source += escapePattern(template.slice(cursor, match.index)) + '(.*?)';
      names.push(match[1]); cursor = match.index! + match[0].length;
    }
    return { english, names, pattern: new RegExp('^' + source + escapePattern(template.slice(cursor)) + '$', 's') };
  }));
function canonicalMessage(message: string): { key: string; values: Record<string, string> } {
  if (Object.hasOwn(myMessages, message)) return { key: message, values: {} };
  const exact = englishByMyanmar.get(message); if (exact) return { key: exact, values: {} };
  for (const { english, names, pattern } of messageTemplates) {
    const match = pattern.exec(message); if (!match) continue;
    return { key: english, values: Object.fromEntries(names.map((name, index) => [name, match[index + 1]])) };
  }
  return { key: message, values: {} };
}

export function businessLocale(): Locale {
  return window.location.pathname.startsWith('/office') || window.location.pathname.startsWith('/menu/') ? 'en' : selected;
}

export function tr(message: string, values: Record<string, string | number | null | undefined> = {}, context?: string): string {
  const { key: canonical, values: retainedValues } = canonicalMessage(message);
  const translated = businessLocale() === 'my' ? (context ? myMessages[canonical + '|' + context] : undefined) ?? myMessages[canonical] ?? translateValidation(canonical, myMessages) ?? canonical : canonical;
  const replacements = { ...retainedValues, ...values };
  return translated.replace(/\{([a-zA-Z][\w]*)\}/g, (match, name: string) => Object.hasOwn(replacements, name) ? String(replacements[name] ?? '') : match);
}

export function useLocale() { return useContext(LocaleContext); }
export function formatLocale() { return businessLocale() === 'my' ? 'my-MM-u-nu-latn' : 'en-GB'; }
export function formatDate(date: Date, options: Intl.DateTimeFormatOptions) { return formatLocalizedDate(date, businessLocale(), options); }
export function paymentMethodText(label: string, method?: string) {
  if (method === 'other') return label;
  return label.replace(/^Cash(?=$| \+ )/, () => tr('Cash'));
}
export function formatCompactNumber(value: number) {
  if (businessLocale() === 'en') return new Intl.NumberFormat('en-GB', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
  const number = (amount: number) => new Intl.NumberFormat('en-GB', { maximumFractionDigits: 1 }).format(amount);
  if (Math.abs(value) >= 1000000) return tr('{value1} million', { value1: number(value / 1000000) });
  if (Math.abs(value) >= 100000) return tr('{value1} hundred thousand', { value1: number(value / 100000) });
  if (Math.abs(value) >= 1000) return tr('{value1} thousand', { value1: number(value / 1000) });
  return number(value);
}

export function BusinessLocaleProvider({ children }: { children: ReactNode }) {
  const [locale, setLocale] = useState(() => {
    try { selected = valid(localStorage.getItem(storageKey)); } catch { /* Retain the current language. */ }
    return selected;
  });
  const apply = (value: Locale) => { selected = value; setLocale(value); };
  const change = (value: Locale) => {
    apply(value);
    try { localStorage.setItem(storageKey, value); } catch { /* Keep the current language even without persistence. */ }
  };
  useEffect(() => {
    const previous = document.documentElement.lang;
    document.documentElement.lang = locale;
    window.dispatchEvent(new Event('business-locale-changed'));
    const sync = (event: StorageEvent) => { if (event.key === storageKey || event.key === null) apply(valid(event.newValue)); };
    window.addEventListener('storage', sync);
    return () => { document.documentElement.lang = previous; window.removeEventListener('storage', sync); };
  }, [locale]);
  useEffect(() => {
    const controls = () => document.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>('[data-locale-validation]');
    const clear = (event: Event) => {
      const target = event.target;
      if (target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement) {
        if (target.dataset.localeValidation) { target.setCustomValidity(''); delete target.dataset.localeValidation; }
      }
    };
    const invalid = (event: Event) => {
      const target = event.target;
      if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement)) return;
      clear(event);
      const validity = target.validity;
      let message = 'Please enter a valid value.';
      let values: Record<string, string | number> = {};
      if (validity.valueMissing) message = target instanceof HTMLInputElement && target.type === 'checkbox' ? 'Please check this box to continue.' : 'Please fill out this field.';
      else if (validity.typeMismatch && target instanceof HTMLInputElement && target.type === 'email') message = 'Please enter a valid email address.';
      else if (validity.tooShort) { message = 'Use at least {count} characters.'; values = { count: (target as HTMLInputElement).minLength }; }
      else if (validity.tooLong) { message = 'Use no more than {count} characters.'; values = { count: (target as HTMLInputElement).maxLength }; }
      else if (validity.rangeUnderflow) { message = 'Enter a value of at least {count}.'; values = { count: (target as HTMLInputElement).min }; }
      else if (validity.rangeOverflow) { message = 'Enter a value no greater than {count}.'; values = { count: (target as HTMLInputElement).max }; }
      else if (validity.stepMismatch || validity.badInput) message = 'Please enter a valid number.';
      target.setCustomValidity(tr(message, values)); target.dataset.localeValidation = 'true';
    };
    controls().forEach(control => { control.setCustomValidity(''); delete control.dataset.localeValidation; });
    document.addEventListener('invalid', invalid, true); document.addEventListener('input', clear, true); document.addEventListener('change', clear, true);
    return () => {
      controls().forEach(control => { control.setCustomValidity(''); delete control.dataset.localeValidation; });
      document.removeEventListener('invalid', invalid, true); document.removeEventListener('input', clear, true); document.removeEventListener('change', clear, true);
    };
  }, [locale]);
  return <LocaleContext.Provider value={{ locale, change }}>{children}</LocaleContext.Provider>;
}

export function LanguageControl({ publicPage = false }: { publicPage?: boolean }) {
  const { locale, change } = useLocale();
  return <button className={`language-control${publicPage ? ' language-public' : ''}`} type="button" role="switch" aria-label={tr('Myanmar language')} aria-checked={locale === 'my'} title={tr('Language')} onClick={() => change(locale === 'en' ? 'my' : 'en')}>
    <span className="language-toggle-track" aria-hidden="true"><span className="language-toggle-thumb"/><span className="language-toggle-value" lang="en">EN</span><span className="language-toggle-value" lang="my">မြ</span></span>
  </button>;
}
