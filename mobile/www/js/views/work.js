import { api, ApiError, OfflineError } from '../api.js';
import { apiBase } from '../config.js';
import { state } from '../state.js';
import { db } from '../db.js';
import { accountIsLeader, isLeader, loadTasks, loadTeams } from '../data.js';
import { navigate } from '../router.js';
import { queueComment, queueStatus, syncNow } from '../sync.js';
import {
  PRIORITY_LABEL, STATUS_LABEL, badge, confirmDialog, el, emptyState, field, presentError,
  bubble, formatWhen, searchList, setBusy, skeleton, statusBadge, talkForm, taskCard, textInput, toast,
} from '../ui.js';

function screen(title, body, actions = []) {
  return el('section', { class: 'screen stack' }, [
    el('div', { class: 'screen-head' }, [el('h1', {}, title), el('div', { class: 'row' }, actions)]),
    body,
  ]);
}

export async function renderDashboard(root) {
  root.append(skeleton(3));
  try {
    const all = await loadTeams();
    const teams = all.filter((team) => team.status !== 'archived' && team.membership_status !== 'pending');
    const leader = await accountIsLeader(all);
    const led = teams.filter((team) => team.role === 'leader');
    if (!led.length) {
      const tasks = await loadTasks('scope=mine');
      const action = leader
        ? el('button', { class: 'btn primary', type: 'button', onclick: () => navigate('team-new') }, 'Create a group')
        : el('button', { class: 'btn primary', type: 'button', onclick: () => navigate('join') }, 'Join a group');
      root.replaceChildren(screen('My work', taskGroups(tasks), [action]));
      return;
    }
    let teamId = Number(sessionStorage.getItem('orbit.group') || led[0].id);
    if (!led.some((team) => team.id === teamId)) teamId = led[0].id;
    const team = led.find((item) => item.id === teamId);
    const members = navigator.onLine ? (await api(`/teams/${teamId}/members`)).data : [];
    const active = members.filter((person) => person.status === 'active');
    const code = team.invitation?.code || '';
    root.replaceChildren(screen(team.name, el('div', { class: 'stack' }, [
      led.length > 1 ? el('select', {
        onchange: (event) => {
          sessionStorage.setItem('orbit.group', event.target.value);
          renderDashboard(root);
        },
      }, led.map((item) => el('option', { value: String(item.id), selected: item.id === teamId ? 'selected' : null }, item.name))) : null,
      el('div', { class: 'stat' }, [el('span', {}, 'Members'), el('strong', {}, String(active.length))]),
      code ? el('div', { class: 'card stack' }, [
        el('span', { class: 'kicker' }, 'Group code'),
        el('strong', { class: 'code' }, code),
        el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
          await navigator.clipboard?.writeText(code);
          toast('Code copied.');
        } }, 'Copy code'),
      ]) : null,
      el('h2', { class: 'section-title' }, 'Members'),
      active.length ? searchList(active, (person) => memberRow(teamId, person), { placeholder: 'Search members', prompt: 'Search a name to open a member.' }) : el('p', { class: 'muted' }, 'Share the group code so people can join.'),
    ])));
  } catch (error) {
    presentError(root, error, () => renderDashboard(root));
  }
}

function memberRow(teamId, person) {
  const open = Math.max(0, (person.assigned_count || 0) - (person.completed_count || 0));
  return el('button', { class: 'card linkish', type: 'button', onclick: () => navigate('member', { id: teamId, user: person.id }) }, [
    el('strong', {}, person.full_name),
    el('span', { class: 'muted' }, `${person.completed_count || 0} done · ${open} open`),
  ]);
}

function stat(label, value) {
  return el('div', { class: 'stat' }, [el('span', {}, label), el('strong', {}, String(value ?? 0))]);
}

function section(title, nodes, empty) {
  return el('section', { class: 'stack' }, [
    el('h2', { class: 'section-title' }, title),
    nodes.length ? el('div', { class: 'stack' }, nodes) : el('p', { class: 'muted' }, empty),
  ]);
}

function activityLine(item) {
  return el('div', { class: 'activity' }, [
    el('strong', {}, item.actor_name),
    el('span', {}, item.summary),
    el('time', { class: 'muted' }, formatWhen(item.created_at)),
  ]);
}

export async function renderTasks(root) {
  root.append(skeleton());
  try {
    const teams = await loadTeams().catch(() => []);
    const tasks = await loadTasks(isLeader(teams) ? 'scope=team' : 'scope=mine');
    root.replaceChildren(screen('Tasks', taskGroups(tasks)));
  } catch (error) {
    presentError(root, error, () => renderTasks(root));
  }
}

function taskGroups(tasks) {
  const done = tasks.filter((task) => task.status === 'done');
  const pending = tasks.filter((task) => task.status !== 'done' && (task.status === 'pending' || task.review_status === 'awaiting'));
  const open = tasks.filter((task) => task.status !== 'done' && task.status !== 'pending' && task.review_status !== 'awaiting');
  if (!tasks.length) return emptyState('No tasks yet', 'Open a member and assign them a task.');
  return el('div', { class: 'stack' }, [
    taskSection('Done', done),
    taskSection('Not done', open),
    taskSection('Pending', pending),
  ]);
}

function taskSection(title, tasks) {
  return el('section', { class: 'stack' }, [
    el('h2', { class: 'section-title' }, title),
    tasks.length ? el('div', { class: 'stack' }, tasks.map(assignmentCard)) : el('p', { class: 'muted' }, 'None'),
  ]);
}

function assignmentCard(task) {
  const names = (task.assignees || []).map((person) => person.full_name).filter(Boolean);
  return el('button', { class: 'card linkish', type: 'button', onclick: () => navigate('task', { id: task.id }) }, [
    el('strong', {}, task.title),
    el('span', { class: 'muted' }, names.length ? names.join(', ') : 'Unassigned'),
  ]);
}

export async function renderTask(root) {
  const id = Number(state.params.id);
  root.append(skeleton(2));
  try {
    let task = null;
    if (navigator.onLine) {
      try {
        task = (await api(`/tasks/${id}`)).data;
        await db.put('tasks', { ...task, sync_state: 'synced', server_status: task.status });
      } catch (error) {
        if (!(error instanceof OfflineError)) throw error;
      }
    }
    if (!task) task = await db.get('tasks', id);
    if (!task) {
      root.replaceChildren(emptyState('Task unavailable', 'This task is not on this device yet.'));
      return;
    }
    const teams = await loadTeams().catch(() => []);
    const team = teams.find((item) => item.id === task.team_id);
    const leader = team?.role === 'leader';
    const names = (task.assignees || []).map((person) => person.full_name).filter(Boolean);
    const current = task.status === 'done' ? 'done' : (task.status === 'pending' || task.review_status === 'awaiting' ? 'pending' : 'open');
    const drive = textInput({ type: 'url', placeholder: 'https://drive.google.com/...', value: task.work_link || '' });
    const guide = textInput({ type: 'url', placeholder: 'https://drive.google.com/...', value: isDriveLink(task.instructions || '') ? task.instructions : '' });
    const view = el('div', { class: 'stack' }, [
      el('h1', {}, task.title),
      statusBadge(task),
      el('p', { class: 'muted' }, names.length ? names.join(', ') : 'Unassigned'),
      task.deadline ? el('p', { class: 'muted' }, `Due ${formatWhen(task.deadline)}`) : null,
      task.sync_state === 'conflict' ? conflictCard(task) : null,
      instructionView(task.instructions, leader, guide, task),
      task.work_link ? el('a', { class: 'btn ghost', href: task.work_link, target: '_blank', rel: 'noopener noreferrer' }, 'Open submitted work') : (leader ? el('p', { class: 'muted' }, 'No submitted work yet.') : null),
      leader ? null : field('Google Drive link', drive),
      el('div', { class: 'chips' }, [
        ['open', 'Not done', 'todo'],
        ['pending', 'Pending', 'pending'],
        ['done', 'Done', 'done'],
      ].map(([key, label, status]) => el('button', {
        class: `chip ${current === key ? 'on' : ''}`,
        type: 'button',
        onclick: () => {
          if (status !== 'done' || leader) {
            updateStatus(task, status);
            return;
          }
          const link = drive.value.trim();
          if (link && !isDriveLink(link)) {
            toast('Paste a Google Drive link.');
            return;
          }
          if (!link && !task.work_link) {
            toast('Paste a Google Drive link to mark this done.');
            return;
          }
          updateStatus(task, 'done', link || null);
        },
      }, label))),
      leader && task.review_status === 'awaiting' ? el('button', { class: 'btn primary', type: 'button', onclick: async () => {
        await api(`/tasks/${task.id}/review`, { method: 'POST', body: { decision: 'approved', feedback: '' } });
        toast('Marked done.');
        navigate('task', { id: task.id }, true);
      } }, 'Accept as done') : null,
      el('h2', { class: 'section-title' }, 'Talk'),
    ]);
    let comments = [];
    let members = [];
    try {
      comments = (await api(`/tasks/${id}/comments`)).data || [];
      members = ((await api(`/teams/${task.team_id}/members`)).data || []).filter((person) => person.status === 'active');
    } catch (error) {
      if (!(error instanceof OfflineError)) toast(error.message);
    }
    const memberNames = members.map((person) => person.full_name);
    const canTalk = leader || (task.assignees || []).some((person) => person.id === state.user?.id);
    view.append(
      comments.length ? el('div', { class: 'talk' }, comments.map((item) => bubble(item, memberNames))) : el('p', { class: 'muted' }, 'No comments yet.'),
      canTalk
        ? talkForm(members, 'Write a comment', async (body) => {
          await api(`/tasks/${id}/comments`, { method: 'POST', body: { body } });
          toast('Comment sent.');
          await renderTask(root);
        })
        : el('p', { class: 'muted' }, 'The leader and the people on this task can reply.'),
    );
    root.replaceChildren(view);
  } catch (error) {
    presentError(root, error, () => renderTask(root));
  }
}

function drivePreviewUrl(value) {
  try {
    const url = new URL(value);
    const file = url.pathname.match(/\/file\/d\/([^/]+)/);
    if (file) return `https://drive.google.com/file/d/${file[1]}/preview`;
    const doc = url.pathname.match(/\/(document|spreadsheets|presentation)\/d\/([^/]+)/);
    if (doc) return `https://${url.hostname}/${doc[1]}/d/${doc[2]}/preview`;
    const id = url.searchParams.get('id');
    if (id && url.hostname === 'drive.google.com') return `https://drive.google.com/file/d/${id}/preview`;
  } catch {
    return null;
  }
  return null;
}

function instructionView(link, leader, guide, task) {
  const preview = isDriveLink(link || '') ? drivePreviewUrl(link) : null;
  const blocks = [el('h2', { class: 'section-title' }, 'Instructions')];
  if (preview) {
    blocks.push(el('iframe', { class: 'preview', src: preview, title: 'Instructions', loading: 'lazy' }));
    blocks.push(el('a', { class: 'btn ghost', href: link, target: '_blank', rel: 'noopener noreferrer' }, 'Open instructions'));
  }
  if (leader) {
    blocks.push(field('Instruction link', guide));
    blocks.push(el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
      const next = guide.value.trim();
      if (next && !isDriveLink(next)) {
        toast('Paste a Google Drive link.');
        return;
      }
      try {
        await api(`/tasks/${task.id}`, { method: 'PATCH', body: { instructions: next } });
        toast('Instructions saved.');
        navigate('task', { id: task.id }, true);
      } catch (error) {
        toast(error.message);
      }
    } }, 'Save instructions'));
  } else if (!preview) {
    return null;
  }
  return el('div', { class: 'stack' }, blocks);
}

function isDriveLink(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && ['drive.google.com', 'docs.google.com', 'drive.usercontent.google.com'].includes(url.hostname);
  } catch {
    return false;
  }
}

async function updateStatus(task, status, note = null) {
  if (note) task.work_link = note;
  await queueStatus(task, status, note);
  try {
    await syncNow();
    toast('Status updated.');
  } catch (error) {
    if (error instanceof OfflineError) toast('Saved on this device.');
    else toast(error.message);
  }
  navigate('task', { id: task.id }, true);
}

function conflictCard(task) {
  return el('div', { class: 'card conflict' }, [
    el('strong', {}, 'This task changed somewhere else'),
    el('p', {}, 'Your offline edit was kept apart from the newer server copy.'),
    el('div', { class: 'row' }, [
      el('button', { class: 'btn ghost', type: 'button', onclick: async () => {
        if (!task.server_copy) return;
        await db.put('tasks', { ...task.server_copy, sync_state: 'synced', server_status: task.server_copy.status });
        navigate('task', { id: task.id }, true);
      } }, 'Keep server'),
      el('button', { class: 'btn primary', type: 'button', onclick: async () => {
        const server = task.server_copy;
        if (!server) return;
        task.version = server.version;
        task.server_status = server.status;
        task.sync_state = 'synced';
        await queueStatus(task, task.status);
        try { await syncNow(); } catch (error) { toast(error.message); }
        navigate('task', { id: task.id }, true);
      } }, 'Apply my status'),
    ]),
  ]);
}

function checklistBlock(task) {
  const items = task.checklist?.items || [];
  if (!items.length) return null;
  return el('div', { class: 'stack' }, [
    el('h2', { class: 'section-title' }, `Checklist ${task.checklist.done}/${task.checklist.total}`),
    ...items.map((item) => el('label', { class: 'check' }, [
      el('input', { type: 'checkbox', checked: item.is_done ? 'checked' : null, onchange: async (event) => {
        try {
          const updated = (await api(`/checklist-items/${item.id}`, { method: 'PATCH', body: { is_done: event.target.checked, base_is_done: item.is_done } })).data;
          await db.put('tasks', { ...updated, sync_state: 'synced', server_status: updated.status });
          navigate('task', { id: task.id }, true);
        } catch (error) {
          if (error instanceof OfflineError) toast('Checklist changes need a connection right now.');
          else toast(error.message);
          event.target.checked = item.is_done;
        }
      } }),
      el('span', {}, item.content),
    ])),
  ]);
}

function reviewBox(task) {
  const feedback = el('textarea', { rows: '3', placeholder: 'Feedback for the member' });
  return el('form', { class: 'card stack', onsubmit: async (event) => {
    event.preventDefault();
    const decision = event.submitter?.dataset.decision;
    try {
      await api(`/tasks/${task.id}/review`, { method: 'POST', body: { decision, feedback: feedback.value, return_status: 'in_progress' } });
      toast(decision === 'approved' ? 'Completion approved.' : 'Sent back to the member.');
      navigate('task', { id: task.id }, true);
    } catch (error) {
      toast(error.message);
    }
  } }, [
    el('strong', {}, 'Review completion'),
    feedback,
    el('div', { class: 'row' }, [
      el('button', { class: 'btn primary', type: 'submit', dataset: { decision: 'approved' } }, 'Approve'),
      el('button', { class: 'btn ghost', type: 'submit', dataset: { decision: 'rejected' } }, 'Reject'),
    ]),
  ]);
}

export async function renderTaskForm(root) {
  const teams = (await loadTeams()).filter((team) => team.role === 'leader');
  if (!teams.length) {
    root.append(emptyState('Leaders create tasks', 'Create a team before assigning work.'));
    return;
  }
  const teamSelect = el('select', {}, teams.map((team) => el('option', { value: String(team.id) }, team.name)));
  const membersBox = el('div', { class: 'stack' });
  let members = [];
  const loadMembers = async () => {
    members = (await api(`/teams/${teamSelect.value}/members`)).data.filter((person) => person.status === 'active');
    membersBox.replaceChildren(...members.map((person) => el('label', { class: 'check' }, [
      el('input', { type: 'checkbox', value: String(person.id), checked: 'checked' }),
      person.full_name,
    ])));
  };
  teamSelect.addEventListener('change', () => loadMembers().catch((error) => toast(error.message)));
  await loadMembers();
  const title = textInput({ required: 'true', placeholder: 'Task title' });
  const description = el('textarea', { rows: '4', placeholder: 'What does done look like?' });
  const priority = el('select', {}, Object.entries(PRIORITY_LABEL).map(([value, label]) => el('option', { value, selected: value === 'medium' ? 'selected' : null }, label)));
  const deadline = textInput({ type: 'datetime-local' });
  const checklist = textInput({ placeholder: 'Checklist items, separated by commas' });
  const verify = el('input', { type: 'checkbox' });
  const button = el('button', { class: 'btn primary', type: 'submit' }, 'Create task');
  root.append(screen('New task', el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    const assignee_ids = [...membersBox.querySelectorAll('input:checked')].map((input) => Number(input.value));
    setBusy(button, true);
    try {
      const task = (await api(`/teams/${teamSelect.value}/tasks`, { method: 'POST', body: {
        title: title.value,
        description: description.value,
        priority: priority.value,
        deadline: deadline.value ? new Date(deadline.value).toISOString() : null,
        assignee_ids,
        checklist: checklist.value.split(',').map((item) => item.trim()).filter(Boolean),
        requires_verification: verify.checked,
      } })).data;
      await db.put('tasks', { ...task, sync_state: 'synced', server_status: task.status });
      navigate('task', { id: task.id }, true);
    } catch (error) {
      toast(error.message);
      setBusy(button, false, 'Create task');
    }
  } }, [
    field('Team', teamSelect),
    field('Title', title),
    field('Description', description),
    field('Priority', priority),
    field('Deadline', deadline),
    field('Assign to', membersBox),
    field('Checklist', checklist),
    el('label', { class: 'check' }, [verify, 'Require leader approval']),
    button,
  ])));
}

export async function renderCalendar(root) {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), 1);
  const end = new Date(now.getFullYear(), now.getMonth() + 1, 0, 23, 59, 59);
  let tasks = [];
  try {
    if (navigator.onLine) {
      tasks = (await api(`/calendar?from=${encodeURIComponent(start.toISOString())}&to=${encodeURIComponent(end.toISOString())}&scope=mine`)).data;
      await cacheTasks(tasks);
    } else {
      tasks = (await db.all('tasks')).filter((task) => task.deadline && new Date(task.deadline) >= start && new Date(task.deadline) <= end);
    }
  } catch (error) {
    if (!(error instanceof OfflineError)) {
      presentError(root, error, () => renderCalendar(root), 'append');
      return;
    }
  }
  const byDay = new Map();
  for (const task of tasks) {
    const key = new Date(task.deadline || task.start_date).toDateString();
    byDay.set(key, (byDay.get(key) || []).concat(task));
  }
  const cells = [];
  for (let day = 1; day <= end.getDate(); day += 1) {
    const date = new Date(now.getFullYear(), now.getMonth(), day);
    const items = byDay.get(date.toDateString()) || [];
    cells.push(el('div', { class: `day ${items.length ? 'has' : ''}` }, [
      el('span', {}, String(day)),
      items[0] ? el('small', {}, items[0].title) : null,
    ]));
  }
  root.append(screen('Calendar', el('div', { class: 'stack' }, [
    el('div', { class: 'calendar' }, cells),
    ...tasks.map((task) => taskCard(task, () => navigate('task', { id: task.id }))),
  ])));
}

export async function renderSearch(root) {
  const input = textInput({ placeholder: 'Tasks, people, teams, announcements' });
  const results = el('div', { class: 'stack' });
  input.addEventListener('input', async () => {
    if (input.value.trim().length < 2) {
      results.replaceChildren(el('p', { class: 'muted' }, 'Type at least 2 characters.'));
      return;
    }
    try {
      const data = (await api(`/search?q=${encodeURIComponent(input.value.trim())}`)).data;
      const blocks = [];
      for (const task of data.tasks) blocks.push(taskCard(task, () => navigate('task', { id: task.id })));
      for (const team of data.teams) blocks.push(el('button', { class: 'card linkish', type: 'button', onclick: () => navigate('team', { id: team.id }) }, team.name));
      for (const person of data.members) blocks.push(el('p', { class: 'card' }, `${person.full_name} · ${person.team_name}`));
      for (const item of data.announcements) blocks.push(el('p', { class: 'card' }, item.title));
      results.replaceChildren(...(blocks.length ? blocks : [el('p', { class: 'muted' }, 'No matches.')]));
    } catch (error) {
      results.replaceChildren(el('p', { class: 'muted' }, error.message));
    }
  });
  root.append(screen('Search', el('div', { class: 'stack' }, [input, results])));
}

export async function renderReport(root) {
  const teams = (await loadTeams()).filter((team) => team.role === 'leader');
  if (!teams.length) {
    root.append(emptyState('Reports are for leaders', 'Lead a team to export completion and workload.'));
    return;
  }
  const select = el('select', {}, teams.map((team) => el('option', { value: String(team.id) }, team.name)));
  const body = el('div', { class: 'stack' });
  const draw = async () => {
    body.replaceChildren(skeleton(2));
    try {
      const report = (await api(`/teams/${select.value}/report`)).data;
      body.replaceChildren(
        el('div', { class: 'stats' }, [
          stat('Tasks', report.summary.total_tasks),
          stat('Done', report.summary.completed),
          stat('Rate', `${report.summary.completion_rate}%`),
          stat('Overdue', report.summary.overdue),
        ]),
        ...report.members.map((person) => el('div', { class: 'person' }, [
          el('strong', {}, person.full_name),
          el('span', { class: 'muted' }, `${person.completed_count} of ${person.assigned_count} complete`),
        ])),
        el('button', { class: 'btn primary', type: 'button', onclick: () => downloadCsv(select.value) }, 'Download CSV')
      );
    } catch (error) {
      presentError(body, error, draw);
    }
  };
  select.addEventListener('change', draw);
  root.append(screen('Report', el('div', { class: 'stack' }, [select, body])));
  draw();
}

async function downloadCsv(teamId) {
  const response = await fetch(`${apiBase()}/teams/${teamId}/report.csv`, {
    headers: { Authorization: `Bearer ${state.access}` },
  });
  if (!response.ok) {
    toast('The report could not be downloaded.');
    return;
  }
  const blob = await response.blob();
  const url = URL.createObjectURL(blob);
  const link = el('a', { href: url, download: `orbit-team-${teamId}.csv` });
  link.click();
  URL.revokeObjectURL(url);
}
