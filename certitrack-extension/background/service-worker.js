import { registerDetectors } from './event-detector.js';
import { clearNotice, endSession, flushQueue, getStatus, login, logout, onBrowserStartup, pair, refreshConfig, setServer, startSession, syncUi, tick } from './session-manager.js';

// 1) Register every listener synchronously, at top level.
registerDetectors();

chrome.alarms.onAlarm.addListener((alarm) => { if (alarm.name === 'ct-tick') tick().catch((e) => console.warn('[CertiTrack] tick', e)); });
chrome.runtime.onStartup.addListener(() => { onBrowserStartup().catch(() => {}); });
chrome.runtime.onInstalled.addListener(() => { syncUi().catch(() => {}); });

// 2) Popup <-> worker messages (own extension only).
const handlers = {
  GET_STATUS: () => getStatus(),
  SET_SERVER: ({ baseUrl }) => setServer(baseUrl),
  PAIR: ({ code }) => pair(code),
  LOGIN: ({ email, password }) => login(email, password),
  LOGOUT: () => logout(),
  REFRESH_CONFIG: () => refreshConfig().then(() => true),
  START: ({ platformId, consent, adoptSessionId }) => startSession(platformId, consent, { adoptSessionId }).then(() => true),
  END: ({ force }) => endSession({ force: !!force }),
  SYNC_NOW: async () => { await flushQueue(); await tick(); return true; },
  CLEAR_NOTICE: () => clearNotice(),
};

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (sender.id !== chrome.runtime.id || !handlers[msg?.type]) return false;
  Promise.resolve(handlers[msg.type](msg))
    .then((data) => sendResponse({ ok: true, data }))
    .catch((e) => sendResponse({ ok: false, error: e.message, existing: e.existing || null }));
  return true; // async response
});

// 3) Every time the worker wakes: restore badge/alarm and resume syncing if a session exists.
syncUi().then(() => flushQueue()).catch(() => {});
