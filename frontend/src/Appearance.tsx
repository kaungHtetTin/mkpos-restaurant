import { tr, useLocale } from './locale';
import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { platformLogoUrl } from './PlatformLogo';
import { Moon, Sun } from 'lucide-react';

type Appearance = 'system' | 'light' | 'dark';
const key = 'restaurant-pos:appearance';
const valid = (value: string | null): Appearance => value === 'light' || value === 'dark' ? value : 'system';
const AppearanceContext = createContext<{ preference: Appearance; setPreference: (value: Appearance) => void; dark: boolean }>({ preference: 'system', setPreference: () => {}, dark: false });

export function AppearanceProvider({ children }: { children: ReactNode }) {
  useLocale();
  const [preference, setPreference] = useState<Appearance>(() => {
    try { return valid(localStorage.getItem(key)); } catch { return 'system'; }
  });
  const [systemDark, setSystemDark] = useState(() => window.matchMedia('(prefers-color-scheme: dark)').matches);
  useEffect(() => {
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const apply = () => {
      setSystemDark(media.matches);
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
  const dark = preference === 'dark' || (preference === 'system' && systemDark);
  return <AppearanceContext.Provider value={{ preference, setPreference: change, dark }}>{children}</AppearanceContext.Provider>;
}

export function AppearanceControl({ publicPage = false }: { publicPage?: boolean }) {
  useLocale();
  const { dark, setPreference } = useContext(AppearanceContext);
  return <button className={`appearance-control${publicPage ? ' appearance-public' : ''}`} type="button" role="switch" aria-label={tr('Dark mode')} aria-checked={dark} title={tr("Appearance: {value1}", {value1: dark ? tr('Dark') : tr('Light')})} onClick={() => setPreference(dark ? 'light' : 'dark')}>
    <span className="appearance-toggle-track" aria-hidden="true"><span className="appearance-toggle-thumb"/><span className="appearance-toggle-value"><Sun size={16}/></span><span className="appearance-toggle-value"><Moon size={16}/></span></span>
  </button>;
}
