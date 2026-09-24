<?php

return [

    /*
    |--------------------------------------------------------------------
    | PNG Export Size Limit
    |--------------------------------------------------------------------
    |
    | The largest Gantt PNG drawn, in pixels (width × height). GD needs
    | about 4–5 bytes per pixel while drawing, so the default 12M pixels
    | (about 60MB) fits a 128MB memory_limit, common on shared hosting.
    | A larger chart (roughly 180 rows over 24 months) shows a message
    | instead. Raise it only with a higher memory_limit.
    |
    */

    'png_max_pixels' => (int) env('GANTT_PNG_MAX_PIXELS', 12_000_000),

];
