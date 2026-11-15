// Registers Chrome listeners that turn browser activity into queued events.
// Listeners MUST be registered synchronously at worker start-up so Chrome can wake a suspended worker for them.
// All flags live in the persisted session state (never in module variables).
import { detect } from '../services/event-queue.js';
import { getState, mutate } from '../services/storage.js';
import { isApproved, parseHttpUrl } from './platform-resolver.js';
import { flushQueue } from './session-manager.js';

const safe = (fn) => (...args) => { Promise.resolve().then(() => fn(...args)).catch((e) => console.warn('[CertiTrack]', e)); };
const record = async (fn) => { const created = await detect(fn); if (created.length) void flushQueue(); return created; };

let focusTimer = null;

export async function evaluateFocus() {
  const st = await getState();
  const s = st.session;
  if (!s || !s.active) return;
  let w;
  try { w = await chrome.windows.getLastFocused(); } catch { return; }
  const inExam = !!w.focused && w.id === s.examWindowId;
  const reason = !w.focused ? 'browser_unfocused' : 'other_browser_window';
  await record((sess) => {
    if (inExam) {
      if (sess.focusLost) { sess.focusLost = false; return { type: 'window_focus_gained' }; }
      return null;
    }
    if (!sess.focusLost) { sess.focusLost = true; return { type: 'window_focus_lost', details: { reason } }; }
    return null;
  });
}

export function registerDetectors() {
  // Tab switching: only the fact of switching away from / back to the examination tab (no info about the other tab).
  chrome.tabs.onActivated.addListener(safe(({ tabId, windowId }) => record((s) => {
    if (s.examTabId == null || windowId !== s.examWindowId) return null;
    if (tabId !== s.examTabId) {
      if (s.awayFromExam) return null;
      s.awayFromExam = true;
      return { type: 'tab_switched', details: { direction: 'away' } };
    }
    if (!s.awayFromExam) return null;
    s.awayFromExam = false;
    return { type: 'tab_switched', details: { direction: 'return' } };
  })));

  // New tab opened (the address is deliberately not recorded).
  chrome.tabs.onCreated.addListener(safe((tab) => record((s) => ({
    type: 'tab_opened', details: { opener_is_exam: s.examTabId != null && tab.openerTabId === s.examTabId },
  }))));

  // Examination tab (or its window) closed.
  chrome.tabs.onRemoved.addListener(safe((tabId, info) => record((s) => {
    if (s.examTabId == null || tabId !== s.examTabId) return null;
    s.examTabId = null; s.awayFromExam = false; s.outside = false; s.outsideHost = null;
    return { type: 'exam_tab_closed', details: { reason: info && info.isWindowClosing ? 'window_closed' : 'tab_closed' } };
  })));

  // Exam tab dragged into another window.
  chrome.tabs.onAttached.addListener(safe(async (tabId, info) => {
    await mutate((state) => { const s = state.session; if (s && s.active && s.examTabId === tabId) s.examWindowId = info.newWindowId; });
  }));

  // Navigation of the examination tab outside the approved domains (host only; never path or query).
  chrome.webNavigation.onCommitted.addListener(safe(async ({ tabId, frameId, url }) => {
    if (frameId !== 0) return;
    const u = parseHttpUrl(url);
    if (!u) return;
    const st = await getState();
    const s = st.session;
    if (!s || !s.active) return;
    const platform = st.config?.platforms.find((p) => p.id === s.platformId);
    if (!platform) return;
    const approved = isApproved(url, platform);
    if (s.examTabId == null) {
      // After the exam tab was closed, re-bind when an approved page is opened again.
      if (!approved) return;
      let tab; try { tab = await chrome.tabs.get(tabId); } catch { return; }
      await record((sess) => {
        if (sess.examTabId != null) return null;
        sess.examTabId = tabId; sess.examWindowId = tab.windowId;
        return { type: 'exam_tab_reopened' };
      });
      return;
    }
    if (tabId !== s.examTabId) return;
    const host = u.hostname.toLowerCase();
    await record((sess) => {
      if (approved) {
        if (!sess.outside) return null;
        sess.outside = false; sess.outsideHost = null;
        return { type: 'navigation_returned' };
      }
      if (sess.outside && sess.outsideHost === host) return null;
      sess.outside = true; sess.outsideHost = host;
      return { type: 'navigation_outside_domain', details: { host } };
    });
  }));

  // Window focus. Debounced because Chrome emits transient WINDOW_ID_NONE events while switching windows.
  chrome.windows.onFocusChanged.addListener(() => {
    clearTimeout(focusTimer);
    focusTimer = setTimeout(safe(evaluateFocus), 300);
  });
}
