import { api, ApiError, OfflineError } from './api.js';
import { db } from './db.js';
import { state } from './state.js';
import { cacheTasks } from './sync.js';

export async function loadTeams() {
  if (navigator.onLine) {
    try {
      const teams = (await api('/teams')).data;
      for (const team of teams) await db.put('teams', team);
      return teams;
    } catch (error) {
      if (!(error instanceof OfflineError)) throw error;
    }
  }
  return db.all('teams');
}

export async function loadTasks(query = 'scope=mine') {
  if (navigator.onLine) {
    try {
      const items = (await api(`/tasks?${query}`)).data.items;
      await cacheTasks(items);
      return mergePending(items);
    } catch (error) {
      if (!(error instanceof OfflineError)) throw error;
    }
  }
  const tasks = await db.all('tasks');
  return tasks.filter((task) => !task.deleted_at);
}

async function mergePending(items) {
  const locals = await db.all('tasks');
  const byId = new Map(items.map((task) => [task.id, task]));
  for (const local of locals) {
    if (local.sync_state === 'pending' || local.sync_state === 'conflict') byId.set(local.id, local);
  }
  return [...byId.values()];
}

export function isLeader(teams) {
  return teams.some((team) => team.role === 'leader' && team.membership_status !== 'pending');
}

export async function accountIsLeader(teams = []) {
  if (teams.some((team) => team.role === 'leader')) return true;
  if (!state.user) return false;
  return (await db.meta(`designation.${state.user.id}`)) === 'leader';
}

export async function guard(root, fn) {
  try {
    return await fn();
  } catch (error) {
    if (error instanceof OfflineError) return null;
    if (error instanceof ApiError) throw error;
    throw error;
  }
}
