'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

// A feedback notice may contain a connection-test result, but its parent
// .saas-shell also contains that text. Only the actual notice may be hidden.
const javascript=fs.readFileSync(__dirname+'/../assets/brand/integrations-v10.js','utf8');
assert(!javascript.includes("document.body.querySelectorAll('*')"),
  'Whole-document text scanning would include the sidebar and shell');
const hiddenNodes=[];
const classList=(node)=>({
  add(value){if(value==='integrations-global-test-feedback')hiddenNodes.push(node);},
  remove(){},toggle(){},contains(){return false;}
});
const makeNode=(name,content)=>({
  name,textContent:content,attrs:{},classList:null,
  closest(){return null;},matches(){return false;},
  setAttribute(key,value){this.attrs[key]=value;},
  getAttribute(key){return this.attrs[key];}
});
const message='Falha ao testar conexão. Verifique a chave.';
const shell=makeNode('shell','Navegação · Integrações · '+message);
const sidebar=makeNode('sidebar','Navegação · '+message);
const main=makeNode('main','Integrações · '+message);
const flash=makeNode('flash',message);
for(const node of [shell,sidebar,main,flash])node.classList=classList(node);
const input={value:'gemini'};
const card=makeNode('provider','Provedor Gemini');
card.dataset={}; card.classList={toggle(){},contains(){return false;}};
card.querySelector=(selector)=>selector.includes('input[name="provider"]')?input:null;
card.querySelectorAll=()=>[];
card.addEventListener=()=>{};
const grid={children:[card]};
let selectorUsed='';
const document={
  querySelector(selector){return selector==='.translation-provider-grid'?grid:null;},
  body:{querySelectorAll(selector){
    selectorUsed=selector;
    if(selector==='*')return [shell,sidebar,main,flash];
    if(selector.includes('.saas-flash'))return [flash];
    return [];
  }}
};
const listeners=[];
const window={addEventListener(event,listener){listeners.push(event);}};
const sessionStorage={getItem(){return null;},setItem(){},removeItem(){}};
vm.runInNewContext(javascript,{document,window,sessionStorage,console},{timeout:2000});
assert(selectorUsed!== '*','Integration JS queried all page elements');
assert.deepEqual(hiddenNodes,[flash],'Only the provider-test notice should be hidden');
for(const container of [shell,sidebar,main]){
  assert.notEqual(container.attrs.hidden,'','A whole page region was hidden: '+container.name);
  assert(!container.classList.contains?.('integrations-global-test-feedback'),
    'A whole page region was marked as feedback: '+container.name);
}
assert.equal(flash.attrs.hidden,'');
assert.equal(flash.attrs['aria-hidden'],'true');
assert.equal(card.tabIndex,0,'Provider card initialization changed');
assert(listeners.includes('submit'),'Existing provider submit flow was lost');
console.log('INTEGRATIONS_NAV_SIDEBAR_NO_FLASH_TEST_PASSED');
