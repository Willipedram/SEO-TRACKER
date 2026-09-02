(() => {
  'use strict';
  const dateOnly = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {timeZone:'Asia/Tehran', year:'numeric', month:'long', day:'numeric'});
  const dateTime = new Intl.DateTimeFormat('fa-IR-u-ca-persian', {timeZone:'Asia/Tehran', year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'});
  const parse = value => { const match=value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/); if(!match)return null; return {date:new Date(`${match[1]}-${match[2]}-${match[3]}T${match[4]||'12'}:${match[5]||'00'}:${match[6]||'00'}Z`),timed:!!match[4]}; };
  const format = value => { const parsed=parse(value); return parsed ? (parsed.timed ? dateTime : dateOnly).format(parsed.date) : null; };
  const apply = root => {
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,{acceptNode:node=>/\d{4}-\d{2}-\d{2}/.test(node.nodeValue||'')&&!node.parentElement?.closest('script,style,code,pre,input,textarea')?NodeFilter.FILTER_ACCEPT:NodeFilter.FILTER_REJECT});
    const nodes=[];while(walker.nextNode())nodes.push(walker.currentNode);nodes.forEach(node=>{node.nodeValue=(node.nodeValue||'').replace(/\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?/g,value=>format(value)||value);});
    root.querySelectorAll('input[type="date"]').forEach(input=>{let hint=input.nextElementSibling;if(!hint?.classList.contains('persian-date-hint')){hint=document.createElement('small');hint.className='persian-date-hint form-text text-body-secondary';input.after(hint);}hint.textContent=format(input.value)||'';if(!input.dataset.persianBound){input.dataset.persianBound='1';input.addEventListener('change',()=>{hint.textContent=format(input.value)||'';});}});
  };
  const run=()=>apply(document.querySelector('.app-main')||document.body); if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',run);else run(); window.addEventListener('seo:content-updated',run);
})();
