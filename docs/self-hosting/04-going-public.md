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

## Step 7 — Login hardening and logs

Now that a public login surface exists:

Application-level throttling already covers the login and mail routes (see
[`03-production-deploy.md`](03-production-deploy.md#rate-limiting-and-precognition)), so the jail below
is defence in depth rather than the primary gate. The `sshd` jail already covers SSH.

> **A jail on the nginx access log cannot see what you think it can.** The log records
> `POST /login → 302` whether the credentials were right or wrong — the framework redirects back to
> the form either way — so a naive `failregex` bans successful users. Match something that *is*
> unambiguous: either the **429s** your own throttle already emits (a client tripping a 5/min login
> limiter is misbehaving by definition), or a **dedicated application log channel** fed by the
> framework's authentication-failure event. The second is cleaner and worth the small app change.

### A dedicated auth-failure log

The app writes one line per authentication failure to its own channel
(`config/logging.php` → `auth`, filled by `App\Listeners\LogAuthenticationFailures`):

```
[2026-07-19 07:18:02] production.WARNING: login.failed ip=203.0.113.9 username="ada" user_id=- route="login.store" ua="curl/8.7.1"
```

**The submitted password is not there, on purpose.** It is the obvious field to want — it is how you
would spot one password sprayed across many accounts — but failed logins carry *working* credentials
far more often than intuition suggests: the user typoed the username, or picked the wrong entry out
of a password manager. Logging it builds a plaintext credential store that outlives the account,
survives the next password change, and is copied into every backup. A truncated hash is not a fix
either; a fast unsalted hash of a human password is a wordlist away from plaintext. `ua` answers the
same operational question — human or script — and holds no secret.

Three things about that channel are load-bearing rather than stylistic:

- **Its level is a literal, not `env('LOG_LEVEL')`.** Production runs at `warning`; the day someone
  raises that to `error`, an env-driven channel would stop feeding the jail and nothing would look
  broken.
- **`ip=` comes first, and everything after it is scrubbed and quoted.** The username is whatever the
  attacker typed. Left raw, a newline in it forges a complete log line — and since fail2ban bans the
  address it reads *out of the matched line*, that turns your own jail into a way to ban a third
  party. The listener strips control characters, bounds the length, and JSON-quotes the result.
- **Only failures are logged.** A successful login would otherwise write a valid username beside a
  valid IP into a file that outlives the session.

Because the format is parsed by a filter that fails silently, treat it as an interface: the app's
test suite keeps a mirror of the `failregex` and asserts real output against it, including that one
failure produces exactly *one* line.

> **Watch out for double registration.** Laravel discovers listeners in `app/Listeners` by matching
> any `handle*` method against its type-hint. A listener that is *also* wired explicitly gets
> registered twice, and every failure is logged twice — which silently halves the effective
> `maxretry` of any jail counting those lines. Either rely on discovery alone, or name the methods
> something other than `handle*`, as this app does.

### The jail

> **Check your `[DEFAULT]` block first.** A hardened `jail.local` typically sets `backend = systemd`
> so the `sshd` jail reads the journal. That is inherited by every jail — including a file-based one
> like this — which makes fail2ban watch the journal for a log that only exists on disk. The jail
> starts, reports itself healthy, and matches nothing. The shipped jail file overrides `backend`
> for exactly this reason; do not drop the line because it looks redundant.
>
> Inherit `ignoreip`, `banaction`, `bantime` and `findtime` from `[DEFAULT]` rather than repeating
> them, so the ban mechanism and the LAN exemption stay defined in one place.

**The log must exist before the jail starts.** fail2ban refuses to start a jail whose `logpath` is
missing, and the app only creates `auth.log` on the first authentication failure — so deploy, then
fail one login on purpose, then install the jail.

Install the filter and jail, then reload:

```sh
sudo install -m 0644 mixtape-auth.fail2ban-filter.conf /etc/fail2ban/filter.d/mixtape-auth.conf
sudo tee -a /etc/fail2ban/jail.local < mixtape-auth.fail2ban-jail.conf   # edit ignoreip first
sudo fail2ban-client reload
```

Verify against the real log before trusting it — `fail2ban-regex` reports how many lines matched, and
zero matches is the failure mode you will not otherwise notice:

```sh
sudo fail2ban-regex /var/www/mixtape.prod/storage/logs/auth.log \
  /etc/fail2ban/filter.d/mixtape-auth.conf
sudo fail2ban-client status mixtape-auth
```

Then fail a login on purpose and confirm the counter moves.

> **A LAN range in `ignoreip` does not protect your household.** When someone inside the LAN opens
> the *public* URL, the router hairpins the connection (NAT loopback) and the web server sees your
> **WAN** address — so every device in the house arrives as one external-looking IP that no LAN CIDR
> covers. Ten fumbled passwords from anyone at home then bans the whole household off the public
> link (the LAN hostname still works, which is exactly why this can go unnoticed until a guest
> complains).
>
> Verify rather than assume: fail a login from a LAN browser *via the public URL* and read the `ip=`
> field. If it matches `dig +short <your-dyndns-host>`, you are hairpinned. Fix by ignoring the
> DynDNS name — fail2ban accepts hostnames and re-resolves them, so it tracks a dynamic IP:
>
> ```
> ignoreip = 127.0.0.1/8 ::1 <your-lan-cidr> <your-dyndns-host>
> ```
>
> Costs a DNS lookup per check, and leaves a brief window after an IP change where the house is
> bannable again. Both beat the alternative.

### Log rotation

Rotation is not automatic for anything you configured yourself, and an unrotated log on a
public-facing box is a slow-motion disk-full outage.

- **nginx** — the stock `/etc/logrotate.d/nginx` globs `/var/log/nginx/*.log`, so per-vhost logs are
  already covered. Nothing to do.
- **php-fpm** — the stock entry covers only the *master* log. A per-site pool with its own
  `error_log` / `slowlog` (as in [`files/mixtape-prod.pool.conf`](files/mixtape-prod.pool.conf)) is
  **not** rotated by anything. Install
  [`files/mixtape-php.logrotate`](files/mixtape-php.logrotate) as `/etc/logrotate.d/mixtape-php`; the
  file's header explains the non-obvious requirements (`su root <group>`, and signalling the fpm
  master).
- **the app's own logs** — `storage/logs/*.log` are not rotated by anything either. Install
  [`files/mixtape-auth-log.logrotate`](files/mixtape-auth-log.logrotate) for the auth log. It needs
  *no* postrotate signal, because the framework rebuilds its container per request and reopens the
  path on its own — which is also why its `su` can safely drop to the web user, unlike the php-fpm
  entry above.
- Verify rather than assume — `sudo logrotate --debug /etc/logrotate.d/mixtape-php` dry-runs the
  entry and prints what it *would* do, including the "insecure permissions, skipping" refusal.

> **A dry run does not exercise the postrotate script.** If nothing is due for rotation, logrotate
> prints `not running postrotate script, since no logs were rotated` and you learn nothing about the
> half most likely to be wrong. Note *which user* the debug output drops to (`switching euid from 0
> to …`) and ask whether that user can actually signal the daemon that owns the log — a slow log
> opened by a root-run master cannot be reopened by an unprivileged postrotate, and the helper exits
> 0 either way. The symptom, a week later, is a live log frozen at zero bytes next to a rotated file
> that is still growing.
>
> To actually settle it, force one rotation and check that the daemon *acted* on the signal. Seed a
> byte first — `notifempty` skips a zero-length file even under `-f`:
>
> ```sh
> sudo sh -c 'echo rotation-test >> /var/log/php/<pool>.slow.log'
> sudo logrotate -vf /etc/logrotate.d/mixtape-php
> sudo tail -5 /var/log/php8.4-fpm.log        # expect: NOTICE: error log file re-opened
> ```
>
> That notice, timestamped to the rotation, is the confirmation. Inspecting `/proc/<pid>/fd` is *not*
> a reliable substitute: fpm reopens the slow log by path per dump rather than holding a handle, so
> an empty result there proves nothing either way. Delete the seeded `.1` file afterwards so it
> isn't later mistaken for a real slow-request record.

Also revisit **verbosity** now that real traffic is arriving: `LOG_LEVEL=warning` in the production
`.env` keeps the application log to things you would actually act on.

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
