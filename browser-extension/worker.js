const notify = (tabId, message) => chrome.tabs.sendMessage(tabId, {source:'seo-tracker-extension',...message}).catch(()=>{});
const loaded = async tabId => {
  const current=await chrome.tabs.get(tabId); if(current.status==='complete') return;
  return new Promise((resolve,reject)=>{const timer=setTimeout(()=>{chrome.tabs.onUpdated.removeListener(listener);reject(new Error('Google timeout'));},25000);const listener=(id,info)=>{if(id===tabId&&info.status==='complete'){clearTimeout(timer);chrome.tabs.onUpdated.removeListener(listener);resolve();}};chrome.tabs.onUpdated.addListener(listener);});
};
const navigate = (tabId,url) => new Promise((resolve,reject) => {
  const expected=new URL(url).searchParams.get('start')||'0';
  const timer=setTimeout(()=>{chrome.tabs.onUpdated.removeListener(listener);reject(new Error('PAGE_TIMEOUT'));},25000);
  const listener=(id,info,tab)=>{if(id!==tabId||info.status!=='complete')return;const actual=new URL(tab.url).searchParams.get('start')||'0';if(actual!==expected)return;clearTimeout(timer);chrome.tabs.onUpdated.removeListener(listener);resolve();};
  chrome.tabs.onUpdated.addListener(listener);
  chrome.tabs.update(tabId,{url}).catch(error=>{clearTimeout(timer);chrome.tabs.onUpdated.removeListener(listener);reject(error);});
});
const inspect = async tabId => {
  for(let attempt=0;attempt<3;attempt++){
    try{return await chrome.scripting.executeScript({target:{tabId},func:() => ({captcha:!!document.querySelector('form[action*="sorry"],iframe[src*="recaptcha"],#captcha-form'),links:[...document.querySelectorAll('#search a')].filter(a=>a.querySelector('h3')).map(a=>a.href).filter(href=>/^https?:\/\//.test(href)&&!href.includes('google.'))})}).then(result=>result[0]?.result||{captcha:false,links:[]});}
    catch(error){if(attempt===2)throw new Error('INSPECT_FAILED');await new Promise(resolve=>setTimeout(resolve,500));}
  }
};
chrome.runtime.onMessage.addListener((job, sender, reply) => {
  if (job?.source !== 'seo-tracker-page' || job?.type !== 'SEO_RANK_START') return;
  (async () => {
    let windowId; const dashboardTabId=sender.tab?.id;
    try {
      if (!dashboardTabId) throw new Error('Dashboard tab unavailable');
      const first = `https://www.google.com/search?q=${encodeURIComponent(job.query)}&gl=${encodeURIComponent(job.country)}&hl=${encodeURIComponent(job.language)}&num=10&pws=0&start=0`;
      const win = await chrome.windows.create({url:first,incognito:true,focused:false}); windowId=win.id; const tabId=win.tabs[0].id;
      await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:6,message:'پنجره ناشناس ایجاد شد؛ در حال بارگذاری Google…',debug:`WINDOW_CREATED window=${windowId} tab=${tabId}`});
      for (let page=0; page<10; page++) {
        if(page>0){const next=new URL(first);next.searchParams.set('start',String(page*10));await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:page*10,message:`در حال رفتن به صفحه ${page+1} Google…`,debug:`NAVIGATE page=${page+1} start=${page*10}`});await navigate(tabId,next.toString());}else await loaded(tabId);
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`در حال بررسی صفحه ${page+1} Google…`,debug:`PAGE_LOADED page=${page+1} start=${page*10}`});
        const result=await inspect(tabId); if(result.captcha) throw new Error('CAPTCHA');
        const links=result.links; const domain=String(job.domain).replace(/^www\./,'').toLowerCase();
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`${links.length} نتیجه در صفحه ${page+1} خوانده شد.`,debug:`INSPECTED page=${page+1} links=${links.length}`});
        const found=links.findIndex(link => { try { const host=new URL(link).hostname.replace(/^www\./,'').toLowerCase(); return host===domain || host.endsWith(`.${domain}`); } catch { return false; } });
        if (found >= 0) { await notify(dashboardTabId,{type:'SEO_RANK_DONE',id:job.id,position:page*10+found+1,url:links[found],debug:`MATCH_FOUND page=${page+1} index=${found} position=${page*10+found+1}`}); return; }
      }
      await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:'دامنه در ۱۰۰ نتیجه نخست پیدا نشد.'});
    } catch (error) { if(dashboardTabId) await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:error?.message==='CAPTCHA'?'Google درخواست CAPTCHA کرد؛ پس از رفع آن دوباره تلاش کنید.':error?.message==='PAGE_TIMEOUT'?'بارگذاری صفحه بعدی Google کامل نشد؛ دوباره تلاش کنید.':error?.message==='INSPECT_FAILED'?'افزونه نتوانست نتایج صفحه Google را بخواند؛ دسترسی افزونه را بررسی کنید.':'اجرای جستجو کامل نشد. دسترسی Incognito و اتصال اینترنت را بررسی کنید.',debug:`RUN_FAILED code=${error?.message||'unknown'}`}); }
    finally { if (windowId) chrome.windows.remove(windowId).catch(()=>{}); }
  })(); reply({accepted:true}); return true;
});
