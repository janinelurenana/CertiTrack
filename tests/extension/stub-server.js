// CONTRACT STUB - NOT the Laravel backend. It re-implements the documented API contract in memory so the
// extension logic (queueing, dedupe, resync, suspension) can be tested without PHP/MySQL.
// Passing tests here do NOT prove the Laravel implementation works; use tests/integration/e2e-smoke.mjs against it.
import http from 'node:http';
import crypto from 'node:crypto';

const EXT_TYPES = { tab_switched: 'low', tab_opened: 'low', window_focus_lost: 'low', window_focus_gained: 'info', navigation_outside_domain: 'medium', navigation_returned: 'info', exam_tab_closed: 'medium', exam_tab_reopened: 'info', connection_lost: 'medium', connection_restored: 'info' };
const SERVER_SEV = { session_started: 'info', session_ended: 'info', heartbeat_timeout: 'medium', monitoring_resumed: 'info', extension_disconnected: 'high', session_abandoned: 'info' };
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export function createStub() {
  const db = {
    users: [
      { id: 1, name: 'Sara Student', email: 'student1@certitrack.test', role: 'student' },
      { id: 2, name: 'Sam Student', email: 'student2@certitrack.test', role: 'student' },
      { id: 3, name: 'Ines Instructor', email: 'instructor1@certitrack.test', role: 'instructor' },
      { id: 4, name: 'Ivan Instructor', email: 'instructor2@certitrack.test', role: 'instructor' },
    ],
    assignments: [{ student_id: 1, instructor_id: 3 }],
    platforms: [
      { id: 1, name: 'Cisco Networking Academy', slug: 'cisco-netacad', domains: [{ host: 'netacad.com', match_type: 'suffix', path_prefix: null }] },
      { id: 2, name: 'Fortinet Training Institute', slug: 'fortinet', domains: [{ host: 'training.fortinet.com', match_type: 'suffix', path_prefix: null }] },
      { id: 3, name: 'Local Test Page (demo)', slug: 'local-test', domains: [{ host: 'localhost', match_type: 'exact', path_prefix: '/demo-target' }, { host: '127.0.0.1', match_type: 'exact', path_prefix: '/demo-target' }] },
    ],
    sessions: [], events: [], tokens: new Map(), codes: new Map(), nextSession: 1, nextEvent: 1,
  };
  let offline = false, loseNext = false, offset = 0, requests = 0;
  const now = () => Date.now() + offset;
  const iso = (t = now()) => new Date(t).toISOString();

  const sev = (t) => EXT_TYPES[t] ?? SERVER_SEV[t] ?? 'info';
  function serverEvent(s, type, details = null) {
    db.events.push({ id: db.nextEvent++, session_id: s.id, event_uuid: crypto.randomUUID(), seq: null, type, severity: sev(type), source: 'server', received_at: iso(), occurred_at: iso(), details, received_after_end: !open(s) });
  }
  const open = (s) => ['active', 'interrupted'].includes(s.status);
  function touch(s) {
    if (!open(s)) return;
    s.last_heartbeat_at = now();
    if (s.status === 'interrupted') { s.status = 'active'; serverEvent(s, 'monitoring_resumed'); }
  }
  function sweep() {
    for (const s of db.sessions) if (s.status === 'active' && now() - s.last_heartbeat_at > 120000) { s.status = 'interrupted'; serverEvent(s, 'heartbeat_timeout'); }
  }
  function visible(user, s) {
    if (user.role === 'student') return s.student_id === user.id;
    if (user.role === 'instructor') return db.assignments.some((a) => a.instructor_id === user.id && a.student_id === s.student_id);
    return true;
  }
  const present = (s) => ({ ...s, started_at: iso(s.started_at), last_heartbeat_at: iso(s.last_heartbeat_at), platform: db.platforms.find((p) => p.id === s.platform_id), possibly_missing_events: Math.max(0, s.client_last_seq - db.events.filter((e) => e.session_id === s.id && e.source === 'extension').length) });
  const clean = (d) => { const o = {}; if (d && typeof d.host === 'string' && /^[a-z0-9.\-]{1,253}$/i.test(d.host)) o.host = d.host.toLowerCase(); for (const k of ['direction', 'reason', 'opener_is_exam', 'gap_seconds']) if (d && d[k] !== undefined) o[k] = d[k]; return Object.keys(o).length ? o : null; };

  async function handle(req, res) {
    requests++;
    if (offline) { req.socket.destroy(); return; }
    const url = new URL(req.url, 'http://x'); const path = url.pathname;
    let body = ''; for await (const c of req) body += c;
    const json = body ? JSON.parse(body) : {};
    const out = (status, data) => { res.writeHead(status, { 'Content-Type': 'application/json' }); res.end(JSON.stringify(data)); };
    const user = (() => { const t = (req.headers.authorization || '').replace('Bearer ', ''); return db.tokens.get(t) || null; })();
    const need = () => { if (!user) { out(401, { message: 'Unauthenticated.' }); return false; } return true; };
    sweep();
    let m;
    if (req.method === 'POST' && path === '/api/login') {
      const u = db.users.find((x) => x.email === json.email);
      if (!u || json.password !== 'Password123!') return out(401, { message: 'Invalid credentials.' });
      const token = crypto.randomUUID(); db.tokens.set(token, u); return out(200, { data: { token, user: u } });
    }
    if (req.method === 'POST' && path === '/api/extension/pair') {
      const uid = db.codes.get(String(json.code || '').toUpperCase()); if (!uid) return out(422, { message: 'Pairing code is invalid or expired.' });
      db.codes.delete(String(json.code).toUpperCase()); const u = db.users.find((x) => x.id === uid);
      const token = crypto.randomUUID(); db.tokens.set(token, u); return out(201, { data: { token, user: u } });
    }
    if (!need()) return;
    if (req.method === 'GET' && path === '/api/me') return out(200, { data: user });
    if (req.method === 'POST' && (m = path.match(/^\/api\/instructor\/sessions\/(\d+)\/review$/))) {
      if (user.role !== 'instructor') return out(403, { message: 'forbidden' });
      const s = db.sessions.find((x) => x.id === +m[1]); if (!s || !visible(user, s)) return out(404, { message: 'Resource not found.' });
      return out(201, { data: { status: json.status, remarks: json.remarks } });
    }
    if (req.method === 'POST' && path === '/api/pairing-codes') { if (user.role !== 'student') return out(403, { message: 'forbidden' }); const code = crypto.randomBytes(4).toString('hex').toUpperCase(); db.codes.set(code, user.id); return out(201, { data: { code } }); }
    if (req.method === 'GET' && path === '/api/platforms/config') return out(200, { data: { platforms: db.platforms, settings: { heartbeat_interval_sec: 30, heartbeat_timeout_sec: 120, enabled_events: Object.keys(EXT_TYPES), consent_version: '2026-01' }, server_time: iso() } });
    if (req.method === 'POST' && path === '/api/sessions') {
      if (user.role !== 'student') return out(403, { message: 'forbidden' });
      if (json.consent !== true) return out(422, { message: 'consent required', errors: { consent: ['required'] } });
      const ex = db.sessions.find((s) => s.student_id === user.id && open(s)); if (ex) return out(409, { message: 'already open', data: present(ex) });
      const s = { id: db.nextSession++, student_id: user.id, platform_id: json.platform_id, status: 'active', started_at: now(), last_heartbeat_at: now(), client_last_seq: 0, event_count: 0 };
      db.sessions.push(s); serverEvent(s, 'session_started'); return out(201, { data: present(s) });
    }
    if (req.method === 'GET' && path === '/api/sessions/active') { const s = db.sessions.find((x) => x.student_id === user.id && open(x)); return out(200, { data: s ? present(s) : null }); }
    if ((m = path.match(/^\/api\/(?:instructor\/)?sessions\/(\d+)(?:\/(events|heartbeat|end))?$/))) {
      const s = db.sessions.find((x) => x.id === +m[1]); const sub = m[2]; const isInstr = path.includes('/instructor/');
      if (isInstr && !['instructor', 'admin'].includes(user.role)) return out(403, { message: 'forbidden' });
      if (!s || !visible(user, s)) return out(404, { message: 'Resource not found.' });
      if (req.method === 'GET' && !sub) return out(200, { data: present(s) });
      if (req.method === 'GET' && sub === 'events') return out(200, { data: db.events.filter((e) => e.session_id === s.id).sort((a, b) => a.id - b.id), meta: { note: 'stub' } });
      if (s.student_id !== user.id) return out(404, { message: 'Resource not found.' });
      if (req.method === 'POST' && sub === 'heartbeat') { if (!open(s)) return out(409, { message: 'Session is closed.', data: { status: s.status } }); if (json.last_seq) s.client_last_seq = Math.max(s.client_last_seq, json.last_seq); touch(s); return out(200, { data: { status: s.status } }); }
      if (req.method === 'POST' && sub === 'end') { if (open(s)) { s.status = 'completed'; s.ended_at = now(); if (json.last_seq) s.client_last_seq = Math.max(s.client_last_seq, json.last_seq); serverEvent(s, 'session_ended'); } return out(200, { data: present(s) }); }
      if (req.method === 'POST' && sub === 'events') {
        const accepted = [], duplicates = [], rejected = [];
        for (const e of json.events || []) {
          if (!UUID.test(e.event_uuid || '') || !Number.isInteger(e.seq) || !(e.type in EXT_TYPES)) { rejected.push({ event_uuid: e.event_uuid, reason: 'invalid' }); continue; }
          if (db.events.some((x) => x.event_uuid === e.event_uuid)) { duplicates.push(e.event_uuid); continue; }
          if (db.events.some((x) => x.session_id === s.id && x.seq === e.seq)) { rejected.push({ event_uuid: e.event_uuid, reason: 'seq_conflict' }); continue; }
          db.events.push({ id: db.nextEvent++, session_id: s.id, event_uuid: e.event_uuid, seq: e.seq, type: e.type, severity: sev(e.type), source: 'extension', client_timestamp: e.client_timestamp, received_at: iso(), occurred_at: e.client_timestamp, details: clean(e.details), received_after_end: !open(s) });
          accepted.push(e.event_uuid); s.event_count++; s.client_last_seq = Math.max(s.client_last_seq, e.seq);
        }
        touch(s);
        if (loseNext) { loseNext = false; req.socket.destroy(); return; } // processed, but the response never arrives
        return out(200, { data: { accepted, duplicates, rejected, session_status: s.status } });
      }
    }
    if (req.method === 'GET' && path === '/api/instructor/sessions') {
      if (!['instructor', 'admin'].includes(user.role)) return out(403, { message: 'forbidden' });
      return out(200, { data: db.sessions.filter((s) => visible(user, s)).map(present) });
    }
    out(404, { message: 'Resource not found.' });
  }

  const server = http.createServer((req, res) => handle(req, res).catch((e) => { res.writeHead(500); res.end(JSON.stringify({ message: String(e) })); }));
  return {
    db,
    listen: () => new Promise((r) => server.listen(0, '127.0.0.1', () => r(`http://127.0.0.1:${server.address().port}`))),
    close: () => new Promise((r) => { server.closeAllConnections?.(); server.close(r); }),
    setOffline: (v) => { offline = v; },
    loseNextEventResponse: () => { loseNext = true; },
    advance: (sec) => { offset += sec * 1000; },
    sweep,
    addPlatform: (p) => db.platforms.push(p),
    eventsOf: (sid) => db.events.filter((e) => e.session_id === sid),
  };
}
