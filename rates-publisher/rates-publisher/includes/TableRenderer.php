<?php
/**
 * TableRenderer — builds the exact HTML structure used on Alert Web Publisher.
 */
class TableRenderer
{
    public function __construct(private array $config) {}

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
        $vd = self::formatDate($valueDate);

        $html = [];
        $html[] = "<table border=\"1\" cellspacing=\"1\" style=\"background-color: {$c['table_bg']}; border-spacing: 1px; width: 100%;\">";
        $html[] = '<tbody>';

        // Row 1: dates
        $html[] = '<tr>';
        $html[] = '<td width="27%">';
        $html[] = "<p><span style=\"{$fg}\"><b>&nbsp;Date: {$d}</b></span></p>";
        $html[] = '</td>';
        $html[] = '<td width="36%" colspan="2"></td>';
        $html[] = '<td width="36%" colspan="2">';
        $html[] = "<p><b><span style=\"{$fg}\">&nbsp;Value Date: {$vd}</span></b></p>";
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
        foreach ($rates as $code => $values) {
            $html[] = '<tr>';
            $cells  = [$code, $values['spot_sell'], $values['spot_buy'], $values['note_sell'], $values['note_buy']];
            foreach ($cells as $cell) {
                $html[] = "<td style=\"{$row}\">";
                $html[] = '<p>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</p>';
                $html[] = '</td>';
            }
            $html[] = '</tr>';
        }

        $html[] = '</tbody>';
        $html[] = '</table>';

        return implode("\n", $html);
    }

    /** Y-m-d -> dd/mm/yyyy */
    public static function formatDate(string $ymd): string
    {
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        return $dt ? $dt->format('d/m/Y') : htmlspecialchars($ymd, ENT_QUOTES, 'UTF-8');
    }
}
