(function(){
  'use strict';
  const STORAGE_KEY='tmr-theme',DARK='dark',LIGHT='light';
  function storedTheme(){
    try{
      const value=window.localStorage.getItem(STORAGE_KEY);
      return value===LIGHT||value===DARK?value:DARK;
    }catch(_error){return DARK;}
  }
  function setMetaColor(theme){
    const meta=document.querySelector('meta[name="theme-color"]');
    if(meta) meta.setAttribute('content',theme===LIGHT?'#F4F7FB':'#0B1220');
  }
  function syncButtons(theme){
    const isLight=theme===LIGHT;
    document.querySelectorAll('[data-theme-toggle]').forEach(button=>{
      button.setAttribute('aria-pressed',isLight?'true':'false');
      button.setAttribute('aria-label',isLight?'Ativar modo escuro':'Ativar modo claro');
      button.setAttribute('title',isLight?'Ativar modo escuro':'Ativar modo claro');
      const label=button.querySelector('.tmr-theme-label');
      if(label) label.textContent=isLight?'Modo escuro':'Modo claro';
    });
  }
  function applyTheme(theme,persist){
    const safeTheme=theme===LIGHT?LIGHT:DARK;
    document.documentElement.dataset.theme=safeTheme;
    document.documentElement.style.colorScheme=safeTheme;
    setMetaColor(safeTheme);
    if(document.readyState!=='loading') syncButtons(safeTheme);
    if(persist){try{window.localStorage.setItem(STORAGE_KEY,safeTheme);}catch(_error){}}
    return safeTheme;
  }
  applyTheme(storedTheme(),false);
  document.addEventListener('DOMContentLoaded',()=>{
    const activeTheme=document.documentElement.dataset.theme||DARK;
    setMetaColor(activeTheme);
    syncButtons(activeTheme);
    document.addEventListener('click',event=>{
      const button=event.target.closest('[data-theme-toggle]');
      if(!button) return;
      const current=document.documentElement.dataset.theme===LIGHT?LIGHT:DARK;
      applyTheme(current===LIGHT?DARK:LIGHT,true);
    });
  });
  window.addEventListener('storage',event=>{
    if(event.key!==STORAGE_KEY) return;
    applyTheme(event.newValue===LIGHT?LIGHT:DARK,false);
  });
})();