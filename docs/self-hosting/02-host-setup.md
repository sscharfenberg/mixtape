# 2 — Host setup

> From a blank machine to a hardened Debian host with the full stack installed and TLS working on the
> LAN. Nothing here is reachable from the internet; that comes in
> [`04-going-public.md`](04-going-public.md).

Substitute your own values for `<lan-subnet>` (e.g. `192.168.1.0/24`), `<server-lan-ip>`, and
`<admin-user>` throughout.

## 2.1 Install Debian

Minimal install, no desktop. At the partitioning step choose **manual / LVM** and create the volumes
from [`01-requirements.md`](01-requirements.md#storage-layout-lvm).

> ⚠️ **If you are reinstalling over an existing collection, back it up first and verify the backup can
> be read** — not just that the copy finished. Repartitioning is not reversible, and "the tar file
> exists" is not the same as "the tar file restores".

Two choices that matter later:

- **Leave part of the volume group unallocated.** You cannot `lvextend` into space you already gave
  away, and resizing downward is a restore-from-backup operation.
- **Set the timezone to UTC**, not your local zone.

Set a root password at install time only if you want one; the recommended posture is to leave it blank
so the root account is locked, and administer through `sudo`.

## 2.2 Networking — prefer a cable

Give the host a **stable LAN address**: either a static configuration, or a DHCP reservation on your
router keyed to its MAC.

**Use wired Ethernet if you possibly can.** A USB WiFi dongle under an always-on, internet-facing
service is a recurring source of subtle failures — dropped frames under load, driver resets, and races
at boot where the network is not up when services start. Two specific traps, if WiFi is unavoidable:

- **Boot ordering.** `systemd-networkd` plus `wpa_supplicant@<iface>.service` is far more reliable
  than the legacy `ifupdown` path. Check `resolv.conf` actually gets nameservers — an empty one
  produces failures that look like everything except DNS.
- **Silent DSCP drops.** Some WiFi paths discard DSCP/WMM-marked frames. OpenSSH marks interactive
  traffic DSCP-EF by default, so SSH hangs at "banner exchange" while HTTP and file sharing work
  perfectly — a genuinely confusing signature. The fix is `IPQoS cs0 cs0` in `sshd_config`. If SSH is
  broken over WiFi but loopback SSH works, this is very likely why.

Install `avahi-daemon` so the box answers to `<hostname>.local` on the LAN — much nicer than
memorising an IP while you are still building.

## 2.3 Base packages and time

```bash
sudo apt update && sudo apt full-upgrade
sudo apt install -y chrony unattended-upgrades nftables fail2ban auditd apparmor-utils \
                    rsync git curl unzip avahi-daemon
```

`chrony` matters more than it looks: signed URLs, TLS certificates, and TOTP two-factor codes are all
time-sensitive, and a clock that has drifted produces "expired" errors on things that are not expired.

Enable automatic security patching:

```bash
sudo dpkg-reconfigure -plow unattended-upgrades
```

## 2.4 SSH hardening

Copy your public key up **before** disabling password authentication, and keep an existing session
open while you test the new one — locking yourself out of a headless box means a trip to a physical
console.

```bash
ssh-copy-id <admin-user>@<server-lan-ip>
```

In `/etc/ssh/sshd_config`:

```
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
AllowUsers <admin-user>
IPQoS cs0 cs0
```

```bash
sudo sshd -t && sudo systemctl reload ssh
```

Verify from a **second** terminal before closing the first.

## 2.5 Firewall (nftables)

Default-deny inbound, with the web ports **LAN-only for now** — they open to the world in
[`04-going-public.md`](04-going-public.md#step-3--widen-the-firewall), after authentication works.

`/etc/nftables.conf`:

```nft
#!/usr/sbin/nft -f
flush ruleset

table inet filter {
    chain input {
        type filter hook input priority 0; policy drop;

        ct state established,related accept
        iif lo accept
        ip protocol icmp accept
        ip6 nexthdr ipv6-icmp accept

        # Web — LAN-only until go-live.
        ip saddr <lan-subnet> tcp dport { 80, 443 } accept

        # SSH and Samba stay LAN-only forever.
        ip saddr <lan-subnet> tcp dport 22 accept
        ip saddr <lan-subnet> tcp dport { 139, 445 } accept

        # mDNS, so <hostname>.local resolves.
        ip saddr <lan-subnet> udp dport 5353 accept
    }
    chain forward { type filter hook forward priority 0; policy drop; }
    chain output  { type filter hook output  priority 0; policy accept; }
}
```

**Always syntax-check before loading** — a bad ruleset applied over SSH can drop your own session:

```bash
sudo nft -c -f /etc/nftables.conf && sudo nft -f /etc/nftables.conf
sudo systemctl enable --now nftables
```

Note that PostgreSQL is absent from this list on purpose. It listens on localhost only; you reach it
from a laptop through an SSH tunnel, never a firewall hole:

```bash
ssh -f -N -L 5432:127.0.0.1:5432 <admin-user>@<server-lan-ip>
```

## 2.6 PostgreSQL

```bash
sudo apt install -y postgresql
```

Confirm it is bound to localhost (`listen_addresses = 'localhost'` in `postgresql.conf`, which is the
Debian default).

Per-site roles and databases are created in [`03-production-deploy.md`](03-production-deploy.md#3-database).
When you do, **verify the role can log in over TCP**, not just that it exists — the app connects to
`127.0.0.1`, and a `pg_hba.conf` that only permits `peer` authentication will appear fine until the
first migration fails:

```bash
psql -h 127.0.0.1 -U <role> -d <database> -c 'SELECT current_user, current_database()'
```

## 2.7 PHP and nginx

```bash
sudo apt install -y nginx php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
                    php8.4-gd php8.4-curl php8.4-bcmath php8.4-intl php8.4-zip php8.4-opcache \
                    zip findutils
```

If your Debian release does not carry PHP 8.4, add the `sury` repository rather than downgrading the
app.

Install Composer following the official instructions, and verify the installer checksum — it is the
one step here where blindly piping a download into a shell is genuinely risky.

Node is **not** installed system-wide. It is installed per-user with `nvm`, as part of the deploy
setup in [`03-production-deploy.md`](03-production-deploy.md#2-nvm--node), so that the version is
pinned by the repo's `.nvmrc` rather than by whatever Debian ships.

## 2.8 Media library and Samba

```bash
sudo install -d -o <admin-user> -g <admin-user> /var/media/music /var/media/audiobooks
sudo apt install -y samba
```

In `/etc/samba/smb.conf`, export `/var/media` with **no guest access**, `valid users` naming your
account, and SMB3 enforced:

```ini
[global]
   server min protocol = SMB3
   map to guest = never

[media]
   path = /var/media
   valid users = <admin-user>
   read only = no
   browseable = yes
```

```bash
sudo smbpasswd -a <admin-user>
sudo systemctl restart smbd
```

Samba is LAN-only at the firewall and must **never** be forwarded. It exists so you can drop files
into the collection from a desktop machine.

## 2.9 TLS on the LAN (self-signed)

Do this before you have a domain. It proves the entire nginx → php-fpm → app → database path works
under HTTPS, so that when you later swap in a real certificate, the only new variable is the
certificate itself.

```bash
sudo openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
  -keyout /etc/ssl/private/mixtape-selfsigned.key \
  -out    /etc/ssl/certs/mixtape-selfsigned.crt \
  -subj "/CN=<hostname>.local"
```

Reference it from your vhost, redirect HTTP to HTTPS, and enable HTTP/2. Browsers will warn about the
untrusted certificate; that is expected and fine on the LAN.

Keep the ACME challenge path open from the start, so nothing needs changing when certbot arrives:

```nginx
location /.well-known/acme-challenge/ { allow all; root /var/www/html; }
```

## 2.10 Backups

The media is the only thing whose loss is permanent. A workable scheme, driven by a systemd timer:

- Rotated `tar` snapshots to the separate backup drive, keeping the last few.
- **Change detection**, so an unchanged collection does not burn a full snapshot every run.
- **Verification on write** — read the archive back after creating it. An unverified backup is a
  guess.
- A weekly `systemd` timer rather than cron, so failures land in the journal with context.

The script and its units are [`files/mixtape-media-backup.sh`](files/mixtape-media-backup.sh),
[`files/mixtape-media-backup.service`](files/mixtape-media-backup.service) and
[`files/mixtape-media-backup.timer`](files/mixtape-media-backup.timer).

Failure *alerting* is covered in [`04-going-public.md`](04-going-public.md#step-8--backup-alerting).
Until that exists, a backup that quietly stops running looks exactly like one that is working.

## Next

[`03-production-deploy.md`](03-production-deploy.md) — getting the app onto this host.
