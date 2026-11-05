import { api, ApiError, OfflineError } from './api.js';
import { db } from './db.js';
import { state } from './state.js';

function opId() {
  return crypto.randomUUID ? crypto.randomUUID() : `op${Date.now().toString(16)}${Math.random().toString(16).slice(2, 10)}`;
}

export async function pendingCount() {
  const queue = await db.all('queue');
  state.pending = queue.length;
  return queue.length;
}

export async function cacheTasks(tasks) {
  for (const task of tasks) {
    const local = await db.get('tasks', task.id);
    if (local && (local.sync_state === 'pending' || local.sync_state === 'conflict')) continue;
    await db.put('tasks', { ...task, sync_state: 'synced', server_status: task.status });
  }
}

export async function queueStatus(task, status, note = null) {
  const queue = await db.all('queue');
  let op = queue.find((item) => item.type === 'task.status' && Number(item.entity_id) === Number(task.id));
  if (!op) {
    op = {
      op_id: opId(),
      type: 'task.status',
      entity_id: task.id,
      base_version: task.version,
      payload: { status, base_status: task.server_status || task.status, note },
      created_at: new Date().toISOString(),
    };
  } else {
    op.payload.status = status;
    if (note) op.payload.note = note;
  }
  task.status = status;
  task.sync_state = 'pending';
  await db.put('tasks', task);
  await db.put('queue', op);
  await pendingCount();
}

export async function queueComment(task, body, commentType = 'comment') {
  const localId = `local-${opId()}`;
  const op = {
    op_id: opId(),
    type: 'task.comment',
    entity_id: task.id,
    base_version: task.version,
    payload: { body, comment_type: commentType, local_id: localId },
    created_at: new Date().toISOString(),
  };
  await db.put('comments', {
    id: localId,
    task_id: task.id,
    user_id: state.user?.id || null,
    author_name: state.user?.full_name || 'You',
    body,
    comment_type: commentType,
    created_at: new Date().toISOString(),
    edited: false,
    pending: true,
  });
  await db.put('queue', op);
  await pendingCount();
}

export async function flushQueue() {
  if (!navigator.onLine) {
    state.offline = true;
    throw new OfflineError();
  }
  const queue = (await db.all('queue')).sort((a, b) => a.created_at.localeCompare(b.created_at));
  for (const op of queue) {
    let payload;
    try {
      payload = await api('/sync/push', { method: 'POST', body: { operations: [op] } });
    } catch (error) {
      if (error instanceof OfflineError || (error instanceof ApiError && error.status >= 500)) throw error;
      throw error;
    }
    const result = payload.data.results[0];
    if (result.status === 'applied') {
      if (result.data?.task) {
        await db.put('tasks', { ...result.data.task, sync_state: 'synced', server_status: result.data.task.status });
      }
      if (result.data?.comment) {
        if (result.data.local_id) await db.delete('comments', result.data.local_id);
        await db.put('comments', result.data.comment);
      }
    } else if (result.status === 'conflict') {
      const task = await db.get('tasks', Number(op.entity_id));
      if (task) {
        task.sync_state = 'conflict';
        task.server_copy = result.data?.task || null;
        await db.put('tasks', task);
      }
    } else if (result.data?.task) {
      await db.put('tasks', { ...result.data.task, sync_state: 'synced', server_status: result.data.task.status });
    }
    await db.delete('queue', op.op_id);
  }
  await pendingCount();
}

export async function pullChanges() {
  const since = (await db.meta('cursor')) || '1970-01-01T00:00:00Z';
  const payload = await api(`/sync/pull?since=${encodeURIComponent(since)}`);
  const data = payload.data;
  const active = new Set(data.active_team_ids || []);
  for (const team of data.teams || []) {
    if (team.deleted_at || ['left', 'removed'].includes(team.membership_status)) await db.delete('teams', team.id);
    else await db.put('teams', team);
  }
  for (const team of await db.all('teams')) {
    if (!active.has(team.id)) await db.delete('teams', team.id);
  }
  const queue = await db.all('queue');
  for (const task of data.tasks || []) {
    if (task.deleted_at) {
      await db.delete('tasks', task.id);
      continue;
    }
    const pending = queue.find((op) => op.type === 'task.status' && Number(op.entity_id) === task.id);
    if (pending && task.status !== pending.payload.base_status && task.version !== pending.base_version) {
      const local = (await db.get('tasks', task.id)) || task;
      local.sync_state = 'conflict';
      local.server_copy = task;
      await db.put('tasks', local);
      continue;
    }
    if (pending) {
      await db.put('tasks', { ...task, status: pending.payload.status, sync_state: 'pending', server_status: task.status });
      continue;
    }
    const local = await db.get('tasks', task.id);
    if (local?.sync_state === 'conflict') continue;
    await db.put('tasks', { ...task, sync_state: 'synced', server_status: task.status });
  }
  for (const comment of data.comments || []) {
    if (comment.deleted_at) await db.delete('comments', comment.id);
    else await db.put('comments', comment);
  }
  for (const item of data.announcements || []) {
    if (item.deleted_at) await db.delete('announcements', item.id);
    else await db.put('announcements', item);
  }
  for (const item of data.notifications || []) await db.put('notifications', item);
  await db.setMeta('cursor', data.server_time);
  state.offline = false;
}

export async function syncNow() {
  await flushQueue();
  await pullChanges();
  await pendingCount();
}
