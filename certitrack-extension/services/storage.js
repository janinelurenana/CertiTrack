// All durable state lives in chrome.storage.local because the MV3 service worker can be
// suspended at any time. Writes of state + queue happen in ONE storage.set call, serialised by a lock.

const STATE_KEY = 'ct_state';
const QUEUE_KEY = 'ct_queue';

export const DEFAULT_STATE = {
  server: { baseUrl: 'http://127.0.0.1:8000', token: null, user: null, clientId: null },
  session: null,        // see session-manager.startSession for shape
  connection: { online: true, offlineSince: null, lastSuccessAt: null, lastError: null },
  config: null,         // { platforms, settings, fetchedAt }
  notice: null,         // user-visible message
};

export async function getState() {
  const r = await chrome.storage.local.get(STATE_KEY);
  const s = r[STATE_KEY] || {};
  return {
    ...DEFAULT_STATE, ...s,
    server: { ...DEFAULT_STATE.server, ...(s.server || {}) },
    connection: { ...DEFAULT_STATE.connection, ...(s.connection || {}) },
  };
}

export async function getQueue() {
  const r = await chrome.storage.local.get(QUEUE_KEY);
  return Array.isArray(r[QUEUE_KEY]) ? r[QUEUE_KEY] : [];
}

let chain = Promise.resolve();
/** Run fn exclusively (within this worker lifetime). Never call withLock/mutate from inside fn. */
export function withLock(fn) {
  const run = chain.then(fn);
  chain = run.catch(() => {});
  return run;
}

/** Atomically read state+queue, let `fn(state, queue)` mutate them synchronously, persist both. */
export function mutate(fn) {
  return withLock(async () => {
    const state = await getState();
    const queue = await getQueue();
    const result = fn(state, queue);
    await chrome.storage.local.set({ [STATE_KEY]: state, [QUEUE_KEY]: queue });
    return result;
  });
}

export async function ensureClientId() {
  return mutate((state) => {
    if (!state.server.clientId) state.server.clientId = crypto.randomUUID();
    return state.server.clientId;
  });
}
