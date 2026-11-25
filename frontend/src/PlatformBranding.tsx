import { tr } from './locale';
import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { useLocation } from 'react-router-dom';
import { api } from './api';
import { useLiveRefresh } from './realtime';

const BrandingContext = createContext('Restaurant POS');
export const usePlatformName = () => useContext(BrandingContext);

export function PlatformBranding({ children }: { children: ReactNode }) {
  const [name, setName] = useState(() => document.querySelector<HTMLMetaElement>('meta[name="application-name"]')?.content || 'Restaurant POS');
  const sequence = useRef(0);
  const location = useLocation();
  const [languageRevision, setLanguageRevision] = useState(0);
  useEffect(() => { const update = () => setLanguageRevision(value => value + 1); window.addEventListener('business-locale-changed', update); return () => window.removeEventListener('business-locale-changed', update); }, []);
  const refresh = async () => {
    const request = ++sequence.current;
    try {
      const branding = await api<{ name: string }>('platform/branding');
      if (request === sequence.current && branding.name.trim()) setName(branding.name);
    } catch { /* Retain the last known branding while offline. */ }
  };
  useLiveRefresh(refresh, 'platform');
  useEffect(() => {
    void refresh();
    const saved = (event: Event) => {
      if ((event as CustomEvent).detail?.path === 'office/manage/settings') void refresh();
    };
    window.addEventListener('office-write', saved);
    return () => { sequence.current++; window.removeEventListener('office-write', saved); };
  }, []);
  useEffect(() => {
    const area = location.pathname.startsWith('/office') ? 'Platform office' : location.pathname.startsWith('/menu/') ? 'Customer menu' : tr('Restaurant POS');
    document.title = `${name} · ${area}`;
    document.querySelector<HTMLMetaElement>('meta[name="application-name"]')?.setAttribute('content', name);
    document.querySelector<HTMLMetaElement>('meta[name="description"]')?.setAttribute('content', tr('{value1} restaurant ordering, tables and kitchen', { value1: name }));
  }, [name, location.pathname, languageRevision]);
  return <BrandingContext.Provider value={name}>{children}</BrandingContext.Provider>;
}
