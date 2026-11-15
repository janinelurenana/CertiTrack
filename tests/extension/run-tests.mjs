// Extension logic tests: the REAL extension code runs against a fake Chrome API and a CONTRACT STUB server.
// Each worker-step is a separate Node process, so persisted state must survive "service worker suspension".
// These tests do NOT exercise Laravel/MySQL.
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createStub } from './stub-server.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const worker = path.join(here, 'worker-step.mjs');
const exec = promisify(execFile);
const stub = createStub();
const BASE = await stub.listen();
const results = [];
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ct-'));
let n = 0;

async function step(store, actions) {
  const { stdout, stderr } = await exec(process.execPath, [worker, store, JSON.stringify(actions)], { timeout: 60000 });
  const line = stdout.split('\n').find((l) => l.startsWith('@@RESULT@@'));
  if (!line) throw new Error('worker produced no result: ' + stderr);
  return JSON.parse(line.slice(10));
}
const check = (name, ok, info = '') => { results.push({ name, ok: !!ok, info: ok ? '' : String(info) }); console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${ok ? '' : '  -> ' + info}`); };
const examTab = (url = 'http://localhost:8000/demo-target?x=1') => ({ id: 1, windowId: 1, url, active: true });
const otherTab = { id: 2, windowId: 1, url: 'https://other.test/', active: false };
const meta = (tabs, extra = {}) => ({ do: 'meta', patch: { tabs, focusedWindow: 1, browserFocused: true, alarms: {}, ...extra } });
const msg = (type, extra = {}) => ({ do: 'msg', msg: { type, ...extra } });

async function pairCode(email = 'student1@certitrack.test') {
  const l = await (await fetch(BASE + '/api/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'Password123!' }) })).json();
  const c = await (await fetch(BASE + '/api/pairing-codes', { method: 'POST', headers: { Authorization: 'Bearer ' + l.data.token } })).json();
  return c.data.code;
}
async function fresh(email) {
  const store = path.join(tmp, `s${++n}.json`);
  const code = await pairCode(email);
  const [, , pairRes] = await step(store, [msg('SET_SERVER', { baseUrl: BASE }), meta([examTab(), otherTab]), msg('PAIR', { code })]);
  return { store, pairRes };
}
const startAction = (platformId = 3) => msg('START', { platformId, consent: true });
const sessionOf = () => stub.db.sessions[stub.db.sessions.length - 1];

// ---------- S1: pairing, platform detection, consent, start ----------
{
  const { store, pairRes } = await fresh();
  check('extension pairing succeeds with one-time code', pairRes?.ok, JSON.stringify(pairRes));
  const [, noConsent] = await step(store, [meta([examTab(), otherTab]), msg('START', { platformId: 3, consent: false })]);
  check('start refused without consent', noConsent && !noConsent.ok);
  const [, wrongTab] = await step(store, [meta([examTab('https://example.com/'), otherTab]), startAction()]);
  check('platform detection: start refused when active tab is not on approved domain', wrongTab && !wrongTab.ok && /Open the/.test(wrongTab.error), JSON.stringify(wrongTab));
  const [, started, status] = await step(store, [meta([examTab(), otherTab]), startAction(), msg('GET_STATUS')]);
  check('session starts on approved tab and is stored server-side for the right student', started?.ok && sessionOf()?.student_id === 1 && sessionOf()?.status === 'active', JSON.stringify(started));
  check('status reports monitoring active, platform detected', status?.data?.session?.active === true && status.data.detectedPlatformId === 3);
  const [, dup] = await step(store, [meta([examTab(), otherTab]), startAction()]);
  check('second start in same browser refused', dup && !dup.ok);
  await step(store, [msg('END', {})]);
}

// ---------- S1b: new platform via configuration only ----------
{
  stub.addPlatform({ id: 99, name: 'Acme Cert (config only)', slug: 'acme', domains: [{ host: 'exams.acme.test', match_type: 'suffix', path_prefix: null }] });
  const { store } = await fresh('student2@certitrack.test');
  const tab = { id: 1, windowId: 1, url: 'https://lms.exams.acme.test/start?id=7', active: true };
  const [, , r] = await step(store, [meta([tab, otherTab]), msg('REFRESH_CONFIG'), msg('START', { platformId: 99, consent: true })]);
  check('new platform works after config refresh with NO extension code change', r?.ok && sessionOf()?.platform_id === 99, JSON.stringify(r));
  await step(store, [msg('END', {})]);
}

// ---------- S2: each event type, across several worker lifetimes ----------
{
  const { store } = await fresh();
  await step(store, [meta([examTab(), otherTab]), startAction()]);
  const sid = sessionOf().id;
  const T = [examTab(), otherTab];
  // lifetime 2: tab switch away/return + new tab + focus lost
  await step(store, [
    { do: 'fire', event: 'tabs.onActivated', args: [{ tabId: 2, windowId: 1 }] },
    { do: 'fire', event: 'tabs.onActivated', args: [{ tabId: 1, windowId: 1 }] },
    { do: 'fire', event: 'tabs.onCreated', args: [{ id: 3, openerTabId: 1 }] },
    meta(T, { browserFocused: false }), { do: 'fire', event: 'windows.onFocusChanged', args: [-1], wait: 500 },
  ]);
  // lifetime 3 (after "suspension"): focus back, outside navigation (with secret query), subframe ignored, return, close
  await step(store, [
    meta(T, { browserFocused: true }), { do: 'fire', event: 'windows.onFocusChanged', args: [1], wait: 500 },
    { do: 'fire', event: 'webNavigation.onCommitted', args: [{ tabId: 1, frameId: 0, url: 'https://example.com/private/path?token=SECRET&q=answer' }] },
    { do: 'fire', event: 'webNavigation.onCommitted', args: [{ tabId: 1, frameId: 1, url: 'https://ads.example.net/frame' }] },
    { do: 'fire', event: 'webNavigation.onCommitted', args: [{ tabId: 1, frameId: 0, url: 'http://localhost:8000/demo-target?back=1' }] },
    { do: 'fire', event: 'tabs.onRemoved', args: [1, { isWindowClosing: false }] },
    { do: 'alarm' },
  ]);
  const ev = stub.eventsOf(sid).filter((e) => e.source === 'extension');
  const types = ev.map((e) => e.type);
  const expected = ['tab_switched', 'tab_switched', 'tab_opened', 'window_focus_lost', 'window_focus_gained', 'navigation_outside_domain', 'navigation_returned', 'exam_tab_closed'];
  check('S2 all 8 scripted activities captured in order (capture rate 8/8 = 100%)', JSON.stringify(types) === JSON.stringify(expected), JSON.stringify(types));
  check('S2 sequence numbers contiguous 1..8', JSON.stringify(ev.map((e) => e.seq)) === JSON.stringify([1, 2, 3, 4, 5, 6, 7, 8]));
  const nav = ev.find((e) => e.type === 'navigation_outside_domain');
  check('S2 outside navigation records host only', nav?.details?.host === 'example.com' && !JSON.stringify(ev).includes('SECRET') && !JSON.stringify(ev).includes('private'), JSON.stringify(nav));
  check('S2 subframe navigation ignored', !ev.some((e) => e.details?.host === 'ads.example.net'));
  check('S2 severity assigned by server', ev.find((e) => e.type === 'exam_tab_closed')?.severity === 'medium' && ev[0].severity === 'low');
  // reopening exam page re-binds
  await step(store, [meta([{ id: 4, windowId: 1, url: 'http://localhost:8000/demo-target', active: true }]), { do: 'fire', event: 'webNavigation.onCommitted', args: [{ tabId: 4, frameId: 0, url: 'http://localhost:8000/demo-target' }] }, { do: 'alarm' }]);
  check('S2 exam tab re-binding recorded', stub.eventsOf(sid).some((e) => e.type === 'exam_tab_reopened'));
  await step(store, [msg('END', {})]);
}

// ---------- S3: offline queue, resync, duplicate protection ----------
{
  const { store } = await fresh();
  await step(store, [meta([examTab(), otherTab]), startAction()]);
  const sid = sessionOf().id;
  stub.setOffline(true);
  await step(store, [
    { do: 'fire', event: 'tabs.onActivated', args: [{ tabId: 2, windowId: 1 }], wait: 300 },
    { do: 'fire', event: 'tabs.onActivated', args: [{ tabId: 1, windowId: 1 }], wait: 300 },
  ]);
  check('S3 no events reached server while offline', stub.eventsOf(sid).filter((e) => e.source === 'extension').length === 0);
  const [, st] = await step(store, [{ do: 'fire', event: 'tabs.onCreated', args: [{ id: 5, openerTabId: 1 }], wait: 200 }, msg('GET_STATUS')]);
  check('S3 events persisted in queue across worker restart; connection shown offline', st.data.session.queued >= 4 && st.data.connection.online === false, JSON.stringify(st.data.session));
  stub.setOffline(false);
  await step(store, [{ do: 'alarm', wait: 600 }]);
  const ev = stub.eventsOf(sid).filter((e) => e.source === 'extension');
  const types = ev.map((e) => e.type);
  check('S3 queued events synchronised after reconnection (none lost)', ['tab_switched', 'tab_opened', 'connection_lost', 'connection_restored'].every((t) => types.includes(t)) && ev.length === 5, JSON.stringify(types));
  check('S3 no duplicates, contiguous seq', new Set(ev.map((e) => e.event_uuid)).size === ev.length && JSON.stringify(ev.map((e) => e.seq).sort((a, b) => a - b)) === '[1,2,3,4,5]');
  // lost-response case: server stores batch but client never sees the 200
  stub.loseNextEventResponse();
  await step(store, [{ do: 'fire', event: 'tabs.onCreated', args: [{ id: 6, openerTabId: 1 }], wait: 300 }, { do: 'alarm', wait: 600 }]);
  const after = stub.eventsOf(sid).filter((e) => e.source === 'extension');
  const seqs = after.map((e) => e.seq).sort((a, b) => a - b);
  check('S3 retry after lost response creates no duplicate (tab_opened stored once more, not twice)', after.filter((e) => e.type === 'tab_opened').length === 2 && new Set(after.map((e) => e.event_uuid)).size === after.length && JSON.stringify(seqs) === JSON.stringify(seqs.map((_, i) => i + 1)), JSON.stringify(after.map((e) => e.type + '#' + e.seq)));
  check('S3 connection_lost/restored recorded around the lost response', after.filter((e) => e.type === 'connection_lost').length === 2 && after.filter((e) => e.type === 'connection_restored').length === 2);
  const [st2] = await step(store, [msg('GET_STATUS')]);
  check('S3 queue empty after acknowledgement', st2.data.session.queued === 0);
  await step(store, [msg('END', {})]);
}

// ---------- S4: heartbeat / interruption / resume ----------
{
  const { store } = await fresh();
  await step(store, [meta([examTab(), otherTab]), startAction(), { do: 'alarm' }]);
  const s = sessionOf();
  check('S4 heartbeat accepted', Date.now() + 5000 > s.last_heartbeat_at);
  stub.advance(130); stub.sweep();
  check('S4 missing heartbeats mark session interrupted (server-side event recorded)', s.status === 'interrupted' && stub.eventsOf(s.id).some((e) => e.type === 'heartbeat_timeout' && e.severity === 'medium'));
  await step(store, [{ do: 'alarm' }]);
  check('S4 resumed heartbeat returns session to active and logs monitoring_resumed', s.status === 'active' && stub.eventsOf(s.id).some((e) => e.type === 'monitoring_resumed'));
  stub.advance(5000); stub.sweep(); s.status = 'completed'; // simulate server closing the session while extension still thinks it is running
  const [, hb] = await step(store, [{ do: 'alarm' }, msg('GET_STATUS')]);
  check('S4 extension stops monitoring when server reports session closed (409)', hb.data.session && hb.data.session.active === false && /closed this session/.test(hb.data.notice || ''), JSON.stringify(hb.data));
  await step(store, [{ do: 'alarm' }]);
}

// ---------- S5: ending while offline ----------
{
  const { store } = await fresh();
  await step(store, [meta([examTab(), otherTab]), startAction()]);
  const sid = sessionOf().id;
  stub.setOffline(true);
  const [, endRes, st] = await step(store, [{ do: 'fire', event: 'tabs.onActivated', args: [{ tabId: 2, windowId: 1 }], wait: 250 }, msg('END', {}), msg('GET_STATUS')]);
  check('S5 end while offline leaves session pending (not silently dropped)', endRes?.data?.status === 'pending' && st.data.session.pendingEnd === true && sessionOf().status === 'active', JSON.stringify(endRes));
  const [, st2] = await step(store, [{ do: 'fire', event: 'tabs.onCreated', args: [{ id: 7 }], wait: 200 }, msg('GET_STATUS')]);
  check('S5 no new events recorded after end requested', st2.data.session.eventCount === st.data.session.eventCount);
  stub.setOffline(false);
  await step(store, [{ do: 'alarm', wait: 800 }]);
  const ev = stub.eventsOf(sid).filter((e) => e.source === 'extension');
  check('S5 pending end completes after reconnection; all events delivered; session completed', sessionOf().status === 'completed' && ev.some((e) => e.type === 'tab_switched') && stub.eventsOf(sid).some((e) => e.type === 'session_ended'), sessionOf().status);
  check('S5 integrity: client_last_seq equals received count', sessionOf().client_last_seq === ev.length, `${sessionOf().client_last_seq} vs ${ev.length}`);
  const [final] = await step(store, [msg('GET_STATUS')]);
  check('S5 local session cleared after completion', final.data.session === null);
}

// ---------- S6: browser restart ----------
{
  const { store } = await fresh();
  await step(store, [meta([examTab(), otherTab]), startAction()]);
  const sid = sessionOf().id;
  await step(store, [{ do: 'fire', event: 'runtime.onStartup', args: [], wait: 400 }, { do: 'alarm' }]);
  const closed = stub.eventsOf(sid).find((e) => e.type === 'exam_tab_closed');
  check('S6 browser restart recorded as exam_tab_closed(browser_restarted); session state survived', closed?.details?.reason === 'browser_restarted', JSON.stringify(closed));
  await step(store, [msg('END', {})]);
}

// ---------- S7: authorization contract (stub) + smoke script self-check ----------
{
  const smoke = await exec(process.execPath, [path.join(here, '..', 'integration', 'e2e-smoke.mjs')], { env: { ...process.env, BASE_URL: BASE }, timeout: 60000 }).then((r) => ({ ok: true, out: r.stdout }), (e) => ({ ok: false, out: (e.stdout || '') + (e.stderr || '') }));
  check('S7 e2e-smoke.mjs script runs green against the CONTRACT STUB (validates the script only, not Laravel)', smoke.ok, smoke.out.slice(-600));
}

await stub.close();
const failed = results.filter((r) => !r.ok);
const out = { generated_at: new Date().toISOString(), node: process.version, target: 'extension code + fake chrome + contract stub (NOT Laravel)', total: results.length, passed: results.length - failed.length, failed: failed.length, results };
fs.writeFileSync(path.join(here, '..', '..', 'documentation', 'test-results-extension.json'), JSON.stringify(out, null, 2));
console.log(`\n${out.passed}/${out.total} passed`);
process.exit(failed.length ? 1 : 0);
