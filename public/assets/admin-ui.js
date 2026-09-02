(() => {
  'use strict';
  const managed = /\/(?:websites|keywords|rank-dashboard|rank-checks|admin\/modules\/search-console)(?:\/|$)/;
  const toast = (message, danger = false) => {
    let area = document.querySelector('.seo-toast-area');
    if (!area) { area = document.createElement('div'); area.className = 'toast-container position-fixed top-0 start-0 p-3 seo-toast-area'; document.body.append(area); }
    const item = document.createElement('div'); item.className = `toast show text-bg-${danger ? 'danger' : 'success'} border-0`; item.setAttribute('role', 'status');
    item.innerHTML = `<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" aria-label="بستن"></button></div>`;
    item.querySelector('.toast-body').textContent = message; item.querySelector('button').addEventListener('click', () => item.remove()); area.append(item); window.setTimeout(() => item.remove(), 4500);
  };
  const swap = (html, url, push = true) => {
    const next = new DOMParser().parseFromString(html, 'text/html'); const incoming = next.querySelector('.app-main'); const current = document.querySelector('.app-main');
    if (!incoming || !current) { location.assign(url); return; }
    current.replaceWith(incoming); document.title = next.title; if (push) history.pushState({}, '', url);
    window.dispatchEvent(new CustomEvent('seo:content-updated')); window.scrollTo({top: 0, behavior: 'smooth'});
  };
  const request = async (url, options = {}, push = true) => {
    document.body.classList.add('seo-loading');
    try {
      const response = await fetch(url, {...options, headers: {...options.headers, 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin'});
      const type = response.headers.get('content-type') || '';
      if (type.includes('text/html')) { swap(await response.text(), response.url || url, push); return true; }
      const payload = await response.json().catch(() => ({})); toast(payload.error || 'عملیات انجام نشد.', true); return false;
    } catch { toast('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', true); return false; }
    finally { document.body.classList.remove('seo-loading'); }
  };
  document.addEventListener('click', event => {
    const link = event.target.closest('.app-main a[href]'); if (!link || event.ctrlKey || event.metaKey || event.shiftKey || link.target) return;
    const url = new URL(link.href, location.href); if (url.origin !== location.origin || !managed.test(url.pathname)) return;
    event.preventDefault(); request(url.href);
  });
  document.addEventListener('submit', event => {
    const form = event.target; if (!(form instanceof HTMLFormElement) || !form.closest('.app-main') || form.classList.contains('manual-rank-form')) return;
    const url = new URL(form.action, location.href); if (url.origin !== location.origin || !managed.test(url.pathname)) return;
    if (url.pathname.endsWith('/search-console/connect')) return;
    if ((form.querySelector('.danger,.btn-danger') || /archive|delete|disconnect/.test(url.pathname)) && !window.confirm('از انجام این عملیات مطمئن هستید؟')) { event.preventDefault(); return; }
    event.preventDefault(); const submit = event.submitter; if (submit) submit.disabled = true;
    const method = form.method.toUpperCase() || 'GET'; const data = new FormData(form);
    if (method === 'GET') { const query = new URLSearchParams(data); url.search = query.toString(); request(url.href).finally(() => { if (submit) submit.disabled = false; }); }
    else request(url.href, {method, body: new URLSearchParams(data)}).then(ok => { if (ok) toast('عملیات با موفقیت انجام شد.'); }).finally(() => { if (submit) submit.disabled = false; });
  });
  window.addEventListener('popstate', () => request(location.href, {}, false));
})();
