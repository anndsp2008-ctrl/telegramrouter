'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

// Exercise the real sidebar-only guard embedded in runtime-ui-labels.php.
// Feedback notices must stay untouched, but no page/nav ancestor may be hidden.
const php=fs.readFileSync(__dirname+'/../runtime-ui-labels.php','utf8');
const match=php.match(/<script id="tmr-sidebar-feedback-guard">\s*([\s\S]*?)\s*<\/script>/);
assert(match,'Navigation guard is missing from the index output formatter');
const guard=match[1];
assert(!guard.includes('preventDefault'),'Navigation click behavior changed');
assert(!guard.includes('replaceWith('),'Navigation DOM is replaced after paint');
assert(!guard.includes('window.location'),'Unexpected route change in navigation guard');
assert(php.includes('.saas-shell.integrations-global-test-feedback{display:flex!important}'),
  'A marked app shell may disappear before the observer runs');
const selectors=['.saas-shell','.saas-sidebar','.saas-main','.saas-content'];
function node(name){
  const labels=new Set(name==='.saas-sidebar'?['saas-sidebar','other-active-link']:['other-existing-class']);
  const attributes=new Map();
  return {
    name,
    classList:{
      contains:x=>labels.has(x),
      add:x=>labels.add(x),
      remove:x=>labels.delete(x)
    },
    setAttribute:(k,v)=>attributes.set(k,v),
    getAttribute:k=>attributes.get(k)??null,
    removeAttribute:k=>attributes.delete(k),
    hasAttribute:k=>attributes.has(k)
  };
}
const elements=Object.fromEntries(selectors.map(x=>[x,node(x)]));
const observers=[];
class MutationObserver{
  constructor(fn){this.fn=fn}
  observe(target,options){observers.push({target,options,callback:this.fn})}
}
const document={querySelector:s=>elements[s]??null};
vm.runInNewContext(guard,{document,MutationObserver},{timeout:2000});
assert.equal(observers.length,4,'Guard must monitor shell, sidebar, main and content');
for(const {target,options} of observers){
  assert.equal(options.attributes,true);
  assert.deepEqual(Array.from(options.attributeFilter),
    ['class','hidden','aria-hidden']);
  target.classList.add('integrations-global-test-feedback');
  target.setAttribute('hidden','');
  target.setAttribute('aria-hidden','true');
}
for(const {target,callback} of observers) callback([{target}]);
for(const selector of selectors){
  const current=elements[selector];
  assert(!current.classList.contains('integrations-global-test-feedback'),
    'App container remains hidden: '+selector);
  assert(!current.hasAttribute('hidden'),'Hidden attribute remains: '+selector);
  assert(!current.hasAttribute('aria-hidden'),'ARIA hidden remains: '+selector);
  assert(current.classList.contains(selector==='.saas-sidebar'?'other-active-link':'other-existing-class'),
    'An unrelated navigation class was removed: '+selector);
}
const flash=node('provider-notice');
flash.classList.add('integrations-global-test-feedback');
flash.setAttribute('hidden','');
assert(flash.classList.contains('integrations-global-test-feedback'));
assert(flash.hasAttribute('hidden'),'Legitimate provider notices were changed');
console.log('SIDEBAR_SHELL_FEEDBACK_GUARD_PASSED');
