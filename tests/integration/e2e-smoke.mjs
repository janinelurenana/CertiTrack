// End-to-end API smoke test for the Phase-3 gate (steps 1-10 + negative checks).
// Run against the REAL Laravel backend after migrate + seed:  BASE_URL=http://127.0.0.1:8000 node tests/integration/e2e-smoke.mjs
// (tests/extension/run-tests.mjs also runs it against a contract stub, which only validates this script.)
const BASE = (process.env.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const PW = process.env.DEV_PASSWORD || 'Password123!';
let pass = 0, fail = 0;
const ok = (name, cond, info = '') => { cond ? pass++ : fail++; console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${cond ? '' : '  -> ' + info}`); };

async function call(method, path, token, body) {
  const r = await fetch(BASE + '/api' + path, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) }, body: body ? JSON.stringify(body) : undefined });
  let j = null; try { j = await r.json(); } catch { /* none */ }
  return { s: r.status, j };
}
const login = async (email) => (await call('POST', '/login', null, { email, password: PW })).j?.data?.token;
const ev = (seq, type, details) => ({ event_uuid: crypto.randomUUID(), seq, type, client_timestamp: new Date().toISOString(), details });

const s1 = await login('student1@certitrack.test'), s2 = await login('student2@certitrack.test');
const i1 = await login('instructor1@certitrack.test'), i2 = await login('instructor2@certitrack.test');
ok('1. student/instructor logins return tokens', s1 && s2 && i1 && i2, 'seed the database first');
if (!(s1 && s2 && i1 && i2)) process.exit(1);

ok('unauthenticated request rejected (401)', (await call('GET', '/platforms')).s === 401);
ok('wrong password rejected (401)', (await call('POST', '/login', null, { email: 'student1@certitrack.test', password: 'wrong' })).s === 401);

// 2. extension pairing
const code = (await call('POST', '/pairing-codes', s1)).j?.data?.code;
const pair = await call('POST', '/extension/pair', null, { code, device_name: 'smoke' });
const ext = pair.j?.data?.token;
ok('2. extension pairing with one-time code', pair.s === 201 && ext, JSON.stringify(pair.j));
ok('pairing code is single-use', (await call('POST', '/extension/pair', null, { code })).s === 422);
ok('student cannot create pairing code via instructor token', (await call('POST', '/pairing-codes', i1)).s === 403);

// 3. start an authorised session (close leftovers from previous runs first)
const left = (await call('GET', '/sessions/active', ext)).j?.data;
if (left) await call('POST', `/sessions/${left.id}/end`, ext, {});
const cfg = (await call('GET', '/platforms/config', ext)).j?.data;
const plat = cfg?.platforms?.find((p) => p.slug === 'local-test');
ok('platform config served to extension', !!plat && plat.domains.length > 0 && cfg.settings.heartbeat_interval_sec > 0);
ok('session refused without consent (422)', (await call('POST', '/sessions', ext, { platform_id: plat.id })).s === 422);
const started = await call('POST', '/sessions', ext, { platform_id: plat.id, consent: true, client_id: 'smoke' });
const sid = started.j?.data?.id;
ok('3. session started (201, status active)', started.s === 201 && started.j.data.status === 'active', JSON.stringify(started.j));
ok('only one open session per student (409)', (await call('POST', '/sessions', ext, { platform_id: plat.id, consent: true })).s === 409);

// 4-6. events
const batch = [ev(1, 'tab_switched', { direction: 'away' }), ev(2, 'tab_switched', { direction: 'return' }), ev(3, 'navigation_outside_domain', { host: 'example.com', url: 'https://example.com/?token=SECRET' })];
const r1 = await call('POST', `/sessions/${sid}/events`, ext, { events: batch });
ok('4-6. batch accepted (3)', r1.s === 200 && r1.j.data.accepted.length === 3, JSON.stringify(r1.j));
const r2 = await call('POST', `/sessions/${sid}/events`, ext, { events: batch });
ok('duplicate batch rejected as duplicates (3), nothing new stored', r2.j?.data?.accepted.length === 0 && r2.j.data.duplicates.length === 3, JSON.stringify(r2.j));
const r3 = await call('POST', `/sessions/${sid}/events`, ext, { events: [ev(9, 'heartbeat_timeout', {})] });
ok('client cannot submit server-only event types', r3.j?.data?.rejected?.length === 1);
ok('heartbeat accepted', (await call('POST', `/sessions/${sid}/heartbeat`, ext, { last_seq: 3 })).s === 200);

// 7-8. authorisation
ok('other student cannot read the session (404)', (await call('GET', `/sessions/${sid}`, s2)).s === 404);
ok('other student cannot post events (404)', (await call('POST', `/sessions/${sid}/events`, s2, { events: [ev(1, 'tab_opened', {})] })).s === 404);
ok('student cannot use instructor API (403)', (await call('GET', '/instructor/sessions', s1)).s === 403);
const view = await call('GET', `/instructor/sessions/${sid}/events`, i1);
const types = (view.j?.data || []).map((e) => e.type);
ok('7-8. assigned instructor retrieves the actual events', view.s === 200 && types.filter((t) => t === 'tab_switched').length === 2 && types.includes('navigation_outside_domain') && types.includes('session_started'), JSON.stringify(types));
ok('event association: correct session and server-assigned severity', view.j.data.find((e) => e.type === 'navigation_outside_domain')?.severity === 'medium');
ok('privacy: URL/query never stored', !JSON.stringify(view.j.data).includes('SECRET'));
ok('unassigned instructor blocked (404)', (await call('GET', `/instructor/sessions/${sid}/events`, i2)).s === 404);
const list = await call('GET', '/instructor/sessions', i1);
const rows = list.j?.data?.data ?? list.j?.data ?? [];
ok('instructor session list contains the session', rows.some((s) => s.id === sid));
const rev = await call('POST', `/instructor/sessions/${sid}/review`, i1, { status: 'reviewed', remarks: 'smoke test' });
ok('instructor review saved (201)', rev.s === 201, JSON.stringify(rev.j));
ok('unassigned instructor cannot review', (await call('POST', `/instructor/sessions/${sid}/review`, i2, { status: 'reviewed' })).s === 404);

// 9-10. end and persistence
const end = await call('POST', `/sessions/${sid}/end`, ext, { last_seq: 3 });
ok('9. session ended (completed)', end.s === 200 && end.j.data.status === 'completed');
ok('end is idempotent', (await call('POST', `/sessions/${sid}/end`, ext, { last_seq: 3 })).s === 200);
ok('heartbeat on closed session rejected (409)', (await call('POST', `/sessions/${sid}/heartbeat`, ext, {})).s === 409);
const after = await call('GET', `/sessions/${sid}/events`, s1);
ok('10. session and events remain available to the student afterwards', after.s === 200 && after.j.data.length >= 5);
const late = await call('POST', `/sessions/${sid}/events`, ext, { events: [ev(4, 'tab_opened', {})] });
ok('late event after end is still accepted (not lost)', late.j?.data?.accepted?.length === 1);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
