import { apiBase } from './config.js';
import { clearSession, saveSession } from './session.js';
import { state } from './state.js';

export class OfflineError extends Error {
  constructor() {
    super('You are offline. This change is saved on this device.');
    this.name = 'OfflineError';
  }
}

export class ApiError extends Error {
  constructor(message, status, payload) {
    super(message);
    this.status = status;
    this.payload = payload;
  }
}

let refreshing = null;

async function refreshSession() {
  if (!state.refresh) return false;
  if (!refreshing) {
    refreshing = fetch(`${apiBase()}/auth/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Device-Name': deviceName() },
      body: JSON.stringify({ refresh_token: state.refresh }),
    }).then(async (response) => {
      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.success) return false;
      await saveSession(payload.data);
      return true;
    }).catch((error) => {
      if (error instanceof TypeError) throw new OfflineError();
      return false;
    }).finally(() => {
      refreshing = null;
    });
  }
  return refreshing;
}

function deviceName() {
  return `ORBIT ${navigator.platform || 'device'}`.slice(0, 120);
}

export async function api(path, options = {}) {
  const method = options.method || 'GET';
  const headers = { 'X-Device-Name': deviceName() };
  if (options.body && !(options.body instanceof FormData)) headers['Content-Type'] = 'application/json';
  if (options.auth !== false && state.access) headers.Authorization = `Bearer ${state.access}`;
  let response;
  try {
    response = await fetch(`${apiBase()}${path}`, {
      method,
      headers,
      body: options.body ? (options.body instanceof FormData ? options.body : JSON.stringify(options.body)) : undefined,
    });
  } catch {
    state.offline = true;
    throw new OfflineError();
  }
  if (response.status === 401 && options.auth !== false && !options.retried) {
    const ok = await refreshSession();
    if (ok) return api(path, { ...options, retried: true });
    await clearSession();
    throw new ApiError('Your session has expired. Please sign in again.', 401, null);
  }
  const payload = await response.json().catch(() => null);
  state.offline = false;
  if (!response.ok || !payload?.success) {
    throw new ApiError(payload?.message || 'Request failed.', response.status, payload);
  }
  return payload;
}

export { deviceName };
