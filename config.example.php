<?php
/**
 * Visitor Insights — configuration.
 *
 * Copy this file to `config.php` (in the same directory) and fill it in,
 * or let `install.sh` generate it for you.
 *
 * Keep `config.php` unreadable by the web server: mode 640, owner root,
 * group = your PHP-FPM user (e.g. www-data).
 */

return array(
    // Read-only MariaDB/MySQL account. Create it with bin/setup-db.sh or by hand:
    //   CREATE USER 'vi_read'@'localhost' IDENTIFIED BY '...';
    //   GRANT SELECT ON your_wp_db.* TO 'vi_read'@'localhost';
    'db' => array(
        'host' => '127.0.0.1',
        'port' => 3306,
        'user' => 'vi_read',
        'pass' => 'CHANGE_ME',
    ),

    // One entry per WordPress site you want to report on.
    //  key    : short id used in the UI and ?site=<key>
    //  label  : human label shown in the dashboard
    //  db     : WordPress database name
    //  prefix : WordPress $table_prefix (usually "wp_")
    'sites' => array(
        'example' => array('label' => 'example.com', 'db' => 'wp_example', 'prefix' => 'wp_'),
    ),
);
