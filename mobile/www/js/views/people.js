import { api, OfflineError } from '../api.js';
import { db } from '../db.js';
import { loadTeams } from '../data.js';
import { navigate } from '../router.js';
import { clearSession } from '../session.js';
import { applyTheme, state } from '../state.js';
import { syncNow } from '../sync.js';
import { badge, confirmDialog, el, emptyState, errorState, field, formatWhen, setBusy, skeleton, textInput, toast } from '../ui.js';

function screen(title, body, actions = []) {
  return el('section', { class: 'screen stack' }, [
    el('div', { class: 'screen-head' }, [el('h1', {}, title), el('div', { class: 'row' }, actions)]),
    body,
  ]);
}

export async function renderTeams(root) {
  root.append(skeleton());
  try {
    const teams = await loadTeams();
    root.replaceChildren(screen('Teams', teams.length ? el('div', { class: 'stack' }, teams.map((team) =>
      el('button', { class: 'card linkish', type: 'button', onclick: () => navigate('team', { id: team.id }) }, [
        el('strong', {}, team.name),
        el('span', { class: 'muted' }, `${team.role} · ${team.member_count ?? 0} members${team.membership_status === 'pending' ? ' · waiting for approval' : ''}`),
      ])
    )) : emptyState('No teams yet', 'Create a team or join one with an invitation code.'), [
      el('button', { class: 'btn ghost', type: 'button', onclick: () => navigate('join') }, 'Join'),
      el('button', { class: 'btn primary', type: 'button', onclick: () => navigate('team-new') }, 'Create'),
    ]));
  } catch (error) {
    root.replaceChildren(errorState(error.message, () => renderTeams(root)));
  }
}

export async function renderTeam(root) {
  const id = Number(state.params.id);
  root.append(skeleton());
  try {
    const team = navigator.onLine ? (await api(`/teams/${id}`)).data : (await db.all('teams')).find((item) => item.id === id);
    if (!team) {
      root.replaceChildren(emptyState('Team not on this device', 'Open it once while online.'));
      return;
    }
    await db.put('teams', team);
    const members = navigator.onLine ? (await api(`/teams/${id}/members`)).data : [];
    const announcements = navigator.onLine ? (await api(`/teams/${id}/announcements`)).data : (await db.all('announcements')).filter((item) => item.team_id === id);
    const leader = team.role === 'leader';
    const blocks = [
      el('button', { class: 'link back', type: 'button', onclick: () => history.back() }, 'Back'),
      el('h1', {}, team.name),
      team.description ? el('p', {}, team.description) : null,
      leader && team.invitation ? el('div', { class: 'card stack' }, [
        el('span', { class: 'kicker' }, 'Invitation'),
        el('strong', { class: 'code' }, team.invitation.code),
        el('span', { class: 'muted' }, team.invitation.is_active ? `${team.invitation.use_count} uses` : 'Invitations are off'),
        el('div', { class: 'row' }, [
          el('button', { class: 'btn ghost', type: 'button', onclick: async () => { await navigator.clipboard?.writeText(team.invitation.code); toast('Code copied.'); } }, 'Copy'),
          el('button', { class: 'btn ghost', type: 'button', onclick: async () => { await api(`/teams/${id}/invitation/regenerate`, { method: 'POST' }); renderTeam(root); } }, 'New code'),
        ]),
      ]) : null,
      el('h2', { class: 'section-title' }, 'Members'),
      ...members.map((person) => el('div', { class: 'person' }, [
        el('div', {}, [
          el('strong', {}, person.full_name),
          el('span', { class: 'muted' }, `${person.role} · ${person.completed_count}/${person.assigned_count} tasks${person.current_activity ? ` · ${person.current_activity}` : ''}`),
        ]),
        person.status === 'pending' && leader ? el('button', { class: 'btn primary', type: 'button', onclick: async () => { await api(`/teams/${id}/members/${person.id}/approve`, { method: 'POST' }); renderTeam(root); } }, 'Approve') : null,
        leader && person.role !== 'leader' ? el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
          if (!await confirmDialog({ title: `Remove ${person.full_name}?`, message: 'They will lose access to this team.', confirmLabel: 'Remove', danger: true })) return;
          await api(`/teams/${id}/members/${person.id}`, { method: 'DELETE' });
          renderTeam(root);
        } }, 'Remove') : null,
      ])),
      el('h2', { class: 'section-title' }, 'Announcements'),
      ...announcements.map((item) => el('article', { class: 'card' }, [item.is_important ? badge('urgent', 'Important') : null, el('h3', {}, item.title), el('p', {}, item.body)])),
    ];
    if (leader) {
      const title = textInput({ placeholder: 'Announcement title' });
      const message = el('textarea', { rows: '3', placeholder: 'Message' });
      blocks.push(el('form', { class: 'stack', onsubmit: async (event) => {
        event.preventDefault();
        await api(`/teams/${id}/announcements`, { method: 'POST', body: { title: title.value, body: message.value, is_important: true } });
        renderTeam(root);
      } }, [title, message, el('button', { class: 'btn primary', type: 'submit' }, 'Publish')]));
      blocks.push(el('button', { class: 'btn ghost', type: 'button', onclick: () => navigate('report') }, 'Open report'));
    } else {
      blocks.push(el('button', { class: 'btn danger', type: 'button', onclick: async () => {
        if (!await confirmDialog({ title: 'Leave this team?', message: 'You will keep your account, and lose access to this team.', confirmLabel: 'Leave', danger: true })) return;
        await api(`/teams/${id}/leave`, { method: 'POST' });
        navigate('teams');
      } }, 'Leave team'));
    }
    root.replaceChildren(el('section', { class: 'screen stack' }, blocks));
  } catch (error) {
    root.replaceChildren(errorState(error.message, () => renderTeam(root)));
  }
}

export function renderTeamForm(root) {
  const name = textInput({ required: 'true', placeholder: 'Team name' });
  const description = el('textarea', { rows: '3', placeholder: 'What is this team for?' });
  const category = textInput({ placeholder: 'Academic, product, studio…' });
  const approval = el('input', { type: 'checkbox' });
  const verify = el('input', { type: 'checkbox' });
  const button = el('button', { class: 'btn primary', type: 'submit' }, 'Create team');
  root.append(screen('New team', el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    setBusy(button, true);
    try {
      const team = (await api('/teams', { method: 'POST', body: {
        name: name.value,
        description: description.value,
        category: category.value,
        require_join_approval: approval.checked,
        require_task_verification: verify.checked,
      } })).data;
      await db.put('teams', team);
      navigate('team', { id: team.id }, true);
    } catch (error) {
      toast(error.message);
      setBusy(button, false, 'Create team');
    }
  } }, [
    field('Name', name),
    field('Description', description),
    field('Category', category),
    el('label', { class: 'check' }, [approval, 'Approve new members']),
    el('label', { class: 'check' }, [verify, 'Review completed tasks']),
    button,
  ])));
}

export function renderJoin(root) {
  const code = textInput({ required: 'true', placeholder: 'ABCD-EFGH-IJKL', autocapitalize: 'characters' });
  const button = el('button', { class: 'btn primary', type: 'submit' }, 'Join team');
  root.append(screen('Join a team', el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    setBusy(button, true);
    try {
      const team = (await api('/invitations/join', { method: 'POST', body: { code: code.value } })).data;
      await db.put('teams', team);
      toast(team.membership_status === 'pending' ? 'Request sent to the leader.' : 'You joined the team.');
      navigate('team', { id: team.id }, true);
    } catch (error) {
      toast(error.message);
      setBusy(button, false, 'Join team');
    }
  } }, [field('Invitation code', code), button])));
}

export async function renderAlerts(root) {
  root.append(skeleton());
  try {
    let data = { items: [], unread_count: 0 };
    if (navigator.onLine) {
      try {
        data = (await api('/notifications')).data;
        for (const item of data.items) await db.put('notifications', item);
      } catch (error) {
        if (!(error instanceof OfflineError)) throw error;
        data.items = await db.all('notifications');
      }
    } else {
      data.items = await db.all('notifications');
    }
    root.replaceChildren(screen('Notifications', data.items.length ? el('div', { class: 'stack' }, data.items.map((item) =>
      el('button', { class: `card notice ${item.is_read ? '' : 'unread'}`, type: 'button', onclick: async () => {
        if (navigator.onLine) await api(`/notifications/${item.id}/read`, { method: 'POST' });
        if (item.data?.task_id) navigate('task', { id: item.data.task_id });
      } }, [
        el('strong', {}, item.title),
        el('span', {}, item.body),
        el('time', { class: 'muted' }, formatWhen(item.created_at)),
      ])
    )) : emptyState('You are caught up', 'Assignments, comments, and reminders will land here.'), [
      el('button', { class: 'btn ghost', type: 'button', onclick: async () => { await api('/notifications/read', { method: 'POST' }); renderAlerts(root); } }, 'Mark all read'),
    ]));
  } catch (error) {
    root.replaceChildren(errorState(error.message, () => renderAlerts(root)));
  }
}

export async function renderProfile(root) {
  const user = state.user;
  let prefs = {};
  try {
    prefs = (await api('/users/me/preferences')).data;
  } catch {
    prefs = {};
  }
  const name = textInput({ value: user.full_name });
  const theme = el('select', {}, ['system', 'light', 'dark'].map((value) => el('option', { value, selected: (user.theme || 'system') === value ? 'selected' : null }, value)));
  theme.addEventListener('change', async () => {
    applyTheme(theme.value);
    const updated = (await api('/users/me', { method: 'PATCH', body: { theme: theme.value, full_name: name.value } })).data;
    state.user = updated;
    await db.setMeta('user', JSON.stringify(updated));
  });
  const prefBox = el('div', { class: 'stack' }, Object.entries(prefs).map(([key, value]) => {
    const input = el('input', { type: 'checkbox', checked: value ? 'checked' : null, onchange: async () => {
      prefs[key] = input.checked;
      await api('/users/me/preferences', { method: 'PUT', body: prefs });
      toast('Preferences saved.');
    } });
    return el('label', { class: 'check' }, [input, key.replaceAll('_', ' ')]);
  }));
  const current = textInput({ type: 'password', placeholder: 'Current password' });
  const next = textInput({ type: 'password', placeholder: 'New password' });
  root.append(screen('Profile', el('div', { class: 'stack' }, [
    el('p', { class: 'muted' }, user.email),
    field('Name', name),
    el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
      const updated = (await api('/users/me', { method: 'PATCH', body: { full_name: name.value, theme: theme.value } })).data;
      state.user = updated;
      await db.setMeta('user', JSON.stringify(updated));
      toast('Profile saved.');
    } }, 'Save profile'),
    field('Appearance', theme),
    el('h2', { class: 'section-title' }, 'Notifications'),
    prefBox,
    el('h2', { class: 'section-title' }, 'Password'),
    field('Current', current),
    field('New', next),
    el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
      try {
        await api('/users/me/password', { method: 'POST', body: { current_password: current.value, password: next.value, password_confirmation: next.value } });
        await clearSession();
      } catch (error) {
        toast(error.message);
      }
    } }, 'Change password'),
    el('button', { class: 'btn ghost', type: 'button', onclick: async () => { await syncNow().catch((error) => toast(error.message)); toast('Sync finished.'); } }, 'Sync now'),
    el('h2', { class: 'section-title' }, 'Sessions'),
    el('div', { id: 'sessions', class: 'stack' }, [el('button', { class: 'btn ghost', type: 'button', onclick: async (event) => {
      const box = event.currentTarget.parentElement;
      const sessions = (await api('/auth/sessions')).data;
      box.replaceChildren(...sessions.map((item) => el('div', { class: 'person' }, [
        el('div', {}, [el('strong', {}, item.device_name || 'Device'), el('span', { class: 'muted' }, item.current ? 'This device' : formatWhen(item.last_used_at))]),
        item.current ? null : el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
          await api(`/auth/sessions/${item.id}`, { method: 'DELETE' });
          toast('Session revoked.');
        } }, 'Revoke'),
      ])));
    } }, 'Show sessions')]),
    el('button', { class: 'btn ghost', type: 'button', onclick: () => navigate('calendar') }, 'Calendar'),
    el('button', { class: 'btn ghost', type: 'button', onclick: () => navigate('report') }, 'Reports'),
    el('button', { class: 'btn danger', type: 'button', onclick: async () => {
      await api('/auth/logout', { method: 'POST' }).catch(() => {});
      await clearSession();
    } }, 'Sign out'),
    el('button', { class: 'btn danger', type: 'button', onclick: async () => {
      if (!await confirmDialog({ title: 'Delete this account?', message: 'This removes your profile and signs out every device. Transfer leadership first.', confirmLabel: 'Delete account', danger: true })) return;
      const current = prompt('Current password');
      if (!current) return;
      try {
        await api('/users/me', { method: 'DELETE', body: { current_password: current } });
        await clearSession();
      } catch (error) {
        toast(error.message);
      }
    } }, 'Delete account'),
  ])));
}
