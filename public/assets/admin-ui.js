(() => {
  'use strict';
  const managed = /\/(?:websites|keywords|rank-dashboard|rank-checks|admin\/modules\/search-console)(?:\/|$)/;
  const removeInjectedSkipLinks = () => {
    const nodes = document.querySelectorAll('.skip-links');
    nodes.forEach(node => node.remove());
    return nodes.length > 0;
  };
  const watchForInjectedSkipLinks = () => {
    if (removeInjectedSkipLinks()) return;
    const observer = new MutationObserver(() => {
      if (removeInjectedSkipLinks()) observer.disconnect();
    });
    observer.observe(document.body, {childList: true, subtree: true});
    window.setTimeout(() => observer.disconnect(), 10000);
  };
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
      const payload = await response.json().catch(() => ({}));
      if (response.ok) {
        if (payload.message) toast(payload.message);
        if (payload.redirect) await request(new URL(payload.redirect, location.href).href);
        return false;
      }
      toast(payload.error || 'عملیات انجام نشد.', true); return false;
    } catch { toast('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', true); return false; }
    finally { document.body.classList.remove('seo-loading'); }
  };
  const openKeywordEditor = async url => {
    const modal = document.getElementById('keyword-edit-modal'); const content = modal?.querySelector('[data-keyword-edit-content]');
    if (!modal || !content) { location.assign(url); return; }
    modal.hidden = false; content.setAttribute('aria-busy', 'true');
    content.innerHTML = '<div class="d-flex align-items-center justify-content-center gap-2 py-5"><span class="spinner-border text-primary" aria-hidden="true"></span><span>Loading...</span></div>';
    try {
      const response = await fetch(url, {headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
      if (!response.ok) throw new Error('EDITOR_RESPONSE');
      const next = new DOMParser().parseFromString(await response.text(), 'text/html'); const panel = next.querySelector('.keyword-edit-panel');
      if (!panel) throw new Error('EDITOR_MARKUP');
      content.replaceChildren(panel); content.removeAttribute('aria-busy'); panel.querySelector('input[name="keyword"]')?.focus();
    } catch { content.removeAttribute('aria-busy'); content.innerHTML = '<div class="alert alert-danger" role="alert">ویرایش کلیدواژه بارگذاری نشد. دوباره تلاش کنید.</div>'; }
  };
  document.addEventListener('click', async event => {
    if (event.target.closest('[data-keyword-modal-open]')) {
      const modal = document.getElementById('keyword-create-modal');
      if (modal) { event.preventDefault(); modal.hidden = false; modal.querySelector('textarea')?.focus(); }
      return;
    }
    if (event.target.closest('[data-keyword-modal-close]')) {
      const modal = event.target.closest('.keyword-modal');
      if (modal) { event.preventDefault(); modal.hidden = true; }
      return;
    }
    const edit = event.target.closest('.keyword-edit-open');
    if (edit?.dataset.editUrl) { event.preventDefault(); await openKeywordEditor(edit.dataset.editUrl); return; }
    const link = event.target.closest('.app-main a[href]'); if (!link || event.ctrlKey || event.metaKey || event.shiftKey || link.target) return;
    const url = new URL(link.href, location.href); if (url.origin !== location.origin || !managed.test(url.pathname)) return;
    event.preventDefault(); request(url.href);
  });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') document.querySelectorAll('.keyword-modal:not([hidden])').forEach(modal => { modal.hidden = true; }); });
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
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watchForInjectedSkipLinks, {once: true});
  else watchForInjectedSkipLinks();
})();
