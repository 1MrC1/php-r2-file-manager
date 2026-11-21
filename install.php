<?php
/**
 * R2 File Manager & Video Backup - one-time installer.
 *
 * Upload all files to your web space, open install.php in a browser,
 * fill in the form, then DELETE this file.
 */

declare(strict_types=1);

$lockFile    = __DIR__ . '/install.lock';
$configFile  = __DIR__ . '/config.php';
$locked      = file_exists($lockFile);
$errors      = [];
$done        = false;
$accessKey   = '';
$suggestedKey = bin2hex(random_bytes(12));

$requirements = [
    'PHP 7.4 or newer'    => version_compare(PHP_VERSION, '7.4.0', '>='),
    'mysqli extension'    => extension_loaded('mysqli'),
    'pdo_mysql extension' => extension_loaded('pdo_mysql'),
    'Writable directory'  => is_writable(__DIR__),
];

$schema = [
    'buckets' => "CREATE TABLE IF NOT EXISTS `buckets` (
        `bucket_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bucket_name` VARCHAR(255) NOT NULL,
        `endpoint_url` VARCHAR(1024) NOT NULL DEFAULT '',
        PRIMARY KEY (`bucket_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'directories' => "CREATE TABLE IF NOT EXISTS `directories` (
        `directory_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bucket_id` INT UNSIGNED NOT NULL,
        `path` VARCHAR(1024) NOT NULL,
        `object_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `total_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `last_modified` DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (`directory_id`),
        KEY `idx_bucket_path` (`bucket_id`, `path`(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'files' => "CREATE TABLE IF NOT EXISTS `files` (
        `file_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `bucket_id` INT UNSIGNED NOT NULL,
        `directory_id` INT UNSIGNED NOT NULL,
        `object_key` VARCHAR(1024) NOT NULL,
        `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `last_modified` DATETIME NULL DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (`file_id`),
        KEY `idx_bucket_dir` (`bucket_id`, `directory_id`, `is_active`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'avideobackup' => "CREATE TABLE IF NOT EXISTS `avideobackup` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sid` VARCHAR(255) NOT NULL DEFAULT '',
        `name` VARCHAR(255) NOT NULL DEFAULT '',
        `link` VARCHAR(1024) NOT NULL DEFAULT '',
        `tags` VARCHAR(1024) NOT NULL DEFAULT '',
        `creation_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function old(string $name, string $default = ''): string
{
    return e((string)($_POST[$name] ?? $default));
}

if (!$locked && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteTitle    = trim((string)($_POST['site_title'] ?? ''));
    $dbHost       = trim((string)($_POST['db_host'] ?? ''));
    $dbUser       = trim((string)($_POST['db_user'] ?? ''));
    $dbPassword   = (string)($_POST['db_password'] ?? '');
    $dbName       = trim((string)($_POST['db_name'] ?? ''));
    $customDomain = trim((string)($_POST['custom_domain'] ?? ''));
    $accessKey    = trim((string)($_POST['access_key'] ?? ''));
    $bucketName   = trim((string)($_POST['bucket_name'] ?? ''));
    $endpointUrl  = trim((string)($_POST['endpoint_url'] ?? ''));

    if ($siteTitle === '') {
        $errors[] = 'Site title is required.';
    }
    if ($dbHost === '') {
        $errors[] = 'Database host is required.';
    }
    if ($dbUser === '') {
        $errors[] = 'Database user is required.';
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
        $errors[] = 'Database name is required and may only contain letters, numbers and underscores.';
    }
    if ($bucketName === '') {
        $errors[] = 'Bucket name is required (the file manager needs at least one bucket).';
    }
    if ($endpointUrl === '') {
        $errors[] = 'Endpoint URL is required.';
    }
    if ($accessKey === '') {
        $accessKey = $suggestedKey;
    }

    if (!$errors) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($dbHost, $dbUser, $dbPassword);

        if ($mysqli->connect_error) {
            $errors[] = 'Database connection failed: ' . $mysqli->connect_error;
        } else {
            $mysqli->set_charset('utf8mb4');
            $dbNameQuoted = '`' . str_replace('`', '``', $dbName) . '`';

            if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS $dbNameQuoted CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                $errors[] = 'Could not create database: ' . $mysqli->error;
            } elseif (!$mysqli->select_db($dbName)) {
                $errors[] = 'Could not select database: ' . $mysqli->error;
            } else {
                foreach ($schema as $table => $sql) {
                    if (!$mysqli->query($sql)) {
                        $errors[] = "Failed creating table '$table': " . $mysqli->error;
                        break;
                    }
                }

                if (!$errors) {
                    $result = $mysqli->query('SELECT COUNT(*) AS c FROM buckets');
                    $hasRows = $result && (int)$result->fetch_assoc()['c'] > 0;

                    if (!$hasRows) {
                        $stmt = $mysqli->prepare('INSERT INTO buckets (bucket_name, endpoint_url) VALUES (?, ?)');
                        $stmt->bind_param('ss', $bucketName, $endpointUrl);
                        if (!$stmt->execute()) {
                            $errors[] = 'Could not seed the first bucket: ' . $stmt->error;
                        }
                        $stmt->close();
                    }
                }
            }

            $mysqli->close();
        }
    }

    if (!$errors) {
        $config = [
            'db_host'        => $dbHost,
            'db_user'        => $dbUser,
            'db_password'    => $dbPassword,
            'db_name'        => $dbName,
            'page_title'     => $siteTitle,
            'items_per_page' => 50,
            'bucket_id'      => null,
            'custom_domain'  => $customDomain,
            'access_key'     => $accessKey,
        ];

        $php = "<?php\n"
            . "// Generated by install.php on " . date('Y-m-d H:i:s') . "\n"
            . "// Do not commit this file to version control.\n\n"
            . 'return ' . var_export($config, true) . ";\n";

        if (@file_put_contents($configFile, $php) === false) {
            $errors[] = 'Could not write config.php - make sure the directory is writable by PHP and try again.';
        } else {
            @chmod($configFile, 0640);

            if (@file_put_contents($lockFile, date('c')) === false) {
                $errors[] = 'Could not write install.lock.';
            } else {
                $done = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - R2 File Manager &amp; Video Backup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        .container {
            max-width: 640px;
            margin: 0 auto;
            background: #fff;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
        }
        h1 { color: #333; font-size: 26px; text-align: center; margin-bottom: 8px; }
        .subtitle { color: #666; text-align: center; margin-bottom: 30px; font-size: 14px; }
        h2 { color: #444; font-size: 17px; margin: 28px 0 14px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 6px; color: #555; font-weight: 600; font-size: 14px; }
        .optional { color: #999; font-weight: 400; font-size: 12px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 11px 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            font-family: inherit;
            transition: border-color 0.2s ease;
        }
        input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .hint { font-size: 12px; color: #999; margin-top: 5px; }
        .btn {
            width: 100%;
            padding: 14px;
            margin-top: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn:hover { box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3); }
        .message { padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.error ul { margin-left: 18px; }
        .requirements { list-style: none; font-size: 14px; }
        .requirements li { padding: 6px 0; color: #555; }
        .ok { color: #2e7d32; font-weight: 600; }
        .bad { color: #c62828; font-weight: 600; }
        .success-box { text-align: center; }
        .success-box h1 { color: #2e7d32; }
        .key-box {
            background: #f4f6ff;
            border: 1px dashed #667eea;
            border-radius: 8px;
            padding: 12px;
            margin: 20px 0;
            font-family: monospace;
            font-size: 15px;
            word-break: break-all;
        }
        .actions { display: flex; gap: 12px; margin-top: 20px; }
        .actions a {
            flex: 1;
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .warning { margin-top: 24px; padding: 12px 15px; background: #fff8e1; color: #8d6e00; border: 1px solid #ffe082; border-radius: 8px; font-size: 14px; text-align: center; }
        code { background: #f0f0f0; padding: 1px 6px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">
<?php if ($locked): ?>
    <h1>Already installed</h1>
    <p class="subtitle">This installation is locked.</p>
    <p style="color:#555; font-size:14px; line-height:1.7;">
        The file <code>install.lock</code> exists, so the installer will not run again.
        To reinstall, delete <code>install.lock</code> (and optionally <code>config.php</code>)
        from the server and reload this page.
    </p>
    <div class="warning">For security, delete <code>install.php</code> from the server.</div>

<?php elseif ($done): ?>
    <div class="success-box">
        <h1>Installation complete</h1>
        <p class="subtitle">Database created, tables installed, config written.</p>

        <p style="color:#555; font-size:14px;">Your access key (also stored in <code>config.php</code>):</p>
        <div class="key-box"><?= e($accessKey) ?></div>

        <div class="actions">
            <a href="index.php?key=<?= urlencode($accessKey) ?>">Open File Manager</a>
            <a href="links.php?key=<?= urlencode($accessKey) ?>">Open Video Backup</a>
        </div>

        <div class="warning">Now delete <code>install.php</code> from the server.</div>
    </div>

<?php else: ?>
    <h1>R2 File Manager &amp; Video Backup</h1>
    <p class="subtitle">One-time setup - creates the database tables and writes config.php</p>

    <h2>Requirements</h2>
    <ul class="requirements">
        <?php foreach ($requirements as $label => $ok): ?>
            <li><span class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '&#10003;' : '&#10007;' ?></span> <?= e($label) ?></li>
        <?php endforeach; ?>
    </ul>

    <?php if ($errors): ?>
        <div class="message error" style="margin-top:20px;">
            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="">
        <h2>Site</h2>
        <div class="form-group">
            <label for="site_title">Site title</label>
            <input type="text" id="site_title" name="site_title" value="<?= old('site_title', 'R2 File Manager') ?>" required>
        </div>
        <div class="form-group">
            <label for="custom_domain">Custom file domain <span class="optional">(optional)</span></label>
            <input type="text" id="custom_domain" name="custom_domain" value="<?= old('custom_domain') ?>" placeholder="https://files.example.com">
            <div class="hint">File links point here. Leave empty to use the bucket endpoint URL.</div>
        </div>
        <div class="form-group">
            <label for="access_key">Access key</label>
            <input type="text" id="access_key" name="access_key" value="<?= old('access_key', $suggestedKey) ?>">
            <div class="hint">Pages open via <code>?key=...</code>. Keep this long and random.</div>
        </div>

        <h2>Database</h2>
        <div class="form-group">
            <label for="db_host">Host</label>
            <input type="text" id="db_host" name="db_host" value="<?= old('db_host', 'localhost') ?>" required>
        </div>
        <div class="form-group">
            <label for="db_user">User</label>
            <input type="text" id="db_user" name="db_user" value="<?= old('db_user') ?>" required>
        </div>
        <div class="form-group">
            <label for="db_password">Password</label>
            <input type="password" id="db_password" name="db_password" value="<?= old('db_password') ?>" autocomplete="off">
        </div>
        <div class="form-group">
            <label for="db_name">Database name</label>
            <input type="text" id="db_name" name="db_name" value="<?= old('db_name') ?>" required>
            <div class="hint">Created automatically if it does not exist and the user has permission.</div>
        </div>

        <h2>First bucket</h2>
        <div class="form-group">
            <label for="bucket_name">Bucket name</label>
            <input type="text" id="bucket_name" name="bucket_name" value="<?= old('bucket_name') ?>" required>
        </div>
        <div class="form-group">
            <label for="endpoint_url">Endpoint URL</label>
            <input type="text" id="endpoint_url" name="endpoint_url" value="<?= old('endpoint_url') ?>" placeholder="https://your-bucket.example.com" required>
        </div>

        <button type="submit" class="btn">Install</button>
    </form>
<?php endif; ?>
</div>
</body>
</html>
