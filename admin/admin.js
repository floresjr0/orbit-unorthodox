const tokenKey = 'orbit.adminToken';
let token = sessionStorage.getItem(tokenKey);

const login = document.getElementById('login');
const desk = document.getElementById('desk');
const panel = document.getElementById('panel');
const tabs = document.getElementById('tabs');
const signout = document.getElementById('signout');

async function api(path, options = {}) {
  const headers = { ...(options.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }) };
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`/api/v1${path}`, {
    method: options.method || 'GET',
    headers,
    body: options.body instanceof FormData ? options.body : (options.body ? JSON.stringify(options.body) : undefined),
  });
  if (options.raw) return response;
  const payload = await response.json().catch(() => null);
  if (!response.ok || !payload?.success) throw new Error(payload?.message || 'Request failed.');
  return payload.data;
}

function table(headers, rows) {
  const node = document.createElement('table');
  const head = document.createElement('tr');
  headers.forEach((label) => {
    const cell = document.createElement('th');
    cell.textContent = label;
    head.append(cell);
  });
  node.append(head);
  rows.forEach((row) => {
    const line = document.createElement('tr');
    row.forEach((cell) => {
      const td = document.createElement('td');
      if (cell instanceof Node) td.append(cell);
      else td.textContent = cell ?? '';
      line.append(td);
    });
    node.append(line);
  });
  return node;
}

function button(label, action) {
  const node = document.createElement('button');
  node.type = 'button';
  node.textContent = label;
  node.addEventListener('click', action);
  return node;
}

const views = {
  Overview: async () => {
    const stats = await api('/admin/stats');
    panel.replaceChildren(table(['Metric', 'Count'], Object.entries(stats)));
  },
  Accounts: async () => {
    const data = await api('/admin/users');
    panel.replaceChildren(table(['Name', 'Email', 'Status', 'Teams', ''], data.items.map((user) => [
      user.full_name,
      user.email,
      user.status,
      String(user.team_count),
      user.is_admin ? 'Administrator' : button(user.status === 'suspended' ? 'Reactivate' : 'Suspend', async () => {
        const path = user.status === 'suspended' ? 'reactivate' : 'suspend';
        await api(`/admin/users/${user.id}/${path}`, { method: 'POST' });
        views.Accounts();
      }),
    ])));
  },
  Reports: async () => {
    const data = await api('/admin/reports');
    panel.replaceChildren(table(['Reported', 'Reason', 'Status', ''], data.items.map((item) => [
      item.reported_name,
      item.reason,
      item.status,
      item.status === 'open' ? button('Mark reviewed', async () => {
        await api(`/admin/reports/${item.id}/resolve`, { method: 'POST', body: { status: 'reviewed' } });
        views.Reports();
      }) : '',
    ])));
  },
  Audit: async () => {
    const data = await api('/admin/audit');
    panel.replaceChildren(table(['When', 'Action', 'Summary'], data.items.map((item) => [item.created_at, item.action, item.summary])));
  },
  Messages: async () => {
    const data = await api('/admin/messages');
    panel.replaceChildren(table(['From', 'Email', 'Message'], data.items.map((item) => [item.name, item.email, item.message])));
  },
  Releases: async () => {
    const releases = await api('/admin/releases');
    const form = document.createElement('form');
    form.className = 'card';
    form.innerHTML = '<input name="version" placeholder="1.0.0" required><textarea name="changelog" placeholder="Release notes" required></textarea><input name="file" type="file" accept=".apk" required><button class="btn" type="submit">Publish APK</button>';
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const body = new FormData(form);
      await api('/admin/releases', { method: 'POST', body });
      views.Releases();
    });
    const list = table(['Version', 'Downloads', 'File'], releases.map((item) => [item.version, String(item.download_count), item.available ? 'On disk' : 'Missing']));
    panel.replaceChildren(form, list);
  },
};

function showDesk() {
  login.hidden = true;
  desk.hidden = false;
  signout.hidden = false;
  tabs.replaceChildren(...Object.keys(views).map((name) => {
    const node = button(name, () => open(name));
    node.dataset.tab = name;
    return node;
  }));
  open('Overview');
}

async function open(name) {
  tabs.querySelectorAll('button').forEach((node) => node.classList.toggle('on', node.dataset.tab === name));
  panel.textContent = 'Loading…';
  try {
    await views[name]();
  } catch (error) {
    panel.textContent = error.message;
    if (error.message.includes('authorized') || error.message.includes('sign')) {
      sessionStorage.removeItem(tokenKey);
      token = null;
      desk.hidden = true;
      login.hidden = false;
    }
  }
}

login.addEventListener('submit', async (event) => {
  event.preventDefault();
  const status = document.getElementById('login-status');
  status.textContent = 'Signing in…';
  try {
    const data = await api('/auth/login', {
      method: 'POST',
      body: { email: document.getElementById('email').value, password: document.getElementById('password').value },
    });
    token = data.access_token;
    sessionStorage.setItem(tokenKey, token);
    await api('/admin/stats');
    status.textContent = '';
    showDesk();
  } catch (error) {
    sessionStorage.removeItem(tokenKey);
    token = null;
    status.textContent = error.message;
  }
});

signout.addEventListener('click', async () => {
  await api('/auth/logout', { method: 'POST' }).catch(() => {});
  sessionStorage.removeItem(tokenKey);
  token = null;
  location.reload();
});

if (token) showDesk();
