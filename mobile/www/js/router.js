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
  state.route = name;
  state.params = params || {};
  const hash = `#/${name}${params.id ? `/${params.id}` : ''}`;
  if (location.hash === hash) return show();
  if (replace) location.replace(hash);
  else location.hash = hash;
  return undefined;
}

export function readHash() {
  const raw = location.hash.replace(/^#\/?/, '');
  const [name, id] = raw.split('/');
  return { name: name || (state.user ? 'dashboard' : 'login'), id: id || null };
}

export async function show() {
  const root = document.getElementById('view');
  const view = routes[state.route] || routes.login;
  root.replaceChildren();
  root.scrollTop = 0;
  if (renderer) renderer();
  try {
    await view(root);
  } catch (error) {
    root.replaceChildren(document.createTextNode(error.message || 'Something went wrong.'));
  }
  if (renderer) renderer();
}
