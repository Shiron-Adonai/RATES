# Rates Publisher — Technical Documentation

**Audience:** developers maintaining or extending the tool.
**Version:** 1.1 (30 September 2026)

Related documents: [Operator guide](OPERATOR.md) · [User guide](USER_GUIDE.md)

---

## Contents

1. [Purpose](#1-purpose)
2. [Architecture](#2-architecture)
3. [File structure](#3-file-structure)
4. [Requirements](#4-requirements)
5. [Request flow](#5-request-flow)
6. [Components](#6-components)
7. [Parsing rules](#7-parsing-rules)
8. [Output format](#8-output-format)
9. [Session state](#9-session-state)
10. [Security](#10-security)
11. [Error handling](#11-error-handling)
12. [Extending the tool](#12-extending-the-tool)
13. [Testing](#13-testing)
14. [Known limitations](#14-known-limitations)

---

## 1. Purpose

Every working day the bank issues an Excel file (`RTSddmmyy.xlsx`, e.g. `RTS300926.xlsx`) with its exchange rates. The rates are published on a page in **Alert Web Publisher** as an HTML table with 15 currencies × 4 values.

Before this tool, the 60 values and 2 dates were typed into Alert by hand. Rates Publisher reads the Excel file and produces the finished table HTML in exactly the same structure as the existing Alert table. The user reviews it and pastes it into Alert.

The tool does **not** connect to Alert. Publishing is still a manual copy and paste.

---

## 2. Architecture

A single-page PHP application with no database, no framework and no Composer dependencies.

```
 Browser                          index.php (controller) → includes/actions.php
 ───────                          ─────────────────────────────────────────────
 [Upload .xlsx] ── POST upload ─► SpreadsheetReader::read()      .xlsx/.csv → sheets, parsed lazily
                                   RateParser::pickSheet()        choose "RATES" + its currency rows
                                   RateParser::detectColumns()    field → column
                                   RateParser::extractRates()     60 formatted values
                                   RateParser::detectDates()      date, value date
                                   store in $_SESSION['job']
                 ◄── 303 redirect ─
 [GET page]      ───────────────► TableRenderer::render()        Alert HTML (includes/view.php)
                 ◄── HTML page ───
 [Edit + Update] ── POST update ─► save edits / re-read columns → 302
 [Copy buttons]   (assets/js/app.js, clipboard only — no request)
```

Design choices:

| Decision | Reason |
|---|---|
| Own `.xlsx` reader instead of PhpSpreadsheet | Runs on a plain XAMPP install with no Composer; the file is small and only values are needed. |
| Detect cells by content, not fixed addresses | The layout of the bank's file can shift (added rows, moved columns) without breaking the tool. |
| Session instead of database or files | One user, one file per day; nothing needs to be kept. The uploaded file is never saved. |
| Post/Redirect/Get | Refreshing the page never re-submits an upload or edit. |
| Inline styles in the output | Alert pages do not load this tool's CSS; inline styles survive the paste. |

---

## 3. File structure

```
rates-publisher/                (any folder name works)
├── index.php                    Page controller: bootstrap, POST dispatch, view data
├── .htaccess                    No listings; hides dotfiles and .md; display_errors off
├── README.md                    Short overview and links to these docs
├── includes/
│   ├── .htaccess                Blocks direct web access to this folder
│   ├── config.php               All settings (returns an array)
│   ├── helpers.php              e(), asset(), redirectTo(), isValidYmd(), upload messages, security headers
│   ├── actions.php              handleUpload(), handleUpdate(), buildReviewView()
│   ├── SpreadsheetReader.php    .xlsx / .csv → cell grids
│   ├── RateParser.php           Finds currencies, columns, dates; formats values
│   ├── TableRenderer.php        Builds the Alert table HTML
│   ├── header.php               <head>, site header, opens <main>
│   ├── view.php                 Page body: upload, review, copy
│   └── footer.php               Closes <main>, footer
├── assets/
│   ├── css/style.css            Styles for the tool's page (not the output table)
│   └── js/app.js                Dropzone, tabs, copy buttons
├── tests/
│   ├── .htaccess                Blocks web access
│   └── run.php                  Test runner: php tests/run.php
└── docs/                        (.htaccess blocks web access)
    ├── TECHNICAL.md             This document
    ├── OPERATOR.md              Install, configure, maintain, troubleshoot
    ├── USER_GUIDE.md            Daily use
    └── images/                  Screenshots
```

Classes and public methods have PHPDoc blocks; `style.css` has a table of contents at the top.

---

## 4. Requirements

| Item | Requirement |
|---|---|
| PHP | 8.1 or newer (uses the `never` return type, `match`, constructor property promotion, `str_contains`). Tested on 8.4. |
| PHP extensions | `zip` (ZipArchive) and `simplexml`. Both are on by default in XAMPP. `session` (always present). |
| Web server | Apache (XAMPP) or any server running PHP. `includes/.htaccess` is Apache-only. |
| Browser | Any current Chrome, Edge or Firefox. Copy buttons use the Clipboard API with a fallback. |
| Internet | Not required. Everything is served locally (system font, no CDN). |

---

## 5. Request flow

All requests go to `index.php`. POST requests carry a hidden `action` field and a `csrf` token.

| Request | Handler | Result |
|---|---|---|
| `GET index.php` | View section | Renders the page. If `$_SESSION['job']` exists, shows steps 2 and 3. |
| `POST action=upload` + `rates_file` | Upload block | Validates, parses, stores `job`, redirects with a success or error message. |
| `POST action=update` + `date`, `value_date`, `map[…]`, `rates[CODE][field]` | Update block | See below. Redirects. |
| `POST action=reset` | Reset block | Removes `job`, redirects. |
| POST larger than `post_max_size` | Before `session_start()` | PHP drops the whole body, so the request is redirected to `index.php?notice=too-large`, which shows "The file is too large." |

All POST handlers redirect with `303 See Other` (Post/Redirect/Get). A POST to `update` when the session has no job shows "Nothing to update – your session expired. Upload the file again."

**Update logic.** The column dropdowns (`map[field]`) are compared with the stored mapping:

- **Mapping changed:** all values are re-read from the stored sheet with the new mapping (`method` becomes `manual`). Hand edits are discarded.
- **Mapping unchanged:** the grid values are taken as typed and re-formatted (empty, non-numeric, zero or negative → `N/A`; numbers → configured decimals; decimal comma accepted).

Dates are saved in both cases if they are real calendar dates in `Y-m-d` form (`2026-02-31` is rejected). A mapping value must be a column between 0 and the last column of the currency rows; anything else counts as "not in file". Non-string (array) inputs are ignored.

---

## 6. Components

### 6.1 `SpreadsheetReader` (static)

| Method | Description |
|---|---|
| `read(string $path, string $originalName): iterable` | Entry point. Chooses the reader by the original file extension. Yields `sheetName => grid`; .xlsx sheets are parsed one at a time as the caller iterates (a generator), so later sheets are never parsed if the first one is used. A CSV is one sheet named `CSV`. Throws `RuntimeException` for `.xls`, unknown types, corrupt or oversized files, or a missing zip extension (for .xlsx, when iteration starts). |

**Grid format:** `array<int row, array<int col, string|float>>`, 0-based, empty cells omitted. Cell `D9` is `$grid[8][3]`. Cells beyond `MAX_ROWS` (5,000) or `MAX_COLS` (200), and cell references longer than three letters, are ignored.

**Value types (.xlsx):** text → `string` (trimmed); number → `float`; number with a date format → `'Y-m-d'` string (1900 and 1904 date systems); formula → the cached result Excel saved; error cells (`#N/A`) → their text. In .csv every value is a trimmed `string`.

**How it reads .xlsx:** opens the ZIP and rejects it if the total uncompressed size is over 50 MB or any part is over 20 MB (zip-bomb protection). Reads `xl/workbook.xml` + `xl/_rels/workbook.xml.rels` (sheet names → XML paths, locations of the shared strings and styles parts, 1904 flag), the shared strings (text table), the styles (which cell styles are dates), then each worksheet on demand. Both Transitional and Strict Open XML namespaces are accepted, and part names are matched case-insensitively. XML is parsed with SimpleXML and `LIBXML_NONET`; any part containing a `<!DOCTYPE` is rejected.

**How it reads .csv:** a UTF-8 BOM is skipped before parsing. The delimiter is detected from the first 10 non-empty lines: tab or `;` if present on at least half of them, otherwise `,`. Text that is not valid UTF-8 is converted from Windows-1252 (Excel's plain "CSV" export).

### 6.2 `RateParser`

Constructed with the config array.

| Method | Returns | Description |
|---|---|---|
| `pickSheet(iterable $sheets)` | `['name', 'rows', 'currency_rows']` | First sheet (workbook order) containing any configured currency; stops reading there. |
| `findCurrencyRows(array $rows)` | `code => ['row', 'col']` | Leftmost code in each row; first row for each code. |
| `detectColumns(array $rows, array $currencyRows)` | `['mapping' => field => col\|null, 'method']` | Headings first, position fallback. `$currencyRows` must not be empty. |
| `extractRates(array $rows, array $currencyRows, array $mapping)` | `code => field => string` | Formatted values in config order. |
| `formatRate(?float, int $decimals)` | `string` | Fixed decimals, or `missing_value` for null, zero or negative. Used by upload and update. |
| `detectDates(array $rows, ?int $beforeRow)` | `['date', 'value_date']` | `Y-m-d` or `null`. Searches rows above `$beforeRow` (the first currency row) first; the whole sheet only if none are found there. |
| `toNumber(mixed)` *(static)* | `?float` | Numeric parsing (decimal comma, `1,234.56`, `1.234,56`, space/NBSP/apostrophe thousands separators). `1,234` is read as 1.234 (decimal comma). Non-finite → `null`. |
| `toDate(mixed)` *(static)* | `?string` | Date parsing (`Y-m-d`, `d/m/Y`, `d.m.Y`, `d-m-Y`, 2-digit years). |
| `addBusinessDays(string, int)` *(static)* | `string` | Adds Mon–Fri days. No holiday calendar. |
| `columnLetter(int)` *(static)* | `string` | `6` → `G`. |

Constants: `FIELDS` = `spot_sell, spot_buy, note_sell, note_buy`; `FIELD_LABELS` for the UI.

### 6.3 `TableRenderer`

| Method | Description |
|---|---|
| `render(string $date, string $valueDate, array $rates): string` | Full `<table>…</table>` HTML, one tag per line. Values are HTML-escaped. |
| `formatDate(string $ymd): string` *(static)* | `Y-m-d` → `dd/mm/yyyy` (not escaped; `render()` escapes it). |

The constructor checks that every `colors` value is a hex colour (`#abc` or `#aabbcc`), because they are written into `style` attributes unescaped.

### 6.4 Templates and assets

| File | Role |
|---|---|
| `header.php` | Uses `$config` and `$pageTitle`; outputs up to `<main>`, loads `style.css` and `app.js` (`defer`) with `?v=<mtime>` cache busting. |
| `view.php` | The three steps (upload, review, copy). |
| `footer.php` | Closes `<main>`, outputs the footer. |
| `app.js` | Dropzone (drop = auto-submit; button disabled while uploading), accessible tabs (arrow keys), copy buttons with `execCommand` fallback, live N/A styling. |
| `style.css` | Tool page styles. Tokens in `:root`; the grid heading colour comes from `colors.header_bg`. |

---

## 7. Parsing rules

### 7.1 Where things are in the bank's file

Sheet **RATES** (used). Sheet **REF RATES** (reference rates) is ignored.

| Content | Cells | How it is found |
|---|---|---|
| Rate date | label `B4` "Date:", value `C4` | Cell with word "DATE" → date in next cell |
| Spot value date | label `I4` "Spot Value date:", value `J4` | Cell with "DATE" and "VALUE" → next cell |
| Section titles | `G6` "S  P  O  T", `I6` "B A N K   N O T E S" (merged G6:H6, I6:J6) | Spaces removed, then `SPOT` / `NOTE` |
| Column headings | `G7:J7` "BNK SELLS", "BNK BUYS", "BNK SELLS", "BNK BUYS" | Row above data with ≥2 cells containing SELL/BUY |
| Currency codes | `D9:D23` | Stand-alone 3-letter code from `currencies` |
| Values | `G9:J23` | Mapping above → `G`=spot sells, `H`=spot buys, `I`=notes sells, `J`=notes buys |
| Interest-rate tables | from row 27 | Ignored. Their titles are letter-spaced ("U S D"), so they never match a code. |

### 7.2 Currency detection

- Regex per cell: `(?<![A-Z])(USD|GBP|…)(?![A-Z])` on the upper-cased text. This matches `USD`, `USD - US Dollar`, `US Dollar (USD)`, but not `USDX` or `U S D`.
- Only the leftmost match in each row counts (even if that code was already found higher up).
- Only the first row for each code counts, so the first table in the sheet wins.

### 7.3 Column detection

1. **Headings (`method = headers`).** Scan up to 6 rows above the first currency row, nearest first. The first row with at least two cells (right of the code column) containing `SELL` or `BUY` is the heading row.
2. **Sections.** In the heading row and the two rows above it, find titles containing `NOTE`/`CASH` (→ note) or `SPOT`/`TRANSFER`/`T/T` (→ spot). Each value column takes the nearest title at or to its left, because merged cells keep their value in the leftmost cell. If no titles are found, the first two headed columns are spot and the rest are notes.
3. **Position fallback (`method = position`).** If headings give fewer than two fields, every column right of the code with a number in any currency row is collected, sorted and assigned in `fallback_column_order`.
4. **Manual (`method = manual`).** The user changed the dropdowns on the page.

### 7.4 Values

- `toNumber()` accepts floats and numeric text. Non-numeric text (`N/A`, `-`, `#N/A`) → missing.
- Missing, empty or `0` → `missing_value` (`N/A`).
- Otherwise `number_format($value, $decimals, '.', '')`: rounded half-up, fixed decimals, no thousands separator. The file stores full precision (e.g. `1.1178765`) and shows it rounded, so the result matches what Excel displays (`1.1179`).

### 7.5 Dates

- Only the rows above the first currency row are searched, unless they contain no date at all (then the whole sheet).
- A label is any text cell containing the word `DATE` (`\bDATE\b`, so "last updated" is ignored).
- The date comes from the label text itself or the first neighbour that is a date: right, 2 right, below.
- If no rate date is found, the first date in the sheet is used, else today (a warning is shown).
- **Value date** in the output depends on `value_date_source`:
  - `next_business_day` (default): rate date + `value_date_offset_days` Mon–Fri days. Matches what was published on Alert before the tool (30/09 → 01/10).
  - `file`: the file's "Spot Value date" (30/09 → 02/10, i.e. T+2).
  The file's value date is always shown under the Value date field as a hint when it differs. With `file`, if the file has no value date, the calculated one is used and a warning is shown.

---

## 8. Output format

`TableRenderer::render()` reproduces the structure of the original Alert table:

```html
<table border="1" cellspacing="1" style="background-color: #062d6e; border-spacing: 1px; width: 100%;">
<tbody>
<tr>                                            <!-- dates, white text on dark blue -->
<td width="27%"><p><span style="color: #ffffff;"><b>&nbsp;Date: 30/09/2026</b></span></p></td>
<td width="36%" colspan="2"></td>
<td width="36%" colspan="2"><p><b><span style="color: #ffffff;">&nbsp;Value Date: 01/10/2026</span></b></p></td>
</tr>
<tr> … SPOT (colspan 2) | BANK NOTES (colspan 2), background #cdd4df … </tr>
<tr> … CURRENCY | BNK SELLS | BNK BUYS | BNK SELLS | BNK BUYS (bold) … </tr>
<tr>                                            <!-- one per currency, background #eaedf2 -->
<td style="background-color: #eaedf2;"><p>USD</p></td>
<td style="background-color: #eaedf2;"><p>1.1179</p></td>
…
</tr>
</tbody>
</table>
```

Cosmetic differences from the hand-edited original: the date is in one `<span>` instead of two, and stray `<br />` / empty `<span>` tags left by the editor are omitted. The displayed result is identical.

**Verified:** for `RTS300926.xlsx` every cell of the generated table (dates, headings, 60 values) matches the table that was live on Alert for 30/09/2026.

---

## 9. Session state

`$_SESSION['job']` holds everything between requests:

| Key | Type | Meaning |
|---|---|---|
| `file_name` | string | Uploaded file name (display only) |
| `sheet_name` | string | Sheet used, e.g. `RATES` |
| `rows` | array | Only the currency rows of that sheet (row index → cells), kept so columns can be re-read without re-uploading |
| `max_col` | int | Last column used in those rows (limits the column dropdowns) |
| `currency_rows` | array | `code => [row, col]` |
| `mapping` | array | `field => col\|null` |
| `method` | string | `headers`, `position`, `manual` |
| `rates` | array | `code => field => text`: exactly what is rendered |
| `date`, `value_date` | string | `Y-m-d` |
| `dates_found` | bool | `false` → rate date fell back to today |
| `file_value_date` | ?string | Value date found in the file (hint) |
| `value_date_fallback` | bool | `value_date_source` is `file` but the file had no value date |

Other session keys: `csrf` (token), `flash` (one-time message). A job saved by an older version (no `max_col`) is discarded.

The session is stored server-side by PHP (by default in its temp folder) and holds about 15 rows of rates.

---

## 10. Security

| Risk | Mitigation |
|---|---|
| Cross-site request forgery | Random 128-bit token per session, checked with `hash_equals` on every POST. Session cookie is `SameSite=Strict`. |
| Cross-site scripting | All dynamic output goes through `e()` (`htmlspecialchars`, `ENT_QUOTES`). The table HTML is printed raw, but `TableRenderer` escapes every value, dates are validated `Y-m-d` and colours must be hex. A Content-Security-Policy allows only this site's own scripts. |
| Clickjacking | `frame-ancestors 'none'` and `X-Frame-Options: DENY`. |
| Malicious upload | Only `.xlsx`, `.xlsm` and `.csv` are processed. The file is read from PHP's temp upload (`is_uploaded_file`) and never stored or executed. Size limit `max_upload_bytes`; zip-bomb, DOCTYPE and row/column limits (§6.1). |
| Session | `HttpOnly`, `SameSite=Strict`, `Secure` over HTTPS, `use_strict_mode`. |
| Error details | `display_errors` is turned off (in `index.php` and `.htaccess`); unexpected errors are logged and the user sees a generic message. |
| Direct access to includes, docs, tests | `.htaccess` files deny web access (Apache); the root `.htaccess` hides dotfiles/dot-folders and `.md` files and disables directory listings. |
| Other headers | `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`, `Cache-Control: no-store`. |
| Unauthorised use | **There is no login.** Run it on `localhost`, or restrict access (see [Operator guide §6](OPERATOR.md#6-access-and-security)). |

---

## 11. Error handling

Errors in the upload pipeline are thrown as `RuntimeException` and shown to the user as a red message. Any other exception or PHP error is written to the PHP error log and the user sees "Something went wrong reading the file. Check that it opens in Excel, then try again." Nothing is stored in the session in either case.

| Condition | Message |
|---|---|
| No file chosen | Please choose the rates Excel file first. |
| Larger than `max_upload_bytes`, `upload_max_filesize` or `post_max_size` | The file is too large. |
| Upload interrupted | The upload was interrupted. Please try again. |
| Server temp folder / write problem | The server could not store the upload (error code N)… |
| Other PHP upload error | Upload failed (error code N). See [PHP upload error codes](https://www.php.net/manual/en/features.file-upload.errors.php). |
| Part too large when unzipped | The workbook is too large to read. Is this the right rates file? |
| XML with a DOCTYPE or broken XML | The workbook contains invalid XML. |
| `.xls` | Old .xls format is not supported. Open the file in Excel and use "Save As → Excel Workbook (.xlsx)". |
| Other extension | Unsupported file type. Please upload an .xlsx or .csv file. |
| Not a ZIP / corrupt | The file is not a valid .xlsx workbook. |
| Zip extension missing | PHP "zip" extension is not enabled… |
| No currency codes in any sheet | No currency codes (USD, GBP, CHF…) were found in the file. Is this the right rates file? |
| Invalid CSRF token | Your session expired. Please try again. |
| Update after the session expired | Nothing to update – your session expired. Upload the file again. |

Non-fatal problems are shown as yellow warnings above step 2: currencies missing from the file, columns not detected, positional fallback used, no date found, no value date in the file (with `value_date_source = file`).

---

## 12. Extending the tool

**Add, remove or reorder a currency.** Edit `currencies` in `config.php`. The order there is the order in the table. The code must appear as a stand-alone 3-letter code in the file (e.g. `ZAR`, which is on the REF RATES sheet but not on RATES, would show N/A).

**Change decimals.** Change the number next to the code in `currencies`.

**New column layout in the file.** Usually nothing to do: detection is based on the headings. If the headings change wording, update the `SELL`/`BUY` checks in `RateParser::detectColumns()` or the section regexes in `detectSections()`.

**Change the table's look.** Colours are in `config.php` → `colors`. Structure is in `TableRenderer::render()`. Keep it identical to what Alert expects.

**Public holidays for the value date.** `addBusinessDays()` skips weekends only. To add holidays, add a `holidays` list (`Y-m-d` strings) to `config.php` and skip those dates in the loop.

**Support `.xls`.** Would need PhpSpreadsheet (Composer). Replace `SpreadsheetReader::read()` with a wrapper that returns the same grid format. Nothing else changes.

**Add a page.** Create `newpage.php` with the same bootstrap as `index.php` (config, helpers, session, `sendSecurityHeaders()`), set `$pageTitle`, `require 'includes/header.php'`, content, `require 'includes/footer.php'`. The header has no navigation menu today; add links in `header.php` if a second page is added.

---

## 13. Testing

**Automated:** `php tests/run.php` (no Composer). It covers number/date parsing, business days, column and date detection, CSV (BOM, `;`, Windows-1252), .xlsx (shared/inline strings, date styles, 1904, Strict namespace, rows without `r`, oversized refs, zip bomb, DOCTYPE) and the renderer. Put the real file at `tests/fixtures/RTS300926.xlsx` to also check it. Exit code is non-zero on failure.

**Manual procedure** after any code change:

1. **Syntax:** `php -l index.php includes/*.php` and, if Node is available, `node --check assets/js/app.js`.
2. **Reference file:** upload a known file (e.g. `RTS300926.xlsx`) and compare every cell with the Alert table published that day. For `RTS300926.xlsx` the expected rows are:

   | CCY | Spot sells | Spot buys | Notes sells | Notes buys |
   |---|---|---|---|---|
   | USD | 1.1179 | 1.1519 | 1.1009 | 1.1803 |
   | JPY | 175.46 | 180.80 | 172.79 | 185.26 |
   | TND | 3.3161 | 3.4508 | N/A | N/A |
   | RUB | 93.9329 | 97.2707 | N/A | N/A |

   Date 30/09/2026, value date 01/10/2026 (`next_business_day`) or 02/10/2026 (`file`).
3. **Edit:** change a value to `1,2`, press Update table → shows `1.2000`. Clear a value → `N/A`.
4. **Remap:** swap the two Spot columns in *Excel columns* → the Spot values swap.
5. **Bad input:** upload a text file renamed to `.xlsx` → "not a valid .xlsx workbook". Upload an `.xls` → Save-As message.
6. **Copy:** paste the result of both copy buttons into Alert (or a test page) and check it looks the same as before.
7. **Mobile:** open the page at phone width; no horizontal scrolling of the page.

---

## 14. Known limitations

- No login; intended for a single user on a trusted machine or network.
- The value date ignores public holidays.
- Formulas are not recalculated; the value Excel last saved is used. The bank's file is saved by Excel, so this is correct.
- `.xls` (Excel 97–2003) is not supported.
- `1,234` in text is read as 1.234 (decimal comma), which is right for exchange rates.
- The tool does not publish to Alert; copy and paste is required.
- If the bank changes the currency code column into something without stand-alone codes (e.g. only "U S Dollar"), currencies will not be found.
