# 4 — Going public

> Taking the instance from LAN-only to publicly reachable: a real domain, a port-forward, a trusted
> certificate, and working transactional mail. Do these in order.

## 🚫 Hard precondition — authentication before exposure

**Do not forward any port until real authentication is in force.** Invite-only registration and signed
share-links must both work on the LAN first.

This is not a formality. The moment port 443 is forwarded, the entire collection is one
misconfiguration away from being world-readable, and scanners find new hosts within minutes. Verify
that a logged-out browser can reach nothing but the login page before continuing.

## Step 1 — Domain and DNS

Your home IP is almost certainly dynamic, so keep a DynDNS host updating it and point a **real domain**
at that host by CNAME:

- Register a domain. You need one for three separate reasons: a clean URL, a Let's Encrypt
  certificate, and — the one people forget — the **SPF/DKIM/DMARC TXT records** mail requires. Most
  DynDNS providers cannot host TXT records at all, which is what forces this.
- Create `<subdomain>.<your-domain>` as a **CNAME** to your DynDNS hostname. The DynDNS client keeps
  that hostname pointed at your current WAN IP, so address churn is invisible to your real domain and
  nothing extra runs on the server.
- Let's Encrypt's HTTP-01 challenge follows CNAMEs, so certificate validation works fine through this
  indirection.

> **CNAME at the apex is impossible** — the DNS specification forbids it, because the apex must carry
> SOA and NS records and a CNAME cannot coexist with other records. This is universal, not a
> limitation of your registrar. Use a subdomain, or a provider offering ALIAS/ANAME flattening.

## Step 2 — Port-forward

- Forward **TCP 80 and 443** to the server's LAN address. Port 80 is needed for the ACME challenge
  and the HTTPS redirect.
- **Verify SSH (22), Samba (139/445), and the database are not forwarded.** Check this explicitly
  rather than assuming; some routers helpfully "assist" with UPnP.
- **Decide about IPv6.** If your ISP gives the host a public IPv6 address, it may be reachable
  *without* any forward, because IPv6 has no NAT — the router firewall is the only thing stopping it.
  Either open it deliberately for 80/443, or confirm it is closed. Do not leave this unexamined.

## Step 3 — Widen the firewall

Replace the LAN-only web rule from [`02-host-setup.md`](02-host-setup.md#25-firewall-nftables):

```nft
# was:  ip saddr <lan-subnet> tcp dport { 80, 443 } accept
        tcp dport { 80, 443 } accept
```

```bash
sudo nft -c -f /etc/nftables.conf && sudo nft -f /etc/nftables.conf
sudo systemctl restart fail2ban     # re-adds its ban table after the reload
```

SSH and Samba stay LAN-scoped; the database stays localhost-only.

## Step 4 — Real TLS certificate

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d <subdomain>.<your-domain>
```

certbot rewrites the vhost, swapping the self-signed paths for the real certificate and ensuring the
HTTP→HTTPS redirect. It leaves `# managed by Certbot` markers; leave them alone, as renewal re-applies
them.

Update `server_name` and `APP_URL` to the real host, then confirm renewal actually works:

```bash
sudo certbot renew --dry-run
systemctl list-timers | grep certbot
```

A certificate that cannot auto-renew silently expires in 90 days.

## Step 5 — App production config

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<subdomain>.<your-domain>
```

```bash
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan config:cache
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan route:cache
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan view:cache
sudo -u www-data /usr/bin/php /var/www/mixtape.prod/artisan event:cache
```

> ⚠️ **Production runs from cached config, so editing `.env` changes nothing on its own.** Every
> `.env` edit must be followed by `optimize:clear` + `config:cache`. Verify with `artisan about`,
> which prints what the app actually believes. The routine deploy script does this for you; manual
> edits do not.

nginx terminates TLS directly on the host — the router forward is plain NAT, not a reverse proxy — so
no `TrustProxies` or `X-Forwarded-*` handling is needed. If you *do* put a proxy in front later, that
changes.

## Step 6 — Transactional mail

The app sends password resets, email verification, and username reminders. **Email verification gates
login**, so until mail works, an invited person cannot finish signing up without someone reading the
link out of the logs for them.

Send through a **relay**. Never send directly from a residential IP: those ranges are blocklisted and
have no PTR record, so the mail is discarded — usually silently, which is the worst failure mode.

Any relay works (Mailtrap, Postmark, SES, Brevo…). The setup shape is the same.

> ⚠️ If your provider offers both a **sandbox/testing** inbox and a **sending** product, make sure you
> use the sending one. A sandbox captures mail and never delivers it, so a misconfiguration here looks
> exactly like success while nobody receives anything.

### DNS records

Your provider generates the exact values. Three things to know:

- **DKIM** — usually CNAMEs on selector subdomains. No special handling.
- **SPF** — ⚠️ **exactly one SPF TXT record per domain, ever.** If the domain already has one (a
  registrar's mail service often adds one), you must **merge** the includes into that single record:

  ```
  v=spf1 include:existing-provider include:new-provider ~all
  ```

  Two SPF records produce a permerror, and SPF fails *entirely* — strictly worse than having no SPF
  record at all. This is the single most common way to break mail while trying to fix it.

  Some providers avoid SPF changes altogether by giving you a **custom Return-Path** CNAME. Since SPF
  authenticates the envelope sender, that subdomain inherits the provider's SPF and still aligns with
  your domain. If you are offered this, take it — no merge, no lookup budget consumed.

- **DMARC** — a TXT record at `_dmarc`. Start at `p=none` (monitor only) and tighten to
  `quarantine`/`reject` once you have confirmed SPF and DKIM both pass *and align*.

  ```
  _dmarc    TXT    "v=DMARC1; p=none;"
  ```

  If your provider hands you a DMARC record with `rua`/`ruf` pointing at *their* address, consider
  writing your own instead: those are your reports, and `ruf` in particular ships the content of
  failing messages — your users' password-reset mails — to a third party. Add `rua` later, pointing
  at a mailbox you control. Note that a cross-domain `rua` requires the receiving domain to publish
  `<your-domain>._report._dmarc.<their-domain>` or the reports are silently dropped.

**Click/open tracking is offered by most relays; decline it for this app.** It rewrites every link to
route through the provider's domain, which for verification and password-reset mail means a third
party sits in the middle of your security-sensitive links — and rewritten links read as phishing to
both users and spam filters.

### App configuration

```ini
MAIL_MAILER=smtp
MAIL_HOST=<relay host>
MAIL_PORT=587
MAIL_USERNAME=<from the relay>
MAIL_PASSWORD=<from the relay>
MAIL_FROM_ADDRESS="no-reply@<your-domain>"
MAIL_FROM_NAME="${APP_NAME}"

LOG_LEVEL=warning
```

Two traps in that block:

> ⚠️ **Leave `MAIL_SCHEME` unset.** Laravel accepts only `smtp` or `smtps` and derives the right one
> from the port (465 → `smtps`, otherwise `smtp` with STARTTLS negotiated automatically). A plausible-
> looking value such as `tls` reaches Symfony's transport factory and throws
> `UnsupportedSchemeException`.

> ⚠️ **Raise `LOG_LEVEL` in the same edit.** While `MAIL_MAILER=log`, the level must be `debug`,
> because the log mailer writes rendered messages at debug level — anything higher discards them. Once
> real delivery works, leave it at `debug` and every email, including signed reset links, sits in your
> production logs indefinitely.
>
> The combination is what bites: a **stale config cache** still on `MAIL_MAILER=log` plus a raised
> `LOG_LEVEL` means mail "sends" successfully, writes nothing, and delivers nothing. Silent at every
> layer. `artisan about` is how you find it.

Then `optimize:clear` + `config:cache`, and confirm *Drivers → Mail* reads `smtp`.

### Verify

```bash
sudo -u www-data env HOME=/tmp /usr/bin/php /var/www/mixtape.prod/artisan tinker \
  --execute='Mail::raw("test", fn($m) => $m->to("you@example.com")->subject("test"));'
```

Then send one to [mail-tester.com](https://mail-tester.com) and check the **authentication** section —
ignore the overall score, which penalises a bare test message for thin content. You want SPF, DKIM and
DMARC each passing *and aligned* with your domain. (Alternatively, mail a Gmail account and use
**Show original**, which prints the same verdicts without involving another service.)

Finally exercise the real flow: request a password reset in the browser and confirm the link works
when clicked. That tests `APP_URL` and signed-URL generation, which a raw test message does not touch.

## Step 7 — Login hardening

Now that a public login surface exists:

- Add a fail2ban jail watching the **production** nginx logs for repeated failed logins. The `sshd`
  jail already covers SSH.
- Application-level throttling already covers the login and mail routes (see
  [`03-production-deploy.md`](03-production-deploy.md#rate-limiting-and-precognition)), so fail2ban is
  defence in depth rather than the primary gate.

## Step 8 — Backup alerting

The backup job from [`02-host-setup.md`](02-host-setup.md#210-backups) logs to the journal, which
nobody reads. The failure that actually happens is the *silent* one — the machine was off, the timer
got disabled, the script hung — and a journal entry cannot report an event that never occurred.

- **A dead-man's-switch** (healthchecks.io or similar) catches exactly that class. Create a check with
  a period slightly longer than your backup interval, and have the script ping `/start` at the
  beginning, success at the end, and `/fail` from a `trap` on error. Skipped "nothing changed" runs
  should still ping success, or the switch false-alarms.
- **Push delivery** (ntfy or similar) gets it to your phone without needing a mail server.
- A systemd `OnFailure=` hook on the backup unit adds an immediate push when a run errors, which
  complements the dead-man's-switch covering "did not run at all".

This does not depend on TLS, the domain, or mail, so it can be done at any point.

## Step 9 — Final verification

- [ ] From **off the LAN** (phone on mobile data): the site loads with a **valid** certificate,
      HTTP redirects to HTTPS, and login is required.
- [ ] Security headers present on a real page response:
      `curl -sI https://<your-domain> | grep -iE 'content-security|x-frame|x-content|referrer'`
- [ ] **Audio playback works under the full CSP** — the likeliest casualty, so exercise it explicitly.
- [ ] **SSH, Samba, and the database are unreachable from the WAN** — over IPv4 **and** IPv6.
- [ ] The invite flow works end to end, and a signed share-link plays without login and then expires.
- [ ] A password reset arrives, and SPF/DKIM/DMARC pass.
- [ ] `certbot renew --dry-run` is clean and the renewal timer is active.
- [ ] A deliberately failed backup produces an actual notification.

## Kill switch

**Removing the router's 80/443 forward instantly takes the instance offline** without touching the
server at all. That is the fastest recovery from anything alarming. If the pause will be long,
re-tighten the host firewall to LAN-only as well.
