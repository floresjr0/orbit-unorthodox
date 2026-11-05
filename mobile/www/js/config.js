export function apiBase() {
  const saved = localStorage.getItem('orbit.apiBase');
  if (saved) return saved.replace(/\/$/, '');
  const native = window.Capacitor?.isNativePlatform?.();
  if (native) return 'http://10.0.2.2/orbit-unorthodox/api/v1';
  const root = location.pathname.includes('/orbit-unorthodox') ? '/orbit-unorthodox' : '';
  return `${location.origin}${root}/api/v1`;
}

export function setApiBase(value) {
  const next = value.trim().replace(/\/$/, '');
  if (next) localStorage.setItem('orbit.apiBase', next);
  else localStorage.removeItem('orbit.apiBase');
}
