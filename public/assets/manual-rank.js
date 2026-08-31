(() => {
  'use strict';
  const modal = () => document.getElementById('manual-rank-modal');
  let active = null;
  const show = (status, progress = 0) => {
    const root = modal(); if (!root) return;
    root.hidden = false;
    root.querySelector('[data-manual-rank-status]').textContent = status;
    const bar = root.querySelector('[data-manual-rank-progress]');
    bar.style.width = `${progress}%`; bar.textContent = `${progress}٪`;
  };
  document.addEventListener('click', event => {
    const start = event.target.closest('.manual-rank-start');
    if (start) {
      active = {id: crypto.randomUUID(), website:start.dataset.website, keyword:start.dataset.keywordId};
      show('در حال اتصال به افزونه مرورگر…', 2);
      window.postMessage({source:'seo-tracker-page', type:'SEO_RANK_START', id:active.id, query:start.dataset.query, domain:start.dataset.domain, country:start.dataset.country, language:start.dataset.language, device:start.dataset.device}, location.origin);
      window.setTimeout(() => { if (active) show('افزونه پاسخ نداد. افزونه SEO Tracker را نصب کنید و دسترسی Incognito را فعال کنید.', 0); }, 5000);
    }
    if (event.target.closest('[data-manual-rank-close]')) { modal().hidden = true; active = null; }
  });
  window.addEventListener('message', async event => {
    if (event.source !== window || event.origin !== location.origin || !active || event.data?.id !== active.id || event.data?.source !== 'seo-tracker-extension') return;
    if (event.data.type === 'SEO_RANK_PROGRESS') return show(event.data.message || 'در حال بررسی…', Math.max(0, Math.min(99, Number(event.data.progress) || 0)));
    if (event.data.type === 'SEO_RANK_ERROR') { show(event.data.message || 'رتبه‌یابی ناموفق بود.', 0); active = null; return; }
    if (event.data.type !== 'SEO_RANK_DONE') return;
    show(`رتبه ${event.data.position} پیدا شد؛ در حال ذخیره…`, 100);
    const body = new URLSearchParams({_token:document.getElementById('manual-rank-csrf').value, website:active.website, keyword:active.keyword, position:String(event.data.position), ranking_url:event.data.url});
    const response = await fetch('/rank-checks/manual', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body, credentials:'same-origin'});
    if (!response.ok) { show('ذخیره رتبه ناموفق بود.', 0); active = null; return; }
    window.setTimeout(() => location.reload(), 500);
  });
})();
