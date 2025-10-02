<?php
// 确保中文正常显示
header('Content-Type: text/html; charset=utf-8');

// 确保数据目录存在且可写
$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0777, true)) {
        die('无法创建数据目录，请检查权限');
    }
}

// 数据库文件路径
$dbPath = $dataDir . '/data.db';
$dbExists = file_exists($dbPath);

// 初始化数据库连接
$db = new SQLite3($dbPath);
if (!$db) {
    die('无法连接到数据库，请检查权限');
}

// 如果数据库文件是新建的，则初始化表结构
if (!$dbExists) {
    // 创建表结构
    $db->exec('CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        content TEXT,
        user_agent TEXT,
        ip_address TEXT,
        created_at DATETIME,
        text_expire DATETIME,
        image_expire DATETIME,
        file_expire DATETIME
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        filename TEXT,
        original_name TEXT,
        size INTEGER,
        created_at DATETIME,
        FOREIGN KEY (post_id) REFERENCES posts (id)
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS files (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER,
        filename TEXT,
        original_name TEXT,
        size INTEGER,
        created_at DATETIME,
        FOREIGN KEY (post_id) REFERENCES posts (id)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS settings (
        name TEXT PRIMARY KEY,
        value TEXT
    )');

    // 设置默认值
    $defaultSettings = [
        'items_per_page' => 10,
        'show_user_info' => 1,
        'text_expire_days' => 0,
        'image_expire_days' => 0,
        'file_expire_days' => 0,
        'max_image_size' => 0,
        'max_file_size' => 0
    ];

    $stmt = $db->prepare('INSERT INTO settings (name, value) VALUES (:name, :value)');
    foreach ($defaultSettings as $name => $value) {
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
    }
}

// 设置默认值
$settings = [
    'items_per_page' => 10,
    'show_user_info' => 1,
    'text_expire_days' => 0,
    'image_expire_days' => 0,
    'file_expire_days' => 0,
    'max_image_size' => 0,
    'max_file_size' => 0
];

// 从数据库加载设置
$query = $db->query('SELECT name, value FROM settings');
while ($row = $query->fetchArray(SQLITE3_ASSOC)) {
    $settings[$row['name']] = $row['value'];
}

// 清除过期内容
$currentTime = date('Y-m-d H:i:s');

// 清除过期文本
if ($settings['text_expire_days'] > 0) {
    $expireTime = date('Y-m-d H:i:s', strtotime("-$settings[text_expire_days] days"));
    $db->exec("UPDATE posts SET content = NULL WHERE created_at < '$expireTime' AND content IS NOT NULL");
}

// 清除过期图片
if ($settings['image_expire_days'] > 0) {
    $expireTime = date('Y-m-d H:i:s', strtotime("-$settings[image_expire_days] days"));
    $imageQuery = $db->query("SELECT id, filename FROM images WHERE created_at < '$expireTime'");
    while ($image = $imageQuery->fetchArray(SQLITE3_ASSOC)) {
        if (file_exists('pic/' . $image['filename'])) {
            unlink('pic/' . $image['filename']);
        }
        $db->exec("DELETE FROM images WHERE id = {$image['id']}");
    }
}

// 清除过期文件
if ($settings['file_expire_days'] > 0) {
    $expireTime = date('Y-m-d H:i:s', strtotime("-$settings[file_expire_days] days"));
    $fileQuery = $db->query("SELECT id, filename FROM files WHERE created_at < '$expireTime'");
    while ($file = $fileQuery->fetchArray(SQLITE3_ASSOC)) {
        if (file_exists('file/' . $file['filename'])) {
            unlink('file/' . $file['filename']);
        }
        $db->exec("DELETE FROM files WHERE id = {$file['id']}");
    }
}

// 删除空帖子
$db->exec("DELETE FROM posts WHERE id NOT IN (SELECT DISTINCT post_id FROM images)
                                     AND id NOT IN (SELECT DISTINCT post_id FROM files)
                                     AND (content IS NULL OR content = '')");

// 处理发布请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $content = isset($_POST['content']) ? $_POST['content'] : '';
    $userAgent = $_SERVER['HTTP_USER_AGENT'];
    $createdAt = date('Y-m-d H:i:s');

    // 优先从常见代理头获取真实 IP，没有则使用 REMOTE_ADDR
    $ipAddress =
        $_SERVER['HTTP_CF_CONNECTING_IP'] ??  // Cloudflare 专用头
        $_SERVER['HTTP_X_REAL_IP'] ??         // Nginx 常用头
        ($_SERVER['HTTP_X_FORWARDED_FOR'] ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : null) ??  // 取第一个 IP
        $_SERVER['REMOTE_ADDR'] ??            // 回退到 REMOTE_ADDR
        'unknown';                            // 如果都没有，设置为 unknown

    // 简单清理：移除可能的空格
    $ipAddress = trim($ipAddress);

    // 插入帖子
    $stmt = $db->prepare('INSERT INTO posts (content, user_agent, ip_address, created_at, text_expire, image_expire, file_expire) VALUES (:content, :user_agent, :ip_address, :created_at, :text_expire, :image_expire, :file_expire)');
    $stmt->bindValue(':content', $content, SQLITE3_TEXT);
    $stmt->bindValue(':user_agent', $userAgent, SQLITE3_TEXT);
    $stmt->bindValue(':ip_address', $ipAddress, SQLITE3_TEXT);
    $stmt->bindValue(':created_at', $createdAt, SQLITE3_TEXT);

    // 设置过期时间
    $textExpire = $settings['text_expire_days'] > 0 ? date('Y-m-d H:i:s', strtotime("+$settings[text_expire_days] days")) : null;
    $imageExpire = $settings['image_expire_days'] > 0 ? date('Y-m-d H:i:s', strtotime("+$settings[image_expire_days] days")) : null;
    $fileExpire = $settings['file_expire_days'] > 0 ? date('Y-m-d H:i:s', strtotime("+$settings[file_expire_days] days")) : null;

    $stmt->bindValue(':text_expire', $textExpire, $textExpire ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->bindValue(':image_expire', $imageExpire, $imageExpire ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->bindValue(':file_expire', $fileExpire, $fileExpire ? SQLITE3_TEXT : SQLITE3_NULL);
    $stmt->execute();

    $postId = $db->lastInsertRowID();

    // 处理上传的图片
    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                // 检查图片大小
                if ($settings['max_image_size'] > 0 && $_FILES['images']['size'][$key] > $settings['max_image_size'] * 1024 * 1024) {
                    continue; // 跳过过大的图片
                }

                $originalName = $_FILES['images']['name'][$key];
                $safeFilename = uniqid() . '_' . $originalName;
                $safeFilename = preg_replace('/[^a-zA-Z0-9_\.\-\p{Han}]/u', '', $safeFilename);
                $destination = 'pic/' . $safeFilename;

                if (!is_dir('pic')) {
                    mkdir('pic', 0777, true);
                }

                if (move_uploaded_file($tmpName, $destination)) {
                    // 获取文件大小（字节）
                    $fileSize = $_FILES['images']['size'][$key];

                    // 存储文件信息（新增 size 字段）
                    $stmt = $db->prepare('INSERT INTO images (post_id, filename, original_name, size, created_at) VALUES (:post_id, :filename, :original_name, :size, :created_at)');
                    $stmt->bindValue(':post_id', $postId, SQLITE3_INTEGER);
                    $stmt->bindValue(':filename', $safeFilename, SQLITE3_TEXT);
                    $stmt->bindValue(':original_name', $originalName, SQLITE3_TEXT);
                    $stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
                    $stmt->bindValue(':created_at', $createdAt, SQLITE3_TEXT);
                    $stmt->execute();
                }
            }
        }
    }

    // 处理上传的文件
    if (!empty($_FILES['files']['name'][0])) {
        foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
            if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
                // 检查文件大小
                if ($settings['max_file_size'] > 0 && $_FILES['files']['size'][$key] > $settings['max_file_size'] * 1024 * 1024) {
                    continue; // 跳过过大的文件
                }

                $originalName = $_FILES['files']['name'][$key];
                $safeFilename = uniqid() . '_' . $originalName;
                $safeFilename = preg_replace('/[^a-zA-Z0-9_\.\-\p{Han}]/u', '', $safeFilename);
                $destination = 'file/' . $safeFilename;

                if (!is_dir('file')) {
                    mkdir('file', 0777, true);
                }

                if (move_uploaded_file($tmpName, $destination)) {
                    // 获取文件大小（字节）
                    $fileSize = $_FILES['files']['size'][$key];

                    // 存储文件信息（新增 size 字段）
                    $stmt = $db->prepare('INSERT INTO files (post_id, filename, original_name, size, created_at) VALUES (:post_id, :filename, :original_name, :size, :created_at)');
                    $stmt->bindValue(':post_id', $postId, SQLITE3_INTEGER);
                    $stmt->bindValue(':filename', $safeFilename, SQLITE3_TEXT);
                    $stmt->bindValue(':original_name', $originalName, SQLITE3_TEXT);
                    $stmt->bindValue(':size', $fileSize, SQLITE3_INTEGER);
                    $stmt->bindValue(':created_at', $createdAt, SQLITE3_TEXT);
                    $stmt->execute();
                }
            }
        }
    }

    header('Location: index.php');
    exit;
}

// 处理删除请求
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $postId = (int)$_GET['id'];

    // 删除相关图片
    $imageQuery = $db->query("SELECT filename FROM images WHERE post_id = $postId");
    while ($image = $imageQuery->fetchArray(SQLITE3_ASSOC)) {
        if (file_exists('pic/' . $image['filename'])) {
            unlink('pic/' . $image['filename']);
        }
    }
    $db->exec("DELETE FROM images WHERE post_id = $postId");

    // 删除相关文件
    $fileQuery = $db->query("SELECT filename FROM files WHERE post_id = $postId");
    while ($file = $fileQuery->fetchArray(SQLITE3_ASSOC)) {
        if (file_exists('file/' . $file['filename'])) {
            unlink('file/' . $file['filename']);
        }
    }
    $db->exec("DELETE FROM files WHERE post_id = $postId");

    // 删除帖子
    $db->exec("DELETE FROM posts WHERE id = $postId");

    header('Location: index.php');
    exit;
}

// 获取总帖子数
$totalPostsQuery = $db->query('SELECT COUNT(*) as count FROM posts');
$totalPosts = $totalPostsQuery->fetchArray(SQLITE3_ASSOC)['count'];

// 计算总页数
$itemsPerPage = (int)$settings['items_per_page'];
$totalPages = ceil($totalPosts / $itemsPerPage);

// 当前页码
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$currentPage = max(1, min($currentPage, $totalPages));

// 计算偏移量
$offset = ($currentPage - 1) * $itemsPerPage;

// 获取当前页的帖子
$postsQuery = $db->query("SELECT * FROM posts ORDER BY created_at DESC LIMIT $itemsPerPage OFFSET $offset");
$posts = [];
while ($post = $postsQuery->fetchArray(SQLITE3_ASSOC)) {
    // 获取相关图片
    $imagesQuery = $db->query("SELECT * FROM images WHERE post_id = {$post['id']}");
    $images = [];
    while ($image = $imagesQuery->fetchArray(SQLITE3_ASSOC)) {
        $images[] = $image;
    }

    // 获取相关文件
    $filesQuery = $db->query("SELECT * FROM files WHERE post_id = {$post['id']}");
    $files = [];
    while ($file = $filesQuery->fetchArray(SQLITE3_ASSOC)) {
        $files[] = $file;
    }

    $posts[] = [
        'post' => $post,
        'images' => $images,
        'files' => $files
    ];
}

function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';

    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes) / log(1024));

    return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>公共交流区</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3B82F6',
                        secondary: '#10B981',
                        danger: '#EF4444',
                        warning: '#F59E0B',
                        dark: '#1F2937',
                        light: '#F3F4F6'
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                    },
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .upload-container {
                @apply border-2 border-dashed border-gray-300 rounded-lg p-4 transition-all duration-300 hover:border-primary hover:bg-blue-50;
            }
            .upload-container.drag-over {
                @apply border-primary bg-blue-100;
            }
            .post-card {
                @apply bg-white rounded-xl shadow-md overflow-hidden transition-all duration-300 hover:shadow-lg mb-6;
            }
            .btn {
                @apply px-4 py-2 rounded-md font-medium transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2;
            }
            .btn-primary {
                @apply bg-primary text-white hover:bg-blue-600 focus:ring-primary;
            }
            .btn-danger {
                @apply bg-danger text-white hover:bg-red-600 focus:ring-danger;
            }
            .btn-outline {
                @apply border border-gray-300 text-gray-700 hover:bg-gray-50 focus:ring-gray-300;
            }
            .form-input {
                @apply w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200;
            }
            .toast {
                @apply fixed top-4 right-4 px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-500 opacity-0 translate-y-[-20px];
            }
            .toast.show {
                @apply opacity-100 translate-y-0;
            }
            .toast-success {
                @apply bg-green-500 text-white;
            }
            .toast-error {
                @apply bg-red-500 text-white;
            }
            .pagination-item {
                @apply px-4 py-2 border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 transition-colors duration-200;
            }
            .pagination-item.active {
                @apply bg-primary text-white border-primary;
            }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        <header class="mb-8">
            <h1 class="text-[clamp(1.75rem,4vw,2.5rem)] font-bold text-dark mb-2">公共交流区</h1>
            <p class="text-gray-600 mb-6">分享你的想法、图片和文件</p>
            <div class="flex justify-between items-center">
                <span class="text-sm text-gray-500">
                    当前共有 <strong><?= $totalPosts ?></strong> 条消息
                </span>
            </div>
        </header>

        <!-- 发布区域 -->
        <section class="post-card mb-10">
            <div class="p-6">
                <h2 class="text-xl font-semibold mb-4">发布新内容</h2>

                <form id="postForm" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <!-- 文本输入区域 -->
                    <div>
                        <label for="content" class="block text-sm font-medium text-gray-700 mb-2">文本内容</label>
                        <textarea id="content" name="content" rows="4" class="form-input resize-y" placeholder="输入你的文本内容..."></textarea>
                        <p id="word-count" class="mt-2 text-sm text-gray-500">0 个字</p>
                    </div>

                    <!-- 图片上传区域 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">上传图片</label>
                        <div id="imageUploadContainer" class="upload-container">
                            <div class="flex flex-col items-center justify-center py-6">
                                <i class="fa fa-cloud-upload text-3xl text-gray-400 mb-2"></i>
                                <p class="mb-2 text-sm text-gray-500">拖放图片到这里，或 <span class="font-medium text-primary cursor-pointer">浏览文件</span></p>
                                <p class="text-xs text-gray-500">支持 JPG, PNG, GIF 等格式</p>
                                <input type="file" id="imageUpload" name="images[]" multiple accept="image/*" class="hidden">
                            </div>
                            <div id="imagePreview" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mt-4 hidden"></div>
                        </div>
                        <?php if ($settings['max_image_size'] > 0): ?>
                        <p class="mt-2 text-sm text-gray-500">图片大小限制: <strong><?= $settings['max_image_size'] ?> MB</strong></p>
                        <?php endif; ?>
                    </div>

                    <!-- 文件上传区域 -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">上传文件</label>
                        <div id="fileUploadContainer" class="upload-container">
                            <div class="flex flex-col items-center justify-center py-6">
                                <i class="fa fa-file-text-o text-3xl text-gray-400 mb-2"></i>
                                <p class="mb-2 text-sm text-gray-500">拖放文件到这里，或 <span class="font-medium text-primary cursor-pointer">浏览文件</span></p>
                                <p class="text-xs text-gray-500">支持所有文件格式</p>
                                <input type="file" id="fileUpload" name="files[]" multiple class="hidden">
                            </div>
                            <div id="filePreview" class="space-y-2 mt-4 hidden"></div>
                        </div>
                        <?php if ($settings['max_file_size'] > 0): ?>
                        <p class="mt-2 text-sm text-gray-500">文件大小限制: <strong><?= $settings['max_file_size'] ?> MB</strong></p>
                        <?php endif; ?>
                    </div>

                    <!-- 发布按钮 -->
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary flex items-center gap-2">
                            <i class="fa fa-paper-plane"></i> 发布
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <!-- 展示区域 -->
        <section>
            <h2 class="text-xl font-semibold mb-6">最新消息</h2>

            <!-- 分页控制 -->
            <div class="flex justify-between items-center mb-6">
                <div class="text-sm text-gray-500">
                    显示第 <span class="font-medium"><?= ($currentPage - 1) * $itemsPerPage + 1 ?></span>
                    至 <span class="font-medium"><?= min($currentPage * $itemsPerPage, $totalPosts) ?></span>
                    条，共 <span class="font-medium"><?= $totalPosts ?></span> 条
                </div>

                <div class="flex space-x-1">
                    <?php if ($currentPage > 1): ?>
                    <a href="?page=1" class="pagination-item rounded-l-lg">
                        <i class="fa fa-angle-double-left"></i>
                    </a>
                    <a href="?page=<?= $currentPage - 1 ?>" class="pagination-item">
                        <i class="fa fa-angle-left"></i>
                    </a>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $currentPage - 2);
                    $endPage = min($totalPages, $startPage + 4);
                    if ($endPage - $startPage < 4 && $startPage > 1) {
                        $startPage = max(1, $endPage - 4);
                    }

                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <a href="?page=<?= $i ?>" class="pagination-item <?= $i == $currentPage ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                    <a href="?page=<?= $currentPage + 1 ?>" class="pagination-item">
                        <i class="fa fa-angle-right"></i>
                    </a>
                    <a href="?page=<?= $totalPages ?>" class="pagination-item rounded-r-lg">
                        <i class="fa fa-angle-double-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 帖子列表 -->
            <div class="space-y-6">
                <?php if (empty($posts)): ?>
                <div class="post-card p-6 text-center">
                    <i class="fa fa-inbox text-4xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500">暂无内容，或所有消息已经过期！</p>
                </div>
                <?php endif; ?>

                <?php foreach ($posts as $item): ?>
                <div class="post-card">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <div class="text-sm text-gray-500">
                                    <span class="font-medium"><?= date('Y-m-d H:i:s', strtotime($item['post']['created_at'])) ?></span>
                                    <?php if ($settings['show_user_info']): ?>
                                    <span class="mx-2">|</span>
                                    <span class="text-gray-600">
                                        <?php
                                        // 解析User-Agent获取浏览器和系统信息
                                        $userAgent = $item['post']['user_agent'];

                                        preg_match('/(Chrome|Firefox|Safari|Edge|MSIE|Trident|Opera)[\/\s]([\d.]+)/', $userAgent, $browserMatches);
                                        $browser = !empty($browserMatches[1]) ? $browserMatches[1] : '未知浏览器';
                                        $version = !empty($browserMatches[2]) ? $browserMatches[2] : '';

                                        preg_match('/(Windows|Macintosh|Linux|Android|iPhone|iPad)/', $userAgent, $osMatches);
                                        $os = !empty($osMatches[0]) ? $osMatches[0] : '未知系统';
                                        ?>
                                        <i class="fa fa-user-circle-o mr-1"></i>
                                        <?= $browser ?> (<?= $version ?>) / <?= $os ?>
                                    </span>
                                    <span class="mx-2">|</span>
                                    <span class="text-gray-600">
                                        <i class="fa fa-globe mr-1"></i>
                                        <?= $item['post']['ip_address'] ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex space-x-2">
                                <button class="btn btn-outline text-sm" onclick="copyAllContent(<?= $item['post']['id'] ?>)">
                                    <i class="fa fa-copy"></i> 复制全部
                                </button>
                                <button class="btn btn-danger text-sm" onclick="confirmDelete(<?= $item['post']['id'] ?>)">
                                    <i class="fa fa-trash"></i> 删除
                                </button>
                            </div>
                        </div>

                        <!-- 文件展示 -->
                        <?php if (!empty($item['files'])): ?>
                        <div class="mb-4">
                            <h3 class="text-sm font-medium text-gray-700 mb-2">附件</h3>
                            <div class="space-y-2">
                                <?php foreach ($item['files'] as $file): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg overflow-hidden">
                                    <div class="flex items-center gap-3 flex-grow overflow-hidden min-w-0">
                                        <i class="fa fa-file-o text-gray-400 text-xl flex-shrink-0"></i>
                                        <div class="min-w-0 flex-grow">
                                            <p class="font-medium text-gray-900 truncate whitespace-nowrap overflow-hidden"><?= htmlspecialchars($file['original_name']) ?></p>
                                            <p class="text-xs text-gray-500">
                                                <?= formatFileSize($file['size']) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <a href="file/<?= $file['filename'] ?>" download="<?= htmlspecialchars($file['original_name']) ?>" class="btn btn-outline text-sm flex-shrink-0 ml-2">
                                        <i class="fa fa-download"></i> 下载
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 图片展示 -->
                        <?php if (!empty($item['images'])): ?>
                        <div class="mb-4">
                            <h3 class="text-sm font-medium text-gray-700 mb-2">图片</h3>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                                <?php foreach ($item['images'] as $image): ?>
                                <div class="relative group">
                                    <img src="pic/<?= $image['filename'] ?>" alt="<?= htmlspecialchars($image['original_name']) ?>" class="w-full h-32 object-cover rounded-lg">
                                    <div class="absolute inset-0 bg-black bg-opacity-50 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 rounded-lg">
                                        <button class="btn btn-primary text-sm mb-2 w-24" onclick="copyImage('pic/<?= $image['filename'] ?>')">
                                            <i class="fa fa-copy"></i> 复制图片
                                        </button>
                                        <a href="pic/<?= $image['filename'] ?>" download="<?= htmlspecialchars($image['original_name']) ?>" class="btn btn-primary text-sm mb-2 w-24">
                                            <i class="fa fa-save"></i> 存储图片
                                        </a>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- 文本展示 -->
                        <?php if (!empty($item['post']['content'])): ?>
                        <div>
                            <h3 class="text-sm font-medium text-gray-700 mb-2">文本内容</h3>
                            <div class="bg-gray-50 rounded-lg p-4 max-h-64 overflow-auto">
                                <pre id="content-<?= $item['post']['id'] ?>" class="whitespace-pre text-gray-800 font-normal text-sm"><?= htmlspecialchars($item['post']['content']) ?></pre>
                            </div>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <button class="btn btn-outline text-sm" onclick="copyText('<?= $item['post']['id'] ?>')">
                                    <i class="fa fa-copy"></i> 复制文本
                                </button>
                                <button class="btn btn-outline text-sm" onclick="parseUrls('<?= $item['post']['id'] ?>')">
                                    <i class="fa fa-link"></i> 解析网址
                                </button>
                            </div>
                            <div id="urls-container-<?= $item['post']['id'] ?>" class="mt-3 hidden">
                                <h4 class="text-sm font-medium text-gray-700 mb-1">提取的网址</h4>
                                <div id="urls-list-<?= $item['post']['id'] ?>" class="bg-gray-50 rounded-lg p-3 text-sm max-h-48 overflow-auto">
                                    <!-- 网址列表将在这里动态生成 -->
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- 分页控制（重复一次，用于底部） -->
            <div class="flex justify-between items-center mt-8">
                <div class="text-sm text-gray-500">
                    显示第 <span class="font-medium"><?= ($currentPage - 1) * $itemsPerPage + 1 ?></span>
                    至 <span class="font-medium"><?= min($currentPage * $itemsPerPage, $totalPosts) ?></span>
                    条，共 <span class="font-medium"><?= $totalPosts ?></span> 条
                </div>

                <div class="flex space-x-1">
                    <?php if ($currentPage > 1): ?>
                    <a href="?page=1" class="pagination-item rounded-l-lg">
                        <i class="fa fa-angle-double-left"></i>
                    </a>
                    <a href="?page=<?= $currentPage - 1 ?>" class="pagination-item">
                        <i class="fa fa-angle-left"></i>
                    </a>
                    <?php endif; ?>

                    <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                    <a href="?page=<?= $i ?>" class="pagination-item <?= $i == $currentPage ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                    <?php endfor; ?>

                    <?php if ($currentPage < $totalPages): ?>
                    <a href="?page=<?= $currentPage + 1 ?>" class="pagination-item">
                        <i class="fa fa-angle-right"></i>
                    </a>
                    <a href="?page=<?= $totalPages ?>" class="pagination-item rounded-r-lg">
                        <i class="fa fa-angle-double-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- 页脚 -->
        <footer class="mt-12 text-center text-sm text-gray-500">
            <p>© 2025 公共交流区</p>
            <p class="mt-2">总访问量: <span class="font-medium"><?= $totalPosts ?></span> 条消息</p>
        </footer>
    </div>

    <!-- 通知组件 -->
    <div id="toast" class="toast">
        <span id="toastMessage"></span>
    </div>

    <script>
        // 文件上传预览功能
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('content');
            const wordCountElement = document.getElementById('word-count');

            // 初始检查和设置事件监听
            updateWordCount();
            textarea.addEventListener('input', updateWordCount);

            function updateWordCount() {
                // 获取文本内容并计算长度（包括中文）
                const content = textarea.value;
                const count = content.length;

                // 更新计数显示
                wordCountElement.textContent = `${count} 个字`;

                // // 可选：根据字数添加视觉反馈
                // if (count > 500) {
                //     wordCountElement.classList.add('text-red-500');
                // } else {
                //     wordCountElement.classList.remove('text-red-500');
                // }
            }

            // 存储已选择的文件对象
            let selectedImages = [];
            let selectedFiles = [];

            // 图片上传相关
            const imageUpload = document.getElementById('imageUpload');
            const imageUploadContainer = document.getElementById('imageUploadContainer');
            const imagePreview = document.getElementById('imagePreview');

            // 文件上传相关
            const fileUpload = document.getElementById('fileUpload');
            const fileUploadContainer = document.getElementById('fileUploadContainer');
            const filePreview = document.getElementById('filePreview');

            // 图片上传点击事件
            document.querySelector('#imageUploadContainer .font-medium').addEventListener('click', function() {
                imageUpload.click();
            });

            // 文件上传点击事件
            document.querySelector('#fileUploadContainer .font-medium').addEventListener('click', function() {
                fileUpload.click();
            });

            // 图片上传预览 - 追加模式
            imageUpload.addEventListener('change', function() {
                if (this.files.length > 0) {
                    imagePreview.classList.remove('hidden');

                    // 去重并追加文件
                    const newFiles = Array.from(this.files).filter(file =>
                        !selectedImages.some(img => img.name === file.name)
                    );

                    newFiles.forEach(file => {
                        selectedImages.push(file);
                        renderImagePreview(file);
                    });
                }
            });

            // 文件上传预览 - 追加模式
            fileUpload.addEventListener('change', function() {
                if (this.files.length > 0) {
                    filePreview.classList.remove('hidden');

                    // 去重并追加文件
                    const newFiles = Array.from(this.files).filter(file =>
                        !selectedFiles.some(f => f.name === file.name)
                    );

                    newFiles.forEach(file => {
                        selectedFiles.push(file);
                        renderFilePreview(file);
                    });
                }
            });

            // 渲染图片预览
            function renderImagePreview(file) {
                const container = document.createElement('div');
                container.className = 'relative group';
                container.dataset.filename = file.name;

                const img = document.createElement('img');
                img.src = URL.createObjectURL(file);
                img.alt = file.name;
                img.className = 'w-full h-32 object-cover rounded-lg';
                    
                // 图片加载完成后释放URL对象
                img.onload = function() {
                    URL.revokeObjectURL(img.src);
                };

                const overlay = document.createElement('div');
                overlay.className = 'absolute inset-0 bg-black bg-opacity-50 flex flex-col items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity duration-200 rounded-lg';

                const saveBtn = document.createElement('button');
                saveBtn.className = 'btn btn-primary text-sm w-24';
                saveBtn.innerHTML = '<i class="fa fa-trash"></i> 移除';
                saveBtn.onclick = function(e) {
                    e.stopPropagation();
                    // 从数组中移除文件
                    selectedImages = selectedImages.filter(img => img.name !== file.name);
                    container.remove();
                    if (imagePreview.children.length === 0) {
                        imagePreview.classList.add('hidden');
                    }
                };

                overlay.appendChild(saveBtn);
                container.appendChild(img);
                container.appendChild(overlay);
                imagePreview.appendChild(container);
            }

            // 渲染文件预览
            function renderFilePreview(file) {
                const container = document.createElement('div');
                container.className = 'flex items-center justify-between p-3 bg-gray-50 rounded-lg overflow-hidden';
                container.dataset.filename = file.name;

                const leftDiv = document.createElement('div');
                leftDiv.className = 'flex items-center gap-3 flex-grow overflow-hidden min-w-0';

                const icon = document.createElement('i');
                icon.className = 'fa fa-file-o text-gray-400 text-xl flex-shrink-0';

                const infoDiv = document.createElement('div');
                infoDiv.className = 'min-w-0 flex-grow';

                const name = document.createElement('p');
                name.className = 'font-medium text-gray-900 truncate whitespace-nowrap overflow-hidden';
                name.textContent = file.name;

                const size = document.createElement('p');
                size.className = 'text-xs text-gray-500';
                size.textContent = formatFileSize(file.size);

                infoDiv.appendChild(name);
                infoDiv.appendChild(size);

                leftDiv.appendChild(icon);
                leftDiv.appendChild(infoDiv);

                const removeBtn = document.createElement('button');
                removeBtn.className = 'btn btn-outline text-sm flex-shrink-0 ml-2';
                removeBtn.innerHTML = '<i class="fa fa-times"></i> 移除';
                removeBtn.onclick = function() {
                    // 从数组中移除文件
                    selectedFiles = selectedFiles.filter(f => f.name !== file.name);
                    container.remove();
                    if (filePreview.children.length === 0) {
                        filePreview.classList.add('hidden');
                    }
                };

                container.appendChild(leftDiv);
                container.appendChild(removeBtn);
                filePreview.appendChild(container);
            }

            // 拖放功能 - 优化为追加模式
            function setupDragDrop(container, input, fileArray) {
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    container.addEventListener(eventName, preventDefaults, false);
                });

                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                ['dragenter', 'dragover'].forEach(eventName => {
                    container.addEventListener(eventName, highlight, false);
                });

                ['dragleave', 'drop'].forEach(eventName => {
                    container.addEventListener(eventName, unhighlight, false);
                });

                function highlight() {
                    container.classList.add('drag-over');
                }

                function unhighlight() {
                    container.classList.remove('drag-over');
                }

                container.addEventListener('drop', handleDrop.bind(null, input, fileArray), false);
            }

            function handleDrop(input, fileArray, e) {
                const dt = e.dataTransfer;
                const newFiles = Array.from(dt.files);

                if (newFiles.length > 0) {
                    // 去重处理
                    const uniqueFiles = newFiles.filter(file =>
                        !fileArray.some(f => f.name === file.name)
                    );

                    // 添加到文件数组
                    uniqueFiles.forEach(file => fileArray.push(file));

                    // 更新预览
                    if (input.id === 'imageUpload') {
                        imagePreview.classList.remove('hidden');
                        uniqueFiles.forEach(file => renderImagePreview(file));
                    } else if (input.id === 'fileUpload') {
                        filePreview.classList.remove('hidden');
                        uniqueFiles.forEach(file => renderFilePreview(file));
                    }
                }
            }

            setupDragDrop(imageUploadContainer, imageUpload, selectedImages);
            setupDragDrop(fileUploadContainer, fileUpload, selectedFiles);

            // 文件大小格式化
            function formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';

                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));

                return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
            }

            // 表单提交处理
            document.getElementById('postForm').addEventListener('submit', function(e) {
                // 创建临时 DataTransfer 对象
                const imageDataTransfer = new DataTransfer();
                const fileDataTransfer = new DataTransfer();

                // 添加我们管理的文件到 DataTransfer
                selectedImages.forEach(file => imageDataTransfer.items.add(file));
                selectedFiles.forEach(file => fileDataTransfer.items.add(file));

                // 更新文件输入控件的 files 属性
                document.getElementById('imageUpload').files = imageDataTransfer.files;
                document.getElementById('fileUpload').files = fileDataTransfer.files;

                // 表单验证（使用我们管理的文件数组）
                const content = document.getElementById('content').value.trim();
                const hasImages = selectedImages.length > 0;
                const hasFiles = selectedFiles.length > 0;

                if (!content && !hasImages && !hasFiles) {
                    e.preventDefault();
                    showToast('请至少输入文本内容、上传图片或文件中的一项', 'error');
                }
            });
        });

        // 复制文本功能（增强版：自动回退到传统方法）
        function copyText(postId) {
            const preElement = document.getElementById(`content-${postId}`);
            const textToCopy = preElement.innerText;

            // 首先尝试使用现代 Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy)
                    .then(() => showToast('文本复制成功！'))
                    .catch(() => {
                        // 现代方法失败，尝试传统方法
                        fallbackCopyText(textToCopy);
                    });
            } else {
                // 不支持现代 API，直接使用传统方法
                fallbackCopyText(textToCopy);
            }
        }

        // 传统复制方法（使用 textarea 和 execCommand）
        function fallbackCopyText(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;
            textArea.style.position = "fixed";
            textArea.style.opacity = "0";
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showToast('文本复制成功！');
                } else {
                    throw new Error('execCommand 复制失败');
                }
            } catch (err) {
                console.error('复制失败:', err);
                showToast('复制失败，请手动复制', 'error');
            } finally {
                document.body.removeChild(textArea);
            }
        }

        // 复制图片功能
        function copyImage(imagePath) {
            // 查找当前点击按钮对应的图片元素和覆盖图层
            const button = event.currentTarget;
            const overlay = button.closest('.absolute');
            const img = overlay.previousElementSibling;

            fetch(imagePath)
                .then(response => response.blob())
                .then(blob => {
                    const item = new ClipboardItem({ 'image/png': blob });
                    navigator.clipboard.write([item]).then(function() {
                        showToast('图片复制成功！');
                    }).catch(function(err) {
                        handleCopyFailure();
                    });
                })
                .catch(err => {
                    handleCopyFailure();
                });

            // 处理复制失败的共用逻辑
            function handleCopyFailure() {
                // 隐藏覆盖图层
                overlay.style.display = 'none';

                // 显示提示
                showToast('复制失败，请在图片上右键选择"复制图片"（图片上按钮已隐去，稍后自动恢复）', 'error');

                // 5秒后恢复覆盖图层显示
                setTimeout(() => {
                    overlay.style.display = '';
                }, 5000);
            }
        }

        // 复制全部内容
        function copyAllContent(postId) {
            // 收集所有内容
            let content = '';
            const postCard = document.querySelector(`[onclick="copyAllContent(${postId})"]`).closest('.post-card');

            // 添加时间和用户信息
            const info = postCard.querySelector('.text-sm.text-gray-500').innerText.trim();
            content += info + '\n\n';

            // 添加文本内容
            const textContent = postCard.querySelector('pre');
            if (textContent) {
                content += '【文本内容】\n' + textContent.innerText + '\n\n';
            }

            // 添加图片链接
            const images = postCard.querySelectorAll('img');
            if (images.length > 0) {
                content += '【图片】\n';
                images.forEach(img => {
                    content += window.location.origin + '/' + img.src + '\n';
                });
                content += '\n';
            }

            // 添加文件链接
            const files = postCard.querySelectorAll('.fa-download');
            if (files.length > 0) {
                content += '【文件】\n';
                files.forEach(file => {
                    const link = file.closest('a');
                    content += window.location.origin + '/' + link.href + ' (' + link.previousElementSibling.innerText.replace(/\n/g, ' ').trim() + ')\n';
                });
            }

            // 复制到剪贴板

            // 首先尝试使用现代 Clipboard API
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(content).then(function() {
                    showToast('全部内容复制成功！');
                }).catch(function(err) {
                    // 不支持现代 API，直接使用传统方法
                    fallbackCopyText(content);
                });
            } else {
                // 不支持现代 API，直接使用传统方法
                fallbackCopyText(content);
            }
        }

        function parseUrls(postId) {
            const contentElement = document.getElementById(`content-${postId}`);
            const content = contentElement.textContent || contentElement.innerText;

            // 使用正则表达式提取URL
            const urlRegex = /https?:\/\/[^\s<>"']+/g;
            const urls = content.match(urlRegex) || [];

            const urlsListElement = document.getElementById(`urls-list-${postId}`);
            const containerElement = document.getElementById(`urls-container-${postId}`);

            // 清空现有列表
            urlsListElement.innerHTML = '';

            if (urls.length === 0) {
                urlsListElement.innerHTML = '<div class="text-gray-500 italic">未找到网址</div>';
            } else {
                // 创建URL链接列表
                urls.forEach(url => {
                    const urlElement = document.createElement('div');
                    urlElement.className = 'mb-1';

                    const linkElement = document.createElement('a');
                    linkElement.href = url;
                    linkElement.textContent = url;
                    linkElement.target = '_blank';
                    linkElement.className = 'text-blue-600 hover:underline break-all';

                    urlElement.appendChild(linkElement);
                    urlsListElement.appendChild(urlElement);
                });
            }

            // 显示URL容器
            containerElement.classList.remove('hidden');
        }

        // 确认删除
        function confirmDelete(postId) {
            if (confirm('确定要删除这条消息吗？此操作不可撤销！')) {
                window.location.href = `index.php?action=delete&id=${postId}`;
            }
        }

        // 显示提示消息
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');

            toastMessage.textContent = message;
            toast.className = `toast ${type === 'success' ? 'toast-success' : 'toast-error'}`;

            // 显示提示
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);

            // 自动隐藏
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        }

    </script>
    <script src="/floating-ball/load-floating-ball.js"></script>
</body>
</html>
