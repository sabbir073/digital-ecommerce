<?php

return [
    // Largest image or video accepted by the media library, in kilobytes.
    'media_max_kb' => (int) env('MEDIA_MAX_KB', 20 * 1024),

    // Largest paid digital file accepted, in kilobytes. The server's own PHP
    // limits (upload_max_filesize, post_max_size) must be at least this large.
    'digital_file_max_kb' => (int) env('DIGITAL_FILE_MAX_KB', 512 * 1024),

    // Minutes stock stays held for a customer who has started paying (when overselling is off).
    'reservation_minutes' => (int) env('STORE_RESERVATION_MINUTES', 20),

    // Download links: how many times each file can be fetched, and for how many days.
    'download_limit' => (int) env('STORE_DOWNLOAD_LIMIT', 5),
    'download_days' => (int) env('STORE_DOWNLOAD_DAYS', 30),
];
