<?php
declare(strict_types=1);

/**
 * RateParser — finds the currency rows, the BUY/SELL columns and the dates
 * in the rows returned by SpreadsheetReader, and formats the values.
 */
final class RateParser
{
    public const FIELDS = ['spot_sell', 'spot_buy', 'note_sell', 'note_buy'];

    public const FIELD_LABELS = [
        'spot_sell' => 'Spot – Bank Sells',
        'spot_buy'  => 'Spot – Bank Buys',
        'note_sell' => 'Bank Notes – Bank Sells',
        'note_buy'  => 'Bank Notes – Bank Buys',
    ];

    /** How many rows above the first currency row to search for BUY/SELL headings. */
    private const HEADER_LOOKBACK_ROWS = 6;

    /** How many rows above the BUY/SELL headings to search for SPOT/NOTES labels. */
    private const SECTION_LOOKBACK_ROWS = 2;

    private readonly string $currencyPattern;

    public function __construct(private readonly array $config)
    {
        $codes = array_map(static fn($code) => preg_quote((string) $code, '/'), array_keys($config['currencies']));
        $this->currencyPattern = '/(?<![A-Z])(' . implode('|', $codes) . ')(?![A-Z])/';
    }

    /**
     * Use the first sheet (in workbook order) that contains the rates table.
     * Later sheets (e.g. "REF RATES") are only read if the first has no currencies.
     *
     * @param iterable<string, array> $sheets
     * @return array{name:string, rows:array, currency_rows:array<string, array{row:int, col:int}>}
     */
    public function pickSheet(iterable $sheets): array
    {
        foreach ($sheets as $name => $rows) {
            $currencyRows = $this->findCurrencyRows($rows);
            if ($currencyRows) {
                return ['name' => (string) $name, 'rows' => $rows, 'currency_rows' => $currencyRows];
            }
        }

        throw new RuntimeException(
            'No currency codes (USD, GBP, CHF…) were found in the file. Is this the right rates file?'
        );
    }

    /**
     * Currency code => [row index, column index of the code cell].
     * Only the leftmost code in each row counts; the first row for a code wins.
     *
     * @return array<string, array{row:int, col:int}>
     */
    public function findCurrencyRows(array $rows): array
    {
        $found = [];
        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                if (is_string($value) && preg_match($this->currencyPattern, strtoupper($value), $m)) {
                    $found[$m[1]] ??= ['row' => $r, 'col' => $c];
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Work out which spreadsheet column holds which value.
     *
     * @param array<string, array{row:int, col:int}> $currencyRows  non-empty (see pickSheet)
     * @return array{mapping: array<string,int|null>, method: 'headers'|'position'}
     */
    public function detectColumns(array $rows, array $currencyRows): array
    {
        $firstDataRow = min(array_column($currencyRows, 'row'));
        $codeCol      = (int) round(array_sum(array_column($currencyRows, 'col')) / count($currencyRows));

        // ---- 1) Header-based detection -------------------------------------
        $headerRow  = null;
        $headerHits = [];
        for ($r = $firstDataRow - 1; $r >= max(0, $firstDataRow - self::HEADER_LOOKBACK_ROWS); $r--) {
            $hits = [];
            foreach ($rows[$r] ?? [] as $c => $value) {
                if ($c <= $codeCol || !is_string($value)) {
                    continue;
                }
                $v = strtoupper($value);
                if (str_contains($v, 'SELL')) {
                    $hits[$c] = 'sell';
                } elseif (str_contains($v, 'BUY')) {
                    $hits[$c] = 'buy';
                }
            }
            if (count($hits) >= 2) {
                $headerRow  = $r;
                $headerHits = $hits;
                break;
            }
        }

        if ($headerRow !== null) {
            ksort($headerHits);
            $cols    = array_keys($headerHits);
            $section = $this->detectSections($rows, $headerRow, $cols);

            $mapping = array_fill_keys(self::FIELDS, null);
            foreach ($cols as $i => $c) {
                // Without SPOT/NOTES labels: first pair is spot, the rest bank notes
                $group = $section[$c] ?? ($i < 2 ? 'spot' : 'note');
                $key   = $group . '_' . $headerHits[$c];
                if (in_array($key, self::FIELDS, true) && $mapping[$key] === null) {
                    $mapping[$key] = $c;
                }
            }

            if (count(array_filter($mapping, static fn($v) => $v !== null)) >= 2) {
                return ['mapping' => $mapping, 'method' => 'headers'];
            }
        }

        // ---- 2) Positional fallback ----------------------------------------
        $numericCols = [];
        foreach ($currencyRows as $pos) {
            foreach ($rows[$pos['row']] ?? [] as $c => $value) {
                if ($c > $pos['col'] && self::toNumber($value) !== null) {
                    $numericCols[$c] = true;
                }
            }
        }
        ksort($numericCols);
        $numericCols = array_keys($numericCols);

        $mapping = array_fill_keys(self::FIELDS, null);
        foreach ($this->config['fallback_column_order'] as $i => $field) {
            $mapping[$field] = $numericCols[$i] ?? null;
        }

        return ['mapping' => $mapping, 'method' => 'position'];
    }

    /**
     * Look above the BUY/SELL header row for "SPOT" / "NOTES" / "CASH" labels
     * (often merged cells, so the label sits in the leftmost column of the group).
     *
     * @param int[] $cols
     * @return array<int,string> column => 'spot'|'note'
     */
    private function detectSections(array $rows, int $headerRow, array $cols): array
    {
        $labels = [];
        for ($r = $headerRow; $r >= $headerRow - self::SECTION_LOOKBACK_ROWS; $r--) {
            foreach ($rows[$r] ?? [] as $c => $value) {
                if (!is_string($value)) {
                    continue;
                }
                $v = strtoupper(preg_replace('/\s+/', '', $value));
                if (preg_match('/NOTE|CASH/', $v)) {
                    $labels[$c] = 'note';
                } elseif (preg_match('/SPOT|TRANSFER|T\/T/', $v)) {
                    $labels[$c] = 'spot';
                }
            }
            if (count(array_unique($labels)) >= 2) {
                break;
            }
        }

        if (count(array_unique($labels)) < 2) {
            return [];
        }

        ksort($labels);
        $result = [];
        foreach ($cols as $c) {
            $group = null;
            foreach ($labels as $lc => $g) {
                if ($lc <= $c) {
                    $group = $g;
                }
            }
            if ($group !== null) {
                $result[$c] = $group;
            }
        }
        return $result;
    }

    /**
     * Pull the values for every configured currency using the given mapping.
     *
     * @param array<string,int|null> $mapping
     * @return array<string, array<string, string>>  code => field => formatted value
     */
    public function extractRates(array $rows, array $currencyRows, array $mapping): array
    {
        $rates = [];
        foreach ($this->config['currencies'] as $code => $decimals) {
            foreach (self::FIELDS as $field) {
                $value = null;
                if (isset($currencyRows[$code]) && $mapping[$field] !== null) {
                    $value = self::toNumber($rows[$currencyRows[$code]['row']][$mapping[$field]] ?? null);
                }
                $rates[$code][$field] = $this->formatRate($value, (int) $decimals);
            }
        }
        return $rates;
    }

    /** A rate for display: fixed decimals, or the "missing" text for empty/zero/negative. */
    public function formatRate(?float $value, int $decimals): string
    {
        return $value === null || $value <= 0.0
            ? $this->config['missing_value']
            : number_format($value, $decimals, '.', '');
    }

    /**
     * Find the rate date and value date. The header area (above $beforeRow)
     * is searched first, so dates in the interest-rate tables further down are
     * not picked up; the whole sheet is searched only for a missing rate date
     * (or for both if the header area has neither).
     *
     * @return array{date:?string, value_date:?string}  (Y-m-d)
     */
    public function detectDates(array $rows, ?int $beforeRow = null): array
    {
        if ($beforeRow !== null) {
            $header = array_filter($rows, static fn(int $r) => $r < $beforeRow, ARRAY_FILTER_USE_KEY);
            $found  = $this->scanDates($header);
            if ($found['date'] !== null) {
                return $found;
            }
            if ($found['value_date'] !== null) {
                return ['date' => $this->scanDates($rows)['date'], 'value_date' => $found['value_date']];
            }
        }
        return $this->scanDates($rows);
    }

    /** @return array{date:?string, value_date:?string} */
    private function scanDates(array $rows): array
    {
        $date      = null;
        $valueDate = null;
        $allDates  = [];

        foreach ($rows as $r => $cells) {
            foreach ($cells as $c => $value) {
                // Date inside the label cell itself, e.g. "Value Date: 01/10/2026"
                $inline = self::toDate($value);
                if ($inline !== null) {
                    $allDates[] = $inline;
                }

                $label = is_string($value) ? strtoupper($value) : '';
                if (!preg_match('/\bDATE\b/', $label)) {
                    continue;
                }
                if (str_contains($label, 'VALUE')) {
                    $valueDate ??= $inline ?? $this->neighbourDate($rows, $r, $c);
                } else {
                    $date ??= $inline ?? $this->neighbourDate($rows, $r, $c);
                }
            }
        }

        if ($date === null && $allDates) {
            $date = $allDates[0] !== $valueDate ? $allDates[0] : ($allDates[1] ?? null);
        }

        return ['date' => $date, 'value_date' => $valueDate];
    }

    /** A date to the right of, two to the right of, or below a label cell. */
    private function neighbourDate(array $rows, int $r, int $c): ?string
    {
        foreach ([[$r, $c + 1], [$r, $c + 2], [$r + 1, $c]] as [$rr, $cc]) {
            $d = self::toDate($rows[$rr][$cc] ?? null);
            if ($d !== null) {
                return $d;
            }
        }
        return null;
    }

    // ------------------------------------------------------------ helpers

    /**
     * Parses a rate: floats as-is; strings in 1.1179, 1,1179 (decimal comma),
     * 1,234.56 and 1.234,56 notation. Note "1,234" is read as 1.234 (decimal
     * comma), which is right for rates. Returns null if not a finite number.
     */
    public static function toNumber(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return is_finite((float) $value) ? (float) $value : null;
        }
        if (!is_string($value)) {
            return null;
        }

        // Spaces, NBSP, thin/narrow NBSP and apostrophes used as thousands separators
        $v = str_replace([' ', "\u{00A0}", "\u{2009}", "\u{202F}", "'"], '', trim($value));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?\d+,\d+$/', $v)) {                         // 1,1179
            $v = str_replace(',', '.', $v);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) {   // 1,234.56
            $v = str_replace(',', '', $v);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+,\d+$/', $v)) {      // 1.234,56
            $v = str_replace(['.', ','], ['', '.'], $v);
        }

        if (!is_numeric($v)) {
            return null;
        }
        $number = (float) $v;
        return is_finite($number) ? $number : null;
    }

    /** Accepts Y-m-d, d/m/Y, d.m.Y, d-m-Y and 2-digit years (also inside longer text). */
    public static function toDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/\b(\d{1,2})([\/.\-])(\d{1,2})\2(\d{4}|\d{2})\b/', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[3], (int) $m[4]];
            if ($y < 100) {
                $y += 2000;
            }
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Adds working days (Mon–Fri); public holidays are not known. */
    public static function addBusinessDays(string $ymd, int $days): string
    {
        $date = new DateTimeImmutable($ymd);
        while ($days > 0) {
            $date = $date->modify('+1 day');
            if ((int) $date->format('N') < 6) {
                $days--;
            }
        }
        return $date->format('Y-m-d');
    }

    /** 0 => "A", 25 => "Z", 26 => "AA" … */
    public static function columnLetter(int $index): string
    {
        $letters = '';
        $index++;
        while ($index > 0) {
            $mod     = ($index - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $index   = intdiv($index - 1, 26);
        }
        return $letters;
    }
}
