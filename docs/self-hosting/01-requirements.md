# 1 — Requirements & design

> What a host needs to run MixTape, and **why** those choices. Read this before buying hardware or
> wiping a disk; the rest of the guide assumes these decisions.

## Role — what runs where

A single always-on machine hosts everything:

| Concern | Location | Notes |
| --- | --- | --- |
| Web app | `/var/www/mixtape.{dev,prod}` | Laravel + Inertia; two isolated sites (own DB / fpm pool / vhost / logs) |
| Database | `/var/lib/postgresql` | PostgreSQL, **localhost-only** |
| Media library | `/var/media/{music,audiobooks}` | the mp3 / audiobook collection |
| File share | exports `/var/media` | Samba SMB3, no guests, LAN-only |
| Web server | nginx + php-fpm | HTTPS (self-signed on the LAN; Let's Encrypt once public) |

## Hardware

| Resource | Minimum | Notes |
| --- | --- | --- |
| CPU | x86-64, 2 cores | transcoding is not performed; this is mostly I/O |
| RAM | 4 GB | 8 GB+ comfortable (php-fpm `memory_limit` 256M; backups are I/O-bound) |
| System disk | SSD | OS + DB + app + media; size the media volume to your collection plus growth |
| Backup disk | second drive ≥ collection size | must be a **separate physical disk**, so reinstalling the system disk leaves backups untouched |
| Availability | always-on | it is a server people expect to answer |

A reference build used an 8-core / 32 GB box with a ~930 GB NVMe holding a ~96 GB collection, and a
2 TB external USB drive for backups. Considerably less will do.

## Operating system

- Latest stable **Debian**, **minimal** install, no desktop. (Built and verified on Debian 13
  "Trixie". Anything systemd-based works with small adjustments.)
- **Plain LVM, no full-disk encryption.** This is a deliberate trade: the data is a music collection,
  not secrets, and an unencrypted box **reboots unattended** after a power cut. On an always-on server
  that family depends on, "comes back by itself" beats at-rest encryption. LVM stays for the resize
  flexibility; only LUKS is dropped. If your collection *is* sensitive, reverse this and accept that
  every reboot needs a passphrase at the console.
- **Timezone UTC.** The database and app store UTC; the browser renders the visitor's local time.
  Setting a local timezone on the server buys nothing and makes log correlation worse.

## Storage layout (LVM)

Separate logical volumes, so a runaway log or a growing database cannot fill the media volume:

| Volume | Mount | Guidance |
| --- | --- | --- |
| root | `/` | ~50 GB (OS + packages) |
| var | `/var` | ~80 GB (app + database + logs) |
| media | `/var/media` | your collection + growth |
| home | `/home` | small — no media here |
| swap | — | modest; no hibernation |
| (unallocated) | — | **leave headroom** |

Three principles, in order of how much they matter:

1. **Media on its own LV** — isolation, and it can grow or be snapshotted independently.
2. **Do not allocate the whole volume group up front.** Unallocated space is what makes `lvextend`
   possible later. Filling the VG at install time is the mistake you cannot undo without downtime.
3. **The backup drive is a separate disk.** A system-disk reinstall must never be able to touch it.

## Software stack

- **PostgreSQL** (17+) — application database, **bound to localhost**.
- **PHP 8.4** — `fpm` + `cli`, with extensions `pdo_pgsql`, `mbstring`, `xml`, `gd`, `curl`, `bcmath`,
  `intl`, `zip`, `exif`, `opcache`. The app also shells out to the `zip` and `find` (findutils)
  binaries.
- **nginx** — web server, FastCGI to php-fpm.
- **Samba (SMB3)** — LAN access to the media library for adding files (no guests, `valid users` only).
- **Composer**, **Node** (version pinned by `.nvmrc` in the repo), and the app itself.
- **Operational:** `chrony` (time sync), `unattended-upgrades`, `fail2ban`, `nftables`, `auditd`,
  AppArmor, `avahi-daemon` (mDNS, so the box answers on `<hostname>.local`), `rsync`, `git`, `curl`,
  `unzip`, `certbot` + `python3-certbot-nginx`, and `exfatprogs` if your backup drive is exFAT.

## Network & exposure model

MixTape is **intentionally internet-facing** — that is the point, since sharing links with people is
the headline feature. That makes hardening non-optional.

- A LAN whose router can **port-forward TCP 80 and 443** to the host.
- A **stable LAN address** for the host: a static IP, or a DHCP reservation by MAC.
- A **DynDNS** name, if your home IP is dynamic (it almost certainly is).
- Ideally a **real domain** pointed at the DynDNS name by CNAME. You need one for clean URLs, for a
  Let's Encrypt certificate, and — the part people forget — because transactional mail needs
  SPF/DKIM/DMARC **TXT** records, which most DynDNS providers cannot host.
- **Only 80 and 443 are ever exposed.** SSH, Samba, and the database stay LAN-only or localhost and
  must never be forwarded. Port 80 exists only to answer the ACME challenge and redirect to HTTPS.

> **The hard rule:** do not forward any port until real authentication is in force. Invite-only
> registration and signed share-links must both work on the LAN first. See
> [`04-going-public.md`](04-going-public.md).

## Security posture

- **SSH:** key-only, `PermitRootLogin no`, password auth off, restricted with `AllowUsers`, and
  LAN-only at the firewall. Lock the root account and use `sudo`.
- **Firewall (nftables):** default-deny inbound. 80/443 public once you go live (LAN-only before
  that); SSH and Samba LAN-only; **database localhost-only** — reach it through an SSH tunnel, never
  a firewall hole.
- **TLS:** Let's Encrypt via certbot once you have a domain. Before that, a self-signed certificate
  on the LAN, which validates the entire nginx → php-fpm → app path without needing DNS.
- **App auth:** open registration disabled, one-time expiring invite tokens, optional per-user 2FA,
  and signed temporary URLs for account-free sharing.
- **Hardening:** `unattended-upgrades` for security patches, `fail2ban` on SSH and the web login,
  AppArmor, `auditd`, and sysctl hardening.
- **Mail:** transactional mail goes through a **relay**, never directly from a residential IP — those
  are blocklisted and have no PTR record, so mail sent from them is discarded, usually silently.

## Backups

- **The media is the priority.** The database is *derived* — the scan chain rebuilds it from the files
  in minutes — so the collection is the only thing whose loss is permanent. Use rotated, checksummed
  snapshots to the backup drive, ideally with one copy off-box, and **verify a restore periodically**.
  A backup you have never restored is a hypothesis.
- **The database** needs no backup until there is user data (accounts, playlists, listen history).
  Once there is, schedule `pg_dump` too.
- **Alert on failure.** A backup job that silently stops running is the common failure, not one that
  errors loudly. See [`04-going-public.md`](04-going-public.md#step-8--backup-alerting).
