<?php
/**
 * Configuration template.
 *
 * Easiest path: open install.php in your browser and it writes config.php
 * for you. To configure manually instead, copy this file to config.php
 * and fill in your values.
 */

return [
    // MySQL / MariaDB connection
    'db_host'        => 'localhost',
    'db_user'        => 'your_db_user',
    'db_password'    => 'your_db_password',
    'db_name'        => 'your_db_name',

    // UI
    'page_title'     => 'R2 File Manager',
    'items_per_page' => 50,

    // Which bucket to show first (null = first bucket in the table)
    'bucket_id'      => null,

    // Public base URL for file links, e.g. https://files.example.com
    // Leave empty to use the bucket's endpoint_url from the database.
    'custom_domain'  => '',

    // Access key for the ?key=... gate. Use a long random string.
    'access_key'     => 'change-me-to-a-long-random-string',
];
