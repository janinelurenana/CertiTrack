import { getState } from './storage.js';

export class NetworkError extends Error {}

/** Minimal JSON client. Throws NetworkError on connection failure/timeouts; otherwise returns {ok,status,data}. */
export async function apiFetch(path, { method = 'GET', body, auth = true, timeoutMs = 10000 } = {}) {
  const state = await getState();
  const base = state.server.baseUrl.replace(/\/+$/, '');
  const headers = { Accept: 'application/json' };
  if (body) headers['Content-Type'] = 'application/json';
  if (auth && state.server.token) headers.Authorization = `Bearer ${state.server.token}`;
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), timeoutMs);
  let res;
  try {
    res = await fetch(`${base}/api${path}`, { method, headers, body: body ? JSON.stringify(body) : undefined, signal: ctrl.signal });
  } catch (e) {
    throw new NetworkError(e && e.name === 'AbortError' ? 'Request timed out' : `Cannot reach server (${e && e.message})`);
  } finally {
    clearTimeout(timer);
  }
  let data = null;
  try { data = await res.json(); } catch { /* non-JSON body */ }
  return { ok: res.ok, status: res.status, data };
}

/** 5xx and 429 are treated like connectivity problems: keep events queued and retry later. */
export const isTransient = (r) => r.status >= 500 || r.status === 429;
