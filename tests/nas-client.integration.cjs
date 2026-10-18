// Called by the isolated PHP integration suite; never points at the real NAS.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const html = fs.readFileSync(path.join(__dirname, '..', 'calorietracker.html'), 'utf8');
const start = html.indexOf('    // Writes require the record-merge API');
const end = html.indexOf('\n  </script>',start);
vm.runInNewContext(`
const assert=require('node:assert/strict');
const localStorage={setItem(){},getItem(){return null}};
const document={getElementById(){return {className:'',textContent:''}}};
const window={addEventListener(){}};
let state={};function renderAll(){}function showToast(){};
${html.slice(start,end)}
(async()=>{
calorieNasToken=process.env.TEST_NAS_TOKEN;
const initial=await calorieRequest('GET');calorieNasProtocol=initial.protocol;
calorieSetData(initial.store);calorieRecordSynced(initial.store,initial.revision,initial.baseTag);
// Simulate a second device saving first; this device keeps its original baseline.
const added={id:2,date:'10/17/26',consumed:1900};
await calorieRequest('PUT',{protocol:2,revision:initial.revision,baseTag:initial.baseTag,changes:{dailyLogs:{upsert:[added],delete:[]}}});
state.dailyLogs[0].consumed=1750;calorieOnLocalChange();clearTimeout(calorieNasTimer);
await caloriePush();clearTimeout(calorieNasTimer);
assert.equal(calorieNasMode,'ready');assert.equal(calorieNasDirty,false);
assert.equal(state.dailyLogs.length,2);assert.equal(state.dailyLogs.find(row=>row.id===1).consumed,1750);
const result=await calorieRequest('GET');assert.equal(JSON.stringify(result.store),JSON.stringify(calorieData()));
// A legacy payload cannot reach even this client transport.
await assert.rejects(()=>calorieRequest('PUT',{revision:result.revision,store:calorieData()}),/protocol 2/);
console.log('Browser-client-to-PHP integration passed: stale baseline record save preserves concurrent NAS additions.');
})().catch(error=>{console.error(error);process.exitCode=1;});
`,{require,console,process,structuredClone,setTimeout,clearTimeout,AbortController,
fetch:(url,options)=>fetch(new URL(url,process.env.TEST_NAS_URL),options)});
