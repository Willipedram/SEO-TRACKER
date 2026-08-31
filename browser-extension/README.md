# SEO Tracker User-IP Rank Runner

This unpacked Chromium extension is required by the **رتبه‌یابی با IP من** button.

1. Open `chrome://extensions` (or `edge://extensions`) and enable Developer mode.
2. Choose **Load unpacked** and select this `browser-extension` directory.
3. Open the extension details and enable **Allow in Incognito**.
4. Open SEO Tracker on **any HTTP or HTTPS domain**, click the extension toolbar
   icon once, approve access to that domain, and wait for the page to reload.
5. Open Rank Tracking and press the user-IP rank button.

The extension has a fixed Google Search permission, but requests application-site
access only after the user clicks its toolbar icon. It then registers the bridge for
that exact scheme and host and remembers it across browser restarts. Multiple SEO
Tracker domains can be enabled independently without editing or rebuilding files.
It opens a background Incognito window, checks up to ten result pages, reports
progress only to the tab that started the run, and closes that window. Google can
present a CAPTCHA or change its result markup; in that case the run stops with an
error and no rank is stored.
