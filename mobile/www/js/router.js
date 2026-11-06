import { state } from './state.js';

const routes = {};
let renderer = null;

export function register(map) {
  Object.assign(routes, map);
}

export function onRender(fn) {
  renderer = fn;
}

export function navigate(name, params = {}, replace = false) {
  if (name === 'task' && state.route !== 'task') {
    state.back = { route: state.route, params: { ...state.params } };
  }
  state.route = name;
  state.params = params || {};
  const tail = params.id ? `/${params.id}${params.user ? `/${params.user}` : ''}` : '';
  const hash = `#/${name}${tail}`;
  if (location.hash === hash) return show();
  if (replace) location.replace(hash);
  else location.hash = hash;
  return undefined;
}

export function readHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [name, id, user] = raw.split('/');
  return { name: name || (state.user ? 'dashboard' : 'login'), id: id || null, user: user || null };
}

let showTicket = 0;

export async function show() {
  const ticket = ++showTicket;
  const root = document.getElementById('view');
  const stage = document.createElement('div');
  const view = routes[state.route] || routes.login;
  root.replaceChildren(stage);
  root.scrollTop = 0;
  if (renderer) renderer();
  try {
    await view(stage);
  } catch (error) {
    if (ticket !== showTicket) return;
    root.replaceChildren(document.createTextNode(error.message || 'Something went wrong.'));
    if (renderer) renderer();
    return;
  }
  if (ticket !== showTicket) return;
  if (renderer) renderer();
}
