import { api, ApiError, OfflineError } from '../api.js';
import { registerPush } from '../push.js';
import { db } from '../db.js';
import { apiBase, setApiBase } from '../config.js';
import { navigate } from '../router.js';
import { saveSession } from '../session.js';
import { applyTheme } from '../state.js';
import { el, field, setBusy, textInput, toast } from '../ui.js';

async function afterAuth(data, next = 'dashboard') {
  await saveSession(data);
  applyTheme(data.user.theme);
  registerPush().catch(() => {});
  navigate(next, {}, true);
}

function authShell(title, copy, form) {
  return el('section', { class: 'auth' }, [
    el('div', { class: 'auth-brand' }, [el('p', { class: 'kicker' }, 'ORBIT'), el('h1', {}, title), el('p', { class: 'muted' }, copy)]),
    form,
  ]);
}

export function renderLogin(root) {
  const email = textInput({ type: 'email', autocomplete: 'username', required: 'true', placeholder: 'you@studio.edu' });
  const password = textInput({ type: 'password', autocomplete: 'current-password', required: 'true', placeholder: 'Password' });
  const server = textInput({ type: 'url', value: apiBase(), placeholder: 'https://orbit.example/api/v1' });
  const button = el('button', { class: 'btn primary full', type: 'submit' }, 'Sign in');
  const form = el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    if (window.Capacitor?.isNativePlatform?.() && !server.value.trim()) {
      toast('Enter the server address, such as https://your-domain/api/v1.');
      return;
    }
    setApiBase(server.value);
    setBusy(button, true);
    try {
      const result = await api('/auth/login', { method: 'POST', auth: false, body: { email: email.value, password: password.value } });
      await afterAuth(result.data);
    } catch (error) {
      toast(error instanceof OfflineError ? 'Cannot reach the server.' : error.message);
      setBusy(button, false, 'Sign in');
    }
  } }, [
    field('Email', email),
    field('Password', password),
    window.Capacitor?.isNativePlatform?.()
      ? field('Server address', server)
      : el('details', { class: 'advanced' }, [el('summary', {}, 'Server address'), field('API base URL', server)]),
    button,
    el('p', { class: 'auth-links' }, [
      el('button', { class: 'link', type: 'button', onclick: () => navigate('forgot') }, 'Forgot password'),
      el('button', { class: 'link', type: 'button', onclick: () => navigate('register') }, 'Create an account'),
    ]),
  ]);
  root.append(authShell('Sign in', 'Pick up your teams, tasks, and deadlines.', form));
}

export function renderRegister(root) {
  const name = textInput({ required: 'true', autocomplete: 'name', placeholder: 'Full name' });
  const email = textInput({ type: 'email', required: 'true', autocomplete: 'username', placeholder: 'Email' });
  const password = textInput({ type: 'password', required: 'true', autocomplete: 'new-password', placeholder: 'At least 8 characters' });
  const confirm = textInput({ type: 'password', required: 'true', placeholder: 'Confirm password' });
  let designation = '';
  const leader = el('button', { class: 'chip', type: 'button', onclick: () => pick('leader') }, 'Leader');
  const member = el('button', { class: 'chip', type: 'button', onclick: () => pick('member') }, 'Member');
  function pick(value) {
    designation = value;
    leader.classList.toggle('on', value === 'leader');
    member.classList.toggle('on', value === 'member');
  }
  const server = textInput({ type: 'url', value: apiBase(), placeholder: 'https://your-domain/api/v1' });
  const button = el('button', { class: 'btn primary full', type: 'submit' }, 'Create account');
  const form = el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    if (!designation) {
      toast('Choose Leader or Member.');
      return;
    }
    if (window.Capacitor?.isNativePlatform?.() && !server.value.trim()) {
      toast('Enter the server address, such as https://your-domain/api/v1.');
      return;
    }
    setApiBase(server.value);
    setBusy(button, true);
    try {
      const result = await api('/auth/register', {
        method: 'POST',
        auth: false,
        body: { full_name: name.value, email: email.value, password: password.value, password_confirmation: confirm.value },
      });
      await db.setMeta(`designation.${result.data.user.id}`, designation);
      await afterAuth(result.data, designation === 'leader' ? 'team-new' : 'join');
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not create the account.');
      setBusy(button, false, 'Create account');
    }
  } }, [
    window.Capacitor?.isNativePlatform?.() ? field('Server address', server) : null,
    field('Full name', name),
    field('Email', email),
    field('Password', password),
    field('Confirm password', confirm),
    el('div', { class: 'field' }, [el('span', {}, 'Designation'), el('div', { class: 'choice' }, [leader, member])]),
    button,
    el('button', { class: 'link', type: 'button', onclick: () => navigate('login') }, 'I already have an account'),
  ]);
  root.append(authShell('Create your account', 'Leaders start a group. Members join with a code.', form));
}

export function renderForgot(root) {
  const email = textInput({ type: 'email', required: 'true', placeholder: 'Email' });
  const button = el('button', { class: 'btn primary full', type: 'submit' }, 'Send reset link');
  const form = el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    setBusy(button, true);
    try {
      const result = await api('/auth/forgot-password', { method: 'POST', auth: false, body: { email: email.value } });
      toast(result.message);
      setBusy(button, false, 'Send reset link');
    } catch (error) {
      toast(error.message);
      setBusy(button, false, 'Send reset link');
    }
  } }, [
    field('Email', email),
    button,
    el('button', { class: 'link', type: 'button', onclick: () => navigate('login') }, 'Back to sign in'),
  ]);
  root.append(authShell('Reset password', 'If the account exists, reset instructions are created.', form));
}
