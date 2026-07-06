# Phase 2 go-live — make debbie public (TLS + domain + mail)

> The **ordered sequence** for taking MixTape v2 from LAN-only (end of Phase 1) to publicly reachable.
> The *why* lives in [app-rewrite.md](app-rewrite.md) and [server-requirements.md](server-requirements.md); this doc
> is the *do-it* checklist so the go-live steps don't get lost between phases. The LAN-only Phase-1
> baseline we start from is summarised in "Where Phase 1 left it" below.

## Where Phase 1 left it (the starting state)

- **Only one site exists — `dev`:** `/var/www/mixtape.dev`, served over **HTTPS on the LAN only**
  (self-signed `/etc/ssl/certs/mixtape-selfsigned.crt`) at **`https://debbie.local`**, DB **`mixtape_dev`**
  (localhost). It's the development site and **stays untouched** by go-live.
- **There is no prod site yet** — building it is **Step 0** below.
- nginx: HTTP→HTTPS 301 + `443 ssl http2` + security headers — incl. a CSP **hardening subset**
  (`base-uri`/`object-src`/`form-action`/`frame-ancestors`); prod adds the fuller resource policy (Step 0).
  ACME path (`/.well-known/acme-challenge/`) already open for HTTP-01.
- Firewall: **`80/443` tightened to LAN-only (IPv4)**; v6 web dropped; **no Fritzbox forward** exists.
- PostgreSQL localhost-only; Samba + SSH LAN-only.

## 🚫 Hard precondition — auth before exposure

**Do not forward any port until real auth is in force.** Fortify (registration disabled) + one-time
expiring **invite tokens** + **signed share-links** must all work on the LAN first. The legacy web-server
Basic Auth is dropped (it would block share-links). No public exposure of the app + collection without
this. (See [app-rewrite.md](app-rewrite.md) §auth.)

## Step 0 — Build the prod site on debbie

The dev site (`/var/www/mixtape.dev`, `debbie.local`) stays as-is; **prod is a separate, isolated site**
so dev work never affects it. The media library `/var/media` is shared by both at the filesystem level
(one collection).

- **App:** deploy the rewritten app to **`/var/www/mixtape.prod`** (`composer install --no-dev
  --optimize-autoloader`, `npm ci && npm run build`, `php artisan storage:link`). **Ownership (decide at
  go-live):** prefer **git/CI deploy** with code **root/deploy-owned + `www-data` group-read** (a
  compromised web user can't rewrite prod code) — *not* SFTP-writable by the dev user as on dev.
  `storage/` + `bootstrap/cache` → `www-data`-writable; `.env` 640 `root:www-data`. (If you must
  SFTP-deploy prod as a user, mirror the dev ownership model — weaker, but works.)
- **DB:** create role + db **`mixtape_prod`** (scram); password → `/root/mixtape-prod-db.pw`. `.env`:
  `DB_DATABASE=mixtape_prod`, `DB_USERNAME=mixtape_prod`, `APP_ENV=production`, `APP_DEBUG=false`. Then
  `migrate --force` → `db:seed --force` → `app:update` (rebuild the library from `/var/media`).
- **php-fpm:** isolated pool **`mixtape-prod`** → `/run/php/mixtape-prod.sock` with its own slow/error
  logs (mirror the dev pool config; dev keeps the default pool).
- **nginx:** vhost **`mixtape.prod`** → root `/var/www/mixtape.prod/public`, `fastcgi_pass` the prod
  socket, **`server_name mixtape.ddns.example`** (→ real host in Step 4), make it the **`default_server`**
  (move that flag off the dev vhost), per-site logs `/var/log/nginx/mixtape.prod.{access,error}.log`.
  Reuse the self-signed cert for now (Step 4 swaps in the real one).
- **Security headers / CSP:** carry the three headers dev has (`X-Content-Type-Options nosniff`,
  `X-Frame-Options SAMEORIGIN`, `Referrer-Policy strict-origin-when-cross-origin`) into the prod vhost,
  and add the **full** CSP. Dev runs only the resource-agnostic hardening subset
  (`base-uri`/`object-src`/`form-action`/`frame-ancestors`) because Vite HMR loads scripts + a `wss` from
  the separate `:5174` vhost; **prod serves built assets from `'self'` with no dev server**, so it can lock
  resource origins too:

  ```nginx
  add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; upgrade-insecure-requests" always;
  ```

  Keep all four `add_header`s in the `server {}` block — no `location` sets its own `add_header`, so they
  apply to every response (the inheritance trap). Caveats baked into the policy: `script-src 'unsafe-inline'`
  is required by the inline pre-paint theme script (nginx can't emit per-request nonces) and
  `style-src 'unsafe-inline'` by Vue's `v-bind()` inline styles — both still block *external-origin*
  loads. When the **vidstack** player lands, `media-src` may need `blob:` (and possibly `worker-src 'self'
  blob:`) — re-test then. Stronger follow-up: move CSP into Laravel middleware with per-request **nonces**
  (e.g. `spatie/laravel-csp`) to drop `script-src 'unsafe-inline'`.
- Confirm prod serves on the LAN (via the IP) **before** touching the router.

## Step 1 — Real domain + DNS (CNAME hybrid)

The home IP is dynamic, so we keep the DynDNS host updating it and point a **real** domain at it via CNAME:

- Buy a cheap real domain (`<domain>`). Needed for: a clean URL, a Let's Encrypt cert, **and** the
  SPF/DKIM/DMARC TXT records mail needs (the DynDNS host can't host TXT).
- Create `music.<domain>` → **CNAME** → `mixtape.ddns.example` (the DynDNS dynamic host — currently **No-IP**;
  note No-IP can't host TXT records even on its paid plan — A/AAAA/redirect only — which is the *other*
  reason a real domain is needed: it's where SPF/DKIM/DMARC live). The DynDNS client keeps
  `mixtape.ddns.example` pointed at the current home WAN IP, so **nothing new runs on debbie** and the home-IP
  churn is invisible to `music.<domain>`.
- Let's Encrypt **HTTP-01 follows the CNAME**, so the cert validates against `music.<domain>` fine.

## Step 2 — Fritzbox port-forward (the only WAN exposure)

> **Decide the network link before exposing.** debbie runs on a **USB WiFi dongle** (no Ethernet in its
> current room). The link is stable today — the 2026-06-30 incident where WiFi silently dropped SSH's
> DSCP-EF frames is fixed via sshd **`IPQoS cs0 cs0`**, and WiFi power-save is disabled — but a **wired**
> link is a sturdier foundation for an internet-facing, family-relied-on service, and removes the entire
> WiFi failure class (which is miserable to debug once you're locked out remotely). Options that **avoid
> relocating the box**: a **Powerline** link (AVM **FRITZ!Powerline**, pairs with the Fritzbox) or a
> discreet long/flat cable; otherwise move debbie to a room with a drop. Not a blocker — but don't let it
> stay forgotten once debbie is public. Why/context: `debbie-infrastructure.local.md` → Network.

- Forward **TCP 80 + 443** → `192.168.178.200` (80 = ACME challenge + HTTP→HTTPS redirect; 443 = app).
- Confirm the DynDNS client updates `mixtape.ddns.example` → current WAN IPv4.
- **Verify SSH (22), Samba (139/445), and PostgreSQL are NOT forwarded** — only 80/443, ever.
- **IPv6 decision:** debbie has a public IPv6 GUA. Decide whether to expose the web over v6 too
  (open the Fritzbox IPv6 firewall for debbie:80/443) or stay **v4-only** (simpler; leave the Fritzbox
  v6 firewall default-deny). If staying v4-only, also leave debbie's v6 web blocked (Phase 1 state).

## Step 3 — Widen debbie's firewall back to WAN

Phase 1 tightened `80/443` to LAN-only. For go-live, reopen them in `/etc/nftables.conf`:

```nft
# replace the Phase-1 LAN-only line:
#   ip saddr 192.168.178.0/24 tcp dport { 80, 443 } accept
# with open-to-all (both families):
    tcp dport { 80, 443 } accept
```

Then: `nft -c -f /etc/nftables.conf && nft -f /etc/nftables.conf && systemctl restart fail2ban`
(restart fail2ban so it re-adds its ban table after the reload). SSH/Samba stay LAN-scoped; Postgres
stays localhost-only.

## Step 4 — Real TLS cert (Let's Encrypt, HTTP-01)

```bash
debbie#  apt -y install certbot python3-certbot-nginx
debbie#  certbot --nginx -d music.<domain>     # HTTP-01; needs WAN-reachable :80 (Steps 2–3 done)
```

- certbot rewrites the vhost: swaps the self-signed `ssl_certificate*` lines for the real LE cert and
  ensures the HTTP→HTTPS redirect.
- Swap `server_name` / Laravel `APP_URL` / any remaining `debbie.local`/`ddns.me` placeholders to
  **`music.<domain>`**.
- DNS-01 alternative **only** if the real domain's DNS provider supports TXT automation (the DynDNS host
  does not) — HTTP-01 is the default path here.
- **Confirm auto-renewal:** `certbot renew --dry-run` and `systemctl list-timers | grep certbot`.

## Step 5 — App production config

- prod `.env` (`/var/www/mixtape.prod/.env`): `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL=https://music.<domain>`.
- nginx terminates TLS directly on debbie (the Fritzbox forward is plain NAT, **not** a reverse proxy),
  so no `TrustProxies`/`X-Forwarded-*` handling is needed.
- Cache for prod: `php artisan config:cache route:cache view:cache` (and `event:cache` if used).
- Re-confirm storage/`bootstrap/cache` are www-data-writable; run artisan as `www-data`.

## Step 6 — Transactional mail (Mailtrap + DNS auth)

Fortify sends password resets, email verification, and invite links — via a **relay**, never from
debbie's residential/dynamic IP (blocklisted, no PTR).

- Use **Mailtrap** (free tier; same as on `cantrip.me`). `.env`: `MAIL_MAILER=smtp`, Mailtrap
  `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD`/encryption, `MAIL_FROM_ADDRESS=no-reply@<domain>`.
- Add DNS records **on the real domain** (Mailtrap provides the exact values):
  - **SPF** TXT — authorize Mailtrap's sending hosts.
  - **DKIM** — Mailtrap-provided CNAME/TXT selector(s).
  - **DMARC** TXT — start `p=none` (monitor), tighten to `quarantine`/`reject` once SPF+DKIM align.
- Inbound mail isn't needed; add a free forwarder later only if replies are ever wanted.
- Verify deliverability: send a test (e.g. mail-tester.com) → SPF/DKIM/DMARC all pass.

## Step 7 — Web-login hardening (fail2ban + rate limit)

Now that a login surface exists:

- Add a Fortify/nginx login jail to `/etc/fail2ban/jail.local` (ban repeated failed logins), watching the
  **prod** nginx logs (`/var/log/nginx/mixtape.prod.*`) — dev is LAN-only and gets no jail. The `sshd`
  jail already covers SSH; this adds the web login.
- Rate-limit the login/invite routes (Laravel `throttle` middleware and/or nginx `limit_req`).

## Step 8 — Backup failure alerting (healthchecks.io + ntfy)

Deferred here from Phase 1 on purpose: the media backup itself runs from **Stage 10** (weekly tar
snapshots, with **verify-on-backup** already added in Phase 1), but it currently only logs to the
**journal** — there's **no active alert** if a run fails or silently stops happening. Wire up monitoring
now, alongside the other go-live polish:

- **Dead-man's-switch — [healthchecks.io](https://healthchecks.io)** (free tier). Catches the *silent*
  cases the journal can't — debbie powered off, the timer disabled, the script hung. Create a check
  (period **7d**, grace **1d**) and add pings to `/usr/local/sbin/mixtape-media-backup.sh`: `…/start` at
  begin, the **success** URL at end, and `…/fail` (via a `trap`) on error. Skipped "no-change" runs should
  still ping success so the switch doesn't false-alarm.
- **Delivery — [ntfy.sh](https://ntfy.sh)** (push to phone, no MTA needed). Set healthchecks' alert
  channel to **ntfy** (built-in integration) → phone push when a backup is missing/failed. Use a long,
  random topic name (it's the only secret on the public server); self-hostable if you want it private.
- Add a systemd **`OnFailure=`** hook on `mixtape-media-backup.service` → ntfy too, for an instant push
  the moment a run errors (complements the dead-man's-switch, which covers "didn't run at all").
- Once **Mailtrap** is up (Step 6), email is an alternative/extra alert channel.

> Note: we're documenting this at go-live for convenience, but it doesn't depend on TLS/domain/mail —
> ntfy + healthchecks work standalone, so it *can* be done earlier if a silent backup failure ever bites.

## Step 9 — Verify go-live ✅

- [ ] From **off-LAN** (phone on mobile data): `https://music.<domain>` loads with a **valid** cert (no
      warning), HTTP→HTTPS works, and login is required.
- [ ] **Security headers** on a prod page response (`curl -sI https://music.<domain> | grep -iE
      'content-security|x-frame|x-content|referrer'`); app, login, and **audio playback** all work under the
      full CSP — the vidstack player is the likeliest `media-src`/`worker-src` casualty, so exercise it.
- [ ] **SSH / Samba / PostgreSQL are NOT reachable** from the WAN (IPv4 **and** IPv6).
- [ ] Invite flow works end-to-end; a **signed share-link** plays without login and **expires**.
- [ ] Mail: trigger a password reset → arrives, **SPF/DKIM/DMARC pass**.
- [ ] `certbot` auto-renew timer active; `renew --dry-run` clean.
- [ ] Backup alerting works: a test `…/fail` ping (or a deliberately-failed run) produces a **phone push**, and healthchecks flags a missing success ping.

## Rollback / kill-switch

Removing the **Fritzbox 80/443 forward** instantly takes debbie offline (back to LAN-only) without
touching debbie — the fastest "oh no" switch. Re-tighten debbie's `80/443` to LAN-only too if pausing
for long.
