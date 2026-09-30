<?php
declare(strict_types=1);

/**
 * TableRenderer — builds the HTML of the rates block on Alert Web Publisher,
 * byte for byte as in the page's source view, so it can replace that source.
 *
 * The source contains leftovers from Alert's editor that are reproduced on
 * purpose (marked "Alert source:" below). Checked against the live page by
 * tests/fixtures/alert-table-2026-09-30.html.
 */
final class TableRenderer
{
    /** @throws InvalidArgumentException if a configured colour is not a hex colour */
    public function __construct(private readonly array $config)
    {
        foreach ($config['colors'] as $name => $color) {
            if (!is_string($color) || !preg_match('/^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i', $color)) {
                throw new InvalidArgumentException("config.php: colors.{$name} must be a hex colour like #062d6e.");
            }
        }
    }

    /**
     * @param string $date       Y-m-d
     * @param string $valueDate  Y-m-d
     * @param array  $rates      code => [spot_sell, spot_buy, note_sell, note_buy]
     */
    public function render(string $date, string $valueDate, array $rates): string
    {
        $c   = $this->config['colors'];
        $hdr = "background-color: {$c['header_bg']};";
        $row = "background-color: {$c['row_bg']};";
        $fg  = "color: {$c['title_fg']};";

        $d  = self::formatDate($date);
        $vd = self::escape(self::formatDate($valueDate));
        // Alert source: the date is split after "dd/" into two spans ("30/" + "09/2026")
        $dHead = self::escape(substr($d, 0, 3));
        $dTail = self::escape(substr($d, 3));

        $html = [];
        $html[] = '<p></p>';
        $html[] = "<table border=\"1\" cellspacing=\"1\" style=\"background-color: {$c['table_bg']}; border-spacing: 1px; width: 100%;\">";
        $html[] = '<tbody>';

        // Row 1: dates
        $html[] = '<tr>';
        $html[] = '<td width="27%">';
        $html[] = "<p><span style=\"{$fg}\"><b>&nbsp;Date: {$dHead}</b></span><span style=\"{$fg}\"><b>{$dTail}</b></span></p>";
        $html[] = '</td>';
        $html[] = '<td width="36%" colspan="2"></td>';
        $html[] = '<td width="36%" colspan="2">';
        // Alert source: trailing "<br /> " inside the bold
        $html[] = "<p><b><span style=\"{$fg}\">&nbsp;Value Date: {$vd}</span><br /> </b></p>";
        $html[] = '</td>';
        $html[] = '</tr>';

        // Row 2: section headings
        $html[] = '<tr>';
        $html[] = "<td style=\"{$hdr}\"></td>";
        $html[] = "<td colspan=\"2\" style=\"{$hdr}\">";
        $html[] = '<p>SPOT</p>';
        $html[] = '</td>';
        $html[] = "<td colspan=\"2\" style=\"{$hdr}\">";
        $html[] = '<p>BANK NOTES</p>';
        $html[] = '</td>';
        $html[] = '</tr>';

        // Row 3: column headings
        $html[] = '<tr>';
        foreach (['CURRENCY', 'BNK SELLS', 'BNK BUYS', 'BNK SELLS', 'BNK BUYS'] as $label) {
            $html[] = "<td style=\"{$hdr}\">";
            $html[] = "<p><b>{$label}</b></p>";
            $html[] = '</td>';
        }
        $html[] = '</tr>';

        // Currency rows
        $firstRow = true;
        foreach ($rates as $code => $values) {
            $html[] = '<tr>';
            $cells  = [$code, $values['spot_sell'], $values['spot_buy'], $values['note_sell'], $values['note_buy']];
            foreach ($cells as $i => $cell) {
                $text = self::escape((string) $cell);
                // Alert source: the first rate of the first row is wrapped in a bare <span>
                if ($firstRow && $i === 1) {
                    $text = "<span>{$text}</span>";
                }
                $html[] = "<td style=\"{$row}\">";
                $html[] = "<p>{$text}</p>";
                $html[] = '</td>';
            }
            $html[] = '</tr>';
            $firstRow = false;
        }

        $html[] = '</tbody>';
        $html[] = '</table>';

        // Alert source: empty paragraphs and an empty table pasted from Excel after the rates
        $html[] = '<p></p>';
        $html[] = '<p></p>';
        $html[] = '<p></p>';
        $html[] = '<table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; width: 114px;">';
        $html[] = '<tbody>';
        $html[] = '<tr height="34" style="height: 25.5pt;">';
        $html[] = '<td height="34" class="xl64" width="114" style="height: 25.5pt; width: 86pt;"></td>';
        $html[] = '</tr>';
        $html[] = '</tbody>';
        $html[] = '</table>';

        return implode("\n", $html);
    }

    /** Y-m-d -> dd/mm/yyyy (unchanged if not a valid Y-m-d date). Not escaped. */
    public static function formatDate(string $ymd): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $dt && $dt->format('Y-m-d') === $ymd ? $dt->format('d/m/Y') : $ymd;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
