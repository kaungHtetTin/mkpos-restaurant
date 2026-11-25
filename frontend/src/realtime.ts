import { useEffect, useRef, useState } from 'react';
import { api, ApiError, isLocallyReconciled } from './api';

type Scope = 'business' | 'office' | 'guest' | 'platform' | 'finance';
interface Credentials { url: string; token: string; expires_at: number }

export function useLiveRefresh(callback: () => unknown, scope: Scope = 'business') {
  const current = useRef(callback);
  current.current = callback;
  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const update = () => { clearTimeout(timer); timer = setTimeout(() => { void current.current(); }, 80); };
    window.addEventListener('realtime:'+scope, update);
    return () => { clearTimeout(timer); window.removeEventListener('realtime:'+scope, update); };
  }, [scope]);
}

export function useRealtime(scope: Scope, identity: string | number | null | undefined, status?: (connected: boolean) => void) {
  const [connected, setConnected] = useState(false);
  const statusRef = useRef(status); statusRef.current = status;
  useEffect(() => {
    if (!identity) { setConnected(false); return; }
    let stopped = false, socket: WebSocket | undefined, timer: ReturnType<typeof setTimeout> | undefined, credentials: Credentials | undefined, attempt = 0, dialing = false;
    let handshake: ReturnType<typeof setTimeout> | undefined;
    const seen = new Set<string>();
    const mark = (value: boolean) => { if (!stopped) { setConnected(value); statusRef.current?.(value); } };
    const schedule = () => { if (stopped) return; clearTimeout(timer); timer = setTimeout(() => { void connect(); }, Math.min(15000, 500 * 2 ** Math.min(attempt++, 5)) + Math.random() * 300); };
    const connect = async () => {
      if (stopped || dialing) return;
      dialing=true;
      mark(false);
      try {
        if (!credentials || credentials.expires_at * 1000 <= Date.now()+10000) {
          const route = scope === 'platform' ? 'realtime/platform' : scope === 'office' ? 'office/realtime' : scope === 'guest' ? 'realtime/guest?'+identity : 'realtime/business';
          credentials = await api<Credentials>(route);
        }
        if (stopped) return;
        socket = new WebSocket(credentials.url);
        dialing=false;
        const active = socket;
        handshake = setTimeout(()=>active.close(), 10000);
        active.onopen = () => { if (!stopped) active.send(JSON.stringify({type:'auth',token:credentials!.token})); };
        active.onmessage = event => {
          if (stopped || socket !== active) return;
          try {
            const message = JSON.parse(event.data);
            if (message.type === 'ready') {
              clearTimeout(handshake); attempt=0; mark(true);
              window.dispatchEvent(new CustomEvent('realtime:'+scope, {detail:{reconnected:true}}));
              if (scope !== 'platform') window.dispatchEvent(new CustomEvent('realtime:platform'));
              if (scope === 'business') window.dispatchEvent(new CustomEvent('realtime:finance'));
            } else if (message.type === 'changed' && typeof message.id === 'string' && !seen.has(message.id)) {
              seen.add(message.id); if(seen.size>200) seen.delete(seen.values().next().value!);
              if (message.topic === 'platform') {
                window.dispatchEvent(new CustomEvent('realtime:platform', {detail:message}));
                if (scope === 'office') window.dispatchEvent(new CustomEvent('realtime:office', {detail:message}));
              } else if (message.topic === 'finance') {
                window.dispatchEvent(new CustomEvent('realtime:finance', {detail:message}));
              } else if (!isLocallyReconciled(message.request_key)) window.dispatchEvent(new CustomEvent('realtime:'+scope, {detail:message}));
            }
          } catch { active.close(); }
        };
        active.onerror = () => active.close();
        active.onclose = event => { clearTimeout(handshake); if (event.code === 4001 || event.code === 4003) credentials=undefined; mark(false); schedule(); };
      } catch (error) { dialing=false; if (error instanceof ApiError && [401,403,404].includes(error.status)) { mark(false); return; } schedule(); }
    };
    const online = () => { if (socket?.readyState === WebSocket.OPEN || socket?.readyState === WebSocket.CONNECTING) return; clearTimeout(timer); void connect(); };
    window.addEventListener('online',online);
    void connect();
    return () => { stopped=true; clearTimeout(timer); clearTimeout(handshake); socket?.close(); window.removeEventListener('online',online); };
  }, [scope, identity]);
  return connected;
}


export function useDeadline(deadline: string | null | undefined, callback: () => unknown) {
  const current = useRef(callback); current.current = callback;
  useEffect(() => {
    if (!deadline) return;
    const target = new Date(deadline.includes('T') ? deadline : deadline.replace(' ', 'T')+'Z').getTime();
    if (!Number.isFinite(target)) return;
    let timer: ReturnType<typeof setTimeout>;
    const schedule = () => {
      const delay = target - Date.now() + 1000;
      if (delay <= 0) { void current.current(); return; }
      timer = setTimeout(schedule, Math.min(delay, 2147483647));
    };
    schedule();
    return () => clearTimeout(timer);
  }, [deadline]);
}
