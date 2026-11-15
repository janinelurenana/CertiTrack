import { apiFetch, isTransient, NetworkError } from '../services/api.js';
import { ackBatch, BATCH_SIZE, discardSession, peekBatch, pushEvent, queueLength } from '../services/event-queue.js';
import { ensureClientId, getQueue, getState, mutate } from '../services/storage.js';
import { isApproved, resolvePlatform } from './platform-resolver.js';

const ALARM = 'ct-tick';

// ---------- badge + alarms (re-applied whenever the worker wakes) ----------
export async function syncUi() {
  const { session } = await getState();
  const on = !!(session && session.active);
  const pending = !!(session && session.pendingEnd);
  try {
    await chrome.action.setBadgeText({ text: on ? 'ON' : pending ? '...' : '' });
    await chrome.action.setBadgeBackgroundColor({ color: on ? '#dc3545' : '#fd7e14' });
    await chrome.action.setTitle({ title: on ? 'CertiTrack: monitoring is ON' : pending ? 'CertiTrack: finishing session' : 'CertiTrack' });
  } catch { /* action API unavailable in tests */ }
  if (session) {
    const existing = await chrome.alarms.get(ALARM);
    // 0.5 min is the minimum period in Chrome 120+. Alarms are best-effort, so the server timeout is much longer.
    if (!existing) await chrome.alarms.create(ALARM, { periodInMinutes: 0.5 });
  } else {
    await chrome.alarms.clear(ALARM);
  }
}

// ---------- connection bookkeeping (records connection_lost / connection_restored) ----------
async function markOffline(reason) {
  await mutate((state, queue) => {
    state.connection.lastError = reason;
    if (state.connection.online) {
      state.connection.online = false;
      state.connection.offlineSince = new Date().toISOString();
      pushEvent(state, queue, 'connection_lost', {});
    }
  });
}

async function markOnline() {
  await mutate((state, queue) => {
    state.connection.lastSuccessAt = new Date().toISOString();
    state.connection.lastError = null;
    if (!state.connection.online) {
      const gap = Math.round((Date.now() - new Date(state.connection.offlineSince).getTime()) / 1000);
      state.connection.online = true;
      state.connection.offlineSince = null;
      pushEvent(state, queue, 'connection_restored', { gap_seconds: gap });
    }
  });
}

async function authLost() {
  await mutate((state) => {
    state.server.token = null;
    state.notice = 'Your extension is signed out. Pair it again from the CertiTrack dashboard; unsent events are kept.';
  });
}

// ---------- sync ----------
let flushing = null;
/** Send queued events in batches. Safe to call concurrently (calls share one run). */
export function flushQueue() {
  if (!flushing) flushing = doFlush().finally(() => { flushing = null; });
  return flushing;
}

async function doFlush() {
  for (let i = 0; i < 40; i++) {
    const state = await getState();
    const s = state.session;
    if (!s) return { status: 'no_session' };
    if (!state.server.token) return { status: 'signed_out' };
    const batch = await peekBatch(s.id, BATCH_SIZE);
    if (!batch.length) return { status: 'empty' };

    let r;
    try {
      r = await apiFetch(`/sessions/${s.id}/events`, { method: 'POST', body: { events: batch.map(({ session_id, ...e }) => e) } });
    } catch (e) {
      await markOffline(e.message);
      return { status: 'offline' };
    }
    if (isTransient(r)) { await markOffline(`Server error ${r.status}`); return { status: 'offline' }; }
    if (r.status === 401) { await authLost(); return { status: 'signed_out' }; }
    if (r.status === 404 || r.status === 403) { await dropSession('The server no longer has this session; local data was cleared.'); return { status: 'session_gone' }; }
    if (!r.ok) {
      // Unexpected 4xx for the whole request: drop the batch so one bad request cannot block the queue forever.
      await ackBatch(batch.map((e) => e.event_uuid));
      await mutate((st) => { if (st.session) st.session.rejected = (st.session.rejected || 0) + batch.length; st.notice = `Server refused ${batch.length} event(s) (HTTP ${r.status}).`; });
      continue;
    }
    await markOnline();
    const d = r.data.data;
    await ackBatch(batch.map((e) => e.event_uuid)); // server has processed every event in the batch
    await mutate((st) => {
      if (!st.session) return;
      st.session.sent = (st.session.sent || 0) + d.accepted.length;
      st.session.rejected = (st.session.rejected || 0) + d.rejected.length;
    });
  }
  return { status: 'more' };
}

export async function heartbeat() {
  const state = await getState();
  const s = state.session;
  if (!s || !s.active || !state.server.token) return { status: 'skipped' };
  let r;
  try {
    r = await apiFetch(`/sessions/${s.id}/heartbeat`, { method: 'POST', body: { last_seq: s.seq } });
  } catch (e) { await markOffline(e.message); return { status: 'offline' }; }
  if (isTransient(r)) { await markOffline(`Server error ${r.status}`); return { status: 'offline' }; }
  if (r.status === 401) { await authLost(); return { status: 'signed_out' }; }
  if (r.status === 404 || r.status === 403) { await dropSession('The server no longer has this session.'); return { status: 'session_gone' }; }
  if (r.status === 409) { // session closed on the server (e.g. marked abandoned after missed heartbeats)
    await mutate((st) => { if (st.session) { st.session.active = false; st.session.pendingEnd = true; } st.notice = 'The server closed this session because heartbeats were missed. Monitoring has stopped.'; });
    await syncUi();
    return { status: 'closed_by_server' };
  }
  await markOnline();
  await mutate((st) => { if (st.session) st.session.lastHeartbeatAt = new Date().toISOString(); });
  return { status: 'ok' };
}

async function dropSession(notice) {
  const s = (await getState()).session;
  if (s) await discardSession(s.id);
  await mutate((st) => { st.session = null; st.notice = notice; });
  await syncUi();
}

/** Alarm entry point. */
export async function tick() {
  const state = await getState();
  if (!state.session) { await syncUi(); return; }
  await flushQueue();
  const s = (await getState()).session;
  if (!s) return;
  if (s.pendingEnd) await finishEnd(false);
  else await heartbeat();
}

// ---------- auth + config ----------
export async function setServer(baseUrl) {
  let u;
  try { u = new URL(baseUrl); } catch { throw new Error('Enter a full address such as http://127.0.0.1:8000'); }
  if (u.protocol !== 'http:' && u.protocol !== 'https:') throw new Error('Server address must start with http:// or https://');
  const s = (await getState()).session;
  if (s) throw new Error('End the current monitoring session before changing the server.');
  await mutate((st) => { st.server.baseUrl = u.origin; st.server.token = null; st.server.user = null; st.config = null; });
  return u.origin;
}

async function storeLogin(data) {
  if (data.user.role !== 'student') throw new Error('Only student accounts can run monitoring sessions.');
  await mutate((st) => { st.server.token = data.token; st.server.user = data.user; st.notice = null; });
  await ensureClientId();
  await refreshConfig().catch(() => {});
}

export async function pair(code) {
  let r;
  try { r = await apiFetch('/extension/pair', { method: 'POST', auth: false, body: { code, device_name: 'Chrome extension' } }); }
  catch (e) { throw new Error(e.message); }
  if (!r.ok) throw new Error(r.data?.message || `Pairing failed (HTTP ${r.status})`);
  await storeLogin(r.data.data);
}

export async function login(email, password) {
  let r;
  try { r = await apiFetch('/login', { method: 'POST', auth: false, body: { email, password, device_name: 'extension:Chrome' } }); }
  catch (e) { throw new Error(e.message); }
  if (!r.ok) throw new Error(r.data?.message || `Login failed (HTTP ${r.status})`);
  await storeLogin(r.data.data);
}

export async function logout() {
  const state = await getState();
  if (state.session) throw new Error('End monitoring before signing out.');
  try { await apiFetch('/logout', { method: 'POST' }); } catch { /* best effort */ }
  await mutate((st) => { st.server.token = null; st.server.user = null; st.notice = null; });
}

export async function refreshConfig() {
  const r = await apiFetch('/platforms/config');
  if (r.status === 401) { await authLost(); throw new Error('Signed out'); }
  if (!r.ok) throw new Error(`Could not load platform configuration (HTTP ${r.status})`);
  const d = r.data.data;
  await mutate((st) => { st.config = { platforms: d.platforms, settings: d.settings, fetchedAt: new Date().toISOString() }; });
  return d;
}

// ---------- session control ----------
async function activeTab() {
  const [tab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
  return tab || null;
}

/**
 * Start monitoring. Requires explicit consent and that the active tab is on one of the
 * chosen platform's approved domains (that tab becomes the "examination tab").
 */
export async function startSession(platformId, consent, { adoptSessionId } = {}) {
  if (!consent) throw new Error('You must consent before monitoring can start.');
  let state = await getState();
  if (state.session) throw new Error('A monitoring session is already running in this browser.');
  if (!state.server.token) throw new Error('Sign in first (pair this extension).');
  if (!state.config) await refreshConfig();
  state = await getState();
  const platform = state.config.platforms.find((p) => p.id === Number(platformId));
  if (!platform) throw new Error('Choose a platform first.');
  const tab = await activeTab();
  if (!tab || !isApproved(tab.url, platform)) {
    const hosts = platform.domains.map((d) => d.host + (d.path_prefix || '')).join(', ');
    throw new Error(`Open the ${platform.name} page in this window first (${hosts}), then start monitoring from that tab.`);
  }
  const clientId = await ensureClientId();
  let session;
  if (adoptSessionId) {
    const r = await apiFetch(`/sessions/${adoptSessionId}`);
    if (!r.ok || !['active', 'interrupted'].includes(r.data.data.status)) throw new Error('That session is no longer open.');
    session = r.data.data;
  } else {
    let r;
    try { r = await apiFetch('/sessions', { method: 'POST', body: { platform_id: platform.id, consent: true, client_id: clientId } }); }
    catch (e) { throw new Error(e.message); }
    if (r.status === 409) { const err = new Error('You already have an open monitoring session.'); err.existing = r.data?.data; throw err; }
    if (!r.ok) throw new Error(r.data?.message || `Could not start session (HTTP ${r.status})`);
    session = r.data.data;
  }
  await mutate((st) => {
    st.session = {
      id: session.id, platformId: platform.id, platformName: platform.name,
      examTabId: tab.id, examWindowId: tab.windowId, startedAt: session.started_at,
      seq: session.client_last_seq || 0, eventCount: 0, sent: 0, rejected: 0, dropped: 0,
      active: true, pendingEnd: false, outside: false, outsideHost: null, focusLost: false, awayFromExam: false,
      lastHeartbeatAt: new Date().toISOString(),
    };
    st.connection = { online: true, offlineSince: null, lastSuccessAt: new Date().toISOString(), lastError: null };
    st.notice = null;
  });
  await syncUi();
  return session;
}

/**
 * End monitoring: stop recording immediately, sync everything that is queued, then close the session
 * on the server. If the network is down the session stays "ending" and is finished by the alarm.
 * force=true discards unsynced events (explicit user choice).
 */
export async function endSession({ force = false } = {}) {
  const state = await getState();
  if (!state.session) return { status: 'no_session' };
  await mutate((st) => { st.session.active = false; st.session.pendingEnd = true; });
  await syncUi();
  await flushQueue();
  return finishEnd(force);
}

async function finishEnd(force) {
  const state = await getState();
  const s = state.session;
  if (!s) return { status: 'no_session' };
  const queued = await queueLength(s.id);
  if (queued > 0 && !force) return { status: 'pending', queued };
  let r;
  try { r = await apiFetch(`/sessions/${s.id}/end`, { method: 'POST', body: { last_seq: s.seq } }); }
  catch (e) { await markOffline(e.message); return { status: 'pending', queued }; }
  if (isTransient(r)) { await markOffline(`Server error ${r.status}`); return { status: 'pending', queued }; }
  if (r.status === 401) { await authLost(); return { status: 'pending', queued }; }
  await markOnline();
  await discardSession(s.id);
  await mutate((st) => { st.session = null; st.notice = r.ok ? null : 'Session ended locally; the server no longer has it.'; });
  await syncUi();
  return { status: 'ended' };
}

// ---------- startup / status ----------
/** Browser restarted: tab ids are gone, so the examination tab is considered closed. */
export async function onBrowserStartup() {
  await mutate((state, queue) => {
    const s = state.session;
    if (s && s.active && s.examTabId != null) {
      pushEvent(state, queue, 'exam_tab_closed', { reason: 'browser_restarted' });
      s.examTabId = null; s.awayFromExam = false; s.outside = false; s.focusLost = false;
    }
  });
  await syncUi();
  void flushQueue();
}

export async function getStatus() {
  const state = await getState();
  const queue = await getQueue();
  const s = state.session;
  let detected = null;
  try {
    const tab = await activeTab();
    if (tab && state.config) { const p = resolvePlatform(tab.url, state.config.platforms); detected = p ? p.id : null; }
  } catch { /* ignore */ }
  return {
    server: { baseUrl: state.server.baseUrl, user: state.server.user, signedIn: !!state.server.token },
    platforms: (state.config?.platforms || []).map((p) => ({ id: p.id, name: p.name })),
    detectedPlatformId: detected,
    consentVersion: state.config?.settings?.consent_version || null,
    session: s ? {
      id: s.id, platformName: s.platformName, startedAt: s.startedAt, active: s.active, pendingEnd: s.pendingEnd,
      eventCount: s.eventCount, sent: s.sent || 0, rejected: s.rejected || 0, dropped: s.dropped || 0,
      queued: queue.filter((e) => e.session_id === s.id).length, hasExamTab: s.examTabId != null,
    } : null,
    connection: state.connection,
    notice: state.notice,
  };
}

export async function clearNotice() { await mutate((st) => { st.notice = null; }); }
