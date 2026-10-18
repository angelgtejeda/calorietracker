// Exercise the actual inline sync client without a browser or a live NAS.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const html = fs.readFileSync(path.join(__dirname, '..', 'calorietracker.html'), 'utf8');
const start = html.indexOf('    // Writes require the record-merge API');
const end = html.indexOf('\n  </script>', start);
assert.ok(start >= 0 && end > start);
const checks = `
(async () => {
const base={goals:{startDate:'2026-10-16',idealWeight:200},dailyLogs:[{id:1,date:'10/16/26',consumed:2000}],weightTimeline:[],bodyFatTimeline:[]};
state=structuredClone(base);
calorieNasToken='test-only';calorieNasProtocol=2;
calorieRecordSynced(base,'baseline','base-tag');
state.dailyLogs[0].consumed=1800;
calorieOnLocalChange();
state.goals.idealWeight=195;
calorieOnLocalChange();
clearTimeout(calorieNasTimer);
assert.equal(calorieNasMode,'busy');assert.equal(calorieNasPending,true);
let sent;
calorieRequest=async(method,body)=>{
 assert.equal(method,'PUT');sent=body;
 // Another device added a different record and this device edits during the request.
 const merged=structuredClone(state);merged.dailyLogs.push({id:2,date:'10/17/26',consumed:1900});
 state.goals.idealWeight=190;
 return {protocol:2,revision:'merged',baseTag:'merged-tag',store:merged};
};
await caloriePush();clearTimeout(calorieNasTimer);
assert.equal(sent.protocol,2);assert.equal(sent.revision,'baseline');assert.equal(sent.store,undefined);
assert.equal(JSON.stringify(sent.changes.dailyLogs.upsert),JSON.stringify([{id:1,date:'10/16/26',consumed:1800}]));
assert.equal(state.dailyLogs.length,2);assert.equal(state.goals.idealWeight,190);
assert.equal(JSON.parse(calorieNasLastSynced).goals.idealWeight,195);
assert.equal(calorieNasDirty,true);assert.equal(calorieNasPending,true);
// Dirty reconnect preserves the original revision, rather than borrowing the newest NAS revision.
calorieNasPending=false;calorieNasMode='conflict';
calorieRequest=async()=>({protocol:2,revision:'newer',store:{...base,goals:{...base.goals,idealWeight:180}}});
await calorieCheckNas({auto:true});
assert.equal(calorieNasRevision,'merged');assert.equal(calorieNasPending,false);
// Conflicting in-flight edits do not erase device data.
assert.throws(()=>calorieRebase(base,{...base,goals:{...base.goals,idealWeight:180}},{...base,goals:{...base.goals,idealWeight:190}}),/conflicts/);
// Legacy endpoints never enable saving.
calorieNasProtocol=1;calorieNasPending=false;calorieNasMode='ready';calorieOnLocalChange();
assert.equal(calorieNasPending,false);
console.log('Client sync checks passed: record diffs, rapid edits, concurrent NAS additions, in-flight edits, baseline retention, conflicts, legacy API protection.');
})().catch(error=>{console.error(error);process.exitCode=1;});
`;
vm.runInNewContext(`
const assert=require('node:assert/strict');
const localStorage={setItem(){},getItem(){return null}};
const document={getElementById(){return {className:'',textContent:''}}};
const window={addEventListener(){}};
let state;function renderAll(){}function showToast(){};
${html.slice(start,end)}\n${checks}`, {
  require, console, process, structuredClone, setTimeout, clearTimeout,
  AbortController, fetch: () => { throw new Error('Unexpected network request'); }
});
