const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
class Element{
 constructor(tag='div'){this.tag=tag;this.children=[];this.value='';this.textContent='';this.hidden=false;this.disabled=false;this.handlers={};this.classList={toggle(){}};this.dataset={};}
 get options(){return this.children;}
 appendChild(e){this.children.push(e);return e;}
 replaceChildren(){this.children=[];this.value='';}
 addEventListener(name,fn){this.handlers[name]=fn;}
}
(async()=>{
 const nodes=new Map();const get=id=>{if(!nodes.has(id))nodes.set(id,new Element());return nodes.get(id);};
 const document={getElementById:get,querySelector:s=>s.startsWith('meta')?{content:'fixture'}:get('upload-submit'),createElement:t=>new Element(t),addEventListener(){}};
 let archives=[{id:'db-fixture',header_valid:true,bytes:100,kind:'manual'}],offline=false;
 const fetch=async url=>{
  if(url.includes('restore_database_backup'))return {ok:false,json:async()=>({success:false,message:'Wrong fixture password'})};
  if(offline)throw Error('Synthetic connection interrupted');
  return {ok:true,json:async()=>({success:true,capabilities:{enabled:true,restore_enabled:true,messages:[],restore_message:null},archives,jobs:[]})};
 };
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/admin-page/admin_database_backups.js'),'utf8'),{document,fetch,window:{setTimeout(){}},URL,URLSearchParams,FormData});
 await new Promise(setImmediate);
 const select=get('database-backup-restore-id'),message=get('database-backup-message');
 select.value='db-fixture';get('database-backup-password').value='wrong';get('database-backup-confirmation').value='RESTORE';
 await get('database-backup-restore-form').handlers.submit({preventDefault(){}});
 assert.equal(message.textContent,'Wrong fixture password');assert.equal(message.hidden,false);assert.equal(select.value,'db-fixture');assert.equal(get('database-backup-password').value,'');
 await get('database-backup-refresh').handlers.click();assert.equal(message.textContent,'Wrong fixture password');assert.equal(select.value,'db-fixture');
 offline=true;await get('database-backup-refresh').handlers.click();assert.equal(message.textContent,'Synthetic connection interrupted');
 offline=false;await get('database-backup-refresh').handlers.click();assert.equal(message.hidden,true,'successful retry clears only status-fetch errors');
 archives=[];await get('database-backup-refresh').handlers.click();assert.equal(get('database-backup-restore').disabled,true,'no archive leaves restore disabled');
 console.log('PASS backup feedback: rejected-password error survives refresh, archive retained, password cleared, status interruption/recovery, no-archive restore disabled');
})().catch(e=>{console.error(e);process.exitCode=1;});
