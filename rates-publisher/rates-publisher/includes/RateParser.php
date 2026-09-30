<?php
/**
 * RateParser — finds the currency rows, the BUY/SELL columns and the dates
 * in the rows returned by SpreadsheetReader.
 */
class RateParser
{
    public const FIELDS = ['spot_sell', 'spot_buy', 'note_sell', 'note_buy'];

    public const FIELD_LABELS = [
        'spot_sell' => 'Spot – Bank Sells',
        'spot_buy'  => 'Spot – Bank Buys',
        'note_sell' => 'Bank Notes – Bank Sells',
        'note_buy'  => 'Bank Notes – Bank Buys',
    ];

    public function __construct(private array $config) {}

    /**
     * Use the first sheet (in workbook order) that contains the rates table.
     * Later sheets (e.g. "REF RATES") are only used if the first has no currencies.
     * @return array{name:string, rows:array}
     */
    public function pickSheet(array $sheets): array
    {
        foreach ($sheets as $name => $rows) {
            if (count($this->findCurrencyRows($rows)) > 0) {
                return ['name' => $name, 'rows' => $rows];
            }
        }

        throw new RuntimeException(
            'No currency codes (USD, GBP, CHF…) were found in the file. Is this the right rates file?'
        );
    }

    /**
     * Currency code => [row index, column index of the code cell]
     * @return array<string, array{row:int, col:int}>
     */
    public function findCurrencyRows(array $rows): array
    {
        $codes   = array_keys($this->config['currencies']);
        $pattern = '/(?<![A-Z])(' . implode('|', array_map('preg_quote', $codes)) . ')(?![A-Z])/';

        $found = [];
        foreach ($rows as $r => $cells) {
            ksort($cells);
            foreach ($cells as $c => $value) {
                if (!is_string($value)) {
                    continue;
                }
                if (preg_match($pattern, strtoupper($value), $m) && !isset($found[$m[1]])) {
                    $found[$m[1]] = ['row' => $r, 'col' => $c];
                    break; // leftmost code in the row only
                }
            }
        }
        return $found;
    }

    /**
     * Work out which spreadsheet column holds which value.
     * @return array{mapping: array<string,int|null>, method: string}
     */
    public function detectColumns(array $rows, array $currencyRows): array
    {
        if (!$currencyRows) {
            return ['mapping' => array_fill_keys(self::FIELDS, null), 'method' => 'none'];
        }

        $firstDataRow = min(array_column($currencyRows, 'row'));
        $codeCol      = (int) round(array_sum(array_column($currencyRows, 'col')) / count($currencyRows));

        // ---- 1) Header-based detection -------------------------------------
        $headerRow  = null;
        $headerHits = [];
        for ($r = $firstDataRow - 1; $r >= max(0, $firstDataRow - 6); $r--) {
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
            $section = $this->detectSections($rows, $headerRow, $cols, $firstDataRow);

            $mapping = array_fill_keys(self::FIELDS, null);
            foreach ($cols as $i => $c) {
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
     * @return array<int,string> column => 'spot'|'note'
     */
    private function detectSections(array $rows, int $headerRow, array $cols, int $firstDataRow): array
    {
        $labels = [];
        foreach ([$headerRow, $headerRow - 1, $headerRow - 2] as $r) {
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
     * @return array<string, array<string, string>>  code => field => formatted value
     */
    public function extractRates(array $rows, array $currencyRows, array $mapping): array
    {
        $missing = $this->config['missing_value'];
        $rates   = [];

        foreach ($this->config['currencies'] as $code => $decimals) {
            foreach (self::FIELDS as $field) {
                $value = null;
                if (isset($currencyRows[$code]) && $mapping[$field] !== null) {
                    $value = self::toNumber($rows[$currencyRows[$code]['row']][$mapping[$field]] ?? null);
                }
                $rates[$code][$field] = $value === null || $value == 0.0
                    ? $missing
                    : number_format($value, $decimals, '.', '');
            }
        }

        return $rates;
    }

    /**
     * Find the rate date and value date in the sheet.
     * @return array{date:?string, value_date:?string}  (Y-m-d)
     */
    public function detectDates(array $rows): array
    {
        $date      = null;
        $valueDate = null;
        $allDates  = [];

        foreach ($rows as $r => $cells) {
            ksort($cells);
            foreach ($cells as $c => $value) {
                $label = is_string($value) ? strtoupper($value) : '';

                // Date inside the label cell itself, e.g. "Value Date: 01/10/2026"
                $inline = self::toDate($value);
                if ($inline !== null) {
                    $allDates[] = $inline;
                }

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

    public static function toNumber(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }

        $v = trim(str_replace(["\u{00A0}", ' '], '', $value));
        if ($v === '') {
            return null;
        }
        if (preg_match('/^-?\d+,\d+$/', $v)) {          // 1,1179  (decimal comma)
            $v = str_replace(',', '.', $v);
        } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) { // 1,234.56
            $v = str_replace(',', '', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    /** Accepts Y-m-d, d/m/Y, d.m.Y, d-m-Y (also inside longer text). */
    public static function toDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $value, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})\b/', $value, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($y < 100) {
                $y += 2000;
            }
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

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
