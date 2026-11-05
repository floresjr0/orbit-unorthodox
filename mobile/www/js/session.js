import { db } from './db.js';
import { state } from './state.js';

export async function saveSession(data) {
  state.access = data.access_token;
  state.refresh = data.refresh_token;
  state.user = data.user;
  await db.setMeta('access', data.access_token);
  await db.setMeta('refresh', data.refresh_token);
  await db.setMeta('user', JSON.stringify(data.user));
}

export async function loadSession() {
  state.access = await db.meta('access');
  state.refresh = await db.meta('refresh');
  const raw = await db.meta('user');
  state.user = raw ? JSON.parse(raw) : null;
}

export async function clearSession() {
  state.access = null;
  state.refresh = null;
  state.user = null;
  await db.delete('meta', 'access');
  await db.delete('meta', 'refresh');
  await db.delete('meta', 'user');
  window.dispatchEvent(new CustomEvent('orbit:logout'));
}
