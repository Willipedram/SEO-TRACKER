# ADR 0012: Hybrid, capability-negotiated rank execution

- Status: Accepted for architecture; engine implementation deferred
- Date: 2026-08-23
- Decision owners: Core, RankTracking, Security

## Context

A rank check should use the initiating user's IP context as far as realistically
possible. PHP running on Apache sends requests from the hosting server, not from the
user's connection. Forwarding an IP header does not change network egress and must
never be represented as user-IP execution. Ordinary page JavaScript is constrained
by browser origin policy, provider defenses, page lifecycle, and mobile background
limits. It is not a reliable unrestricted SERP scraper.

Search providers may prohibit automated queries, impose quotas, present consent or
challenge pages, and change markup without notice. Product availability does not
grant permission to automate a provider. Legal review and current provider terms are
release/adapter gates. The application will never request a user's Google password.

## Options evaluated

### 1. Browser-side page JavaScript

- **Feasibility/reliability:** Low. Cross-origin policy, provider CORS, bot defenses,
  challenge pages, tab closure, sleep, and changing markup prevent dependable jobs.
- **Deployment/DirectAdmin:** Easy to serve, but hosting compatibility does not make
  execution reliable. No worker installation.
- **Desktop/mobile:** Foreground desktop may sometimes execute; mobile background
  operation is especially unreliable. Device emulation is not a physical device.
- **Security/privacy/credentials:** Runs in the page's authority and expands XSS and
  data-exfiltration impact. No Google credential may be requested or injected.
- **IP semantics:** Genuine browser egress when a request is permitted, subject to
  VPN, relay, corporate proxy, and provider routing; not guaranteed to equal a legal
  subscriber address.
- **Limits/terms/maintenance/scale/cost:** Provider throttling applies to the user's
  egress. Terms risk and markup maintenance are high; coordination and observability
  are poor. Infrastructure cost is low, support cost is high.
- **Failure modes:** CORS denial, CSP, challenge/consent result, changed DOM, closed
  tab, offline device, timeout, incomplete/tampered result.
- **Decision:** Rejected as a rank executor. UI may only submit and observe jobs.

### 2. Browser extension

- **Feasibility/reliability:** Technically possible with explicit host permissions,
  but store policies, browser changes, provider defenses, and interactive challenges
  remain. More reliable than page JavaScript, not a dependable universal scraper.
- **Deployment/DirectAdmin:** Server is compatible; users must install, approve, and
  update a separately signed extension. Enterprise policies may block it.
- **Desktop/mobile:** Good potential on supported desktop browsers. Mobile extension
  availability and background execution vary materially by OS/browser.
- **Security/privacy/credentials:** Host permissions are powerful. Use minimum scope,
  signed jobs, no browsing-history collection, no Google passwords/cookies, and clear
  consent. Extension compromise is a high-impact supply-chain event.
- **IP semantics:** Uses the browser's current egress unless VPN/proxy/relay applies.
- **Limits/terms/maintenance/scale/cost:** Per-user/provider limits still apply.
  Browser-store and provider terms require review. Multi-browser maintenance and
  support are significant; server cost is moderate.
- **Failure modes:** Uninstalled/disabled extension, permission revoked, store
  rejection, browser update, asleep browser, provider challenge, tampered client.
- **Decision:** Deferred optional executor, not the default and not Phase 10 scope.

### 3. Dedicated client agent

- **Feasibility/reliability:** High enough for bounded, consented jobs when a lawful
  adapter exists. A signed native agent can lease work and report structured output.
- **Deployment/DirectAdmin:** The control plane remains ordinary PHP/MySQL/cron. Each
  user installs and updates a separate signed application.
- **Desktop/mobile:** Practical on desktop operating systems. Native mobile support is
  a separate product with background/network restrictions; it is not promised.
- **Security/privacy/credentials:** Mutual device enrollment, short-lived scoped job
  tokens, destination allowlists, sandboxing, signed updates, revocation, and result
  validation are required. Never collect Google passwords or session cookies.
- **IP semantics:** Requests originate from the agent's network path, which best
  approximates user context but may be VPN/proxy/NAT/relay and is not identity proof.
- **Limits/terms/maintenance/scale/cost:** Per-device pacing protects user networks.
  Terms still govern every adapter. Agent signing, updates, incident response, and
  cross-platform support are costly; execution load is distributed.
- **Failure modes:** Offline/asleep agent, expired lease, clock skew, revoked device,
  unsupported adapter, challenge, local firewall, update failure, tampered result.
- **Decision:** Selected as an optional future `local_agent` execution source.

### 4. Secure local helper reached from the browser

- **Feasibility/reliability:** A loopback service can bridge browser UI to a native
  agent, but browser-to-local security, origin authentication, TLS, and port conflicts
  are difficult. It inherits native-agent provider constraints.
- **Deployment/DirectAdmin:** Server-compatible but requires local installation and
  firewall/endpoint-security exceptions.
- **Desktop/mobile:** Possible on desktop; generally unsuitable on mobile.
- **Security/privacy/credentials:** Exposed loopback APIs are attack targets. Require
  origin-bound pairing, nonces, short-lived capabilities, strict CORS/origin checks,
  and no general URL-fetch endpoint or provider credentials.
- **IP semantics:** Native helper egress approximates the user's current network.
- **Limits/terms/maintenance/scale/cost:** Same provider limits/terms as an agent,
  plus browser integration burden. Moderate server cost, high endpoint support cost.
- **Failure modes:** Port hijack, malicious local page, pairing loss, firewall block,
  helper absent, provider challenge.
- **Decision:** Rejected as a distinct executor; a future agent may expose a narrowly
  scoped loopback activation channel only after threat modeling.

### 5. Customer/user-supplied proxy

- **Feasibility/reliability:** Technically feasible, but proxy quality, provenance,
  geo semantics, and abuse reputation are unknown.
- **Deployment/DirectAdmin:** Server can connect outbound if hosting allows it;
  proxy configuration and secret storage increase operational complexity.
- **Desktop/mobile:** Device targeting is still an adapter parameter, not actual
  hardware. Same behavior for desktop/mobile clients.
- **Security/privacy/credentials:** Proxy credentials require encrypted storage and
  redaction. Open-proxy acceptance is forbidden. A proxy can observe traffic.
- **IP semantics:** Provider sees proxy egress, never the initiating user's IP.
- **Limits/terms/maintenance/scale/cost:** Proxy/provider quotas and terms apply.
  Reliability and compliance verification are burdensome; costs scale with traffic.
- **Failure modes:** Credential expiry, interception, IP reputation block, geo drift,
  provider challenge, proxy outage, unexpected billing.
- **Decision:** Not a user-IP solution. Deferred adapter for managed enterprise
  deployments only; never accept an arbitrary proxy URL from a rank-check request.

### 6. Server-side direct execution

- **Feasibility/reliability:** Operationally simple, but direct SERP scraping is
  brittle and often challenged or prohibited. Structured official APIs are reliable
  only where they actually provide the required result.
- **Deployment/DirectAdmin:** Compatible with PHP/cron when outbound traffic and
  execution limits allow it; bounded jobs are required.
- **Desktop/mobile:** A user-agent string or request parameter can model a target but
  does not turn the server into the user's desktop or phone.
- **Security/privacy/credentials:** Centralized egress and parsing enlarge the attack
  surface. No user provider credentials. Strict destination allowlists are mandatory.
- **IP semantics:** Provider sees server/NAT egress, never user IP.
- **Limits/terms/maintenance/scale/cost:** Central IP limits and blocking are severe.
  Markup adapters have high maintenance; scaling egress is costly and risky.
- **Failure modes:** Host outbound block, timeout, rate limit, challenge, layout
  change, server IP ban, partial response.
- **Decision:** Allowed only for approved structured adapters and diagnostics; direct
  search-page scraping is not selected.

### 7. Third-party SERP provider

- **Feasibility/reliability:** Usually the most reliable structured server option,
  subject to vendor coverage, provenance, contract, and service quality.
- **Deployment/DirectAdmin:** Good; ordinary HTTPS API calls can be processed by
  bounded cron jobs. Requires outbound HTTPS.
- **Desktop/mobile:** Vendor parameters may model desktop/mobile, locale, and engine;
  semantics must be recorded per adapter and are not physical-device proof.
- **Security/privacy/credentials:** Store vendor keys as secrets, minimize submitted
  data, sign requests where supported, validate responses, and execute vendor review.
- **IP semantics:** Provider/vendor egress is visible to the engine, not user IP.
- **Limits/terms/maintenance/scale/cost:** Contract quotas and per-query cost apply.
  Vendor handles much provider churn; lock-in and price changes remain. Verify that
  collection and downstream use comply with current provider/vendor terms.
- **Failure modes:** Quota, billing, outage, stale/incorrect result, geographic
  mismatch, vendor schema change, credential compromise, contract termination.
- **Decision:** Selected default production source when a configured adapter has
  passed legal, security, privacy, accuracy, and commercial review.

## Decision

Adopt a hybrid control plane with capability-negotiated execution sources:

1. **Default:** an approved structured third-party/provider adapter. It is reliable
   and shared-host compatible but is explicitly labeled `provider_api`; it does not
   satisfy user-IP execution.
2. **User-IP best effort:** an optional signed/enrolled `local_agent`. A job may use
   it only when the initiating user selects it, the device is online and capable,
   and the requested engine adapter is enabled after terms/security review.
3. **Fallback is never silent.** A local-agent request that cannot run becomes
   `awaiting_agent`, `expired`, or fails with a safe reason. Switching to provider or
   server execution requires explicit policy/user consent and records a new attempt.
4. Browser page JavaScript is control/observation UI only. Extension support is a
   future optional executor. Direct server SERP scraping and arbitrary proxies are
   not selected.

No rank engine, agent, extension, provider integration, schema migration, or fake
result is implemented in Phase 09.

## Consequences

The system can truthfully distinguish `local_agent`, `browser_extension`,
`provider_api`, and approved `server_adapter` attempts. Only a successfully attested
agent attempt may be described as user-network execution, and even then the UI must
say “executed from this agent's observed network” rather than claim a residential or
physical IP. Mobile user-IP execution remains unavailable until a supported native
mobile agent exists. Default provider results prioritize reliability over user-IP
semantics. Additional engineering and vendor/legal reviews are unavoidable.

## Terms review record

Before enabling any adapter, record the terms/policy URL, version or review date,
permitted use, rate limits, retention/resale constraints, reviewer, and next review.
At minimum review the current search-provider terms, vendor contract/DPA, browser or
extension-store policies, and applicable privacy law. A technical capability or API
key is not evidence of permission. Terms can change and require periodic re-review.

Reference starting points (not a substitute for counsel or an adapter-specific
review) are [Google Terms of Service](https://policies.google.com/terms),
[Google Search spam policies](https://developers.google.com/search/docs/essentials/spam-policies),
[Chrome extension cross-origin request guidance](https://developer.chrome.com/docs/extensions/develop/concepts/network-requests),
and [browser CORS guidance](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/CORS).
The Phase 09 environment's outbound proxy returned HTTP 403 for each URL, so current
text was not independently retrieved. Selecting and enabling an adapter therefore
remains an explicit Phase 10 blocker rather than a claimed terms approval.
