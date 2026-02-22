<?php

declare(strict_types=1);

return [

    // Where Manticore\Connection connects. The table name stays with the application.
    'manticore' => [
        'host' => env('MANTICORE_HOST', '127.0.0.1'),
        'port' => env('MANTICORE_PORT', 9306),

        // Seconds to connect, not to wait for an answer; empty or 0 means 5.
        'timeout' => env('MANTICORE_TIMEOUT', 5),

        // Seconds to wait for an answer on this connection only; empty means 30, 0 keeps mysqlnd.net_read_timeout.
        'read_timeout' => env('MANTICORE_READ_TIMEOUT', 30),
    ],

    'url' => [
        // comma: ?color=a,b&price=1000-50000
        // brackets: ?color[]=a&color[]=b&price_min=1000&price_max=50000
        'style' => 'comma',

        // Base units in one unit typed in the address: 100 when prices are kept in cents.
        'price_unit' => 1,

        // Sort slugs the address accepts; anything else reads as the default order.
        'sorts' => [],
    ],

    'screen' => [
        // Order of values inside a panel group: dictionary, natural, alpha or count-desc.
        'order' => 'natural',

        // Price bounds of the current selection instead of the whole scope.
        'filtered_bounds' => false,

        // A value nothing matches stays in the panel, dimmed; false hides it.
        'keep_zeros' => true,

        // With keep_zeros, values zeroed by a price range alone stay dimmed too; one more count per such screen.
        'palette_on_price' => false,

        // A group without a selection is shown from this many values.
        'min_values' => 1,
    ],

];
