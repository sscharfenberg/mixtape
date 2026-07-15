# Artisan commands

Project-specific `artisan` commands for MixTape (namespaced `app:*`). Framework
and package commands (`migrate`, `queue:work`, …) are not listed here — run
`php artisan list` for the full set.

> Run everything from the app root. On the dev box that is
> `/var/www/mixtape.dev` on **debbie**; locally it is your working copy.

## Index

| Command | Summary |
| --- | --- |
| [`app:invite`](#appinvite) | Mint a one-time, expiring registration invite link |

---

## `app:invite`

Mints a **one-time, expiring registration invite** and prints a link to share.

```
php artisan app:invite {note?} {--days=7}
```

### Why it exists

MixTape onboarding is **invite-only**: open self-service registration is disabled
by design. The `registration` feature is enabled in `config/fortify.php`, but
every registration path demands a valid invite — there is no way to create an
account without one. This is the headline access model: the owner shares music
with family and friends, and each new account must be deliberately granted.
`app:invite` is how the owner grants one — it creates the invite row and hands
back the URL to send to the person being onboarded.

### What it does

1. Generates a high-entropy, URL-safe code (`Str::random(40)`).
2. Stores **only the SHA-256 hash** of that code in the `invites` table
   (`token`), together with an optional `note` and a `valid_until` timestamp set
   to `now + --days`.
3. Prints the registration link: `…/register?code=<plaintext>`.

The recipient opens the link, which reaches the registration page **only if the
code is still valid** (unknown / expired / already-used codes bounce to the login
page with an error toast). They choose a username + e-mail + password, and on
success the invite row is **deleted** — the link can never be used again.

### Arguments & options

| Name | Kind | Default | Meaning |
| --- | --- | --- | --- |
| `note` | argument (optional) | *prompted* | Free-text reminder of who the invite is for. The invite is **not** tied to a specific user, so this note is the only human hint of the intended recipient. If omitted on the command line, the command **asks** for it interactively; press enter to leave it blank. |
| `--days` | option | `7` | How many days the invite stays valid. Must be ≥ 1. |

### Examples

```bash
# Interactive: prompts for the note, valid for 7 days
php artisan app:invite

# Note supplied inline, custom expiry
php artisan app:invite "Oma" --days=14
```

Example output:

```
 Invite minted — valid for 14 days.
   Note: Oma

 Registration link (copy & share — shown only once):
   https://example.tld/register?code=Xq3…40chars…
```

> The absolute URL is built from `APP_URL`, so make sure that is set correctly on
> the box you run the command from (otherwise the printed host is wrong).

### Security model & notes

- **The plaintext code is never stored.** Only its SHA-256 hash lives in the DB,
  so a database dump reveals no usable invites. SHA-256 (not bcrypt) is the right
  choice because the code is already high-entropy random, and a plain digest can
  be looked up by an indexed equality query.
- **Single-use.** The invite row is deleted the moment it is redeemed
  (`App\Actions\Fortify\CreateNewUser`), inside the same DB transaction (with a
  row lock) that creates the user — so two people racing the same link cannot
  both register, and a leaked link is good for at most one account.
- **Shown once.** Because only the hash is stored, a lost link cannot be
  recovered — mint a new invite instead. (This mirrors how password-reset tokens
  behave.)
- **Expiry is enforced on use**, not by a background job. Expired-but-unredeemed
  rows simply stop working; prune them later if desired.

### Related code

- `app/Console/Commands/CreateInvite.php` — the command.
- `app/Models/Invite.php` — the model (`hashCode()`, casts).
- `database/migrations/*_create_invites_table.php` — the schema.
- `app/Rules/ValidInvite.php` + `app/Actions/Fortify/CreateNewUser.php` — the
  redemption path (validate → lock → create user → delete invite).
- `routes/web.auth.php`, `app/Http/Controllers/Auth/AuthController.php` — the
  `GET` / `POST /register` wiring.
- `resources/app/pages/Auth/RegisterPage.vue` — the registration form.
