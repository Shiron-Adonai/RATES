# Rates Publisher

Upload the daily rates Excel file (e.g. `RTS300926.xlsx`) and get the exchange-rates
table in the exact HTML format used on the Alert Web Publisher page.

## Setup (XAMPP)

1. Copy the `rates-publisher` folder into `C:\xampp\htdocs\`.
2. Start Apache and open <http://localhost/rates-publisher/>.
3. If you see "PHP zip extension is not enabled": open `C:\xampp\php\php.ini`,
   remove the `;` in front of `extension=zip`, save, restart Apache.

No database and no Composer needed. Requires PHP 8.1+.

## Daily use

1. **Upload** the Excel file (drag it onto the box or click to browse).
2. **Check** the values. Dates and every value can be edited by hand; press *Update table*.
3. **Copy**:
   - Alert's normal editor → *Preview* tab → **Copy table** → paste.
   - Alert's Source/HTML view → *HTML source* tab → **Copy HTML** → replace the old table code.

## How the file is read

- Uses the **RATES** sheet (the first sheet with currencies). "REF RATES" and the
  interest-rate tables further down are ignored.
- Finds each currency by its code (column D), and the four value columns from the
  `BNK SELLS` / `BNK BUYS` headings under `SPOT` and `BANK NOTES` (columns G–J).
- If the layout ever changes, open **Excel columns** on the page and pick the right columns.
- `Date:` comes from cell C4.

## Settings (`includes/config.php`)

| Setting | What it does |
|---|---|
| `currencies` | Currencies shown on Alert, in order, with decimal places (JPY = 2). Add or remove lines here. |
| `value_date_source` | `next_business_day` (default, matches the current Alert page: date + 1 working day) or `file` (uses the file's "Spot Value date", which is date + 2). |
| `missing_value` | Text for empty rates (`N/A`). |
| `colors` | The Alert table colours. |

## Files

```
index.php                     upload / review / copy page
includes/config.php           settings
includes/SpreadsheetReader.php  reads .xlsx and .csv (no libraries)
includes/RateParser.php       finds currencies, columns and dates
includes/TableRenderer.php    builds the Alert table HTML
includes/header.php, footer.php
assets/css/style.css, assets/js/app.js
```
