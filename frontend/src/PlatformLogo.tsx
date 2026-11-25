export const platformLogoUrl = () => `${import.meta.env.BASE_URL}brand/platform-transparent.png`;

export function PlatformLogo({ className = '' }: { className?: string }) {
  return <span className={`platform-logo ${className}`} aria-hidden="true">
    <svg viewBox="120 300 1000 680" focusable="false"><image href={platformLogoUrl()} width="1254" height="1254"/></svg>
  </span>;
}
