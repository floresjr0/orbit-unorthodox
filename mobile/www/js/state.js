export const state = {
  user: null,
  access: null,
  refresh: null,
  offline: !navigator.onLine,
  pending: 0,
  route: 'dashboard',
  params: {},
  back: null,
};

export function applyTheme(choice) {
  const theme = choice || localStorage.getItem('orbit.theme') || state.user?.theme || 'light';
  const dark = theme === 'dark';
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
  document.documentElement.dataset.themeChoice = dark ? 'dark' : 'light';
  localStorage.setItem('orbit.theme', dark ? 'dark' : 'light');
}
