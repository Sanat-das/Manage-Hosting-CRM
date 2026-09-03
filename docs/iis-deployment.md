# IIS Deployment Guide

Covers a clean `git clone` → running application on IIS + FastCGI.
No Composer or npm required on the server — `vendor/` and `public/build/` are
committed and ship with the repository.

---

## Prerequisites (once per server)

### 1. Register PHP FastCGI at the server level

IIS handler mappings live in `applicationHost.config`, not in the
per-site `web.config`.  Register PHP once at the server level so that
every site on the box inherits it and `web.config` stays path-free.

**IIS Manager:**
Server node → Handler Mappings → Add Module Mapping

| Field            | Value                                        |
|------------------|----------------------------------------------|
| Request path     | `*.php`                                      |
| Module           | `FastCgiModule`                              |
| Executable       | `C:\path\to\php-cgi.exe`  *(your actual path)* |
| Name             | `PHP84` (or any label)                       |

Or via `appcmd` in an elevated prompt (adjust the path once):

```bat
%windir%\system32\inetsrv\appcmd.exe set config ^
  -section:system.webServer/fastCgi ^
  /+"[fullPath='C:\path\to\php-cgi.exe']"

%windir%\system32\inetsrv\appcmd.exe set config ^
  -section:system.webServer/handlers ^
  /+"[name='PHP84',path='*.php',verb='*',modules='FastCgiModule',scriptProcessor='C:\path\to\php-cgi.exe',resourceType='Unspecified',requireAccess='Script']"
```

Why at the server level: per-site `<handlers>` entries require the
path to be repeated in every `web.config` and updated on each server
that has PHP at a different location.  A server-level mapping is
inherited automatically.

### 2. IIS URL Rewrite module

Install **URL Rewrite 2.1** from the Microsoft IIS site if not already
present — the `web.config` rewrite rules that implement Laravel's front
controller require it.

### 3. Required PHP extensions

Verify the following are enabled in `php.ini`:

```ini
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=fileinfo
extension=zip
extension=curl
```

---

## Fresh deployment

```bat
git clone https://github.com/Sanat-das/Manage-Hosting-CRM.git C:\inetpub\vhosts\<site>\public_html
```

Point the IIS site root at `…\public_html\public`.

**Do not create `.env` manually** — the application bootstraps it
automatically on the first request (see [Auto-bootstrap](#auto-bootstrap) below).

Open the site in a browser; you will be redirected to `/install`.
Complete the installer form.  When it finishes:

- MySQL credentials are written to `.env`.
- All migrations run against MySQL.
- Session and cache are switched to the `database` driver.
- `install.lock` is created and the installer is locked out.

---

## Auto-bootstrap

`bootstrap/app.php` runs a small closure before Laravel starts:

1. If `.env` is missing and `.env.example` exists, copies `.env.example` → `.env`.
2. If `APP_KEY` in `.env` is empty, generates `base64:<32 random bytes>` and writes it.

This means a fresh clone boots to the installer without any manual steps.

**Why it must run before Laravel:** Laravel throws before any route is
reached if `APP_KEY` is empty.  The closure executes at the PHP level,
before the service container is built.

---

## Pre-install boot behaviour

`.env.example` defaults to:

```ini
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
SESSION_DRIVER=file
CACHE_STORE=file
```

Why `:memory:`: on Windows, a missing `.sqlite` file raises an
immediate error; attempting to connect to a firewalled MySQL or Redis
port (3306 / 6379) takes 7–11 seconds to time out per connection, which
blows IIS FastCGI's and Apache mod_fcgid's request timeouts before the
installer page renders.  An in-memory SQLite connection fails in < 1 ms
with a clear "no such table" message that `ModuleManager` catches and
logs as a warning.

`SESSION_DRIVER=file` and `CACHE_STORE=file` ensure sessions and cache
work from the first request without a database.

---

## IIS FastCGI stderr

PHP's `error_log()` writes to stderr.  IIS FastCGI merges PHP stderr
into the HTTP response body before any HTML output, printing raw error
text in the browser.

All module boot and route-registration failures use `Log::warning()`
instead of `error_log()`.  Laravel's file log driver writes to
`storage/logs/laravel.log` — never to stderr.

---

## Redeployment

```bat
cd C:\inetpub\vhosts\<site>\public_html
git pull
```

No additional steps.  `vendor/` and `public/build/` are in the
repository.  If the installer has already run (`install.lock` exists),
the app continues serving normally.

To reinstall from scratch:

```bat
del install.lock
del .env
# reset the database manually, then visit /install
```

---

## Verification checklist

- `GET /` redirects to `/install` (no `.env` → installer) or to the
  login page (installed).
- No raw text before `<!DOCTYPE html>` in the response — confirms
  `error_log()` is not leaking into the HTTP body.
- `storage/logs/laravel.log` contains module boot warnings (if any)
  rather than the browser showing them.
- After installation: `sessions`, `users`, `modules`, `migrations`
  tables exist in the configured MySQL database.
