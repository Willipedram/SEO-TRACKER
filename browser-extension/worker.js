const notify = (tabId, message) => chrome.tabs.sendMessage(tabId, {source:'seo-tracker-extension',...message}).catch(()=>{});
chrome.action.onClicked.addListener(async tab => {
  try {
    const url=new URL(tab.url); if (!['http:','https:'].includes(url.protocol)) return;
    const pattern=`${url.protocol}//${url.hostname}/*`;
    if (!await chrome.permissions.request({origins:[pattern]})) return;
    const id=`seo-tracker-${url.hostname.replace(/[^a-z0-9]/gi,'-').toLowerCase()}`.slice(0,64);
    await chrome.scripting.unregisterContentScripts({ids:[id]}).catch(()=>{});
    await chrome.scripting.registerContentScripts([{id,matches:[pattern],js:['bridge.js'],runAt:'document_start',persistAcrossSessions:true}]);
    await chrome.storage.local.set({[`domain:${url.origin}`]:true});
    await chrome.tabs.reload(tab.id);
  } catch (error) { /* Unsupported/restricted pages remain untouched. */ }
});
const loaded = tabId => new Promise((resolve, reject) => {
  const timer = setTimeout(() => { chrome.tabs.onUpdated.removeListener(listener); reject(new Error('Google timeout')); }, 20000);
  const listener = (id, info) => { if (id === tabId && info.status === 'complete') { clearTimeout(timer); chrome.tabs.onUpdated.removeListener(listener); resolve(); } };
  chrome.tabs.onUpdated.addListener(listener);
});
const inspect = tabId => chrome.scripting.executeScript({target:{tabId},func:() => [...document.querySelectorAll('#search a')].filter(a => a.querySelector('h3')).map(a => a.href).filter(href => /^https?:\/\//.test(href) && !href.includes('google.'))}).then(result => result[0]?.result || []);
chrome.runtime.onMessage.addListener((job, sender, reply) => {
  if (job?.source !== 'seo-tracker-page' || job?.type !== 'SEO_RANK_START') return;
  (async () => {
    let windowId; const dashboardTabId=sender.tab?.id;
    try {
      if (!dashboardTabId) throw new Error('Dashboard tab unavailable');
      const first = `https://www.google.com/search?q=${encodeURIComponent(job.query)}&gl=${encodeURIComponent(job.country)}&hl=${encodeURIComponent(job.language)}&num=10&pws=0&start=0`;
      const win = await chrome.windows.create({url:first,incognito:true,focused:false}); windowId=win.id; const tabId=win.tabs[0].id;
      for (let page=0; page<10; page++) {
        if (page > 0) { await chrome.tabs.update(tabId,{url:first.replace('start=0',`start=${page*10}`)}); }
        await loaded(tabId); await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`در حال بررسی صفحه ${page+1} Google…`});
        const links=await inspect(tabId); const domain=String(job.domain).replace(/^www\./,'').toLowerCase();
        const found=links.findIndex(link => { try { const host=new URL(link).hostname.replace(/^www\./,'').toLowerCase(); return host===domain || host.endsWith(`.${domain}`); } catch { return false; } });
        if (found >= 0) { await notify(dashboardTabId,{type:'SEO_RANK_DONE',id:job.id,position:page*10+found+1,url:links[found]}); return; }
      }
      await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:'دامنه در ۱۰۰ نتیجه نخست پیدا نشد.'});
    } catch (error) { if (dashboardTabId) await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:'پنجره ناشناس اجرا نشد؛ دسترسی Incognito افزونه یا نمایش CAPTCHA را بررسی کنید.'}); }
    finally { if (windowId) chrome.windows.remove(windowId).catch(()=>{}); }
  })(); reply({accepted:true}); return true;
});
