<?php
// =================================================================================
// CẤU HÌNH & THIẾT LẬP
// =================================================================================
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M'); // Tăng bộ nhớ để xử lý chuỗi lớn

// Tắt bộ đệm để SSE hoạt động mượt mà
if (function_exists('apache_setenv')) {
  @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);

// File lưu trữ bookmark & Temp folder
$bookmarkFile = __DIR__ . '/saved_paths.json';
$tempDir = __DIR__ . '/temp_docs'; // Nơi lưu file tạm

// --- DANH SÁCH LOẠI TRỪ (EXCLUSIONS) ---
const EXCLUDED_DIRS = [
  'node_modules',
  '.next',
  'vendor',
  '.git',
  '.idea',
  '.vscode',
  'public',
  'dist',
  'build',
  'out',
  'storage',
  'coverage',
  '__pycache__',
  'tmp',
  'temp',
  'logs'
];

const EXCLUDED_FILES = [
  '.env',
  '.env.local',
  '.env.example',
  '.env.production', // Sensitive
  'package-lock.json',
  'composer.lock',
  'yarn.lock',
  'pnpm-lock.yaml',
  '.DS_Store',
  'Thumbs.db',
  'desktop.ini',
  'tsconfig.tsbuildinfo', // Build info garbage
  'mix-manifest.json',
  'manifest.json'
];

const BINARY_EXTENSIONS = [
  'png',
  'jpg',
  'jpeg',
  'gif',
  'bmp',
  'svg',
  'webp',
  'ico',
  'tif',
  'tiff',
  'mp3',
  'wav',
  'ogg',
  'mp4',
  'mov',
  'avi',
  'webm',
  'mkv',
  'pdf',
  'doc',
  'docx',
  'xls',
  'xlsx',
  'ppt',
  'pptx',
  'zip',
  'rar',
  '7z',
  'tar',
  'gz',
  'iso',
  'ttf',
  'otf',
  'woff',
  'woff2',
  'eot',
  'exe',
  'dll',
  'so',
  'dylib',
  'class',
  'jar',
  'phar',
  'bin',
  'obj',
  'pyc'
];

// =================================================================================
// ROUTING & XỬ LÝ REQUEST
// =================================================================================

$action = $_GET['action'] ?? '';

// 1. API: Lấy danh sách Bookmark
if ($action === 'get_bookmarks') {
  json_response(get_bookmarks());
}

// 2. API: Lưu Bookmark
if ($action === 'save_bookmark') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (!empty($data['path'])) {
    $bookmarks = get_bookmarks();
    $id = uniqid();
    $bookmarks[$id] = [
      'id' => $id,
      'path' => clean_path($data['path']),
      'note' => $data['note'] ?? '',
      'created_at' => date('Y-m-d H:i:s')
    ];
    save_bookmarks($bookmarks);
    json_response(['status' => 'success', 'data' => $bookmarks]);
  }
  json_response(['status' => 'error', 'message' => 'Path is required']);
}

// 3. API: Xóa Bookmark
if ($action === 'delete_bookmark') {
  $data = json_decode(file_get_contents('php://input'), true);
  if (!empty($data['id'])) {
    $bookmarks = get_bookmarks();
    if (isset($bookmarks[$data['id']])) {
      unset($bookmarks[$data['id']]);
      save_bookmarks($bookmarks);
    }
    json_response(['status' => 'success', 'data' => $bookmarks]);
  }
  json_response(['status' => 'error']);
}

// 4. API: Lấy Structure Tree (JSON)
if ($action === 'get_structure') {
  $path = clean_path($_GET['path'] ?? '');
  if (!is_dir($path)) json_response(['status' => 'error', 'message' => 'Đường dẫn không hợp lệ.']);

  try {
    $files = scan_project_files($path);
    $treeString = '';
    generate_directory_tree($path, $files, $treeString);
    json_response(['status' => 'success', 'data' => $treeString, 'count' => count($files)]);
  } catch (Exception $e) {
    json_response(['status' => 'error', 'message' => $e->getMessage()]);
  }
}

// 5. API: Lấy nội dung file đã generate (cho Preview)
if ($action === 'get_content') {
  $filename = basename($_GET['file']);
  $filePath = $tempDir . '/' . $filename;
  if (file_exists($filePath)) {
    echo file_get_contents($filePath);
  } else {
    echo "File không tồn tại hoặc đã bị xóa.";
  }
  exit;
}

// 6. SSE: Generate Full Docs
if ($action === 'generate') {
  handle_generation_request();
  exit;
}

// 7. Download File
if (isset($_GET['download'])) {
  handle_download_request();
  exit;
}

// =================================================================================
// LOGIC CHÍNH
// =================================================================================

function clean_path($path)
{
  return trim(urldecode($path), " \"'\t\n\r\0\x0B");
}

function get_bookmarks()
{
  global $bookmarkFile;
  if (!file_exists($bookmarkFile)) return [];
  return json_decode(file_get_contents($bookmarkFile), true) ?? [];
}

function save_bookmarks($data)
{
  global $bookmarkFile;
  file_put_contents($bookmarkFile, json_encode($data, JSON_PRETTY_PRINT));
}

function json_response($data)
{
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}

function handle_generation_request()
{
  global $tempDir;
  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  header('Connection: keep-alive');

  $projectPath = clean_path($_GET['path'] ?? '');

  try {
    if (!is_dir($projectPath)) throw new Exception("Đường dẫn không tồn tại.");

    // Tạo thư mục temp nếu chưa có
    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

    // Dọn dẹp file cũ trong temp (để tiết kiệm dung lượng)
    $oldFiles = glob($tempDir . '/docs_*.md');
    foreach ($oldFiles as $f) {
      if (is_file($f) && (time() - filemtime($f) > 3600)) { // Xóa file cũ hơn 1 tiếng
        unlink($f);
      }
    }

    send_sse('log', '🚀 Bắt đầu quét file...');
    $files = scan_project_files($projectPath);
    $totalFiles = count($files);

    if ($totalFiles === 0) throw new Exception("Không tìm thấy file nào hợp lệ.");

    send_sse('log', "📦 Tìm thấy {$totalFiles} file. Đang xử lý...");

    // --- BIẾN ĐẾM DÒNG CODE ---
    $totalLines = 0;

    // Tạo nội dung Markdown
    $projectName = basename($projectPath);
    $markdown = "# Tài liệu dự án: " . $projectName . "\n\n";
    $markdown .= "Ngày tạo: " . date('Y-m-d H:i:s') . "\n";

    // Sử dụng Placeholder {{TOTAL_LINES}} để điền sau khi đếm xong
    $markdown .= "Thống kê: " . $totalFiles . " files | {{TOTAL_LINES}} dòng code\n\n";

    // Phần 1: Cấu trúc cây
    $treeString = '';
    generate_directory_tree($projectPath, $files, $treeString);
    $markdown .= "## 🌳 Cấu trúc thư mục\n\n```text\n" . $treeString . "```\n\n";

    // Phần 2: Nội dung chi tiết
    $markdown .= "## 📄 Nội dung chi tiết\n\n";

    foreach ($files as $index => $filePath) {
      $processedCount = $index + 1;
      $relativePath = ltrim(str_replace(str_replace('\\', '/', $projectPath), '', str_replace('\\', '/', $filePath)), '/');

      // SSE Progress
      if ($processedCount % 5 == 0 || $processedCount == $totalFiles) {
        $percent = round(($processedCount / $totalFiles) * 100);
        send_sse('progress', "Đang đọc: $relativePath", ['percent' => $percent]);
      }

      $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
      $markdown .= "### `{$relativePath}`\n\n";

      if (in_array($ext, BINARY_EXTENSIONS)) {
        $markdown .= "> _[File Binary/Media - Không hiển thị nội dung]_\n\n";
      } else {
        $content = @file_get_contents($filePath);

        // --- LOGIC ĐẾM DÒNG ---
        if ($content !== false) {
          // Nếu file không rỗng, đếm số ký tự xuống dòng + 1
          $linesInFile = empty($content) ? 0 : substr_count($content, "\n") + 1;
          $totalLines += $linesInFile;
        }

        if ($content === false) {
          $markdown .= "> _[Lỗi đọc file]_\n\n";
        } elseif (strlen($content) > 512 * 1024) { // > 512KB
          $markdown .= "> _[File quá lớn (>512KB) - Đã ẩn nội dung]_\n\n";
        } else {
          if (empty($ext)) $ext = 'text';
          $markdown .= "```{$ext}\n" . $content . "\n```\n\n";
        }
      }
    }

    // --- THAY THẾ PLACEHOLDER BẰNG SỐ LIỆU THỰC ---
    $formattedLines = number_format($totalLines, 0, '.', ','); // Ví dụ: 1,234
    $markdown = str_replace('{{TOTAL_LINES}}', $formattedLines, $markdown);

    // Lưu file vào thư mục temp của PHP
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $projectName);
    $fileName = 'docs_' . $safeName . '_' . date('Ymd_His') . '.md';
    $tempFilePath = $tempDir . '/' . $fileName;

    if (file_put_contents($tempFilePath, $markdown) === false) {
      throw new Exception("Lỗi ghi file tạm.");
    }

    // Trả về tên file để client tải hoặc preview
    send_sse('complete', $fileName, ['total' => $totalFiles]);
  } catch (Throwable $e) {
    send_sse('error', $e->getMessage());
  }
}

function scan_project_files($dir)
{
  $results = [];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
      function ($current, $key, $iterator) {
        $filename = $current->getFilename();
        if ($current->isDir()) return !in_array($filename, EXCLUDED_DIRS);
        if ($current->isFile()) return !in_array($filename, EXCLUDED_FILES);
        return true;
      }
    ),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $file) {
    if ($file->isFile()) $results[] = $file->getPathname();
  }
  sort($results);
  return $results;
}

function generate_directory_tree($rootPath, $files, &$treeString)
{
  $rootPath = str_replace('\\', '/', $rootPath);
  $structure = [];
  foreach ($files as $file) {
    $file = str_replace('\\', '/', $file);
    $relativePath = ltrim(str_replace($rootPath, '', $file), '/');
    $parts = explode('/', $relativePath);
    $current = &$structure;
    foreach ($parts as $part) {
      if (!isset($current[$part])) $current[$part] = [];
      $current = &$current[$part];
    }
  }
  $treeString .= basename($rootPath) . "/\n";
  print_tree($structure, $treeString);
}

function print_tree($structure, &$output, $prefix = '')
{
  $keys = array_keys($structure);
  $lastIndex = count($keys) - 1;
  foreach ($keys as $index => $key) {
    $isLast = ($index === $lastIndex);
    $marker = $isLast ? '└── ' : '├── ';
    $subPrefix = $isLast ? '    ' : '│   ';
    $output .= $prefix . $marker . $key . "\n";
    if (!empty($structure[$key])) print_tree($structure[$key], $output, $prefix . $subPrefix);
  }
}

function send_sse($event, $message, $data = [])
{
  echo "event: $event\n";
  echo "data: " . json_encode(array_merge(['message' => $message], $data)) . "\n\n";
  if (ob_get_level() > 0) ob_flush();
  flush();
}

function handle_download_request()
{
  global $tempDir;
  $fileName = basename($_GET['download']);
  $filePath = $tempDir . '/' . $fileName;

  if (file_exists($filePath)) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
  } else {
    die("File không tồn tại hoặc đã hết hạn.");
  }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Smart Docs Generator</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #2563eb;
      --primary-hover: #1d4ed8;
      --bg: #f8fafc;
      --surface: #ffffff;
      --text: #0f172a;
      --text-sec: #64748b;
      --border: #e2e8f0;
      --success: #10b981;
      --error: #ef4444;
      --code-bg: #1e293b;
      --code-text: #e2e8f0;
    }

    * {
      box-sizing: border-box;
    }

    body {
      font-family: 'Inter', sans-serif;
      background: var(--bg);
      color: var(--text);
      margin: 0;
      height: 100vh;
      display: flex;
      overflow: hidden;
    }

    /* SIDEBAR */
    .sidebar {
      width: 320px;
      background: var(--surface);
      border-right: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      padding: 20px;
      flex-shrink: 0;
    }

    .brand {
      font-size: 1.25rem;
      font-weight: 700;
      color: var(--primary);
      margin-bottom: 20px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* FORM */
    .input-group {
      margin-bottom: 15px;
    }

    .input-group label {
      display: block;
      font-size: 0.85rem;
      font-weight: 600;
      margin-bottom: 6px;
      color: var(--text-sec);
    }

    .input-control {
      width: 100%;
      padding: 10px;
      border: 1px solid var(--border);
      border-radius: 6px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.9rem;
      transition: 0.2s;
    }

    .input-control:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .btn {
      width: 100%;
      padding: 10px;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      cursor: pointer;
      transition: 0.2s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-size: 0.95rem;
    }

    .btn-primary {
      background: var(--primary);
      color: white;
    }

    .btn-primary:hover:not(:disabled) {
      background: var(--primary-hover);
    }

    .btn-secondary {
      background: #f1f5f9;
      color: var(--text);
      border: 1px solid var(--border);
    }

    .btn-secondary:hover {
      background: #e2e8f0;
    }

    .btn-outline {
      background: transparent;
      border: 1px solid var(--border);
      color: var(--text-sec);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: wait;
    }

    /* BOOKMARKS */
    .bookmarks-area {
      flex: 1;
      overflow-y: auto;
      margin-top: 20px;
      border-top: 1px solid var(--border);
      padding-top: 15px;
    }

    .bm-title {
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--text-sec);
      font-weight: 700;
      margin-bottom: 10px;
    }

    .bm-item {
      background: #f8fafc;
      border: 1px solid var(--border);
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 8px;
      transition: 0.2s;
      position: relative;
    }

    .bm-item:hover {
      border-color: var(--primary);
      background: #eff6ff;
    }

    .bm-name {
      font-weight: 600;
      font-size: 0.9rem;
      margin-bottom: 4px;
    }

    .bm-path {
      font-family: 'JetBrains Mono';
      font-size: 0.75rem;
      color: var(--text-sec);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .bm-del {
      position: absolute;
      top: 8px;
      right: 8px;
      background: none;
      border: none;
      color: #cbd5e1;
      cursor: pointer;
      font-size: 1.2rem;
      line-height: 0.5;
      padding: 5px;
    }

    .bm-del:hover {
      color: var(--error);
    }

    .bm-item:hover .bm-del {
      display: block;
    }

    /* MAIN CONTENT */
    .main {
      flex: 1;
      display: flex;
      flex-direction: column;
      padding: 20px;
      overflow: hidden;
      position: relative;
    }

    /* PREVIEW AREA */
    .preview-container {
      flex: 1;
      display: flex;
      flex-direction: column;
      background: var(--surface);
      border-radius: 12px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      overflow: hidden;
      border: 1px solid var(--border);
    }

    .preview-header {
      padding: 12px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #fff;
    }

    .preview-title {
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .badge {
      font-size: 0.75rem;
      padding: 2px 8px;
      border-radius: 99px;
      background: #e2e8f0;
      color: var(--text-sec);
    }

    .editor-wrapper {
      flex: 1;
      position: relative;
      background: var(--code-bg);
    }

    textarea.code-editor {
      width: 100%;
      height: 100%;
      border: none;
      padding: 20px;
      resize: none;
      background: var(--code-bg);
      color: var(--code-text);
      font-family: 'JetBrains Mono', monospace;
      font-size: 13px;
      line-height: 1.6;
      outline: none;
    }

    /* ACTIONS BAR */
    .actions {
      display: flex;
      gap: 10px;
    }

    .toast {
      position: fixed;
      top: 20px;
      right: 20px;
      background: var(--code-bg);
      color: white;
      padding: 10px 20px;
      border-radius: 6px;
      box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
      opacity: 0;
      transition: 0.3s;
      transform: translateY(-10px);
      z-index: 100;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .toast.show {
      opacity: 1;
      transform: translateY(0);
    }

    /* PROGRESS & LOGS OVERLAY */
    .overlay-status {
      position: absolute;
      bottom: 30px;
      left: 30px;
      right: 30px;
      background: rgba(255, 255, 255, 0.95);
      backdrop-filter: blur(5px);
      padding: 15px;
      border-radius: 8px;
      border: 1px solid var(--border);
      box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
      display: none;
      z-index: 50;
    }

    .progress-bar {
      height: 6px;
      background: #e2e8f0;
      border-radius: 3px;
      overflow: hidden;
      margin-bottom: 10px;
    }

    .progress-fill {
      height: 100%;
      background: var(--primary);
      width: 0%;
      transition: width 0.2s;
    }

    .log-text {
      font-family: 'JetBrains Mono';
      font-size: 0.8rem;
      color: var(--text-sec);
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* MODAL (Structure) */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      z-index: 999;
      justify-content: center;
      align-items: center;
    }

    .modal-box {
      background: white;
      width: 800px;
      height: 80vh;
      border-radius: 12px;
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .modal-body {
      flex: 1;
      padding: 20px;
      overflow: auto;
      background: #f8fafc;
      font-family: 'JetBrains Mono';
      font-size: 13px;
      white-space: pre;
    }

    .modal-head {
      padding: 15px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 600;
    }
  </style>
</head>

<body>

  <!-- LEFT SIDEBAR -->
  <div class="sidebar">
    <div class="brand">🚀 Docs Generator</div>

    <div class="input-group">
      <label>📂 Project Path</label>
      <input type="text" id="path-input" class="input-control" placeholder="C:\laragon\www\my-project" onblur="cleanInput(this)">
    </div>

    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
      <button class="btn btn-secondary" onclick="getStructure()">🌳 Structure</button>
      <button class="btn btn-primary" id="btn-gen" onclick="startGenerate()">⚡ Generate</button>
    </div>

    <div class="input-group" style="margin-top: auto; border-top: 1px dashed var(--border); padding-top: 15px;">
      <label>🔖 Bookmark Note</label>
      <div style="display: flex; gap: 5px;">
        <input type="text" id="bm-note" class="input-control" placeholder="Tên dự án...">
        <button class="btn btn-secondary" style="width: auto;" onclick="addBookmark()">+</button>
      </div>
    </div>

    <div class="bookmarks-area">
      <div class="bm-title">Saved Paths</div>
      <div id="bm-list"></div>
    </div>
  </div>

  <!-- MAIN AREA -->
  <div class="main">
    <div class="preview-container">
      <div class="preview-header">
        <div class="preview-title">
          📄 Preview <span class="badge" id="file-badge">Empty</span>
        </div>
        <div class="actions">
          <button class="btn btn-outline btn-sm" onclick="copyContent()" id="btn-copy" disabled>📋 Copy Markdown</button>
          <a href="#" id="btn-download" class="btn btn-primary btn-sm" style="text-decoration: none; pointer-events: none; opacity: 0.6;">⬇️ Download .MD</a>
        </div>
      </div>
      <div class="editor-wrapper">
        <textarea id="editor" class="code-editor" readonly placeholder="Nội dung docs sẽ hiện ở đây..."></textarea>
      </div>
    </div>

    <!-- STATUS OVERLAY -->
    <div class="overlay-status" id="status-panel">
      <div style="display: flex; justify-content: space-between; margin-bottom: 5px; font-weight: 600; font-size: 0.9rem;">
        <span>Generating...</span>
        <span id="percent-text">0%</span>
      </div>
      <div class="progress-bar">
        <div class="progress-fill" id="progress-fill"></div>
      </div>
      <div class="log-text" id="log-text">Initializing...</div>
    </div>
  </div>

  <!-- TOAST -->
  <div class="toast" id="toast">✅ Copied to clipboard!</div>

  <!-- MODAL STRUCTURE -->
  <div class="modal" id="modal-struct">
    <div class="modal-box">
      <div class="modal-head">
        <span>🌳 Project Structure</span>
        <div style="display: flex; gap: 10px;">
          <button class="btn btn-secondary btn-sm" style="width: auto" onclick="copyStructure()">📋 Copy Raw</button>
          <button class="btn btn-outline btn-sm" style="width: auto" onclick="closeModal()">✕</button>
        </div>
      </div>
      <div class="modal-body" id="struct-content"></div>
    </div>
  </div>

  <script>
    // DOM Elements
    const dom = {
      path: document.getElementById('path-input'),
      note: document.getElementById('bm-note'),
      bmList: document.getElementById('bm-list'),
      editor: document.getElementById('editor'),
      btnGen: document.getElementById('btn-gen'),
      statusPanel: document.getElementById('status-panel'),
      pFill: document.getElementById('progress-fill'),
      pText: document.getElementById('percent-text'),
      logText: document.getElementById('log-text'),
      btnCopy: document.getElementById('btn-copy'),
      btnDl: document.getElementById('btn-download'),
      badge: document.getElementById('file-badge'),
      modal: document.getElementById('modal-struct'),
      structContent: document.getElementById('struct-content'),
      toast: document.getElementById('toast')
    };

    // --- UTILS ---
    function cleanInput(el) {
      // Tự động xóa dấu ngoặc kép khi paste
      el.value = el.value.replace(/^["']+|["']+$/g, '').trim();
    }

    function showToast(msg) {
      dom.toast.innerText = msg;
      dom.toast.classList.add('show');
      setTimeout(() => dom.toast.classList.remove('show'), 2000);
    }

    // --- BOOKMARK LOGIC ---
    window.onload = loadBookmarks;

    async function loadBookmarks() {
      const res = await fetch('?action=get_bookmarks');
      const data = await res.json();
      dom.bmList.innerHTML = '';
      const items = Object.values(data).sort((a, b) => b.created_at.localeCompare(a.created_at));

      if (items.length === 0) dom.bmList.innerHTML = '<div style="color:#94a3b8; font-size:0.8rem; text-align:center;">Trống</div>';

      items.forEach(bm => {
        const div = document.createElement('div');
        div.className = 'bm-item';
        div.innerHTML = `
                <div class="bm-name" onclick="setPath('${encodeURIComponent(bm.path)}')">${bm.note || 'No Name'}</div>
                <div class="bm-path" title="${bm.path}">${bm.path}</div>
                <button class="bm-del" onclick="delBookmark('${bm.id}')">×</button>
            `;
        dom.bmList.appendChild(div);
      });
    }

    function setPath(path) {
      dom.path.value = decodeURIComponent(path);
      // Visual feedback
      dom.path.focus();
      dom.path.style.borderColor = 'var(--primary)';
      setTimeout(() => dom.path.style.borderColor = '', 500);
    }

    async function addBookmark() {
      cleanInput(dom.path);
      if (!dom.path.value) return alert('Nhập Path trước!');
      await fetch('?action=save_bookmark', {
        method: 'POST',
        body: JSON.stringify({
          path: dom.path.value,
          note: dom.note.value
        })
      });
      dom.note.value = '';
      loadBookmarks();
    }

    async function delBookmark(id) {
      if (!confirm('Xóa bookmark này?')) return;
      await fetch('?action=delete_bookmark', {
        method: 'POST',
        body: JSON.stringify({
          id
        })
      });
      loadBookmarks();
    }

    // --- STRUCTURE LOGIC ---
    async function getStructure() {
      cleanInput(dom.path);
      if (!dom.path.value) return alert('Nhập đường dẫn!');

      dom.structContent.innerText = 'Đang quét...';
      dom.modal.style.display = 'flex';

      try {
        const res = await fetch(`?action=get_structure&path=${encodeURIComponent(dom.path.value)}`);
        const json = await res.json();
        if (json.status === 'success') {
          dom.structContent.innerText = json.data;
        } else {
          dom.structContent.innerText = 'Lỗi: ' + json.message;
        }
      } catch (e) {
        dom.structContent.innerText = 'Lỗi kết nối server.';
      }
    }

    function copyStructure() {
      navigator.clipboard.writeText(dom.structContent.innerText);
      showToast('✅ Đã copy Structure!');
    }

    function closeModal() {
      dom.modal.style.display = 'none';
    }

    // --- GENERATE LOGIC (SSE) ---
    function startGenerate() {
      cleanInput(dom.path);
      if (!dom.path.value) return alert('Nhập đường dẫn!');

      // Reset UI
      dom.editor.value = '';
      dom.statusPanel.style.display = 'block';
      dom.btnGen.disabled = true;
      dom.btnCopy.disabled = true;
      dom.btnDl.style.pointerEvents = 'none';
      dom.btnDl.style.opacity = '0.6';
      dom.badge.innerText = 'Generating...';

      const es = new EventSource(`?action=generate&path=${encodeURIComponent(dom.path.value)}`);

      es.addEventListener('log', e => {
        const d = JSON.parse(e.data);
        dom.logText.innerText = d.message;
      });

      es.addEventListener('progress', e => {
        const d = JSON.parse(e.data);
        dom.pFill.style.width = d.percent + '%';
        dom.pText.innerText = d.percent + '%';
        dom.logText.innerText = d.message;
      });

      es.addEventListener('complete', e => {
        const d = JSON.parse(e.data);
        const fileName = d.message; // Tên file temp

        dom.pFill.style.width = '100%';
        dom.pText.innerText = '100%';
        dom.logText.innerText = 'Hoàn tất! Đang tải nội dung...';

        // Fetch nội dung file về editor
        fetch(`?action=get_content&file=${fileName}`)
          .then(res => res.text())
          .then(text => {
            dom.editor.value = text;
            dom.badge.innerText = `${d.total} files`;

            // Kích hoạt nút download & copy
            dom.btnCopy.disabled = false;
            dom.btnDl.href = `?download=${fileName}`;
            dom.btnDl.style.pointerEvents = 'auto';
            dom.btnDl.style.opacity = '1';

            // Ẩn panel status sau 1s
            setTimeout(() => dom.statusPanel.style.display = 'none', 1000);
          });

        es.close();
        dom.btnGen.disabled = false;
      });

      es.addEventListener('error', e => {
        const d = JSON.parse(e.data);
        alert('Lỗi: ' + d.message);
        es.close();
        dom.btnGen.disabled = false;
        dom.statusPanel.style.display = 'none';
      });

      es.onerror = () => {
        es.close();
        dom.btnGen.disabled = false;
      };
    }

    function copyContent() {
      // Copy toàn bộ nội dung trong editor (đã là Markdown)
      dom.editor.select();
      document.execCommand('copy');
      window.getSelection().removeAllRanges();
      showToast('✅ Đã copy toàn bộ Docs!');
    }

    // Close modal on outside click
    window.onclick = e => {
      if (e.target == dom.modal) closeModal();
    }
  </script>
</body>

</html>