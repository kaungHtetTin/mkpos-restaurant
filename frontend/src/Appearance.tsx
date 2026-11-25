import { tr, useLocale } from './locale';
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { platformLogoUrl } from './PlatformLogo';
import { Monitor, Moon, Sun } from 'lucide-react';

type Appearance = 'system' | 'light' | 'dark';
const key = 'restaurant-pos:appearance';
const valid = (value: string | null): Appearance => value === 'light' || value === 'dark' ? value : 'system';
const AppearanceContext = createContext<{ preference: Appearance; setPreference: (value: Appearance) => void }>({ preference: 'system', setPreference: () => {} });

export function AppearanceProvider({ children }: { children: ReactNode }) {
  useLocale();
  const [preference, setPreference] = useState<Appearance>(() => {
    try { return valid(localStorage.getItem(key)); } catch { return 'system'; }
  });
  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = () => {
      const dark = preference === 'dark' || (preference === 'system' && media.matches);
      document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      document.querySelector<HTMLLinkElement>('link[rel="icon"]')?.setAttribute('href', platformLogoUrl());
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#141915' : '#f6ce42');
    };
    apply();
    media.addEventListener('change', apply);
    return () => media.removeEventListener('change', apply);
  }, [preference]);
  useEffect(() => {
    const sync = (event: StorageEvent) => { if (event.key === key || event.key === null) setPreference(valid(event.newValue)); };
    window.addEventListener('storage', sync);
    return () => window.removeEventListener('storage', sync);
  }, []);
  const change = (value: Appearance) => {
    setPreference(value);
    try { localStorage.setItem(key, value); } catch { /* Appearance still works when browser storage is unavailable. */ }
  };
  return <AppearanceContext.Provider value={{ preference, setPreference: change }}>{children}</AppearanceContext.Provider>;
}

export function AppearanceControl({ publicPage = false }: { publicPage?: boolean }) {
  useLocale();
  const { preference, setPreference } = useContext(AppearanceContext);
  const Icon = preference === 'system' ? Monitor : preference === 'dark' ? Moon : Sun;
  return <label className={`appearance-control${publicPage ? ' appearance-public' : ''}`} title={tr("Appearance: {value1}", {value1: tr(preference)})}>
    <Icon size={18} aria-hidden="true"/>
    <select aria-label={tr("Appearance")} value={preference} onChange={event => setPreference(valid(event.target.value))}>
      <option value="system">{tr("System")}</option><option value="light">{tr("Light")}</option><option value="dark">{tr("Dark")}</option>
    </select>
  </label>;
}
