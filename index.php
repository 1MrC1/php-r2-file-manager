<?php
/**
 * R2 File Manager
 *
 * Web interface to browse files and directories in your R2 bucket
 * using data from the MySQL database.
 *
 * Configuration lives in config.php (created by install.php).
 */

require __DIR__ . '/bootstrap.php';

require_access();
$config = app_config();

// Connect to the database
try {
    $mysqli = new mysqli(
        $config['db_host'], 
        $config['db_user'], 
        $config['db_password'], 
        $config['db_name']
    );
    
    if ($mysqli->connect_error) {
        throw new Exception("Database connection unavailable.");
    }
    
    // Set UTF-8 character set
    if (!$mysqli->set_charset("utf8mb4")) {
        error_log("Warning: Failed to set UTF-8 character set");
    }
    
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

/**
 * Get the bucket information
 */
function getBucketInfo($mysqli, $bucketId = null) {
    // Browsing needs no object-store credential. Keep the web DB grant scoped
    // to these display/routing columns instead of exposing access_key/secret_key.
    $query = "SELECT bucket_id, bucket_name, endpoint_url FROM buckets";
    
    if ($bucketId) {
        $query .= " WHERE bucket_id = " . (int)$bucketId;
    }
    
    $query .= " ORDER BY bucket_name LIMIT 1";
    
    $result = $mysqli->query($query);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Get all buckets
 */
function getAllBuckets($mysqli) {
    $query = "SELECT bucket_id, bucket_name, endpoint_url FROM buckets ORDER BY bucket_name";
    $result = $mysqli->query($query);
    
    $buckets = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $buckets[] = $row;
        }
    }
    
    return $buckets;
}

/**
 * Get subdirectories for a given path
 */
function getSubdirectories($mysqli, $bucketId, $path) {
    // Escape special characters for SQL LIKE pattern
    $pathEscaped = $mysqli->real_escape_string($path);
    
    $query = "
        SELECT * FROM directories 
        WHERE bucket_id = " . (int)$bucketId . " 
        AND path LIKE '$pathEscaped%'
        AND path != '$pathEscaped'
        AND path NOT LIKE '$pathEscaped%/%/%'
        ORDER BY path
    ";
    
    $result = $mysqli->query($query);
    
    $directories = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Only include direct subdirectories
            $relativePath = substr($row['path'], strlen($path));
            if (substr_count($relativePath, '/') <= 1) {
                $row['name'] = basename(rtrim($row['path'], '/'));
                $directories[] = $row;
            }
        }
    }
    
    return $directories;
}

/**
 * Get files in a directory
 */
function getDirectoryFiles($mysqli, $bucketId, $directoryId, $path, $page = 1, $itemsPerPage = 50) {
    $offset = ($page - 1) * $itemsPerPage;
    
    // Try to get by directory_id first (more efficient)
    if ($directoryId) {
        $query = "
            SELECT * FROM files 
            WHERE bucket_id = " . (int)$bucketId . "
            AND directory_id = " . (int)$directoryId . "
            AND is_active = 1
            ORDER BY object_key
            LIMIT $offset, $itemsPerPage
        ";
    } else {
        // Fallback to path-based lookup if directory_id is not available
        $pathEscaped = $mysqli->real_escape_string($path);
        $pathPrefix = $pathEscaped . '%';
        
        $query = "
            SELECT f.* FROM files f
            JOIN directories d ON f.directory_id = d.directory_id
            WHERE f.bucket_id = " . (int)$bucketId . "
            AND d.path = '$pathEscaped'
            AND f.is_active = 1
            ORDER BY f.object_key
            LIMIT $offset, $itemsPerPage
        ";
    }
    
    $result = $mysqli->query($query);
    
    $files = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $row['name'] = basename($row['object_key']);
            $files[] = $row;
        }
    }
    
    return $files;
}

/**
 * Count total files in a directory
 */
function countDirectoryFiles($mysqli, $bucketId, $directoryId, $path) {
    // Try to get by directory_id first (more efficient)
    if ($directoryId) {
        $query = "
            SELECT COUNT(*) as count FROM files 
            WHERE bucket_id = " . (int)$bucketId . "
            AND directory_id = " . (int)$directoryId . "
            AND is_active = 1
        ";
    } else {
        // Fallback to path-based lookup if directory_id is not available
        $pathEscaped = $mysqli->real_escape_string($path);
        
        $query = "
            SELECT COUNT(*) as count FROM files f
            JOIN directories d ON f.directory_id = d.directory_id
            WHERE f.bucket_id = " . (int)$bucketId . "
            AND d.path = '$pathEscaped'
            AND f.is_active = 1
        ";
    }
    
    $result = $mysqli->query($query);
    
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    
    return 0;
}

/**
 * Get directory information by path
 */
function getDirectoryByPath($mysqli, $bucketId, $path) {
    $pathEscaped = $mysqli->real_escape_string($path);
    
    $query = "
        SELECT * FROM directories 
        WHERE bucket_id = " . (int)$bucketId . " 
        AND path = '$pathEscaped'
        LIMIT 1
    ";
    
    $result = $mysqli->query($query);
    
    if ($result && $result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    return null;
}

/**
 * Build breadcrumb navigation array
 */
function buildBreadcrumbs($path) {
    $breadcrumbs = [
        ['name' => 'Root', 'path' => '']
    ];
    
    if (!empty($path)) {
        $parts = explode('/', rtrim($path, '/'));
        $currentPath = '';
        
        foreach ($parts as $part) {
            if (empty($part)) continue;
            
            $currentPath .= $part . '/';
            $breadcrumbs[] = [
                'name' => $part,
                'path' => $currentPath
            ];
        }
    }
    
    return $breadcrumbs;
}

/**
 * Format file size in human-readable format
 */
function formatSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Format a database datetime, tolerating NULL or invalid values
 */
function formatDate($value) {
    if (empty($value)) return '-';

    $timestamp = strtotime($value);

    return $timestamp === false ? '-' : date('Y-m-d H:i', $timestamp);
}

/**
 * Get file extension
 */
function getFileExtension($filename) {
    $parts = explode('.', strtolower($filename));
    return count($parts) > 1 ? end($parts) : '';
}

/**
 * Get icon class based on file extension
 */
function getFileIconClass($filename) {
    $extension = getFileExtension($filename);
    
    switch ($extension) {
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'gif':
        case 'bmp':
        case 'svg':
            return 'file-image';
            
        case 'mp4':
        case 'avi':
        case 'mov':
        case 'wmv':
        case 'flv':
        case 'webm':
            return 'file-video';
            
        case 'mp3':
        case 'wav':
        case 'ogg':
        case 'flac':
        case 'm4a':
            return 'file-audio';
            
        case 'pdf':
            return 'file-pdf';
            
        case 'doc':
        case 'docx':
            return 'file-word';
            
        case 'xls':
        case 'xlsx':
            return 'file-excel';
            
        case 'ppt':
        case 'pptx':
            return 'file-powerpoint';
            
        case 'zip':
        case 'rar':
        case '7z':
        case 'tar':
        case 'gz':
            return 'file-archive';
            
        case 'txt':
        case 'rtf':
        case 'md':
            return 'file-alt';
            
        case 'html':
        case 'htm':
        case 'css':
        case 'js':
        case 'php':
        case 'json':
        case 'xml':
            return 'file-code';
            
        default:
            return 'file';
    }
}

// Process the request
$bucketId = $_GET['bucket_id'] ?? $config['bucket_id'];
$currentPath = isset($_GET['path']) ? trim($_GET['path']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

// Ensure path ends with a slash if not empty
if (!empty($currentPath) && substr($currentPath, -1) !== '/') {
    $currentPath .= '/';
}

// Get bucket information
$bucketInfo = getBucketInfo($mysqli, $bucketId);
if (!$bucketInfo) {
    die("No bucket found in the database");
}

$bucketId = $bucketInfo['bucket_id'];

// Get all buckets for the dropdown
$allBuckets = getAllBuckets($mysqli);

// Get current directory information
$currentDirectory = getDirectoryByPath($mysqli, $bucketId, $currentPath);
$directoryId = $currentDirectory ? $currentDirectory['directory_id'] : null;

// Get subdirectories
$subdirectories = getSubdirectories($mysqli, $bucketId, $currentPath);

// Get files with pagination
$files = getDirectoryFiles($mysqli, $bucketId, $directoryId, $currentPath, $page, $config['items_per_page']);
$totalFiles = countDirectoryFiles($mysqli, $bucketId, $directoryId, $currentPath);
$totalPages = ceil($totalFiles / $config['items_per_page']);

// Build breadcrumbs
$breadcrumbs = buildBreadcrumbs($currentPath);

// Page header
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($config['page_title']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root {
            --primary-color: #2196F3;
            --hover-color: #1976D2;
            --text-color: #333;
            --light-text: #666;
            --border-color: #e0e0e0;
            --bg-color: #f5f5f5;
            --card-bg: #fff;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--bg-color);
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }
        
        h1 {
            font-size: 24px;
            font-weight: 500;
        }
        
        .bucket-selector {
            display: flex;
            align-items: center;
        }
        
        .bucket-selector select {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-left: 10px;
        }
        
        .breadcrumb {
            display: flex;
            flex-wrap: wrap;
            list-style: none;
            background-color: var(--card-bg);
            border-radius: 4px;
            padding: 10px 15px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .breadcrumb-item {
            display: flex;
            align-items: center;
        }
        
        .breadcrumb-item a {
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
            color: var(--hover-color);
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            content: "/";
            padding: 0 8px;
            color: var(--light-text);
        }
        
        .breadcrumb-item.active {
            color: var(--light-text);
        }
        
        .stats {
            background-color: var(--card-bg);
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stats-row {
            display: flex;
            justify-content: space-between;
        }
        
        .stat-item {
            flex: 1;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--light-text);
            font-size: 14px;
        }
        
        .file-list {
            background-color: var(--card-bg);
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .file-list-header {
            display: grid;
            grid-template-columns: auto 1fr 150px 180px;
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 500;
        }
        
        .file-list-item {
            display: grid;
            grid-template-columns: auto 1fr 150px 180px;
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            align-items: center;
        }
        
        .file-list-item:last-child {
            border-bottom: none;
        }
        
        .file-list-item:hover {
            background-color: rgba(0,0,0,0.02);
        }
        
        .file-icon {
            font-size: 18px;
            width: 30px;
            color: var(--primary-color);
        }
        
        .file-name {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 10px;
        }
        
        .file-name a {
            color: var(--text-color);
            text-decoration: none;
        }
        
        .file-name a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }
        
        .directory-name {
            font-weight: 500;
        }
        
        .file-size, .file-date {
            color: var(--light-text);
            font-size: 14px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }
        
        .pagination a, .pagination span {
            padding: 8px 16px;
            margin: 0 4px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .pagination a:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .pagination span {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .empty-message {
            text-align: center;
            padding: 30px;
            color: var(--light-text);
        }
        
        footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            text-align: center;
            color: var(--light-text);
            font-size: 14px;
        }
        
        @media (max-width: 768px) {
            .file-list-header, .file-list-item {
                grid-template-columns: auto 1fr 80px;
            }
            
            .file-date {
                display: none;
            }
        }
        
        @media (max-width: 480px) {
            .file-list-header, .file-list-item {
                grid-template-columns: auto 1fr;
            }
            
            .file-size, .file-date {
                display: none;
            }
            
            .stats-row {
                flex-direction: column;
            }
            
            .stat-item {
                margin-bottom: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1><?= htmlspecialchars($config['page_title']) ?></h1>
            
            <div class="bucket-selector">
                <span>Bucket:</span>
                <form method="get" id="bucketForm">
                    <input type="hidden" name="path" value="<?= htmlspecialchars($currentPath) ?>">
                    <select name="bucket_id" onchange="document.getElementById('bucketForm').submit();">
                        <?php foreach ($allBuckets as $bucket): ?>
                            <option value="<?= $bucket['bucket_id'] ?>" <?= $bucket['bucket_id'] == $bucketId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($bucket['bucket_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
        </header>
        
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                    <?php if ($index === count($breadcrumbs) - 1): ?>
                        <li class="breadcrumb-item active"><?= htmlspecialchars($crumb['name']) ?></li>
                    <?php else: ?>
                        <li class="breadcrumb-item">
                            <a href="?bucket_id=<?= $bucketId ?>&path=<?= urlencode($crumb['path']) ?>">
                                <?= htmlspecialchars($crumb['name']) ?>
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ol>
        </nav>
        
        <?php if ($currentDirectory): ?>
        <div class="stats">
            <div class="stats-row">
                <div class="stat-item">
                    <div class="stat-value"><?= $currentDirectory['object_count'] ?></div>
                    <div class="stat-label">Files</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= formatSize($currentDirectory['total_size']) ?></div>
                    <div class="stat-label">Total Size</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= count($subdirectories) ?></div>
                    <div class="stat-label">Subdirectories</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= formatDate($currentDirectory['last_modified']) ?></div>
                    <div class="stat-label">Last Modified</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="file-list">
            <div class="file-list-header">
                <div></div>
                <div>Name</div>
                <div>Size</div>
                <div>Last Modified</div>
            </div>
            
            <?php if ($currentPath !== '' && !empty($breadcrumbs)): ?>
                <div class="file-list-item">
                    <div class="file-icon">
                        <i class="fas fa-level-up-alt"></i>
                    </div>
                    <div class="file-name">
                        <?php
                        $parentPath = '';
                        if (count($breadcrumbs) > 1) {
                            $parentCrumb = $breadcrumbs[count($breadcrumbs) - 2];
                            $parentPath = $parentCrumb['path'];
                        }
                        ?>
                        <a href="?bucket_id=<?= $bucketId ?>&path=<?= urlencode($parentPath) ?>">Parent Directory</a>
                    </div>
                    <div class="file-size">-</div>
                    <div class="file-date">-</div>
                </div>
            <?php endif; ?>
            
            <?php foreach ($subdirectories as $directory): ?>
                <div class="file-list-item">
                    <div class="file-icon">
                        <i class="fas fa-folder"></i>
                    </div>
                    <div class="file-name directory-name">
                        <a href="?bucket_id=<?= $bucketId ?>&path=<?= urlencode($directory['path']) ?>">
                            <?= htmlspecialchars($directory['name']) ?>
                        </a>
                    </div>
                    <div class="file-size"><?= formatSize($directory['total_size']) ?></div>
                    <div class="file-date"><?= formatDate($directory['last_modified']) ?></div>
                </div>
            <?php endforeach; ?>
            
            <?php foreach ($files as $file): ?>
                <div class="file-list-item">
                    <div class="file-icon">
                        <i class="fas fa-<?= getFileIconClass($file['name']) ?>"></i>
                    </div>
                    <div class="file-name">
                        <?php
                        // Determine file URL based on config
                        if (!empty($config['custom_domain'])) {
                            $fileUrl = rtrim($config['custom_domain'], '/')  . '/' . $file['object_key'];
                        } else {
                            $fileUrl = $bucketInfo['endpoint_url']  . '/' . $file['object_key'];
                        }
                        ?>
                        <a href="<?= htmlspecialchars($fileUrl) ?>" target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars($file['name']) ?>
                        </a>
                    </div>
                    <div class="file-size"><?= formatSize($file['size']) ?></div>
                    <div class="file-date"><?= formatDate($file['last_modified']) ?></div>
                </div>
            <?php endforeach; ?>
            
            <?php if (empty($subdirectories) && empty($files)): ?>
                <div class="empty-message">
                    This directory is empty
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?bucket_id=<?= $bucketId ?>&path=<?= urlencode($currentPath) ?>&page=<?= $page - 1 ?>">Previous</a>
                <?php endif; ?>
                
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                if ($startPage > 1) {
                    echo '<a href="?bucket_id=' . $bucketId . '&path=' . urlencode($currentPath) . '&page=1">1</a>';
                    if ($startPage > 2) {
                        echo '<span>...</span>';
                    }
                }
                
                for ($i = $startPage; $i <= $endPage; $i++) {
                    if ($i == $page) {
                        echo '<span>' . $i . '</span>';
                    } else {
                        echo '<a href="?bucket_id=' . $bucketId . '&path=' . urlencode($currentPath) . '&page=' . $i . '">' . $i . '</a>';
                    }
                }
                
                if ($endPage < $totalPages) {
                    if ($endPage < $totalPages - 1) {
                        echo '<span>...</span>';
                    }
                    echo '<a href="?bucket_id=' . $bucketId . '&path=' . urlencode($currentPath) . '&page=' . $totalPages . '">' . $totalPages . '</a>';
                }
                ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?bucket_id=<?= $bucketId ?>&path=<?= urlencode($currentPath) ?>&page=<?= $page + 1 ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <footer>
            <p><?= htmlspecialchars($config['page_title']) ?> | Bucket: <?= htmlspecialchars($bucketInfo['bucket_name']) ?></p>
            <p>Data last updated: <?= date('Y-m-d H:i:s') ?></p>
        </footer>
    </div>
</body>
</html>
