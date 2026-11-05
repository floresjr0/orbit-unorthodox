export const state = {
  user: null,
  access: null,
  refresh: null,
  offline: !navigator.onLine,
  pending: 0,
  route: 'dashboard',
  params: {},
};

export function applyTheme(choice) {
  const theme = choice || localStorage.getItem('orbit.theme') || state.user?.theme || 'system';
  const dark = theme === 'dark' || (theme !== 'light' && matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  document.documentElement.dataset.themeChoice = theme;
  localStorage.setItem('orbit.theme', theme);
}
