import { state } from './state.js';

export const STATUS_LABEL = {
  todo: 'To do',
  in_progress: 'In progress',
  pending: 'Pending',
  blocked: 'Blocked',
  done: 'Done',
  awaiting: 'Awaiting review',
};

export const PRIORITY_LABEL = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' };

export function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs)) {
    if (value == null || value === false) continue;
    if (key === 'class') node.className = value;
    else if (key === 'dataset') Object.assign(node.dataset, value);
    else if (key.startsWith('on') && typeof value === 'function') node.addEventListener(key.slice(2).toLowerCase(), value);
    else node.setAttribute(key, value);
  }
  for (const child of [].concat(children)) {
    if (child == null || child === false) continue;
    node.append(child.nodeType ? child : document.createTextNode(String(child)));
  }
  return node;
}

export function icon(name) {
  const paths = {
    home: 'M4 10.5 12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6H10v6H5a1 1 0 0 1-1-1z',
    tasks: 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
    teams: 'M16 20v-1.2A3.8 3.8 0 0 0 12.2 15H7.8A3.8 3.8 0 0 0 4 18.8V20M10 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM20 20v-1.2A3.8 3.8 0 0 0 17 15.2M16 5.2a3 3 0 0 1 0 5.6',
    bell: 'M6 16h12l-1.2-2.2a6 6 0 0 1-.8-3V9a4 4 0 1 0-8 0v1.8a6 6 0 0 1-.8 3zM10 17a2 2 0 0 0 4 0',
    user: 'M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4zM5 20a7 7 0 0 1 14 0',
    search: 'M11 18a7 7 0 1 1 0-14 7 7 0 0 1 0 14zM20 20l-3.5-3.5',
    back: 'M15 5 8 12l7 7',
    close: 'M6 6l12 12M18 6 6 18',
  };
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('d', paths[name] || paths.home);
  path.setAttribute('fill', 'none');
  path.setAttribute('stroke', 'currentColor');
  path.setAttribute('stroke-width', '1.7');
  path.setAttribute('stroke-linecap', 'round');
  path.setAttribute('stroke-linejoin', 'round');
  svg.append(path);
  return svg;
}

export function mark() {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 32 32');
  svg.setAttribute('class', 'mark');
  svg.setAttribute('aria-hidden', 'true');
  svg.innerHTML = '<circle cx="16" cy="16" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="16" cy="16" r="11" fill="none" stroke="currentColor" stroke-width="1.2"/><circle cx="25" cy="12" r="2" fill="currentColor"/>';
  return svg;
}

export function toast(message) {
  const node = document.getElementById('toast');
  node.textContent = message;
  node.hidden = false;
  clearTimeout(toast.timer);
  toast.timer = setTimeout(() => {
    node.hidden = true;
  }, 3200);
}

export function confirmDialog({ title, message, confirmLabel = 'Confirm', danger = false }) {
  return new Promise((resolve) => {
    const root = document.getElementById('modal-root');
    const dialog = el('div', { class: 'modal', role: 'dialog' }, [
      el('div', { class: 'modal-card' }, [
        el('h2', {}, title),
        el('p', {}, message),
        el('div', { class: 'row end' }, [
          el('button', { class: 'btn ghost', type: 'button', onclick: () => { root.replaceChildren(); resolve(false); } }, 'Cancel'),
          el('button', { class: `btn ${danger ? 'danger' : 'primary'}`, type: 'button', onclick: () => { root.replaceChildren(); resolve(true); } }, confirmLabel),
        ]),
      ]),
    ]);
    root.replaceChildren(dialog);
  });
}

export function field(label, input) {
  return el('label', { class: 'field' }, [el('span', {}, label), input]);
}

export function textInput(attrs) {
  return el('input', { ...attrs });
}

export function badge(kind, label) {
  return el('span', { class: `badge ${kind}` }, label);
}

export function statusBadge(task) {
  if (task.review_status === 'awaiting') return badge('awaiting', STATUS_LABEL.awaiting);
  return badge(task.status, STATUS_LABEL[task.status] || task.status);
}

export function emptyState(title, copy) {
  return el('div', { class: 'empty' }, [el('h2', {}, title), el('p', {}, copy)]);
}

export function errorState(message, onRetry) {
  return el('div', { class: 'empty' }, [
    el('h2', {}, 'Something went wrong'),
    el('p', {}, message),
    onRetry ? el('button', { class: 'btn primary', type: 'button', onclick: onRetry }, 'Try again') : null,
  ]);
}

export function skeleton(count = 3) {
  return el('div', { class: 'stack' }, Array.from({ length: count }, () => el('div', { class: 'skeleton' })));
}

export function formatWhen(iso) {
  if (!iso) return 'No deadline';
  const zone = state.user?.timezone && state.user.timezone !== 'UTC' ? state.user.timezone : undefined;
  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    timeZone: zone,
  }).format(new Date(iso));
}

export function formatDay(iso) {
  if (!iso) return '';
  return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', timeZone: state.user?.timezone || undefined }).format(new Date(iso));
}

export function initials(name) {
  return (name || '?').split(' ').slice(0, 2).map((part) => part[0]?.toUpperCase() || '').join('');
}

export function avatar(name) {
  return el('span', { class: 'avatar', title: name }, initials(name));
}

export function taskCard(task, onOpen) {
  return el('button', { class: 'task-card', type: 'button', onclick: onOpen }, [
    el('span', { class: `rail ${task.priority}` }),
    el('span', { class: 'task-copy' }, [
      el('span', { class: 'task-title' }, task.title),
      el('span', { class: 'muted' }, [
        task.team_name || '',
        task.deadline ? ` · ${formatWhen(task.deadline)}` : '',
        task.sync_state === 'pending' ? ' · Not synced' : '',
        task.sync_state === 'conflict' ? ' · Needs a decision' : '',
      ].join('')),
    ]),
    statusBadge(task),
  ]);
}

export function setBusy(button, busy, label) {
  button.disabled = busy;
  if (busy) button.dataset.label = button.textContent;
  button.textContent = busy ? 'Please wait…' : (label || button.dataset.label || button.textContent);
}
