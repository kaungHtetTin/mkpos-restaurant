import { useEffect, useRef, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { LogOut, MoreVertical, Settings } from 'lucide-react';
import { useApp } from './context';
import { AppearanceControl } from './Appearance';
import { LanguageControl, formatDate, tr, useLocale } from './locale';
import { Modal } from './components';
import { PlatformLogo } from './PlatformLogo';
import { devicePrinter } from './device-printing';
import './app-bar.css';

export function BusinessAppBar({ section, home, onLogout }: { section: string; home: string; onLogout: () => Promise<void> }) {
  useLocale();
  const { user, state, connected, can } = useApp();
  const location = useLocation();
  const [open, setOpen] = useState(false), [busy, setBusy] = useState(false);
  const busyRef = useRef(false);
  busyRef.current = busy;
  useEffect(() => { setOpen(false); }, [location.pathname, user.id]);
  const status = connected ? tr('Connected') : tr('Reconnecting');
  return <>
    <header className="topbar business-app-bar">
      <div className="breadcrumb">
        <Link className="mobile-lemon appbar-home" to={home} aria-label={tr('Home')}><PlatformLogo/></Link>
        <span className="desktop-only">{tr('Workspace')} <span className="slash">/</span></span>
        <div className="appbar-title"><strong title={section}>{section}</strong><span className={`appbar-mobile-status ${connected ? '' : 'offline'}`}><i/>{status}</span></div>
      </div>
      <div className="topbar-right">
        <LanguageControl/><AppearanceControl/>
        <span className={`connection ${connected ? '' : 'offline'}`}><i/>{status}</span>
        <span className="topbar-date">{formatDate(new Date(), { timeZone: state.settings.timezone, day: 'numeric', month: 'short', year: 'numeric' })}</span>
        <div className="user-avatar" aria-hidden="true">{user.name.slice(0, 1)}</div>
        <div className="user-name"><strong>{user.name}</strong><span>{user.roles.map(role => tr(role)).join(' / ')}</span></div>
        <button className="icon-button appbar-menu-button" type="button" aria-label={tr('App menu')} title={tr('App menu')} aria-haspopup="dialog" aria-expanded={open} onClick={() => setOpen(true)}><MoreVertical size={22}/></button>
      </div>
    </header>
    {open && <Modal title={tr('App menu')} subtitle={state.business.name} onClose={() => { if (!busy) setOpen(false); }}>
      <div className="appbar-menu-profile"><span className="user-avatar" aria-hidden="true">{user.name.slice(0, 1)}</span><div><strong>{user.name}</strong><span>{user.roles.map(role => tr(role)).join(' / ')}</span></div></div>
      <div className="appbar-menu-status"><span className={`connection ${connected ? '' : 'offline'}`}><i/>{status}</span><span>{formatDate(new Date(), { timeZone: state.settings.timezone, day: 'numeric', month: 'short', year: 'numeric' })}</span></div>
      <div className="appbar-menu-actions">
        {(can() || !!devicePrinter()) && <Link className="button" to="/settings" onClick={() => setOpen(false)}><Settings size={20}/>{tr('Settings')}</Link>}
        <button className="button appbar-signout" type="button" disabled={busy} onClick={async () => { if (busyRef.current) return; busyRef.current = true; setBusy(true); try { await onLogout(); } finally { busyRef.current = false; setBusy(false); } }}><LogOut size={20}/>{busy ? tr('Working...') : tr('Sign out')}</button>
      </div>
    </Modal>}
  </>;
}
