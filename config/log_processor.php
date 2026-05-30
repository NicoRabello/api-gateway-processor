<?php

return [
    'batch_size' => max(1, (int) env('LOG_PROCESSOR_BATCH_SIZE', 500)),
];
