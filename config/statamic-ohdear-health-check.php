<?php

return [
    'health_route' => [
        'path' => env('STATAMIC_OHDEAR_HEALTH_CHECK_PATH', env('OHDEAR_HEALTH_CHECK_PATH', '/ohdear-health-check')),
        'middleware' => ['web', 'cache.headers:public;max_age=300;etag'],
    ],

    'response_format' => env('OHDEAR_RESPONSE_FORMAT', 'ohdear'),

    'secret' => env('OHDEAR_HEALTH_CHECK_SECRET', env('OHDEAR_SECRET', '')),
    'oh_dear_secret_key' => env('OHDEAR_HEALTH_CHECK_SECRET', env('OHDEAR_SECRET', '')),

    // --- Shared checks (also configurable via Tools → OhDear Health Check in the Statamic CP) ---

    'enable_database_check' => env('OHDEAR_ENABLE_DATABASE_CHECK', false),

    'enable_disk_used_space_check' => env('OHDEAR_ENABLE_DISK_USED_SPACE_CHECK', true),
    'disk_space_error_threshold' => env('OHDEAR_DISK_SPACE_ERROR_THRESHOLD', 90),    // %
    'disk_space_warning_threshold' => env('OHDEAR_DISK_SPACE_WARNING_THRESHOLD', 70), // %

    'enable_error_log_size_check' => env('OHDEAR_ENABLE_ERROR_LOG_SIZE_CHECK', true),
    'error_log_error_threshold' => env('OHDEAR_ERROR_LOG_ERROR_THRESHOLD', 500),     // MB
    'error_log_warning_threshold' => env('OHDEAR_ERROR_LOG_WARNING_THRESHOLD', 50),  // MB

    // --- Statamic-specific checks (also configurable via Tools → OhDear Health Check in the Statamic CP) ---

    'enable_storage_folder_size_check' => env('OHDEAR_ENABLE_STORAGE_FOLDER_SIZE_CHECK', true),
    'storage_folder_size_error_threshold' => env('OHDEAR_STORAGE_FOLDER_SIZE_ERROR_THRESHOLD', 500),   // MB
    'storage_folder_size_warning_threshold' => env('OHDEAR_STORAGE_FOLDER_SIZE_WARNING_THRESHOLD', 50), // MB

    'enable_statamic_version_check' => env('OHDEAR_ENABLE_STATAMIC_VERSION_CHECK', true),
    'enable_forgotten_files_check' => env('OHDEAR_ENABLE_FORGOTTEN_FILES_CHECK', true),

    // Extra filenames / glob patterns allowed in public/ – use config file or Statamic CP for array values
    'allowed_files' => [],
];
