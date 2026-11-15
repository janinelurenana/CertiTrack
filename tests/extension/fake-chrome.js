// Minimal in-memory/on-disk fake of the Chrome APIs the extension uses.
// storage.local persists to a JSON file so separate Node processes behave like a suspended+restarted service worker.
import fs from 'node:fs';

export function installFakeChrome(storeFile) {
  const load = () => (fs.existsSync(storeFile) ? JSON.parse(fs.readFileSync(storeFile, 'utf8')) : {});
  const save = (o) => fs.writeFileSync(storeFile, JSON.stringify(o));
  const listeners = {};
  const ev = (name) => ({ addListener: (fn) => { (listeners[name] ||= []).push(fn); } });
  const meta = () => load().__meta || { tabs: [], focusedWindow: 1, browserFocused: true, alarms: {} };
  const setMeta = (m) => { const s = load(); s.__meta = m; save(s); };

  globalThis.chrome = {
    runtime: { id: 'test-extension', onMessage: ev('runtime.onMessage'), onStartup: ev('runtime.onStartup'), onInstalled: ev('runtime.onInstalled') },
    storage: { local: {
      get: async (key) => { const s = load(); return key ? (key in s ? { [key]: structuredClone(s[key]) } : {}) : s; },
      set: async (obj) => { const s = load(); Object.assign(s, structuredClone(obj)); save(s); },
    } },
    alarms: {
      onAlarm: ev('alarms.onAlarm'),
      get: async (n) => meta().alarms[n] || undefined,
      create: async (n, info) => { const m = meta(); m.alarms[n] = { name: n, ...info }; setMeta(m); },
      clear: async (n) => { const m = meta(); delete m.alarms[n]; setMeta(m); },
    },
    action: { setBadgeText: async () => {}, setBadgeBackgroundColor: async () => {}, setTitle: async () => {} },
    tabs: {
      onActivated: ev('tabs.onActivated'), onCreated: ev('tabs.onCreated'), onRemoved: ev('tabs.onRemoved'), onAttached: ev('tabs.onAttached'),
      query: async () => { const m = meta(); return m.tabs.filter((t) => t.active && t.windowId === m.focusedWindow); },
      get: async (id) => { const t = meta().tabs.find((x) => x.id === id); if (!t) throw new Error('No tab'); return t; },
    },
    windows: { onFocusChanged: ev('windows.onFocusChanged'), getLastFocused: async () => { const m = meta(); return { id: m.focusedWindow, focused: m.browserFocused }; } },
    webNavigation: { onCommitted: ev('webNavigation.onCommitted') },
  };
  return {
    setMeta: (patch) => setMeta({ ...meta(), ...patch }),
    fire: async (name, ...args) => { for (const fn of listeners[name] || []) await fn(...args); },
    message: (msg) => new Promise((resolve) => {
      const fns = listeners['runtime.onMessage'] || []; let handled = false;
      for (const fn of fns) { if (fn(msg, { id: 'test-extension' }, resolve) === true) { handled = true; break; } }
      if (!handled) resolve(undefined);
    }),
  };
}
