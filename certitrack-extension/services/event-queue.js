import { getQueue, mutate } from './storage.js';

export const MAX_QUEUE = 5000;
export const BATCH_SIZE = 50;

/**
 * Append one event to the durable queue (synchronous helper for use inside mutate()).
 * Assigns the per-session sequence number and a client-generated UUID used for server-side dedupe.
 * Returns the event, or null when monitoring is not active / the event type is disabled by the admin.
 */
export function pushEvent(state, queue, type, details = {}) {
  const s = state.session;
  if (!s || !s.active) return null;
  const enabled = state.config?.settings?.enabled_events;
  if (Array.isArray(enabled) && !enabled.includes(type)) return null;
  s.seq += 1;
  s.eventCount += 1;
  const ev = { event_uuid: crypto.randomUUID(), seq: s.seq, type, client_timestamp: new Date().toISOString(), details, session_id: s.id };
  queue.push(ev);
  if (queue.length > MAX_QUEUE) {              // never silent: count what we had to drop
    const drop = queue.length - MAX_QUEUE;
    queue.splice(0, drop);
    s.dropped = (s.dropped || 0) + drop;
  }
  return ev;
}

/** Run detector logic atomically. fn(sessionState, fullState) -> event | [events] | null; may mutate flags on the session. */
export async function detect(fn) {
  const created = await mutate((state, queue) => {
    if (!state.session || !state.session.active) return [];
    const out = fn(state.session, state);
    const list = Array.isArray(out) ? out : out ? [out] : [];
    return list.map((e) => pushEvent(state, queue, e.type, e.details || {})).filter(Boolean);
  });
  return created;
}

export async function queueLength(sessionId) {
  const q = await getQueue();
  return sessionId == null ? q.length : q.filter((e) => e.session_id === sessionId).length;
}

export async function peekBatch(sessionId, n = BATCH_SIZE) {
  const q = await getQueue();
  return q.filter((e) => e.session_id === sessionId).slice(0, n);
}

/** Remove events the server has processed (accepted, duplicate or rejected). */
export function ackBatch(uuids) {
  const set = new Set(uuids);
  return mutate((state, queue) => {
    for (let i = queue.length - 1; i >= 0; i--) if (set.has(queue[i].event_uuid)) queue.splice(i, 1);
  });
}

export function discardSession(sessionId) {
  return mutate((state, queue) => {
    for (let i = queue.length - 1; i >= 0; i--) if (queue[i].session_id === sessionId) queue.splice(i, 1);
  });
}
