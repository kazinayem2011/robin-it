<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reorder Level
    |--------------------------------------------------------------------------
    |
    | Used for any product or option that has not been given its own. A single
    | number was previously hardcoded across the whole catalogue, which is wrong
    | in both directions — ten cables is nearly out, ten flagship graphics cards
    | is months of inventory — so this is only the fallback.
    |
    */

    'default_reorder_level' => (int) env('INVENTORY_DEFAULT_REORDER_LEVEL', 10),

];
