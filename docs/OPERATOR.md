# Rates Publisher — Operator Guide

**Audience:** the person who installs, configures and looks after the tool.
**Version:** 1.1 (30 September 2026)

Related documents: [Technical documentation](TECHNICAL.md) · [User guide](USER_GUIDE.md)

---

## Contents

1. [What you are running](#1-what-you-are-running)
2. [Installation on XAMPP (Windows)](#2-installation-on-xampp-windows)
3. [Installation on a Linux server (optional)](#3-installation-on-a-linux-server-optional)
4. [Configuration](#4-configuration)
5. [PHP settings](#5-php-settings)
6. [Access and security](#6-access-and-security)
7. [Routine tasks](#7-routine-tasks)
8. [Updating the tool](#8-updating-the-tool)
9. [Backup](#9-backup)
10. [Troubleshooting](#10-troubleshooting)
11. [Health check](#11-health-check)

---

## 1. What you are running

- A small PHP web page. No database, no background jobs, no scheduled tasks.
- Uploaded Excel files are **not saved**. They are read once and discarded by PHP.
- The last result (the ~15 currency rows and the edited values) is kept in the user's PHP session on the server only (until Clear, a new upload, or the session expires).
- Nothing needs cleaning up, rotating or backing up apart from the program files themselves.

---

## 2. Installation on XAMPP (Windows)

1. **Install XAMPP** (PHP 8.1 or newer) if it is not already installed. Default path: `C:\xampp`.
2. **Copy the program.** Unzip `rates-publisher.zip` so you have:
   ```
   C:\xampp\htdocs\rates-publisher\index.php
   C:\xampp\htdocs\rates-publisher\includes\...
   C:\xampp\htdocs\rates-publisher\assets\...
   ```
   Any folder name works; `rates-publisher` is used in these guides. Copy the **hidden `.htaccess` files** too (in the main folder, `includes`, `docs` and `tests`): they block web access to everything except the page itself. XAMPP allows `.htaccess` files by default.
3. **Check the zip extension.** Open `C:\xampp\php\php.ini` and search for `extension=zip`.
   - If the line starts with `;` (`;extension=zip`), remove the `;` and save.
4. **Start Apache** in the XAMPP Control Panel (or restart it if it was running and you changed `php.ini`).
5. **Open** <http://localhost/rates-publisher/> in the browser.
6. **Test** by uploading a recent rates file. The values should match the file. See [§11 Health check](#11-health-check).

Tip: bookmark the page, or create a desktop shortcut to the URL for the user.

---

## 3. Installation on a Linux server (optional)

Only needed if the tool should be reachable from other machines. Example for Debian/Ubuntu with Apache:

```bash
sudo apt install apache2 php libapache2-mod-php php-zip php-xml
sudo cp -r rates-publisher /var/www/html/
sudo chown -R root:www-data /var/www/html/rates-publisher
sudo find /var/www/html/rates-publisher -type d -exec chmod 755 {} \;
sudo find /var/www/html/rates-publisher -type f -exec chmod 644 {} \;
```

For the `.htaccess` files to work, the folder must allow overrides (otherwise Apache silently ignores them, and `includes/`, `docs/` and `tests/` can be opened in a browser). In the site config (e.g. `/etc/apache2/sites-available/000-default.conf`):

```apache
<Directory /var/www/html/rates-publisher>
    AllowOverride All
</Directory>
```

Then `sudo systemctl reload apache2`.

The web server needs **read** access only. The tool never writes files (PHP itself writes session files to its own temp folder).

The root `.htaccess` sets `php_flag display_errors off`, which only applies with `mod_php` (as in XAMPP and `libapache2-mod-php`). With PHP-FPM, set `display_errors = Off` in php.ini instead.

Behind a reverse proxy (e.g. Nginx Proxy Manager) use HTTPS so the copy buttons can use the modern clipboard API. **Do not expose the tool to the internet without access control** (see §6).

### Deployment on devbox (Docker)

The repository deploys itself to the devbox server on every push to `main`:

| File | Role |
|---|---|
| `Dockerfile` | `php:8.4-apache` + the `zip` extension (no Composer needed). A `test` stage runs `php tests/run.php`; if a test fails the build stops and the running version stays up. The final image contains only `index.php`, `.htaccess`, `includes/` and `assets/`. |
| `docker/php.ini`, `docker/apache.conf` | Upload limits (6 MB / 8 MB), errors to the container log, `.htaccess` enabled, Apache version hidden. |
| `compose.yml` | Service `rates` on port **8087** → <http://192.168.0.20:8087>, restart unless stopped, health check. |
| `.github/workflows/deploy.yml` | On push to `main`, the devbox runner runs `docker compose -p rates up -d --build`, then checks the page responds. |

Watch a deploy in the repository's **Actions** tab. Logs: `docker compose -p rates logs -f` on devbox. Sessions live inside the container, so a redeploy clears any result a user had open (they upload the file again).

The site is reachable by anyone on the network who can reach devbox and has **no login** (see §6). Over plain `http://192.168.0.20` browsers do not allow the modern clipboard API, so the copy buttons use the fallback method; if copying fails, select and press Ctrl+C.

---

## 4. Configuration

All settings are in **`includes/config.php`**. Open it in any text editor (Notepad++, VS Code). Save, then reload the page; no restart is needed.

| Setting | Default | What it does |
|---|---|---|
| `site_name` | `Rates Publisher` | Name in the page header, footer and browser tab. |
| `site_tagline` | … | Page description (meta tag). |
| `currencies` | USD … RUB (15) | Rows of the Alert table **in display order**. Format `'CODE' => decimals`. JPY uses 2 decimals, the rest 4. |
| `missing_value` | `N/A` | Text shown when a rate is missing, empty, zero or not a number. |
| `fallback_column_order` | spot_sell, spot_buy, note_sell, note_buy | Only used if the file has no BUY/SELL headings. Order of the number columns to the right of the currency code. |
| `value_date_source` | `next_business_day` | `next_business_day` = rate date + N working days (matches what was published on Alert before). `file` = the "Spot Value date" cell in the Excel file (normally 2 working days later). |
| `value_date_offset_days` | `1` | N for `next_business_day`. Working days = Monday–Friday. |
| `max_upload_bytes` | 5 MB | Largest file accepted. The daily file is about 60 KB. |
| `colors` | Alert colours | Colours of the generated table. Change only if the Alert page design changes. |

### Common changes

**Add a currency** (e.g. South African Rand after NOK):

```php
'NOK' => 4,
'ZAR' => 4,   // new
'CNY' => 4,
```

The code must exist in the RATES sheet of the Excel file, otherwise the row shows N/A and a warning is displayed.

**Remove a currency:** delete its line.

**Use the file's value date:**

```php
'value_date_source' => 'file',
```

**Syntax rules:** every line inside `[ … ]` ends with a comma; text is in single quotes. If the page shows a PHP "Parse error" after editing, check for a missing comma or quote on the line mentioned.

---

## 5. PHP settings

Defaults in XAMPP are fine. Check these in `php.ini` only if uploads fail:

| Setting | Needed value | Why |
|---|---|---|
| `extension=zip` | enabled (no `;`) | Reading .xlsx files |
| `file_uploads` | `On` | Uploading at all |
| `upload_max_filesize` | ≥ `5M` | Must be at least `max_upload_bytes` |
| `post_max_size` | ≥ `8M` | Must be larger than `upload_max_filesize` |
| `session.gc_maxlifetime` | default `1440` (24 min) | How long an idle result is kept. Increase if users leave the page open a long time before copying. |

Restart Apache after changing `php.ini`.

---

## 6. Access and security

The tool has **no login**. Anyone who can open the URL can use it. It does not store or send data anywhere, but it should still only be reachable by the people who publish the rates.

- **Recommended:** run it on the publisher's own PC via XAMPP and open it only at `http://localhost/...`. XAMPP's Apache listens on all network interfaces by default, so also block port 80 in Windows Firewall for other machines, or limit access as below.
- **Allow only this PC:** add to the end of `rates-publisher/.htaccess`:
  ```apache
  Require local
  ```
- **Allow a few machines:**
  ```apache
  Require ip 127.0.0.1 192.168.1.20 192.168.1.21
  ```
- **Password protect** (Apache basic auth): create a password file with `htpasswd`, then add to `rates-publisher/.htaccess`:
  ```apache
  AuthType Basic
  AuthName "Rates Publisher"
  AuthUserFile "C:/xampp/security/rates.htpasswd"
  Require valid-user
  ```
- Keep all `.htaccess` files in place. They stop the config, class files, docs and tests being opened directly in a browser, hide dotfiles and turn off directory listings.

Built-in protections (no action needed): CSRF tokens on all forms, output escaping, a Content-Security-Policy and anti-framing headers, hardened session cookie, upload type and size checks, zip-bomb and file-size limits, uploaded files never stored, PHP error details never shown on the page. See [Technical documentation §10](TECHNICAL.md#10-security).

---

## 7. Routine tasks

| When | Task |
|---|---|
| Daily | Nothing for the operator. The user follows the [User guide](USER_GUIDE.md). |
| When the bank adds/drops a currency on the Alert page | Edit `currencies` in `config.php`. |
| When the Alert table design changes | Update `colors` in `config.php`, or the structure in `includes/TableRenderer.php` (developer). |
| When the bank changes the Excel layout | Upload a new file and check the values. Detection is automatic in most cases. If a column is wrong, the user can fix it in *Excel columns*; if it keeps happening, see [Technical documentation §12](TECHNICAL.md#12-extending-the-tool). |
| After PHP / XAMPP upgrades | Run the [health check](#11-health-check). |

---

## 8. Updating the tool

1. Copy your current `includes/config.php` somewhere safe.
2. Replace the `rates-publisher` folder with the new version.
3. Put your `config.php` back, or re-apply your changes to the new one if the new version added settings. Make sure the hidden `.htaccess` files were copied too.
4. Reload the page and run the [health check](#11-health-check).

No database migrations, caches or build steps are involved.

---

## 9. Backup

Only the program folder needs backing up, and really only `includes/config.php` if you changed it. The tool keeps no data of its own. The source Excel files and the Alert page are the records of what was published.

---

## 10. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| "PHP zip extension is not enabled" | `;extension=zip` in php.ini | Remove the `;`, restart Apache. |
| "Old .xls format is not supported" | File saved as Excel 97–2003 | Open in Excel → Save As → *Excel Workbook (.xlsx)*. |
| "The file is not a valid .xlsx workbook" | File is damaged, password-protected, or not really Excel | Re-download the file; open it in Excel to check; save a copy without a password. |
| "No currency codes … were found" | Wrong file, or codes missing from the sheet | Check the RATES sheet has codes (USD, GBP…) in their own cells. |
| "The file is too large." | File larger than `max_upload_bytes`, `upload_max_filesize` or `post_max_size` | The daily file is ~60 KB, so check it is the right file. Otherwise increase the limits (see §5). |
| "The workbook is too large to read" | A part of the .xlsx unzips to more than 20 MB | Not the daily rates file, or a damaged one. Re-download it. |
| "The server could not store the upload (error code 6)" or "(7)" | PHP temp folder missing or not writable | XAMPP: check `upload_tmp_dir` in php.ini points to an existing folder (e.g. `C:\xampp\tmp`). |
| "Something went wrong reading the file" | Unexpected error while reading | Details are in the PHP error log (XAMPP: `C:\xampp\php\logs\php_error_log` or `C:\xampp\apache\logs\error.log`). Check the file opens in Excel. |
| "Nothing to update – your session expired" | Update pressed after the session timed out | Upload the file again. |
| "Your session expired. Please try again." | Page left open very long, or cookies blocked | Reload the page and upload again; allow cookies for localhost. |
| After pressing Update or Copy, the page is back at step 1 | Session expired (idle > `session.gc_maxlifetime`) | Upload the file again; increase the lifetime if it happens often. |
| Values in the wrong columns | Bank changed headings | Open *Excel columns* on the page and pick the right columns. |
| A currency shows N/A everywhere | Code not in the file, or misspelt in `config.php` | Compare the code in config with the file. |
| Value date wrong around weekends/holidays | Holidays are not known to the tool | Correct the date on the page before copying. |
| "Copy failed – select and press Ctrl+C" | Browser blocked clipboard access | Select the preview (or the HTML box) with the mouse and press Ctrl+C. Using `http://localhost` or HTTPS avoids this. |
| Page shows "Parse error … config.php on line N" | Typo in config.php | Fix the line (missing comma/quote), or restore the original file. |
| Page looks unstyled | `assets` folder missing or wrong path | Make sure the whole folder structure was copied. |
| Blank white page or "500 Internal Server Error" | PHP fatal error (errors are never shown on the page), or an `.htaccess` directive not allowed by `AllowOverride` | Check `C:\xampp\apache\logs\error.log` (or `php_error_log`). For `.htaccess` errors, set `AllowOverride All` for the folder (§3). |

---

## 11. Health check

Use after installation, upgrades or config changes (about 2 minutes):

1. Open the page. Header, three-step layout and styling appear.
2. Upload a recent rates file. A green "File read successfully" message appears and there are **no yellow warnings**.
3. *Excel columns* says "detected from headings".
4. Spot-check USD, JPY and one N/A currency against the Excel file. JPY has 2 decimals, the others 4.
5. Date matches the file; value date is as expected for `value_date_source`.
6. Press **Copy HTML** → "Copied ✓" appears.
7. Press **Clear** → back to step 1.
8. Open `http://localhost/rates-publisher/includes/config.php` → **403 Forbidden** (the `.htaccess` files are working).
9. Developers: `php tests/run.php` from the program folder → "0 failed".
