'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

const js=fs.readFileSync(__dirname+'/../assets/brand/integrations-v10.js','utf8');
const begin=js.indexOf('  const testFeedbackRx = ');
const end=js.indexOf('  cards.forEach(card => {\n    const provider = providerOf(card);',begin);
assert(begin>=0&&end>begin,'Could not isolate provider-feedback handler');
const feedbackCode=js.slice(begin,end);
const grid={name:'provider-grid'};
const noticeText='Falha ao testar conexão';
function element(name,{message=noticeText,provider=false,container=false,containsGrid=false}={}){
  const attrs=new Map(),classes=new Set();
  return {
    name,textContent:message,
    closest:selector=>provider&&selector==='.translation-provider-grid'?grid:null,
    matches:selector=>container&&selector.includes('.saas-shell'),
    contains:other=>containsGrid&&other===grid,
    classList:{contains:key=>classes.has(key),add:key=>classes.add(key)},
    setAttribute:(key,value)=>attrs.set(key,value),
    getAttribute:key=>attrs.get(key),
    hasAttribute:key=>attrs.has(key)
  };
}
const shell=element('shell',{container:true,containsGrid:true});
const sidebar=element('sidebar',{container:true});
const main=element('main',{container:true,containsGrid:true});
const content=element('content',{container:true,containsGrid:true});
const providerNotice=element('provider-local',{provider:true});
const globalNotice=element('legitimate-page-flash');
const unrelated=element('unrelated-flash',{message:'Regra atualizada com sucesso'});
const all=[shell,sidebar,main,content,providerNotice,globalNotice,unrelated];
let selected='';
const document={
  querySelectorAll(selector){
    selected=selector;
    assert.notEqual(selector,'*','Global DOM scan may hide the application shell');
    return all;
  },
  body:{
    querySelectorAll(){
      throw new Error('Feedback handler must never scan all body descendants');
    }
  }
};
vm.runInNewContext('(function(grid,document){'+feedbackCode+'})(grid,document)',{grid,document},{timeout:2000});
assert(selected.includes('.saas-flash'),'Feedback must be restricted to notification nodes');
for(const container of [shell,sidebar,main,content]){
  assert(!container.classList.contains('integrations-global-test-feedback'),container.name+' was hidden');
  assert(!container.hasAttribute('hidden'),container.name+' was hidden via attribute');
  assert(!container.hasAttribute('aria-hidden'),container.name+' lost accessibility');
}
assert(!providerNotice.hasAttribute('hidden'),'Provider-local feedback was suppressed');
assert(globalNotice.hasAttribute('hidden'),'Page-level provider-test feedback was not suppressed');
assert(!unrelated.hasAttribute('hidden'),'Unrelated flash was suppressed');
console.log('INTEGRATIONS_SIDEBAR_NO_FLASH_TESTS_PASSED');
