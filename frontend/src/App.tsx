import { tr, useLocale, formatLocale , formatDate } from './locale';
import { BusinessLocaleProvider, LanguageControl } from './locale';
import { PlatformLogo } from './PlatformLogo';
import { AppearanceControl } from './Appearance';
import { usePlatformName } from './PlatformBranding';
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { Link, NavLink, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import { Armchair, ShoppingBag, ChefHat, BookOpen, History, Settings as SettingsIcon, LogOut, Check, AlertCircle, WifiOff, RefreshCw, Sun, UtensilsCrossed, ClipboardList } from 'lucide-react';
import { api, setCsrf, ApiError } from './api';
import { useRealtime, useLiveRefresh, useDeadline } from './realtime';
import type { State, User, Role, Order } from './types';
import { Context } from './context';
import { TablesPage, TakeawayPage, KitchenPage, HistoryPage } from './Operations';
import { OrderPage } from './Order';
import { MenuPage, SettingsPage } from './Management';
import { SalesPage } from './Sales';
import { PlatformAuth } from './PlatformAuth';
import { BusinessSetup } from './BusinessSetup';
import { BusinessHelpPage } from './BusinessHelp';
import { BillingPage } from './Billing';
const OfficeApp = lazy(() => import('./Office').then(module => ({ default: module.OfficeApp })));
import { GuestMenuApp } from './GuestMenu';
import { GuestOrdersPage } from './GuestOrders';
const BusinessFinancePage = lazy(() => import('./BusinessFinance').then(module => ({ default: module.BusinessFinancePage })));
import { ShiftsPage } from './Shifts';
import { Modal } from './components';
import { MoreHorizontal, Banknote } from 'lucide-react';
import { ChartNoAxesCombined } from 'lucide-react';

export function App() {
  useLocale();
  const location = useLocation();
  if (location.pathname.startsWith('/menu/')) return <GuestMenuApp/>;
  return location.pathname.startsWith('/office') ? <Suspense fallback={<div className="boot"><h2>{tr("Opening platform office...")}</h2></div>}><OfficeApp/></Suspense> : <BusinessLocaleProvider><BusinessApp/></BusinessLocaleProvider>;
}

function BusinessApp() {
  useLocale();
  const platformName = usePlatformName();
  const [mobileMore, setMobileMore] = useState(false);
  const [user, setUser] = useState<User | null>(null); const [boot, setBoot] = useState(true); const [state, setState] = useState<State | null>(null);
  const [accessError, setAccessError] = useState('');
  const [connected, setConnected] = useState(true); const [toast, setToast] = useState<{ message: string; error: boolean } | null>(null); const sequence = useRef(0);
  const location = useLocation();
  useEffect(() => setMobileMore(false), [location.pathname, user?.id]);
  const notify = useCallback((message: string, error = false) => setToast({ message, error }), []);
  useEffect(() => { api<{ user: User | null; csrf: string }>('session').then(s => { setUser(s.user); setCsrf(s.csrf); }).catch(e => { setConnected(false); notify(e.message, true); }).finally(() => setBoot(false)); }, [notify]);
  useEffect(() => { const expired = () => { sequence.current++; setUser(null); setState(null); }; window.addEventListener('session-expired', expired); return () => window.removeEventListener('session-expired', expired); }, []);
  useEffect(() => { if (!toast) return; const timer = setTimeout(() => setToast(null), toast.error ? 9000 : 3500); return () => clearTimeout(timer); }, [toast]);
  const reconcileOrder = useCallback((order: Order) => {
    setState(previous => {
      if (!previous || (order.business_id !== undefined && order.business_id !== previous.business.id)) return previous;
      const current = previous.orders.find(row=>row.id===order.id);
      if (current && current.version > order.version) return previous;
      return {...previous,orders:current ? previous.orders.map(row=>row.id===order.id ? order : row) : order.status==='open' ? [...previous.orders,order] : previous.orders};
    });
  }, []);
  const socketReady = useRef(false);
  const realtimeStatus = useCallback((value:boolean)=>{socketReady.current=value;setConnected(value);},[]);
  const refresh = useCallback(async () => {
    const request = ++sequence.current;
    try {
      const data = await api<State>('state');
      if (request !== sequence.current) return;
      setState(previous => {
        const sameAccess = previous?.business.id === data.business.id && previous.user.id === data.user.id && JSON.stringify(previous.user.roles) === JSON.stringify(data.user.roles);
        return {...data, orders: data.orders.map(order => {
          const current = sameAccess ? previous?.orders.find(row => row.id === order.id) : undefined;
          return current && current.version > order.version ? current : order;
        })};
      });
      setConnected(socketReady.current); setAccessError('');
      setUser(previous => JSON.stringify(previous) === JSON.stringify(data.user) ? previous : data.user);
    } catch (error) {
      if (request !== sequence.current) return;
      setConnected(false); setAccessError((error as Error).message);
      if (error instanceof ApiError && [401,403].includes(error.status)) setState(null);
    }
  }, []);
  useRealtime('business', user?.id, realtimeStatus);
  useLiveRefresh(refresh);
  useEffect(() => { if (user?.id) void refresh(); }, [user?.id, refresh]);
  useDeadline(state?.writable ? state.subscription.ends_at : null, refresh);
  const can = useCallback((...roles: Role[]) => !!user?.roles.some(role => role === 'manager' || roles.includes(role)), [user]);
  const home = can('waiter', 'cashier') ? '/tables' : '/kitchen';
  const logout = async () => { try { await api('logout', {}); window.dispatchEvent(new Event('finance-cache-clear')); sequence.current++; setUser(null); setState(null); } catch (e) { notify((e as Error).message, true); } };
  if (boot) return <div className="boot"><PlatformLogo className="platform-logo-boot"/><h2>{tr("Opening the restaurant...")}</h2></div>;
  if (location.pathname === '/forgot-password' || location.pathname === '/reset-password') return <PlatformAuth onLogin={setUser}/>;
  if (!user) return <><PlatformAuth onLogin={u => { setUser(u); setConnected(true); }}/>{toast && <div className="toast error" role="alert">{tr(toast.message)}</div>}</>;
  if (!state) return <div className="boot"><PlatformLogo className="platform-logo-boot"/><h2>{connected ? tr("Setting your station...") : tr("Cannot open workspace")}</h2>{accessError && <p role="alert">{accessError}</p>}<button className="button" onClick={() => void refresh()}><RefreshCw size={17}/>{tr("Retry connection")}</button><button className="text-button" onClick={() => void logout()}>{tr("Sign out")}</button></div>;
  if (!state.business.setup_completed_at) {
    if (location.pathname !== '/setup') return <Navigate to="/setup" replace/>;
    return <BusinessSetup state={state} onComplete={refresh} onLogout={logout}/>;
  }
  if (location.pathname === '/setup') return <Navigate to={home} replace/>;
  const nav = [
    { to: '/sales', label: tr("Sales"), icon: ChartNoAxesCombined, show: can('cashier') },
    { to: '/shifts', label: tr("Cash shifts"), icon: Banknote, show: can('cashier') },
    { to: '/tables', label: tr("Tables"), icon: Armchair, show: can('waiter', 'cashier') },
    { to: '/takeaway', label: tr("Takeaway"), icon: ShoppingBag, show: can('waiter', 'cashier') },
    { to: '/kitchen', label: tr("Kitchen"), icon: ChefHat, show: can('kitchen') },
    { to: '/guest-orders', label: tr("Guest requests"), icon: ClipboardList, show: can('waiter', 'cashier') },
    { to: '/menu', label: tr("Food menu"), icon: BookOpen, show: true },
    { to: '/finance', label: tr("Finance"), icon: Banknote, show: state.business.owner_id === user.id },
    { to: '/history', label: tr("History"), icon: History, show: can('cashier') },
    { to: '/help', label: tr("Help & support"), icon: BookOpen, show: true },
    { to: '/billing', label: tr("Subscription"), icon: BookOpen, show: state.business.owner_id === user.id },
  ].filter(n => n.show);
  const primaryMobile = nav.filter(n => ['/tables', '/takeaway', '/kitchen', '/menu'].includes(n.to));
  const moreMobile = nav.filter(n => !primaryMobile.includes(n));
  const section = location.pathname.startsWith('/orders') ? tr("Order details") : nav.find(n => location.pathname.startsWith(n.to))?.label || tr("Settings");
  return <Context.Provider value={{ user, state, connected, refresh, reconcileOrder, notify, can }}><div className="app-shell">
    <aside className="sidebar"><Link className="brand" to={home}><PlatformLogo/><span>{state.business.name}<span className="brand-caption">{tr("RESTAURANT POS")}</span></span></Link>
      <div className="nav-label">{tr("WORKSPACE")}</div><nav>{nav.map(n => <NavLink key={n.to} to={n.to} className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}><n.icon size={20}/><span>{n.label}</span>{n.to === '/guest-orders' && state.guest_pending_count > 0 && <span className="nav-count">{state.guest_pending_count}</span>}{n.to === '/kitchen' && state.orders.some(o => o.items.some(i => ['sent', 'preparing'].includes(i.status))) && <span className="nav-dot"/>}</NavLink>)}</nav>
      <div className="sidebar-bottom"><div className="service-note"><span className="little-sun"><Sun size={24}/></span><strong>{tr("A little care.")}<br/>{tr("A great service.")}</strong><p>{tr("Good food starts with a happy team.")}</p></div>{can() && <NavLink to="/settings" className="nav-item"><SettingsIcon size={20}/>{tr("Settings")}</NavLink>}<button className="nav-item logout" onClick={() => void logout()}><LogOut size={20}/>{tr("Sign out")}</button><div className="sidebar-footer"><i/>{state.business.code}</div></div>
    </aside>
    <div className="workspace"><header className="topbar"><div className="breadcrumb"><span className="mobile-lemon"><PlatformLogo/></span><span className="desktop-only">{tr("Workspace")} <span className="slash">/</span></span><strong>{section}</strong></div><div className="topbar-right"><LanguageControl/><AppearanceControl/><span className={`connection ${connected ? '' : 'offline'}`}><i/>{connected ? tr("Connected") : tr("Reconnecting")}</span><span className="topbar-date">{formatDate(new Date(), { timeZone: state.settings.timezone, day: 'numeric', month: 'short', year: 'numeric' })}</span>{can() && <Link className="icon-button mobile-settings" aria-label={tr("Settings")} to="/settings"><SettingsIcon size={19}/></Link>}<div className="user-avatar" title={tr("{value1} · {value2}", {value1: user.name, value2: user.roles.map(role => tr(role)).join(', ')})}>{user.name.slice(0, 1)}</div><div className="user-name"><strong>{user.name}</strong><span>{user.roles.map(role => tr(role)).join(' / ')}</span></div><button className="icon-button mobile-logout" aria-label={tr("Sign out")} onClick={() => void logout()}><LogOut size={18}/></button></div></header>
      {!connected && <div className="offline-banner" role="status"><WifiOff size={17}/>{tr("Connection lost. Check the server before sending orders or payments.")}<button onClick={() => void refresh()}>{tr("Retry")}</button></div>}
      {!state.writable && <div className="subscription-banner" role="status"><strong>{state.business.status === 'suspended' ? tr("Business suspended.") : tr("Subscription ") + tr(state.subscription.status) + '.'}</strong>  {tr("Your workspace is read-only. Contact the platform office to restore access.")}{state.business.owner_id === user.id && <Link to="/billing">{tr("View subscription")}</Link>}</div>}
      <main><Routes><Route path="/help" element={<BusinessHelpPage/>}/><Route path="/billing" element={state.business.owner_id === user.id ? <BillingPage/> : <Navigate to={home} replace/>}/><Route path="/" element={<Navigate to={home} replace/>}/><Route path="/tables" element={can('waiter', 'cashier') ? <TablesPage/> : <Navigate to={home} replace/>}/><Route path="/takeaway" element={can('waiter', 'cashier') ? <TakeawayPage/> : <Navigate to={home} replace/>}/><Route path="/orders/:id" element={can('waiter', 'cashier') ? <OrderPage/> : <Navigate to={home} replace/>}/><Route path="/kitchen" element={can('kitchen') ? <KitchenPage/> : <Navigate to={home} replace/>}/><Route path="/guest-orders" element={can('waiter', 'cashier') ? <GuestOrdersPage/> : <Navigate to={home} replace/>}/><Route path="/menu" element={<MenuPage/>}/><Route path="/finance" element={state.business.owner_id === user.id ? <Suspense fallback={<p className="muted">{tr("Opening finances...")}</p>}><BusinessFinancePage/></Suspense> : <Navigate to={home} replace/>}/><Route path="/shifts" element={can('cashier') ? <ShiftsPage/> : <Navigate to={home} replace/>}/><Route path="/sales" element={can('cashier') ? <SalesPage/> : <Navigate to={home} replace/>}/><Route path="/history" element={can('cashier') ? <HistoryPage/> : <Navigate to={home} replace/>}/><Route path="/settings" element={can() ? <SettingsPage/> : <Navigate to={home} replace/>}/><Route path="*" element={<Navigate to={home} replace/>}/></Routes></main>
      <footer className="workspace-footer"><span>{tr("Powered by")} {platformName}</span><span><UtensilsCrossed size={13}/>{state.settings.name}</span></footer>
    </div><nav className="mobile-nav">{primaryMobile.map(n => <NavLink key={n.to} to={n.to}><n.icon size={21}/><span>{n.to === '/menu' ? tr("Menu") : n.label}</span></NavLink>)}{moreMobile.length > 0 && <button className={moreMobile.some(n => location.pathname.startsWith(n.to)) ? 'active' : ''} aria-expanded={mobileMore} onClick={() => setMobileMore(true)}><MoreHorizontal size={21}/><span>{tr("More")}{state.guest_pending_count > 0 && tr(" ({value1})", {value1: state.guest_pending_count})}</span></button>}</nav>
    {mobileMore && <Modal title={tr("More workspace tools")} onClose={() => setMobileMore(false)}><div className="mobile-more-links">{moreMobile.map(n => <NavLink key={n.to} className="button" to={n.to} onClick={() => setMobileMore(false)}><n.icon size={20}/>{n.label}{n.to === '/guest-orders' && state.guest_pending_count > 0 && <span className="nav-count">{state.guest_pending_count}</span>}</NavLink>)}</div></Modal>}
    {toast && <div className={`toast ${toast.error ? 'error' : ''}`} role={toast.error ? 'alert' : 'status'}>{toast.error ? <AlertCircle size={19}/> : <Check size={19}/>}<span>{tr(toast.message)}</span><button aria-label={tr("Dismiss notification")} onClick={() => setToast(null)}>×</button></div>}
  </div></Context.Provider>;
}
