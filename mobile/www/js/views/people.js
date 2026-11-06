import { api, OfflineError } from '../api.js';
import { db } from '../db.js';
import { accountIsLeader, loadTeams } from '../data.js';
import { navigate } from '../router.js';
import { clearSession } from '../session.js';
import { applyTheme, state } from '../state.js';
import { syncNow } from '../sync.js';
import { badge, bubble, confirmDialog, el, emptyState, field, formatWhen, presentError, searchList, setBusy, skeleton, talkForm, textInput, toast } from '../ui.js';

function isDriveLink(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'].includes(url.hostname);
  } catch {
    return false;
  }
}

function settingSwitch(title, detail, on, onChange) {
  const input = el('input', { type: 'checkbox', checked: on ? 'checked' : null, onchange: () => onChange(input.checked) });
  return el('label', { class: 'setting' }, [
    el('span', { class: 'setting-copy' }, [el('strong', {}, title), el('span', {}, detail)]),
    el('span', { class: 'switch' }, [input, el('i')]),
  ]);
}

function screen(title, body, actions = []) {
  return el('section', { class: 'screen stack' }, [
    el('div', { class: 'screen-head' }, [el('h1', {}, title), el('div', { class: 'row' }, actions)]),
    body,
  ]);
}

export async function renderTeams(root) {
  root.append(skeleton());
  try {
    const all = await loadTeams();
    const teams = all.filter((team) => team.status !== 'archived');
    const leader = await accountIsLeader(all);
    const action = leader
      ? el('button', { class: 'btn primary', type: 'button', onclick: () => navigate('team-new') }, 'Create')
      : el('button', { class: 'btn ghost', type: 'button', onclick: () => navigate('join') }, 'Join a group');
    if (!teams.length) {
      root.replaceChildren(screen('Teams', emptyState('No group yet', leader ? 'Create a group to get a code.' : 'Join a group with its code.'), [action]));
      return;
    }
    const blocks = [];
    for (const team of teams) {
      const members = navigator.onLine ? (await api(`/teams/${team.id}/members`)).data : [];
      blocks.push(groupCard(team, members.filter((person) => person.status === 'active')));
    }
    root.replaceChildren(screen('Teams', el('div', { class: 'stack' }, blocks), [action]));
  } catch (error) {
    presentError(root, error, () => renderTeams(root));
  }
}

function groupCard(team, members) {
  const code = team.invitation?.code || '';
  return el('section', { class: 'stack' }, [
    el('h2', { class: 'section-title' }, team.name),
    team.role === 'leader' && code ? el('div', { class: 'card stack' }, [
      el('span', { class: 'kicker' }, 'Group code'),
      el('strong', { class: 'code' }, code),
    ]) : null,
    members.length ? searchList(members, (person) => {
      const body = [el('strong', {}, person.full_name), el('span', { class: 'muted' }, person.role === 'leader' ? 'Leader' : 'Member')];
      return team.role === 'leader'
        ? el('button', { class: 'card linkish', type: 'button', onclick: () => navigate('member', { id: team.id, user: person.id }) }, body)
        : el('div', { class: 'card' }, body);
    }, { placeholder: 'Search members', prompt: 'Search a name.' }) : el('p', { class: 'muted' }, 'No members yet.'),
  ]);
}

export async function renderTeam(root) {
  navigate('teams', {}, true);
}

function memberPicker(active, picked) {
  const chips = el('div', { class: 'picks' });
  const results = el('div', { class: 'stack' });
  const search = textInput({
    type: 'search',
    placeholder: 'Search a name to add',
    autocomplete: 'off',
    oninput: () => paint(),
    onkeydown: (event) => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      const found = matches();
      if (found.length === 1) add(found[0].id);
    },
  });

  function matches() {
    const query = search.value.trim().toLowerCase();
    if (!query) return [];
    return active.filter((item) => !picked.has(item.id) && item.full_name.toLowerCase().includes(query));
  }

  function add(id) {
    picked.add(id);
    search.value = '';
    paint();
  }

  function paint() {
    chips.replaceChildren(...[...picked].flatMap((id) => {
      const person = active.find((item) => item.id === id);
      if (!person) return [];
      return [el('button', {
        type: 'button',
        class: 'chip on',
        onclick: () => {
          picked.delete(id);
          paint();
        },
      }, `${person.full_name} ×`)];
    }));
    const query = search.value.trim();
    const found = matches();
    const shown = found.slice(0, 6);
    if (!query) {
      results.replaceChildren();
      return;
    }
    if (!shown.length) {
      results.replaceChildren(el('p', { class: 'muted' }, 'No one matches.'));
      return;
    }
    const hits = shown.map((item) => el('button', { type: 'button', class: 'card linkish', onclick: () => add(item.id) }, item.full_name));
    if (found.length > shown.length) hits.push(el('p', { class: 'muted' }, 'Keep typing to narrow the list.'));
    results.replaceChildren(...hits);
  }

  paint();
  return el('div', { class: 'stack' }, [chips, search, results]);
}

export async function renderMember(root) {
  const teamId = Number(state.params.id);
  const userId = Number(state.params.user);
  root.append(skeleton(2));
  try {
    const members = navigator.onLine ? (await api(`/teams/${teamId}/members`)).data : [];
    const person = members.find((item) => item.id === userId);
    if (!person) {
      root.replaceChildren(emptyState('Member unavailable', 'Open the group again while you are online.'));
      return;
    }
    const tasks = navigator.onLine ? (await api(`/tasks?scope=team&team_id=${teamId}&assignee_id=${userId}`)).data.items : [];
    const active = members.filter((item) => item.status === 'active');
    const picked = new Set([userId]);
    const title = textInput({ required: 'true', placeholder: 'What should they do?' });
    const guide = textInput({ type: 'url', placeholder: 'https://drive.google.com/...' });
    const due = el('input', { type: 'datetime-local' });
    const button = el('button', { class: 'btn primary', type: 'submit' }, 'Assign');
    const people = memberPicker(active, picked);
    root.replaceChildren(el('section', { class: 'screen stack' }, [
      el('h1', {}, person.full_name),
      el('p', { class: 'muted' }, person.role === 'leader' ? 'Leader' : 'Member'),
      el('h2', { class: 'section-title' }, 'Assign a task'),
      el('form', { class: 'stack', onsubmit: async (event) => {
        event.preventDefault();
        const guideLink = guide.value.trim();
        const assigneeIds = [...picked];
        if (!assigneeIds.length) {
          toast('Choose at least one member.');
          return;
        }
        if (guideLink && !isDriveLink(guideLink)) {
          toast('Paste a Google Drive link.');
          return;
        }
        setBusy(button, true);
        try {
          await api(`/teams/${teamId}/tasks`, { method: 'POST', body: {
            team_id: teamId,
            title: title.value,
            instructions: guideLink || null,
            assignee_ids: assigneeIds,
            deadline: due.value ? new Date(due.value).toISOString() : null,
          } });
          toast('Task assigned.');
          await renderMember(root);
        } catch (error) {
          toast(error.message);
          if (button.isConnected) setBusy(button, false, 'Assign');
        }
      } }, [
        field('Task', title),
        field('Instructions', guide),
        field('Due', due),
        el('div', { class: 'field' }, [el('span', {}, 'People'), people]),
        button,
      ]),
      el('h2', { class: 'section-title' }, 'Status'),
      tasks.length ? el('div', { class: 'stack' }, tasks.map((task) => el('button', { class: 'card linkish', type: 'button', onclick: () => navigate('task', { id: task.id }) }, [
        el('strong', {}, task.title),
        el('span', { class: 'muted' }, task.status === 'done' ? 'Done' : (task.status === 'pending' || task.review_status === 'awaiting' ? 'Pending' : 'Not done')),
      ]))) : el('p', { class: 'muted' }, 'Nothing assigned yet.'),
    ]));
  } catch (error) {
    presentError(root, error, () => renderMember(root));
  }
}

export async function renderTeamForm(root) {
  const teams = await loadTeams().catch(() => []);
  if (!await accountIsLeader(teams)) {
    navigate('teams', {}, true);
    return;
  }
  const name = textInput({ required: 'true', placeholder: 'Group name' });
  const button = el('button', { class: 'btn primary', type: 'submit' }, 'Create group');
  root.append(screen('New group', el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    setBusy(button, true);
    try {
      const team = (await api('/teams', { method: 'POST', body: { name: name.value } })).data;
      await db.put('teams', team);
      toast('Group code is ready. It cannot be changed.');
      navigate('teams', {}, true);
    } catch (error) {
      toast(error.message);
      setBusy(button, false, 'Create group');
    }
  } }, [
    field('Name', name),
    el('p', { class: 'muted' }, 'A group code is created with the group. It stays the same.'),
    button,
  ])));
}

export async function renderJoin(root) {
  const teams = await loadTeams().catch(() => []);
  if (await accountIsLeader(teams)) {
    navigate('teams', {}, true);
    return;
  }
  const code = textInput({ required: 'true', placeholder: 'ABCD-EFGH-IJKL', autocapitalize: 'characters' });
  const button = el('button', { class: 'btn primary', type: 'submit' }, 'Join group');
  root.append(screen('Join a group', el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    setBusy(button, true);
    try {
      const team = (await api('/invitations/join', { method: 'POST', body: { code: code.value } })).data;
      await db.put('teams', team);
      toast(team.membership_status === 'pending' ? 'Request sent to the leader.' : 'You joined the team.');
      navigate('teams', {}, true);
    } catch (error) {
      toast(error.message);
      setBusy(button, false, 'Join group');
    }
  } }, [field('Group code', code), button])));
}

export async function renderChat(root) {
  root.append(skeleton());
  try {
    const teams = (await loadTeams()).filter((team) => team.status !== 'archived' && team.membership_status !== 'pending');
    if (!teams.length) {
      root.replaceChildren(screen('Chat', emptyState('No group yet', 'Join a group before you can talk with everyone.')));
      return;
    }
    let teamId = Number(sessionStorage.getItem('orbit.group') || teams[0].id);
    if (!teams.some((team) => team.id === teamId)) teamId = teams[0].id;
    const team = teams.find((item) => item.id === teamId);
    const members = navigator.onLine ? ((await api(`/teams/${teamId}/members`)).data || []).filter((person) => person.status === 'active') : [];
    const messages = navigator.onLine ? (await api(`/teams/${teamId}/messages`)).data || [] : [];
    const names = members.map((person) => person.full_name);
    const thread = el('div', { class: 'talk' }, messages.length
      ? messages.map((item) => bubble(item, names))
      : [el('p', { class: 'muted' }, 'No messages yet. Type @ to mention someone.')]);
    root.replaceChildren(screen('Chat', el('div', { class: 'stack' }, [
      teams.length > 1 ? el('select', {
        onchange: (event) => {
          sessionStorage.setItem('orbit.group', event.target.value);
          renderChat(root);
        },
      }, teams.map((item) => el('option', { value: String(item.id), selected: item.id === teamId ? 'selected' : null }, item.name))) : el('p', { class: 'muted' }, team.name),
      thread,
      talkForm(members, 'Message the group', async (body) => {
        const message = (await api(`/teams/${teamId}/messages`, { method: 'POST', body: { body } })).data;
        thread.querySelector('.muted')?.remove();
        thread.append(bubble(message, names));
        toast('Message sent.');
      }),
    ])));
  } catch (error) {
    presentError(root, error, () => renderChat(root));
  }
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
    const items = data.items.filter((item) => {
      if (item.type === 'deadline_reminder' || item.type === 'overdue') return true;
      if (item.type === 'review_approved' || item.type === 'review_requested') return true;
      if (item.type === 'comment' || item.type === 'mention') return true;
      return item.type === 'status_changed' && (item.data?.status === 'done' || item.title?.toLowerCase().includes('done'));
    });
    root.replaceChildren(screen('Alerts', items.length ? el('div', { class: 'stack' }, items.map((item) =>
      el('button', { class: `card notice ${item.is_read ? '' : 'unread'}`, type: 'button', onclick: async () => {
        if (navigator.onLine) await api(`/notifications/${item.id}/read`, { method: 'POST' });
        if (item.data?.task_id) navigate('task', { id: item.data.task_id });
        else if (item.type === 'mention') navigate('chat');
      } }, [
        el('strong', {}, item.title),
        el('span', {}, item.body),
        el('time', { class: 'muted' }, formatWhen(item.created_at)),
      ])
    )) : emptyState('No alerts', 'You will see finished work, a close due date, a task comment, and when someone mentions you.'), [
      items.length ? el('button', { class: 'btn ghost', type: 'button', onclick: async () => { await api('/notifications/read', { method: 'POST' }); renderAlerts(root); } }, 'Mark all read') : null,
    ]));
  } catch (error) {
    presentError(root, error, () => renderAlerts(root));
  }
}

export async function renderProfile(root) {
  const user = state.user;
  if (!user) {
    navigate('login', {}, true);
    return;
  }
  let prefs = {};
  try {
    prefs = (await api('/users/me/preferences')).data;
  } catch {
    prefs = {};
  }
  const name = textInput({ value: user.full_name });
  const theme = el('select', {}, [
    ['light', 'Light'],
    ['dark', 'Dark'],
  ].map(([value, label]) => el('option', { value, selected: (user.theme === 'dark' ? 'dark' : 'light') === value ? 'selected' : null }, label)));
  theme.addEventListener('change', async () => {
    applyTheme(theme.value);
    const updated = (await api('/users/me', { method: 'PATCH', body: { theme: theme.value, full_name: name.value } })).data;
    state.user = updated;
    await db.setMeta('user', JSON.stringify(updated));
  });
  const doneKeys = ['status_changed', 'reviews'];
  const dueKeys = ['deadlines', 'reminder_24h', 'reminder_1h', 'reminder_deadline', 'overdue_reminders'];
  const saveGroup = async (keys, on) => {
    for (const key of keys) prefs[key] = on;
    if (on) prefs.in_app_enabled = true;
    try {
      prefs = (await api('/users/me/preferences', { method: 'PUT', body: prefs })).data;
    } catch (error) {
      toast(error.message);
    }
  };
  const prefBox = el('div', { class: 'settings' }, [
    settingSwitch('When someone finishes', 'A member marks work done', doneKeys.some((key) => prefs[key]), (on) => saveGroup(doneKeys, on)),
    settingSwitch('When a due date is close', 'A day before, an hour before, and if it passes', dueKeys.some((key) => prefs[key]), (on) => saveGroup(dueKeys, on)),
  ]);
  const current = textInput({ type: 'password', placeholder: 'Current password' });
  const next = textInput({ type: 'password', placeholder: 'New password' });
  const teams = (await loadTeams().catch(() => [])).filter((team) => team.role === 'leader' && team.status !== 'archived');
  root.replaceChildren(screen('Profile', el('div', { class: 'stack' }, [
    field('Full name', name),
    el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
      const updated = (await api('/users/me', { method: 'PATCH', body: { full_name: name.value, theme: theme.value } })).data;
      state.user = updated;
      await db.setMeta('user', JSON.stringify(updated));
      toast('Name saved.');
    } }, 'Save name'),
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
    el('h2', { class: 'section-title' }, 'Group code'),
    teams.length ? el('div', { class: 'stack' }, teams.map((team) => el('div', { class: 'card stack' }, [
      el('strong', {}, team.name),
      el('span', { class: 'code' }, team.invitation?.code || 'Unavailable offline'),
      el('p', { class: 'muted' }, 'This code cannot be changed.'),
      el('button', { class: 'btn danger', type: 'button', onclick: async () => {
        if (!await confirmDialog({ title: `Close ${team.name}?`, message: 'The project ends and members can no longer use this group.', confirmLabel: 'Close group', danger: true })) return;
        await api(`/teams/${team.id}/archive`, { method: 'POST', body: {} });
        toast('Group closed.');
        renderProfile(root);
      } }, 'Close group'),
    ]))) : el('p', { class: 'muted' }, 'A group code appears here after you create a group.'),
    el('button', { class: 'btn danger', type: 'button', onclick: async () => {
      await api('/auth/logout', { method: 'POST' }).catch(() => {});
      await clearSession();
    } }, 'Sign out'),
  ])));
}
