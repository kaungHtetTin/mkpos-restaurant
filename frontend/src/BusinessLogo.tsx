import { useState } from 'react';
import { tr, useLocale } from './locale';

export function BusinessLogo({ source }: { source?: string | null }) {
  useLocale();
  const [failedSource, setFailedSource] = useState<string | null>(null);
  return source && source !== failedSource
    ? <img className="business-print-logo" src={source} alt={tr('Business logo')} onError={() => setFailedSource(source)}/>
    : null;
}
