window.addEventListener('message', event => {
  if (!document.querySelector('meta[name="seo-tracker-rank-runner"][content="1"]') || event.source !== window || event.origin !== location.origin || event.data?.source !== 'seo-tracker-page' || event.data?.type !== 'SEO_RANK_START') return;
  chrome.runtime.sendMessage(event.data).then(response => {
    if (response?.error) window.postMessage({source:'seo-tracker-extension',type:'SEO_RANK_ERROR',id:event.data.id,message:response.error}, location.origin);
    else if (response?.accepted) window.postMessage({source:'seo-tracker-extension',type:'SEO_RANK_ACK',id:event.data.id,message:'Extension worker accepted the rank job.'}, location.origin);
  }).catch(() => window.postMessage({source:'seo-tracker-extension',type:'SEO_RANK_ERROR',id:event.data.id,message:'ارتباط با افزونه برقرار نشد.'}, location.origin));
});
chrome.runtime.onMessage.addListener(message => {
  if (message?.source === 'seo-tracker-extension') window.postMessage(message, location.origin);
});
