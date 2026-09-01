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
const resolveRedirect = async (windowId,googleUrl,href) => {
  let resolverTabId;
  try {
    const source=new URL(href,googleUrl);if(source.pathname!=='/goto')return null;
    const tab=await chrome.tabs.create({windowId,url:source.href,active:false});resolverTabId=tab.id;
    const deadline=Date.now()+12000;
    while(Date.now()<deadline){
      const current=await chrome.tabs.get(resolverTabId);const url=current.url||current.pendingUrl||'';
      if(/^https?:\/\//.test(url)){const parsed=new URL(url);if(parsed.hostname!==source.hostname||parsed.pathname!=='/goto')return url;}
      await sleep(100);
    }
  } catch(error){/* An individual opaque redirect must not abort the complete run. */}
  finally {if(resolverTabId)await chrome.tabs.remove(resolverTabId).catch(()=>{});}
  return null;
};
const resolveCandidates = async (windowId,googleUrl,candidates) => {
  const resolved=[];
  for(let offset=0;offset<candidates.length;offset+=3){
    const chunk=candidates.slice(offset,offset+3);
    const urls=await Promise.all(chunk.map(candidate=>candidate.direct||resolveRedirect(windowId,googleUrl,candidate.href)));
    for(const url of urls)if(url&&!resolved.includes(url))resolved.push(url);
  }
  return resolved;
};
const inspect = async tabId => {
  for(let attempt=0;attempt<3;attempt++){
    try{return await chrome.scripting.executeScript({target:{tabId},func:() => {
      const googleHost=host=>/(^|\.)google\.[a-z.]+$/i.test(host)||/(^|\.)googleusercontent\.com$/i.test(host);
      const destination=href=>{try{const parsed=new URL(href,location.href);if(googleHost(parsed.hostname)&&parsed.pathname==='/url'){const target=parsed.searchParams.get('q')||parsed.searchParams.get('url');if(target)return new URL(target).href;}if(!googleHost(parsed.hostname)&&/^https?:$/.test(parsed.protocol))return parsed.href;}catch{}return null;};
      const roots=[document.querySelector('#search'),document.querySelector('#rso'),document.querySelector('[role="main"]'),document.querySelector('main')].filter(Boolean);
      const root=roots[0]||document.body; const anchors=[...root.querySelectorAll('a[href]')]; const preferred=anchors.filter(anchor=>anchor.querySelector('h3'));const pool=preferred.length?preferred:anchors;
      const seen=new Set(),candidates=[];for(const anchor of pool){const href=anchor.getAttribute('href')||anchor.href;if(!href||seen.has(href))continue;const parsed=new URL(href,location.href);const direct=destination(href);if(!direct&&parsed.pathname!=='/goto')continue;seen.add(href);candidates.push({href,direct});}
      const samples=anchors.slice(0,20).map(anchor=>(anchor.getAttribute('href')||'').slice(0,240)).filter(Boolean);
      return {captcha:!!document.querySelector('form[action*="sorry"],iframe[src*="recaptcha"],#captcha-form'),candidates,diagnostics:{title:document.title,url:location.href,root:root.id||root.getAttribute('role')||root.tagName,anchors:anchors.length,preferred:preferred.length,strategy:'direct-and-opaque-redirects',samples}};
    }}).then(result=>result[0]?.result||{captcha:false,candidates:[],diagnostics:{strategy:'no-result'}});}
    catch(error){if(attempt===2)throw new Error('INSPECT_FAILED');await new Promise(resolve=>setTimeout(resolve,500));}
  }
};
const waitForCaptchaResolution = async (tabId,windowId,dashboardTabId,jobId,page,progress) => {
  const started=Date.now(),deadline=started+600000;let lastNotice=0,clearSince=null;
  await chrome.windows.update(windowId,{focused:true});
  await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:jobId,progress,message:'Google کپچا نمایش داده است؛ آن را در پنجره ناشناس حل کنید. اجرای رتبه‌یابی منتظر می‌ماند.',debug:`CAPTCHA_WAITING page=${page} timeout_seconds=600`});
  while(Date.now()<deadline){
    try { await chrome.tabs.get(tabId); } catch { throw new Error('CAPTCHA_WINDOW_CLOSED'); }
    const result=await inspect(tabId);
    if(!result.captcha){
      if(clearSince===null)clearSince=Date.now();
      if((result.candidates||[]).length>0||Date.now()-clearSince>=3000){
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:jobId,progress,message:'کپچا حل شد؛ رتبه‌یابی ادامه پیدا کرد.',debug:`CAPTCHA_SOLVED page=${page} waited_seconds=${Math.round((Date.now()-started)/1000)}`});
        await chrome.windows.update(windowId,{state:'minimized'}).catch(()=>{});
        return result;
      }
    }else clearSince=null;
    if(Date.now()-lastNotice>=15000){lastNotice=Date.now();await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:jobId,progress,message:'همچنان منتظر حل کپچا در پنجره ناشناس هستم…',debug:`CAPTCHA_STILL_WAITING page=${page} elapsed_seconds=${Math.round((Date.now()-started)/1000)}`});}
    await sleep(1000);
  }
  throw new Error('CAPTCHA_TIMEOUT');
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
      const normalizeHost=value=>{try{return new URL(String(value).includes('://')?String(value):`https://${value}`).hostname.toLowerCase().replace(/^www\./,'').replace(/\.$/,'');}catch{return String(value).trim().toLowerCase().replace(/^www\./,'').replace(/\.$/,'');}};
      const domain=normalizeHost(job.domain);
      await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:7,message:'دامنه هدف آماده بررسی است.',debug:`TARGET_DOMAIN input=${JSON.stringify(job.domain)} normalized=${domain}`});
      for (let page=0; page<10; page++) {
        if(page>0){const next=new URL(first);next.searchParams.set('start',String(page*10));await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:page*10,message:`در حال رفتن به صفحه ${page+1} Google…`,debug:`NAVIGATE page=${page+1} start=${page*10}`});await navigate(tabId,next.toString());}else await waitForGoogle(tabId,'0');
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`در حال بررسی صفحه ${page+1} Google…`,debug:`PAGE_LOADED page=${page+1} start=${page*10}`});
        let result=await inspect(tabId);if(result.captcha)result=await waitForCaptchaResolution(tabId,windowId,dashboardTabId,job.id,page+1,(page+1)*9);
        const diag=result.diagnostics||{};const candidates=result.candidates||[];
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`در حال بازکردن ${candidates.length} پیوند نتیجه در صفحه ${page+1}…`,debug:`RESOLVE_START page=${page+1} candidates=${candidates.length} opaque=${candidates.filter(candidate=>!candidate.direct).length}`});
        const links=await resolveCandidates(windowId,diag.url||first,candidates);
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`${links.length} نتیجه در صفحه ${page+1} خوانده شد.`,debug:`INSPECTED page=${page+1} links=${links.length} anchors=${diag.anchors||0} preferred=${diag.preferred||0} root=${diag.root||'unknown'} strategy=${diag.strategy||'unknown'}`});
        await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`در حال تطبیق دامنه در صفحه ${page+1}…`,debug:`SEEN_URLS page=${page+1} target=${domain} urls=${JSON.stringify(links)}`});
        if(links.length===0)await notify(dashboardTabId,{type:'SEO_RANK_PROGRESS',id:job.id,progress:(page+1)*9,message:`هیچ نشانی قابل پردازشی در صفحه ${page+1} استخراج نشد.`,debug:`RAW_HREFS page=${page+1} samples=${JSON.stringify(diag.samples||[])}`});
        const found=links.findIndex(link => { try { const host=normalizeHost(link); return host===domain || host.endsWith(`.${domain}`); } catch { return false; } });
        if (found >= 0) { await notify(dashboardTabId,{type:'SEO_RANK_DONE',id:job.id,position:page*10+found+1,url:links[found],debug:`MATCH_FOUND page=${page+1} index=${found} position=${page*10+found+1}`}); return; }
      }
      await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:'دامنه در نتایج خوانده‌شده پیدا نشد.',debug:'SEARCH_COMPLETE pages=10 no_match=true'});
    } catch (error) { if(dashboardTabId) await notify(dashboardTabId,{type:'SEO_RANK_ERROR',id:job.id,message:error?.message==='CAPTCHA_TIMEOUT'?'مهلت ۱۰ دقیقه‌ای حل کپچا تمام شد؛ دوباره رتبه‌یابی را اجرا کنید.':error?.message==='CAPTCHA_WINDOW_CLOSED'?'پنجره ناشناس هنگام انتظار برای کپچا بسته شد.':error?.message==='DOCUMENT_TIMEOUT'?'سند نتایج Google در دسترس افزونه قرار نگرفت؛ مجوز دسترسی Google و Incognito را بررسی کنید.':error?.message==='INSPECT_FAILED'?'افزونه نتوانست نتایج صفحه Google را بخواند؛ دسترسی افزونه را بررسی کنید.':'اجرای جستجو کامل نشد. دسترسی Incognito و اتصال اینترنت را بررسی کنید.',debug:`RUN_FAILED code=${error?.message||'unknown'}`}); }
    finally { if (windowId) chrome.windows.remove(windowId).catch(()=>{}); }
  })(); reply({accepted:true,protocol:5,version:chrome.runtime.getManifest().version}); return true;
});
