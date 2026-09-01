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

After replacing extension files, always press **Reload** for this extension on
`chrome://extensions`. The dashboard checks protocol version 5 and stops with an
explicit upgrade message instead of silently running a cached worker. A current run
logs `EXTENSION_VERSION | version=1.4.0 protocol=5 required=5`; older logs without
`anchors=`, `preferred=`, and `strategy=` come from a stale extension worker.

The extension has a fixed Google Search permission, but requests application-site
access only after the user clicks its toolbar icon. It then registers the bridge for
that exact scheme and host and remembers it across browser restarts. Multiple SEO
Tracker domains can be enabled independently without editing or rebuilding files.
It opens a background Incognito window, checks up to ten result pages, reports
progress only to the tab that started the run, and closes that window. Google can
change its result markup; diagnostics are reported when links cannot be extracted.
A CAPTCHA pauses the run for manual resolution as described below.

Page readiness is verified by polling the actual Incognito document through
`chrome.scripting.executeScript`: its URL, `start` offset, and `document.readyState`
must all match. The runner no longer depends on `tabs.onUpdated`, whose completion
event can be missing for an Incognito tab even when Google visibly finished loading.
Result inspection also retries transient frame replacement errors three times.
It recognizes both heading-based results and Google's newer result containers.
Direct `/url?q=...` redirects are decoded locally. Newer `/goto?url=CAES...` links
contain an opaque Google token rather than an encoded destination; the worker now
resolves those links in temporary inactive tabs in the same Incognito window, reads
the final URL, and immediately closes each resolver tab. Links are resolved in small
batches to avoid flooding the browser. Diagnostic entries include total anchor,
preferred-result, candidate, opaque-redirect, and accepted-link counts.
When Google presents a CAPTCHA, the Incognito window is focused and the job waits up
to ten minutes for the user to solve it. The worker reports `CAPTCHA_WAITING` and a
heartbeat `CAPTCHA_STILL_WAITING`; after the challenge disappears it reports
`CAPTCHA_SOLVED`, minimizes the Incognito window, and resumes the same page instead
of failing or restarting the job. Closing the window or exceeding the timeout yields
a distinct diagnostic error.
Every run logs the normalized target as `TARGET_DOMAIN` and the complete accepted
URL list for each page as `SEEN_URLS`. When extraction returns zero links it also
logs up to twenty original anchor values as `RAW_HREFS`, making redirect and markup
failures diagnosable from the dashboard without opening extension developer tools.

The modal contains an expandable diagnostic log with copy support. It records the
page-to-extension handshake, Incognito window/tab creation, each navigation offset,
loaded page, extracted organic-link count, match, server save response, and a stable
failure code. Persian strings in the inline page asset use ASCII Unicode escapes so
PHP DOM serialization cannot expose numeric HTML entities as unreadable text.
If a run ends without a match, the progress bar keeps the last completed percentage
instead of resetting to zero.

The manifest uses Chromium's `spanning` Incognito mode intentionally: the dashboard
tab remains in the normal profile while the same background worker controls the
temporary Incognito search window. `split` mode would isolate those two contexts and
can open the window but then lose access to its tab, producing a false execution error.
