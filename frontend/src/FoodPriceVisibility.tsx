import { tr, useLocale } from './locale';
import { useEffect, useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';

const key = 'little-lemon:show-food-prices';
function storedVisibility() { try { return localStorage.getItem(key) !== 'false'; } catch { return true; } }

export function useFoodPriceVisibility() {
  const [showPrices, setShowPrices] = useState(storedVisibility);
  useEffect(() => {
    const update = (event: Event) => setShowPrices(event instanceof CustomEvent ? Boolean(event.detail) : storedVisibility());
    window.addEventListener('food-prices-changed', update); window.addEventListener('storage', update);
    return () => { window.removeEventListener('food-prices-changed', update); window.removeEventListener('storage', update); };
  }, []);
  const togglePrices = () => {
    const next = !showPrices; setShowPrices(next);
    try { localStorage.setItem(key, String(next)); } catch { /* The control also works when browser storage is unavailable. */ }
    window.dispatchEvent(new CustomEvent('food-prices-changed', { detail: next }));
  };
  return { showPrices, togglePrices };
}

export function FoodPriceToggle({ visible, onToggle }: { visible: boolean; onToggle: () => void }) {
  useLocale();
  return <button className="button price-visibility-toggle" type="button" aria-pressed={!visible} onClick={onToggle} title={tr("Show or hide prices on food cards. Checkout amounts remain visible.")}>{visible ? <EyeOff size={16}/> : <Eye size={16}/>}{visible ? tr("Hide prices") : tr("Show prices")}</button>;
}
