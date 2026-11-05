import { db } from './db.js';
import { loadSession } from './session.js';
import { applyTheme, state } from './state.js';
import { navigate, onRender, readHash, register, show } from './router.js';
import { pendingCount, syncNow } from './sync.js';
import { icon, mark } from './ui.js';
import { renderForgot, renderLogin, renderRegister } from './views/auth.js';
import { renderAlerts, renderJoin, renderProfile, renderTeam, renderTeamForm, renderTeams } from './views/people.js';
import { renderCalendar, renderDashboard, renderReport, renderSearch, renderTask, renderTaskForm, renderTasks } from './views/work.js';

const titles = {
  dashboard: 'Dashboard',
  tasks: 'My tasks',
  task: 'Task',
  'task-new': 'New task',
  teams: 'Teams',
  team: 'Team',
  'team-new': 'New team',
  join: 'Join',
  alerts: 'Notifications',
  profile: 'Profile',
  calendar: 'Calendar',
  search: 'Search',
  report: 'Report',
};

register({
  login: renderLogin,
  register: renderRegister,
  forgot: renderForgot,
  dashboard: renderDashboard,
  tasks: renderTasks,
  task: renderTask,
  'task-new': renderTaskForm,
  teams: renderTeams,
  team: renderTeam,
  'team-new': renderTeamForm,
  join: renderJoin,
  alerts: renderAlerts,
  profile: renderProfile,
  calendar: renderCalendar,
  search: renderSearch,
  report: renderReport,
});

function paintChrome() {
  const authed = Boolean(state.user);
  const top = document.getElementById('topbar');
  const tabs = document.getElementById('tabbar');
  const banner = document.getElementById('offline-banner');
  top.hidden = !authed;
  tabs.hidden = !authed;
  document.body.classList.toggle('guest', !authed);
  banner.hidden = !authed || (!state.offline && state.pending === 0);
  banner.textContent = state.offline
    ? 'Offline. You can view saved work and queue status updates.'
    : `${state.pending} change${state.pending === 1 ? '' : 's'} waiting to sync.`;
  top.replaceChildren(
    elRow([
      mark(),
      elText('strong', titles[state.route] || 'ORBIT'),
    ]),
    elText('button', state.pending ? String(state.pending) : '')
  );
  const syncButton = top.querySelector('button');
  if (syncButton) {
    syncButton.className = 'sync-btn';
    syncButton.type = 'button';
    syncButton.textContent = state.offline ? 'Offline' : (state.pending ? `${state.pending} pending` : 'Synced');
    syncButton.onclick = async () => {
      try {
        await syncNow();
        await show();
      } catch {
        state.offline = true;
        paintChrome();
      }
    };
  }
  tabs.replaceChildren(...[
    ['dashboard', 'Home', 'home'],
    ['tasks', 'Tasks', 'tasks'],
    ['teams', 'Teams', 'teams'],
    ['alerts', 'Alerts', 'bell'],
    ['profile', 'Profile', 'user'],
  ].map(([route, label, glyph]) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `tab ${state.route === route ? 'on' : ''}`;
    button.append(icon(glyph), document.createTextNode(label));
    button.onclick = () => navigate(route);
    return button;
  }));
}

function elRow(children) {
  const row = document.createElement('div');
  row.className = 'brand';
  children.forEach((child) => row.append(child));
  return row;
}

function elText(tag, text) {
  const node = document.createElement(tag);
  node.textContent = text;
  return node;
}

onRender(paintChrome);

window.addEventListener('hashchange', () => {
  const next = readHash();
  state.route = next.name;
  state.params = { id: next.id };
  show();
});

window.addEventListener('popstate', () => {
  const next = readHash();
  state.route = next.name;
  state.params = { id: next.id };
  show();
});

window.addEventListener('online', async () => {
  state.offline = false;
  if (state.user) {
    try { await syncNow(); } catch { /* the banner stays honest if it fails */ }
    show();
  }
});

window.addEventListener('offline', () => {
  state.offline = true;
  paintChrome();
});

window.addEventListener('orbit:logout', () => navigate('login', {}, true));

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js').catch(() => {});
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.type === 'sync' && state.user) syncNow().then(() => show()).catch(() => {});
  });
}

const engine = await db.init();
document.documentElement.dataset.storage = engine;
await loadSession();
applyTheme(state.user?.theme);
await pendingCount();
const initial = readHash();
if (!state.user && !['login', 'register', 'forgot'].includes(initial.name)) {
  navigate('login', {}, true);
} else if (state.user && ['login', 'register', 'forgot', ''].includes(initial.name)) {
  navigate('dashboard', {}, true);
} else {
  state.route = initial.name;
  state.params = { id: initial.id };
  await show();
}
if (state.user && navigator.onLine) {
  syncNow().then(() => show()).catch(() => { state.offline = true; paintChrome(); });
}
