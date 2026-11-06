import { api } from './api.js';
import { state } from './state.js';

export async function registerPush() {
  const push = window.Capacitor?.Plugins?.PushNotifications;
  if (!window.Capacitor?.isNativePlatform?.() || !push || !state.access) return;
  let permission = await push.checkPermissions();
  if (permission.receive === 'prompt' || permission.receive === 'prompt-with-rationale') {
    permission = await push.requestPermissions();
  }
  if (permission.receive !== 'granted') return;
  if (!registerPush.listening) {
    registerPush.listening = true;
    await push.addListener('registration', async (event) => {
      if (!event?.value || !state.access) return;
      try {
        await api('/devices', { method: 'POST', body: { token: event.value, platform: 'android', device_name: 'Android' } });
      } catch {
        // Alerts inside the app still work if the phone cannot register yet.
      }
    });
  }
  await push.register();
}
