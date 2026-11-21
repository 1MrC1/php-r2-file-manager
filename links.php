<?php
/**
 * Video Backup
 *
 * Simple form + list to catalog video backup links.
 * Configuration lives in config.php (created by install.php).
 */

require __DIR__ . '/bootstrap.php';

require_access();
$config = app_config();

$message = '';
$messageType = '';
$recentRecords = [];

// Database connection
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_password']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Connection failed: ' . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $sid = $_POST['sid'] ?? '';
        $name = $_POST['name'] ?? '';
        $link = $_POST['link'] ?? '';
        $tags = $_POST['tags'] ?? '';
        
        $stmt = $pdo->prepare("INSERT INTO avideobackup (sid, name, link, tags) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sid, $name, $link, $tags]);
        
        $message = 'Video backup added successfully!';
        $messageType = 'success';
        
        // Clear form
        $_POST = array();
        
    } catch (PDOException $e) {
        $message = 'Error: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// Fetch recent records (last 10)
try {
    $stmt = $pdo->query("SELECT * FROM avideobackup ORDER BY creation_time DESC");
    $recentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = 'Error fetching records: ' . $e->getMessage();
    $messageType = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Video Backup</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 40px 20px;
        }
        
        .wrapper {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
        }
        
        @media (min-width: 968px) {
            .wrapper {
                grid-template-columns: 450px 1fr;
            }
        }
        
        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 40px;
            height: fit-content;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
            text-align: center;
        }
        
        .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 14px;
        }
        
        .optional {
            color: #999;
            font-weight: 400;
            font-size: 12px;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: all 0.3s ease;
            font-family: inherit;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        
        .btn:active {
            transform: translateY(0);
        }
        
        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            text-align: center;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .hint {
            font-size: 12px;
            color: #999;
            margin-top: 5px;
        }
        
        /* Recent Records Styles */
        .records-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 30px;
        }
        
        .records-container h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .record-count {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .records-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .record-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid #667eea;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        
        .record-item:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .record-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
            gap: 10px;
        }
        
        .record-id {
            background: #667eea;
            color: white;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .record-time {
            color: #999;
            font-size: 12px;
        }
        
        .record-name {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }
        
        .record-sid {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        
        .record-link {
            font-size: 13px;
            color: #667eea;
            text-decoration: none;
            word-break: break-all;
            display: block;
            margin-bottom: 8px;
        }
        
        .record-link:hover {
            text-decoration: underline;
        }
        
        .record-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 10px;
        }
        
        .tag {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .no-records {
            text-align: center;
            padding: 40px;
            color: #999;
            font-size: 14px;
        }
        
        .no-records::before {
            content: "📭";
            display: block;
            font-size: 48px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Form Section -->
        <div class="container">
            <h1>📹 Video Backup</h1>
            <p class="subtitle">Add new video to backup database</p>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="sid">SID</label>
                    <input type="text" id="sid" name="sid" required placeholder="Enter SID">
                </div>
                
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" required placeholder="Enter video name">
                </div>
                
                <div class="form-group">
                    <label for="link">Link</label>
                    <input type="text" id="link" name="link" required placeholder="https://t.me/c/2759756164/1351">
                    <div class="hint">Enter the full Telegram or video link</div>
                </div>
                
                <div class="form-group">
                    <label for="tags">
                        Tags <span class="optional">(optional)</span>
                    </label>
                    <input type="text" id="tags" name="tags" placeholder="e.g., comedy, tutorial, music">
                    <div class="hint">Separate multiple tags with commas</div>
                </div>
                
                <button type="submit" class="btn">Add Video Backup</button>
            </form>
        </div>
        
        <!-- Recent Records Section -->
        <div class="records-container">
            <h2>
                Recent Records
                <span class="record-count"><?php echo count($recentRecords); ?></span>
            </h2>
            
            <?php if (empty($recentRecords)): ?>
                <div class="no-records">
                    No records found.<br>Add your first video backup!
                </div>
            <?php else: ?>
                <div class="records-list">
                    <?php foreach ($recentRecords as $record): ?>
                        <div class="record-item">
                            <div class="record-header">
                                <span class="record-id">ID: <?php echo htmlspecialchars($record['id']); ?></span>
                                <span class="record-time">
                                    <?php echo date('M d, Y H:i', strtotime($record['creation_time'])); ?>
                                </span>
                            </div>
                            <div class="record-name">
                                <?php echo htmlspecialchars($record['name']); ?>
                            </div>
                            <div class="record-sid">
                                SID: <?php echo htmlspecialchars($record['sid']); ?>
                            </div>
                            <a href="<?php echo htmlspecialchars($record['link']); ?>" 
                               class="record-link" 
                               target="_blank" 
                               rel="noopener noreferrer">
                                <?php echo htmlspecialchars($record['link']); ?>
                            </a>
                            
                            <?php if (!empty($record['tags'])): ?>
                                <div class="record-tags">
                                    <?php 
                                    $tags = array_map('trim', explode(',', $record['tags']));
                                    foreach ($tags as $tag): 
                                        if (!empty($tag)):
                                    ?>
                                        <span class="tag"><?php echo htmlspecialchars($tag); ?></span>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>