# SEO Tracker User-IP Rank Runner

This unpacked Chromium extension is required by the **رتبه‌یابی با IP من** button.
It includes a branded popup and a text-based SVG logo so activation state and the
current domain are clear without shipping binary image files. All extension assets
remain reviewable in source diffs and release systems that reject binary files.

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

The manifest uses Chromium's `spanning` Incognito mode intentionally: the dashboard
tab remains in the normal profile while the same background worker controls the
temporary Incognito search window. `split` mode would isolate those two contexts and
can open the window but then lose access to its tab, producing a false execution error.
