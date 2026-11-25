import { businessLocale } from './locale';
let csrf = '';
const locallyReconciled = new Map<string, string>();
export const isLocallyReconciled = (key: unknown) => typeof key === 'string' && locallyReconciled.get(key) === window.location.pathname;
const retries = new Map<string, string>();
const fileIds = new WeakMap<File, string>();
export class ApiError extends Error { constructor(message: string, public status: number) { super(message); } }
export function setCsrf(token: string) { csrf = token; }
export async function api<T>(path: string, body?: unknown, options: { reconcileLocally?: boolean; retryCsrf?: boolean } = {}): Promise<T> {
  const multipart = body instanceof FormData;
  const content = body === undefined ? undefined : multipart ? body : JSON.stringify(body);
  const fingerprint = multipart ? JSON.stringify(Array.from(body.entries(), ([name, value]) => {
    if (value instanceof File) {
      if (!fileIds.has(value)) fileIds.set(value, `${Date.now()}-${Math.random().toString(36).slice(2)}`);
      return [name, fileIds.get(value), value.name, value.size, value.lastModified];
    }
    return [name, value];
  })) : content;
  const signature = `${path}:${fingerprint}`;
  let key = retries.get(signature);
  if (content && !key) { key = crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`; retries.set(signature, key); }
  if (key && options.reconcileLocally) { locallyReconciled.set(key, window.location.pathname); if (locallyReconciled.size > 200) locallyReconciled.delete(locallyReconciled.keys().next().value!); }
  let response: Response;
  try {
    response = await fetch(`/api/v1/${path}`, { method: content ? 'POST' : 'GET', credentials: 'same-origin', headers: { Accept: 'application/json', 'Accept-Language': businessLocale(), ...(content ? { ...(!multipart ? { 'Content-Type': 'application/json' } : {}), 'X-CSRF-TOKEN': csrf, 'Idempotency-Key': key! } : {}) }, body: content });
  } catch { throw new Error('Cannot reach the restaurant server. Check your connection, then retry the same action.'); }
  const data = await response.json().catch(() => ({ message: 'The server could not complete this action.' }));
  if (response.status === 419 && options.retryCsrf !== false) { const session = await api<{ csrf: string }>('session'); setCsrf(session.csrf); return api<T>(path, body, {...options,retryCsrf:false}); }
  if (response.status < 500) retries.delete(signature);
  if (!response.ok) {
    if (response.status === 401) window.dispatchEvent(new Event('session-expired'));
    throw new ApiError(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Something went wrong.', response.status);
  }
  if (content && path.startsWith('office/')) window.dispatchEvent(new CustomEvent('office-write', { detail: { path } }));
  return data;
}
