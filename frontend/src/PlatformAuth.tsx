import { tr, useLocale } from './locale';
import { LanguageControl } from './locale';
import { PlatformLogo } from './PlatformLogo';
import { AppearanceControl } from './Appearance';
import { usePlatformName } from './PlatformBranding';
import { useState, type FormEvent } from 'react';
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { ArrowRight } from 'lucide-react';
import { useRealtime } from './realtime';
import { api, setCsrf } from './api';
import type { User } from './types';

export function PlatformAuth({ onLogin }: { onLogin: (user: User) => void }) {
  useLocale();
  const platformName = usePlatformName();
  useRealtime('platform', 'public');
  const location = useLocation(), navigate = useNavigate();
  const [params] = useSearchParams();
  const mode = location.pathname === '/register' ? 'register' : location.pathname === '/forgot-password' ? 'forgot' : location.pathname === '/reset-password' ? 'reset' : 'login';
  const [error, setError] = useState(''), [message, setMessage] = useState(''), [busy, setBusy] = useState(false);
  const [businessName, setBusinessName] = useState('');
  const [customBusinessCode, setCustomBusinessCode] = useState<string | null>(null);
  const nameCode = businessName.normalize("NFKD").replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 60).replace(/-+$/g, '');
  const businessCode = customBusinessCode ?? (businessName.trim() ? (nameCode.length >= 3 ? nameCode : nameCode ? `${nameCode}-business` : 'business') : '');
  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault(); setError(''); setMessage(''); setBusy(true);
    const data = Object.fromEntries(new FormData(event.currentTarget));
    try {
      if (mode === 'login' || mode === 'register') {
        const response = await api<{ user: User; csrf: string }>(mode === 'register' ? 'register' : 'login', data);
        setCsrf(response.csrf); onLogin(response.user); navigate('/', { replace: true });
      } else {
        const response = await api<{ message: string }>(mode === 'forgot' ? 'forgot-password' : 'reset-password', { ...data, token: params.get('token') });
        setMessage(response.message);
      }
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };
  return <div className="login-page platform-auth"><LanguageControl publicPage/><AppearanceControl publicPage/>
    <div className="login-story"><Link className="brand" to="/"><PlatformLogo/><span>{platformName}<span className="brand-caption">{tr("BUSINESS PLATFORM")}</span></span></Link>
      <div className="login-art"><div className="brand-hero-art"><PlatformLogo/></div><span className="art-label">{tr("YOUR BUSINESS. YOUR WORKSPACE.")}</span><h1>{tr("A good day")}<br/>{tr("starts here.")}</h1><p>{tr("Your tables, kitchen, team and sales.")}<br/>{tr("One place to run your restaurant.")}</p></div>
      <div className="login-story-footer">{tr("Independent businesses. A smoother service.")}</div></div>
    <div className="login-form-wrap"><div className="login-form">
      <span className="eyebrow">{mode === 'register' ? tr("START YOUR 1-MONTH TRIAL") : tr("WELCOME TO {value1}", {value1: platformName})}</span>
      <h2>{mode === 'register' ? tr("Create your business.") : mode === 'forgot' ? tr("Forgot password?") : mode === 'reset' ? tr("Choose a password.") : tr("Welcome back.")}</h2>
      <p>{mode === 'register' ? tr("Create your account, then set up your business.") : mode === 'login' ? tr("Sign in with your email and password.") : tr("Recover access to your account.")}</p>
      <form onSubmit={e => void submit(e)} key={mode}>
        {mode === 'register' && <><label>{tr("Your name")}<input name="name" autoComplete="name" maxLength={100} required/></label><label>{tr("Business name")}<input name="business_name" value={businessName} onChange={e => setBusinessName(e.target.value)} maxLength={100} required/></label><label>{tr("Business code")}<input aria-label={tr("Business code")} name="business_code" value={businessCode} onChange={e => setCustomBusinessCode(e.target.value)} pattern="[a-z0-9]+(-[a-z0-9]+)*" minLength={3} maxLength={60} placeholder={tr("e.g. golden-cafe")} required/><span className="field-hint">{tr("Generated from your business name. You can edit it if needed.")}</span></label></>}
        <label>{tr("Email")}<input name="email" type="email" autoComplete={mode === 'login' ? 'username' : 'email'} defaultValue={mode === 'reset' ? params.get('email') ?? '' : ''} maxLength={255} required/></label>
        {mode !== 'forgot' && <label>{tr("Password")}<input name="password" type="password" autoComplete={mode === 'login' ? 'current-password' : 'new-password'} minLength={mode === 'login' ? undefined : 6} maxLength={100} required/></label>}
        {(mode === 'register' || mode === 'reset') && <label>{tr("Confirm password")}<input name="password_confirmation" type="password" autoComplete="new-password" minLength={6} maxLength={100} required/></label>}
        {error && <div className="form-error" role="alert">{tr(error)}</div>}{message && <div className="platform-success" role="status">{tr(message)}</div>}
        <button className="button primary full" disabled={busy}>{busy ? tr("Please wait...") : mode === 'register' ? tr("Create business") : mode === 'forgot' ? tr("Send reset link") : mode === 'reset' ? tr("Reset password") : tr("Sign in")}<ArrowRight size={17}/></button>
      </form>
      <div className="auth-links">{mode === 'login' ? <><Link to="/register">{tr("Create a business account")}</Link><Link to="/forgot-password">{tr("Forgot your password?")}</Link></> : <Link to="/">{tr("Back to sign in")}</Link>}</div>
    </div></div>
  </div>;
}
