(() => {
  'use strict';
  const modal = () => document.getElementById('manual-rank-modal');
  let active = null;
  let responseTimer = null;
  let lastProgress = 0;
  const requiredProtocol = 2;
  const debug = (stage, detail = '') => {
    const output = modal()?.querySelector('[data-manual-rank-debug]');
    if (!output) return;
    const time = new Date().toISOString().slice(11, 23);
    output.textContent += `[${time}] ${stage}${detail ? ` | ${detail}` : ''}\n`;
    output.scrollTop = output.scrollHeight;
  };
  const show = (status, progress = null) => {
    const root = modal(); if (!root) return;
    root.hidden = false;
    root.querySelector('[data-manual-rank-status]').textContent = status;
    if (progress !== null) lastProgress = progress;
    const bar = root.querySelector('[data-manual-rank-progress]');
    bar.style.width = `${lastProgress}%`; bar.textContent = `${lastProgress}\u066a`;
  };
  const cancelTimer = () => { if (responseTimer !== null) window.clearTimeout(responseTimer); responseTimer = null; };
  document.addEventListener('click', async event => {
    const start = event.target.closest('.manual-rank-start');
    if (start) {
      active = {id: crypto.randomUUID(), website:start.dataset.website, keyword:start.dataset.keywordId};
      lastProgress = 0;
      const output = modal()?.querySelector('[data-manual-rank-debug]'); if (output) output.textContent = '';
      show('\u062f\u0631 \u062d\u0627\u0644 \u0627\u062a\u0635\u0627\u0644 \u0628\u0647 \u0627\u0641\u0632\u0648\u0646\u0647 \u0645\u0631\u0648\u0631\u06af\u0631\u2026', 2);
      debug('PAGE_START', `job=${active.id} domain=${start.dataset.domain} device=${start.dataset.device}`);
      window.postMessage({source:'seo-tracker-page', type:'SEO_RANK_START', protocol:requiredProtocol, id:active.id, query:start.dataset.query, domain:start.dataset.domain, country:start.dataset.country, language:start.dataset.language, device:start.dataset.device}, location.origin);
      debug('PAGE_MESSAGE_SENT', 'SEO_RANK_START');
      responseTimer = window.setTimeout(() => { if (active) { debug('EXTENSION_TIMEOUT', 'No ACK or progress in 5 seconds'); show('\u0627\u0641\u0632\u0648\u0646\u0647 \u067e\u0627\u0633\u062e \u0646\u062f\u0627\u062f. \u0627\u0641\u0632\u0648\u0646\u0647 SEO Tracker \u0631\u0627 \u0646\u0635\u0628 \u06a9\u0646\u06cc\u062f \u0648 \u062f\u0633\u062a\u0631\u0633\u06cc Incognito \u0631\u0627 \u0641\u0639\u0627\u0644 \u06a9\u0646\u06cc\u062f.', 0); } }, 5000);
    }
    if (event.target.closest('[data-manual-rank-close]')) { cancelTimer(); modal().hidden = true; active = null; }
    if (event.target.closest('[data-manual-rank-copy]')) {
      const text = modal()?.querySelector('[data-manual-rank-debug]')?.textContent || '';
      try { await navigator.clipboard.writeText(text); } catch { /* Clipboard can be unavailable on HTTP. */ }
    }
  });
  window.addEventListener('message', async event => {
    if (event.source !== window || event.origin !== location.origin || !active || event.data?.id !== active.id || event.data?.source !== 'seo-tracker-extension') return;
    cancelTimer();
    debug(event.data.type || 'UNKNOWN_MESSAGE', event.data.debug || event.data.message || '');
    if (event.data.type === 'SEO_RANK_ACK') {
      debug('EXTENSION_VERSION', `version=${event.data.version || 'unknown'} protocol=${event.data.protocol || 'missing'} required=${requiredProtocol}`);
      if (event.data.protocol !== requiredProtocol) { show('\u0646\u0633\u062e\u0647 \u0627\u0641\u0632\u0648\u0646\u0647 \u0642\u062f\u06cc\u0645\u06cc \u0627\u0633\u062a. \u062f\u0631 chrome://extensions \u062f\u06a9\u0645\u0647 Reload \u0631\u0627 \u0628\u0632\u0646\u06cc\u062f \u0648 \u062f\u0648\u0628\u0627\u0631\u0647 \u062a\u0644\u0627\u0634 \u06a9\u0646\u06cc\u062f.'); active = null; return;
      }
      return show('\u0627\u0641\u0632\u0648\u0646\u0647 \u0645\u062a\u0635\u0644 \u0634\u062f\u061b \u062f\u0631 \u062d\u0627\u0644 \u0627\u06cc\u062c\u0627\u062f \u067e\u0646\u062c\u0631\u0647 \u0646\u0627\u0634\u0646\u0627\u0633\u2026', 4);
    }
    if (event.data.type === 'SEO_RANK_PROGRESS') return show(event.data.message || '\u062f\u0631 \u062d\u0627\u0644 \u0628\u0631\u0631\u0633\u06cc\u2026', Math.max(0, Math.min(99, Number(event.data.progress) || 0)));
    if (event.data.type === 'SEO_RANK_ERROR') { show(event.data.message || '\u0631\u062a\u0628\u0647\u200c\u06cc\u0627\u0628\u06cc \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f.'); active = null; return; }
    if (event.data.type !== 'SEO_RANK_DONE') return;
    show(`\u0631\u062a\u0628\u0647 ${event.data.position} \u067e\u06cc\u062f\u0627 \u0634\u062f\u061b \u062f\u0631 \u062d\u0627\u0644 \u0630\u062e\u06cc\u0631\u0647\u2026`, 100);
    debug('SAVE_START', `position=${event.data.position} url=${event.data.url}`);
    const body = new URLSearchParams({_token:document.getElementById('manual-rank-csrf').value, website:active.website, keyword:active.keyword, position:String(event.data.position), ranking_url:event.data.url, diagnostic_id:active.id});
    try {
      const response = await fetch('/rank-checks/manual', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body, credentials:'same-origin'});
      debug('SAVE_RESPONSE', `status=${response.status}`);
      if (!response.ok) { const detail=(await response.text()).replace(/\s+/g,' ').slice(0,1000);debug('SAVE_FAILED_BODY',detail);show('\u0630\u062e\u06cc\u0631\u0647 \u0631\u062a\u0628\u0647 \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f. \u0634\u0646\u0627\u0633\u0647 \u067e\u06cc\u06af\u06cc\u0631\u06cc \u062f\u0631 \u06af\u0632\u0627\u0631\u0634 \u062b\u0628\u062a \u0634\u062f.', 0); active = null; return; }
      window.setTimeout(() => location.reload(), 500);
    } catch (error) { debug('SAVE_NETWORK_ERROR', error?.message || 'unknown'); show('\u0630\u062e\u06cc\u0631\u0647 \u0631\u062a\u0628\u0647 \u0646\u0627\u0645\u0648\u0641\u0642 \u0628\u0648\u062f.', 0); active = null; }
  });
})();
