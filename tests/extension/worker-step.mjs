// One "service-worker lifetime": loads the REAL extension service worker, runs scripted actions, exits.
// Persisted state survives only through the storage file, exactly like a suspended MV3 worker.
import { installFakeChrome } from './fake-chrome.js';
const [storeFile, actionsJson] = process.argv.slice(2);
const fake = installFakeChrome(storeFile);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
await import('../../certitrack-extension/background/service-worker.js'); // registers listeners, resumes sync
await sleep(150);
const results = [];
for (const a of JSON.parse(actionsJson)) {
  if (a.do === 'meta') { fake.setMeta(a.patch); results.push(null); }
  else if (a.do === 'msg') results.push(await fake.message(a.msg));
  else if (a.do === 'fire') { await fake.fire(a.event, ...(a.args || [])); await sleep(a.wait ?? 120); results.push(null); }
  else if (a.do === 'alarm') { await fake.fire('alarms.onAlarm', { name: 'ct-tick' }); await sleep(a.wait ?? 250); results.push(null); }
  else if (a.do === 'wait') { await sleep(a.ms); results.push(null); }
}
await sleep(200);
console.log('@@RESULT@@' + JSON.stringify(results));
process.exit(0);
