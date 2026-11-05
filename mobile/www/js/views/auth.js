import { api, ApiError, OfflineError } from '../api.js';
import { apiBase, setApiBase } from '../config.js';
import { navigate } from '../router.js';
import { saveSession } from '../session.js';
import { applyTheme } from '../state.js';
import { el, field, setBusy, textInput, toast } from '../ui.js';

async function startGoogle() {
  try {
    const providers = await api('/auth/providers', { auth: false });
    if (!providers.data.google_enabled) {
      toast('Google Sign-In is not configured on this server.');
      return;
    }
    await loadGoogle();
    window.google.accounts.id.initialize({
      client_id: providers.data.google_client_id,
      callback: async (response) => {
        try {
          const result = await api('/auth/google', { method: 'POST', auth: false, body: { id_token: response.credential } });
          await afterAuth(result.data);
        } catch (error) {
          toast(error.message);
        }
      },
    });
    window.google.accounts.id.prompt();
  } catch (error) {
    toast(error.message);
  }
}

function loadGoogle() {
  if (window.google?.accounts?.id) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.onload = () => resolve();
    script.onerror = () => reject(new Error('Google Sign-In could not be loaded.'));
    document.head.append(script);
  });
}

async function afterAuth(data) {
  await saveSession(data);
  applyTheme(data.user.theme);
  navigate('dashboard', {}, true);
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
    el('details', { class: 'advanced' }, [el('summary', {}, 'Server address'), field('API base URL', server)]),
    button,
    el('button', { class: 'btn ghost full', type: 'button', onclick: () => startGoogle() }, 'Continue with Google'),
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
  const button = el('button', { class: 'btn primary full', type: 'submit' }, 'Create account');
  const form = el('form', { class: 'stack', onsubmit: async (event) => {
    event.preventDefault();
    setBusy(button, true);
    try {
      const result = await api('/auth/register', {
        method: 'POST',
        auth: false,
        body: { full_name: name.value, email: email.value, password: password.value, password_confirmation: confirm.value },
      });
      await afterAuth(result.data);
    } catch (error) {
      toast(error instanceof ApiError ? error.message : 'Could not create the account.');
      setBusy(button, false, 'Create account');
    }
  } }, [
    field('Full name', name),
    field('Email', email),
    field('Password', password),
    field('Confirm password', confirm),
    button,
    el('button', { class: 'link', type: 'button', onclick: () => navigate('login') }, 'I already have an account'),
  ]);
  root.append(authShell('Create your account', 'One account can belong to as many teams as you need.', form));
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
