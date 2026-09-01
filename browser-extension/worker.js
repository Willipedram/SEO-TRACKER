const notify = (tabId, message) => chrome.tabs.sendMessage(tabId, {source:'seo-tracker-extension',...message}).catch(()=>{});
const sleep = milliseconds => new Promise(resolve=>setTimeout(resolve,milliseconds));
const waitForGoogle = async (tabId,expectedStart='0') => {
  const deadline=Date.now()+30000;
  while(Date.now()<deadline){
    try{
      const state=await chrome.scripting.executeScript({target:{tabId},func:()=>({ready:document.readyState,url:location.href,start:new URL(location.href).searchParams.get('start')||'0'})});
      const page=state[0]?.result;
      if(page&&['interactive','complete'].includes(page.ready)&&page.url.startsWith('https://www.google.')&&page.start===expectedStart)return page;
    }catch(error){/* The main frame is temporarily unavailable while navigating. */}
    await sleep(250);
  }
  throw new Error('DOCUMENT_TIMEOUT');
};
const navigate = async (tabId,url) => { const expected=new URL(url).searchParams.get('start')||'0';await chrome.tabs.update(tabId,{url});return waitForGoogle(tabId,expected); };
const inspect = async tabId => {
  for(let attempt=0;attempt<3;attempt++){
    try{return await chrome.scripting.executeScript({target:{tabId},func:() => {
      const googleHost=host=>/(^|\.)google\.[a-z.]+$/i.test(host)||/(^|\.)googleusercontent\.com$/i.test(host);
      const destination=href=>{try{const parsed=new URL(href,location.href);if(googleHost(parsed.hostname)&&parsed.pathname==='/url'){const target=parsed.searchParams.get('q')||parsed.searchParams.get('url');if(target)return new URL(target).href;}if(!googleHost(parsed.hostname)&&/^https?:$/.test(parsed.protocol))return parsed.href;}catch{}return null;};
      const roots=[document.querySelector('#search'),document.querySelector('#rso'),document.querySelector('[role="main"]'),document.querySelector('main')].filter(Boolean);
      const root=roots[0]||document.body; const anchors=[...root.querySelectorAll('a[href]')]; const preferred=anchors.filter(anchor=>anchor.querySelector('h3')||anchor.closest('.g,.MjjYud,[data-snhf],[data-header-feature]'));
      const pool=preferred.length?preferred:anchors;
      const seen=new Set(),links=[];for(const anchor of pool){const url=destination(anchor.getAttribute('href')||anchor.href);if(!url)continue;const key=new URL(url).origin+new URL(url).pathname.replace(/\/$/,'');if(seen.has(key))continue;seen.add(key);links.push(url);}
      return {captcha:!!document.querySelector('form[action*="sorry"],iframe[src*="recaptcha"],#captcha-form'),links,diagnostics:{title:document.title,url:location.href,root:root.id||root.getAttribute('role')||root.tagName,anchors:anchors.length,preferred:preferred.length,strategy:preferred.length?'result-container':'external-link-fallback'}};
    }}).then(result=>result[0]?.result||{captcha:false,links:[],diagnostics:{strategy:'no-result'}});}
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
        if(page>0){const next=new URL(first);next.searchParams.set('start',String(page*10));await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:page*10,message:`در حال رفتن به صفحه ${page+1} Google…`,debug:`NAVIGATE page=${page+1} start=${page*10}`});await navigate(tabId,next.toString());}else await waitForGoogle(tabId,'0');
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`در حال بررسی صفحه ${page+1} Google…`,debug:`PAGE_LOADED page=${page+1} start=${page*10}`});
        const result=await inspect(tabId); if(result.captcha) throw new Error('CAPTCHA');
        const links=result.links; const domain=String(job.domain).replace(/^www\./,'').toLowerCase();
        const diag=result.diagnostics||{};await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`${links.length} نتیجه در صفحه ${page+1} خوانده شد.`,debug:`INSPECTED page=${page+1} links=${links.length} anchors=${diag.anchors||0} preferred=${diag.preferred||0} root=${diag.root||'unknown'} strategy=${diag.strategy||'unknown'}`});
        const found=links.findIndex(link => { try { const host=new URL(link).hostname.replace(/^www\./,'').toLowerCase(); return host===domain || host.endsWith(`.${domain}`); } catch { return false; } });
        if (found >= 0) { await notify(dashboardTabId,{type:'SEO_RANK_DONE',id:job.id,position:page*10+found+1,url:links[found],debug:`MATCH_FOUND page=${page+1} index=${found} position=${page*10+found+1}`}); return; }
      }
      await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:'دامنه در نتایج خوانده‌شده پیدا نشد.',debug:'SEARCH_COMPLETE pages=10 no_match=true'});
    } catch (error) { if(dashboardTabId) await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:error?.message==='CAPTCHA'?'Google درخواست CAPTCHA کرد؛ پس از رفع آن دوباره تلاش کنید.':error?.message==='DOCUMENT_TIMEOUT'?'سند نتایج Google در دسترس افزونه قرار نگرفت؛ مجوز دسترسی Google و Incognito را بررسی کنید.':error?.message==='INSPECT_FAILED'?'افزونه نتوانست نتایج صفحه Google را بخواند؛ دسترسی افزونه را بررسی کنید.':'اجرای جستجو کامل نشد. دسترسی Incognito و اتصال اینترنت را بررسی کنید.',debug:`RUN_FAILED code=${error?.message||'unknown'}`}); }
    finally { if (windowId) chrome.windows.remove(windowId).catch(()=>{}); }
  })(); reply({accepted:true,protocol:2,version:chrome.runtime.getManifest().version}); return true;
});
