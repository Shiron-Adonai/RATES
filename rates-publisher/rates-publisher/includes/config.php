<?php
/**
 * config.php — everything you might need to tweak lives here.
 */

return [
    // Site branding (used by header/footer)
    'site_name'    => 'Rates Publisher',
    'site_tagline' => 'Turn the daily rates Excel file into an Alert-ready table.',

    // Currencies in the order they appear on Alert.
    // Value = number of decimal places to show.
    'currencies' => [
        'USD' => 4,
        'GBP' => 4,
        'CHF' => 4,
        'JPY' => 2,
        'SEK' => 4,
        'AUD' => 4,
        'CAD' => 4,
        'DKK' => 4,
        'TND' => 4,
        'NOK' => 4,
        'CNY' => 4,
        'PLN' => 4,
        'CZK' => 4,
        'RON' => 4,
        'RUB' => 4,
    ],

    // Text shown when a value is missing / empty in the Excel file.
    'missing_value' => 'N/A',

    // Fallback column order (after the currency code column) when the
    // Excel file has no recognisable BUY/SELL headers.
    // Allowed keys: spot_sell, spot_buy, note_sell, note_buy
    'fallback_column_order' => ['spot_sell', 'spot_buy', 'note_sell', 'note_buy'],

    // Where the "Value Date" comes from:
    //   'next_business_day' => date + value_date_offset_days business days (matches the current Alert page)
    //   'file'              => the "Spot Value date" cell in the Excel file (usually date + 2)
    'value_date_source'      => 'next_business_day',
    'value_date_offset_days' => 1,

    // Max upload size in bytes (5 MB).
    'max_upload_bytes' => 5 * 1024 * 1024,

    // Colours from the Alert table.
    'colors' => [
        'table_bg'  => '#062d6e',
        'header_bg' => '#cdd4df',
        'row_bg'    => '#eaedf2',
        'title_fg'  => '#ffffff',
    ],
];
