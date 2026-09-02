(() => {
  'use strict';
  const modal = () => document.getElementById('manual-rank-modal');
  const requiredProtocol = 7;
  let active = null;
  let queue = [];
  let completed = 0;
  let total = 0;
  let responseTimer = null;
  let lastProgress = 0;
  const debug = (stage, detail = '') => {
    const output = modal()?.querySelector('[data-manual-rank-debug]'); if (!output) return;
    output.textContent += `[${new Date().toISOString().slice(11, 23)}] ${stage}${detail ? ` | ${detail}` : ''}\n`;
    output.scrollTop = output.scrollHeight;
  };
  const show = (status, progress = null) => {
    const root = modal(); if (!root) return; root.hidden = false;
    root.querySelector('[data-manual-rank-status]').textContent = status;
    if (progress !== null) lastProgress = progress;
    const bar = root.querySelector('[data-manual-rank-progress]'); bar.style.width = `${lastProgress}%`; bar.textContent = `${lastProgress}\u066a`;
  };
  const cancelTimer = () => { if (responseTimer !== null) window.clearTimeout(responseTimer); responseTimer = null; };
  const jobFrom = start => ({id:crypto.randomUUID(),website:start.dataset.website,keyword:start.dataset.keywordId,query:start.dataset.query,domain:start.dataset.domain,country:start.dataset.country,language:start.dataset.language,device:start.dataset.device});
  const startNext = () => {
    active = queue.shift() || null;
    if (!active) { show('\u0647\u0645\u0647 \u06a9\u0644\u06cc\u062f\u0648\u0627\u0698\u0647\u200c\u0647\u0627 \u0628\u0631\u0631\u0633\u06cc \u0634\u062f\u0646\u062f\u061b \u062f\u0631 \u062d\u0627\u0644 \u0628\u0647\u200c\u0631\u0648\u0632\u0631\u0633\u0627\u0646\u06cc\u2026', 100); return window.setTimeout(() => location.reload(), 700); }
    lastProgress = total > 1 ? Math.round(completed / total * 100) : 0;
    show(`\u062f\u0631 \u062d\u0627\u0644 \u0628\u0631\u0631\u0633\u06cc ${completed + 1} \u0627\u0632 ${total}: ${active.query}`, Math.max(2, lastProgress));
    debug('PAGE_START', `job=${active.id} item=${completed+1}/${total} domain=${active.domain} device=${active.device} query=${JSON.stringify(active.query)}`);
    window.postMessage({source:'seo-tracker-page',type:'SEO_RANK_START',protocol:requiredProtocol,...active}, location.origin);
    debug('PAGE_MESSAGE_SENT', 'SEO_RANK_START');
    responseTimer = window.setTimeout(() => { if (!active) return; debug('EXTENSION_TIMEOUT','No ACK or progress in 5 seconds'); show('\u0627\u0641\u0632\u0648\u0646\u0647 \u067e\u0627\u0633\u062e \u0646\u062f\u0627\u062f. \u0627\u0641\u0632\u0648\u0646\u0647 SEO Tracker \u0631\u0627 \u0646\u0635\u0628 \u0648 Incognito \u0631\u0627 \u0641\u0639\u0627\u0644 \u06a9\u0646\u06cc\u062f.', lastProgress); active = null; }, 5000);
  };
  document.addEventListener('click', async event => {
    const all = event.target.closest('.manual-rank-start-all');
    const one = event.target.closest('.manual-rank-start');
    if (all || one) {
      const starts = all ? [...document.querySelectorAll('.manual-rank-start:not([disabled])')] : [one];
      queue = starts.map(jobFrom); total = queue.length; completed = 0;
      const output = modal()?.querySelector('[data-manual-rank-debug]'); if (output) output.textContent = '';
      debug('BATCH_START', `total=${total}`); startNext();
    }
    if (event.target.closest('[data-manual-rank-close]')) { cancelTimer(); modal().hidden = true; active = null; queue = []; }
    if (event.target.closest('[data-manual-rank-copy]')) { try { await navigator.clipboard.writeText(modal()?.querySelector('[data-manual-rank-debug]')?.textContent || ''); } catch { /* Clipboard may be unavailable. */ } }
  });
  window.addEventListener('message', async event => {
    if (event.source !== window || event.origin !== location.origin || !active || event.data?.id !== active.id || event.data?.source !== 'seo-tracker-extension') return;
    cancelTimer(); debug(event.data.type || 'UNKNOWN_MESSAGE', event.data.debug || event.data.message || '');
    const itemBase = total > 1 ? completed / total * 100 : 0;
    const itemShare = total > 1 ? 100 / total : 1;
    const mappedProgress = Math.min(99, Math.round(itemBase + (Number(event.data.progress) || 0) * itemShare / 100));
    if (event.data.type === 'SEO_RANK_ACK') {
      debug('EXTENSION_VERSION', `version=${event.data.version || 'unknown'} protocol=${event.data.protocol || 'missing'} required=${requiredProtocol}`);
      if (event.data.protocol !== requiredProtocol) { show('\u0646\u0633\u062e\u0647 \u0627\u0641\u0632\u0648\u0646\u0647 \u0642\u062f\u06cc\u0645\u06cc \u0627\u0633\u062a. \u062f\u0631 chrome://extensions \u062f\u06a9\u0645\u0647 Reload \u0631\u0627 \u0628\u0632\u0646\u06cc\u062f.', lastProgress); active = null; queue = []; }
      return;
    }
    if (event.data.type === 'SEO_RANK_PROGRESS') return show(event.data.message || '\u062f\u0631 \u062d\u0627\u0644 \u0628\u0631\u0631\u0633\u06cc\u2026', mappedProgress);
    if (event.data.type === 'SEO_RANK_ERROR') { debug('BATCH_ITEM_FAILED', `item=${completed+1}/${total}`); completed++; active = null; return queue.length ? startNext() : show(event.data.message || '\u0631\u062a\u0628\u0647\u200c\u06cc\u0627\u0628\u06cc \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f.', Math.round(completed / total * 100)); }
    if (event.data.type !== 'SEO_RANK_DONE') return;
    show(`\u0631\u062a\u0628\u0647 ${event.data.position} \u067e\u06cc\u062f\u0627 \u0634\u062f\u061b \u062f\u0631 \u062d\u0627\u0644 \u0630\u062e\u06cc\u0631\u0647\u2026`, mappedProgress);
    debug('SAVE_START', `position=${event.data.position} url=${event.data.url}`);
    const body = new URLSearchParams({_token:document.getElementById('manual-rank-csrf').value,website:active.website,keyword:active.keyword,position:String(event.data.position),ranking_url:event.data.url,diagnostic_id:active.id});
    try {
      const response = await fetch('/rank-checks/manual',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body,credentials:'same-origin'});
      debug('SAVE_RESPONSE',`status=${response.status}`);
      if (!response.ok) { debug('SAVE_FAILED_BODY',(await response.text()).replace(/\s+/g,' ').slice(0,1000)); show('\u0630\u062e\u06cc\u0631\u0647 \u0631\u062a\u0628\u0647 \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f.', mappedProgress); active = null; queue = []; return; }
      completed++; active = null; startNext();
    } catch (error) { debug('SAVE_NETWORK_ERROR',error?.message || 'unknown'); show('\u0630\u062e\u06cc\u0631\u0647 \u0631\u062a\u0628\u0647 \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f.', mappedProgress); active = null; queue = []; }
  });
})();
