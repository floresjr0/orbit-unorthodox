import { api, ApiError, OfflineError } from '../api.js';
import { apiBase } from '../config.js';
import { state } from '../state.js';
import { db } from '../db.js';
import { isLeader, loadTasks, loadTeams } from '../data.js';
import { navigate } from '../router.js';
import { queueComment, queueStatus, syncNow } from '../sync.js';
import {
  PRIORITY_LABEL, STATUS_LABEL, badge, confirmDialog, el, emptyState, errorState, field,
  formatWhen, setBusy, skeleton, statusBadge, taskCard, textInput, toast,
} from '../ui.js';

function screen(title, body, actions = []) {
  return el('section', { class: 'screen stack' }, [
    el('div', { class: 'screen-head' }, [el('h1', {}, title), el('div', { class: 'row' }, actions)]),
    body,
  ]);
}

export async function renderDashboard(root) {
  root.append(skeleton(4));
  try {
    let data = null;
    if (navigator.onLine) {
      try {
        data = (await api('/dashboard')).data;
        await db.setMeta('dashboard', JSON.stringify(data));
      } catch (error) {
        if (!(error instanceof OfflineError)) throw error;
      }
    }
    if (!data) {
      const cached = await db.meta('dashboard');
      data = cached ? JSON.parse(cached) : null;
    }
    root.replaceChildren();
    if (!data) {
      root.append(emptyState('Your workspace is empty', 'Connect once to load teams and tasks.'));
      return;
    }
    const leader = data.leader;
    const member = data.member;
    const blocks = [];
    if (!navigator.onLine) blocks.push(el('p', { class: 'note' }, 'Showing the last saved dashboard.'));
    if (leader) {
      blocks.push(el('div', { class: 'stats' }, [
        stat('Teams', leader.total_teams),
        stat('Members', leader.total_members),
        stat('Tasks', leader.total_tasks),
        stat('Done', leader.completed_tasks),
        stat('In progress', leader.in_progress_tasks),
        stat('Pending', leader.pending_tasks),
        stat('Overdue', leader.overdue_tasks),
        stat('To review', leader.awaiting_approval),
      ]));
      blocks.push(section('Needs attention', leader.members.filter((person) => person.overdue_count > 0).map((person) =>
        el('div', { class: 'person' }, [
          el('strong', {}, person.full_name),
          el('span', { class: 'muted' }, `${person.completed_count}/${person.assigned_count} done · ${person.overdue_count} overdue`),
        ])
      ), 'Everyone is on track.'));
      blocks.push(section('Upcoming deadlines', leader.upcoming.map((task) => taskCard(task, () => navigate('task', { id: task.id }))), 'No upcoming deadlines.'));
      blocks.push(section('Recent activity', leader.activity.map(activityLine), 'No activity yet.'));
    }
    blocks.push(section('Due today', (member.due_today || []).map((task) => taskCard(task, () => navigate('task', { id: task.id }))), 'Nothing is due today.'));
    blocks.push(section('My overdue work', (member.overdue || []).map((task) => taskCard(task, () => navigate('task', { id: task.id }))), 'No overdue assignments.'));
    blocks.push(section('Announcements', (member.announcements || []).map((item) => el('article', { class: 'card' }, [
      item.is_important ? badge('urgent', 'Important') : null,
      el('h3', {}, item.title),
      el('p', {}, item.body),
      el('span', { class: 'muted' }, item.author_name),
    ])), 'No announcements.'));
    root.append(screen(leader ? 'Team pulse' : 'My work', el('div', { class: 'stack' }, blocks), [
      el('button', { class: 'btn ghost', type: 'button', onclick: () => navigate('search') }, 'Search'),
    ]));
  } catch (error) {
    root.replaceChildren(errorState(error.message, () => renderDashboard(root)));
  }
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
  const teams = await loadTeams().catch(() => []);
  const leader = isLeader(teams);
  let filter = 'all';
  const draw = async () => {
    const query = filter === 'all' ? 'scope=mine' : `scope=mine&status=${encodeURIComponent(filter)}`;
    let tasks = [];
    try {
      tasks = await loadTasks(query);
      if (filter !== 'all') {
        tasks = tasks.filter((task) => filter === 'awaiting' ? task.review_status === 'awaiting' : task.status === filter);
      }
    } catch (error) {
      root.replaceChildren(errorState(error.message, draw));
      return;
    }
    const chips = ['all', 'todo', 'in_progress', 'pending', 'blocked', 'done', 'awaiting'].map((key) =>
      el('button', { class: `chip ${filter === key ? 'on' : ''}`, type: 'button', onclick: () => { filter = key; draw(); } }, key === 'all' ? 'All' : STATUS_LABEL[key])
    );
    root.replaceChildren(screen('My tasks', el('div', { class: 'stack' }, [
      el('div', { class: 'chips' }, chips),
      tasks.length ? el('div', { class: 'stack' }, tasks.map((task) => taskCard(task, () => navigate('task', { id: task.id })))) : emptyState('No tasks here', 'Assignments that match this filter will show up here.'),
    ]), leader ? [el('button', { class: 'btn primary', type: 'button', onclick: () => navigate('task-new') }, 'New task')] : []));
  };
  await draw();
}

export async function renderTask(root) {
  const id = Number(state.params.id);
  root.append(skeleton(2));
  try {
    let task = null;
    let comments = [];
    let activity = [];
    if (navigator.onLine) {
      try {
        task = (await api(`/tasks/${id}`)).data;
        comments = (await api(`/tasks/${id}/comments`)).data;
        activity = (await api(`/tasks/${id}/activity`)).data;
        await db.put('tasks', { ...task, sync_state: 'synced', server_status: task.status });
        for (const comment of comments) await db.put('comments', comment);
      } catch (error) {
        if (!(error instanceof OfflineError)) throw error;
      }
    }
    if (!task) task = await db.get('tasks', id);
    if (!comments.length) comments = (await db.all('comments')).filter((item) => Number(item.task_id) === id && !item.deleted_at);
    if (!task) {
      root.replaceChildren(emptyState('Task unavailable', 'This task is not on this device yet.'));
      return;
    }
    const teams = await loadTeams().catch(() => []);
    const team = teams.find((item) => item.id === task.team_id);
    const leader = team?.role === 'leader';
    const body = textInput({ placeholder: 'Write a comment' });
    body.maxLength = 4000;
    const view = el('div', { class: 'stack' }, [
      el('button', { class: 'link back', type: 'button', onclick: () => history.back() }, 'Back'),
      el('div', { class: 'row spread' }, [el('h1', {}, task.title), statusBadge(task)]),
      el('p', { class: 'muted' }, `${PRIORITY_LABEL[task.priority]} priority${task.deadline ? ` · Due ${formatWhen(task.deadline)}` : ''}`),
      task.sync_state === 'pending' ? el('p', { class: 'note' }, 'Saved on this device. It will sync when you are online.') : null,
      task.sync_state === 'conflict' ? conflictCard(task) : null,
      task.description ? el('p', {}, task.description) : null,
      task.instructions ? el('p', { class: 'muted' }, task.instructions) : null,
      el('div', { class: 'chips' }, ['todo', 'in_progress', 'pending', 'blocked', 'done'].map((status) =>
        el('button', {
          class: `chip ${task.status === status ? 'on' : ''}`,
          type: 'button',
          onclick: () => updateStatus(task, status),
        }, STATUS_LABEL[status])
      )),
      leader && task.review_status === 'awaiting' ? reviewBox(task) : null,
      checklistBlock(task),
      el('h2', { class: 'section-title' }, 'Conversation'),
      ...comments.map((comment) => el('article', { class: 'card' }, [
        el('div', { class: 'row spread' }, [el('strong', {}, comment.author_name), comment.comment_type === 'progress' ? badge('pending', 'Progress') : null]),
        el('p', {}, comment.body),
        el('span', { class: 'muted' }, `${formatWhen(comment.created_at)}${comment.edited ? ' · edited' : ''}${comment.pending ? ' · not synced' : ''}`),
      ])),
      el('form', { class: 'row', onsubmit: async (event) => {
        event.preventDefault();
        if (!body.value.trim()) return;
        await queueComment(task, body.value.trim(), 'comment');
        body.value = '';
        try {
          await syncNow();
        } catch (error) {
          if (error instanceof OfflineError) toast('Saved on this device.');
          else toast(error.message);
        }
        renderTask(root);
      } }, [body, el('button', { class: 'btn primary', type: 'submit' }, 'Send')]),
      el('h2', { class: 'section-title' }, 'History'),
      ...activity.map(activityLine),
      leader ? el('button', { class: 'btn danger', type: 'button', onclick: async () => {
        if (!await confirmDialog({ title: 'Delete this task?', message: 'The history stays in the database, and the task leaves the active list.', confirmLabel: 'Delete', danger: true })) return;
        await api(`/tasks/${task.id}`, { method: 'DELETE' });
        await db.delete('tasks', task.id);
        navigate('tasks');
      } }, 'Delete task') : null,
    ]);
    root.replaceChildren(view);
  } catch (error) {
    root.replaceChildren(errorState(error.message, () => renderTask(root)));
  }
}

async function updateStatus(task, status) {
  await queueStatus(task, status);
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
      root.append(errorState(error.message, () => renderCalendar(root)));
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
      body.replaceChildren(errorState(error.message, draw));
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
