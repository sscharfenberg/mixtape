# MixTape — Server requirements & design

> What a host needs to run **MixTape** (the self-hosted mp3 / audiobook web app), plus the **design
> rationale** behind those choices. Generic + reproducible — safe to commit. The **concrete** details of
> the live server (IPs, disks, secret locations) live in the **gitignored**
> `docs/debbie-infrastructure.local.md`.
>
> Phase 1 (the server) is **built & verified (2026-06-28)**. See also: [app-rewrite.md](app-rewrite.md)
> (the Phase 2 app) · [phase-2-go-live.md](phase-2-go-live.md) (Phase 2 go-live).

## Role — what runs where

A single always-on home server hosts everything:

| Concern | Location | Notes |
| --- | --- | --- |
| Web app | `/var/www/mixtape.{dev,prod}` | Laravel + Inertia; **dev + prod** sites, each isolated (own DB / fpm pool / vhost / logs) |
| Database | `/var/lib/postgresql` | PostgreSQL, **localhost-only** |
| Media library | `/var/media/{music,audiobooks}` | the mp3 / audiobook collection |
| Samba share | exports `/var/media` | SMB3, no guests, LAN-only |
| Web server | nginx + php-fpm | HTTPS (self-signed on LAN; Let's Encrypt at go-live) |

## Hardware

| Resource | Minimum | Notes |
| --- | --- | --- |
| CPU | x86-64, 2 cores | reference box has more headroom |
| RAM | 4 GB | 8 GB+ comfortable (php-fpm `memory_limit` 512M; backups are I/O-bound). Ref: 32 GB |
| System disk | SSD, OS + DB + app + media | size the media volume to the collection + growth (ref: ~96 GB collection on a ~930 GB NVMe) |
| Backup disk | 2nd drive ≥ collection size | a **separate** disk so reinstalling the system disk leaves it untouched (ref: 2 TB exFAT USB) |
| Availability | always-on | it's a home server reachable from the internet |

## Operating system

- Latest stable **Debian** (built and verified on **Debian 13 "Trixie"**), **minimal** install, no desktop.
- **Plain LVM, no disk encryption (no LUKS).** The data is just the collection (not sensitive), and an
  unencrypted box **reboots unattended** after a power cut — important for an always-on server shared
  with family. LVM stays (for the resize flexibility); only LUKS is dropped.
- **Timezone UTC** (the DB and app store UTC; the browser renders the visitor's local time).

## Storage layout (LVM)

Separate logical volumes so a runaway log or DB can't fill the media, and so the media can grow/snapshot
independently. Leave part of the volume group unallocated to `lvextend` later.

| Volume | Mount | Guidance |
| --- | --- | --- |
| root | `/` | ~50 GB (OS + packages) |
| var | `/var` | ~80 GB (app `/var/www` + DB `/var/lib/postgresql` + logs) |
| media | `/var/media` | size to the collection + growth (`music/`, `audiobooks/`) |
| home | `/home` | small (no media here) |
| swap | — | modest (no hibernation) |
| (free) | — | leave headroom unallocated |

Principles: media on its **own LV** (isolation + independent growth/snapshots); don't allocate the whole
VG up front (`lvextend` as volumes fill); the **backup drive is a separate disk** (a system-disk
reinstall never touches it).

## Software stack

- **PostgreSQL 17** — application database, **bound to localhost**. (Greenfield app → Postgres, not the
  legacy MySQL; chosen partly to learn Postgres.)
- **PHP 8.4** — `fpm` + `cli`, extensions: `pdo_pgsql`, `mbstring`, `xml`, `gd`, `curl`, `bcmath`,
  `intl`, `zip`, `exif`, `opcache`; plus the `zip` and `find` (findutils) binaries the app shells out to.
- **nginx** — web server, FastCGI to php-fpm.
- **Samba (SMB3)** — LAN file access to the media library (no guests, `valid users` only).
- **Composer** + **Laravel 13** (the app).
- **Operational:** `chrony` (time sync), `unattended-upgrades`, `fail2ban`, `nftables`, `auditd`,
  AppArmor, `avahi-daemon` (mDNS/`*.local`), `exfatprogs` (exFAT backup drive), `rsync`, `git`, `curl`,
  `unzip`, `certbot` (+ `python3-certbot-nginx`).

## Network & exposure model

debbie is **intentionally internet-facing** so the owner can share music links with family and friends.
A DynDNS name → the Fritzbox router → forwards **only 80 + 443** to the host (80 redirects to HTTPS and
serves the ACME challenge). Because it's exposed, hardening is **not optional** (HTTPS, real per-user
auth, fail2ban, rate limiting), and **auth must be in force before any port is opened** (see
[phase-2-go-live.md](phase-2-go-live.md)).

- A LAN with a router that can **port-forward TCP 80 + 443** to the host.
- The host needs a **stable LAN address** (static IP, or a DHCP reservation by MAC).
- **DynDNS** name for the dynamic home IP; ideally a **real domain** as a `CNAME` to the DynDNS host
  (clean URLs + Let's Encrypt + the SPF/DKIM/DMARC TXT records mail needs).
- **Only 80/443 are ever exposed.** SSH, Samba, and the database stay LAN-only / localhost and must
  **never** be forwarded.

## Services & security posture

- **SSH:** key-only, `PermitRootLogin no`, password auth off, restricted via `AllowUsers`, LAN-only via
  the firewall. Root account locked (use `sudo`).
- **Firewall (nftables):** default-deny inbound; `80/443` public at go-live (LAN-only before that);
  SSH/Samba LAN-only; **DB localhost-only** (reach it via an SSH tunnel, never a firewall hole); mDNS on
  the LAN.
- **TLS:** Let's Encrypt (certbot, HTTP-01) at go-live; a self-signed cert validates the plumbing on the
  LAN beforehand.
- **App auth:** Fortify, **open registration disabled**, one-time **expiring invite tokens**, and
  **signed/temporary share-links** (play without an account); 2FA optional per user.
- **Hardening:** `unattended-upgrades` (security patches), `fail2ban` (SSH + web login), AppArmor,
  `auditd`, sysctl hardening.
- **Mail:** transactional mail via a **relay** (e.g. Mailtrap) — never from the residential/dynamic IP;
  deliverability via SPF/DKIM/DMARC on the real domain.

## Backups

- **Media is the priority** (the DB is *derived* from it — rebuilt from the media by the artisan scan
  chain): rotated, **checksummed** snapshots to the backup drive, plus ideally one off-box copy;
  **verify a restore periodically**.
- **Database:** no backup needed until there's user data (accounts, playlists, listen history) — then
  schedule `pg_dump` too.
- Prefer a scheduled job with **failure alerting** (so a silent backup failure doesn't go unnoticed).
