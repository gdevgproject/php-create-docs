<?php
// --- THIẾT LẬP MÔI TRƯỜNG VÀ XỬ LÝ LỖI ---
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$php_error_log_file = __DIR__ . '/php_error.log';
ini_set('error_log', $php_error_log_file);

// Tắt bộ đệm đầu ra
@ini_set('zlib.output_compression', 0);
if (function_exists('apache_setenv')) {
  @apache_setenv('no-gzip', 1);
}
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);

// File log
$log_file = __DIR__ . '/docs_generator.log';

function write_log($message)
{
  global $log_file;
  file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

// --- CẤU HÌNH ---
const EXCLUDED_DIRS = ['node_modules', '.next', 'vendor', '.git', 'public', 'dist', 'build', 'storage', '.idea', '.vscode'];
const EXCLUDED_FILES = ['.env', '.env.local', 'package-lock.json', 'composer.lock', '.DS_Store', 'yarn.lock'];
const EXCLUDED_EXTENSIONS = [
  'png',
  'jpg',
  'jpeg',
  'gif',
  'bmp',
  'svg',
  'webp',
  'ico',
  'mp3',
  'wav',
  'ogg',
  'mp4',
  'mov',
  'avi',
  'webm',
  'pdf',
  'doc',
  'docx',
  'xls',
  'xlsx',
  'zip',
  'rar',
  '7z',
  'ttf',
  'otf',
  'woff',
  'woff2',
  'eot',
  'phar',
  'exe',
  'dll'
];
$resultsDir = __DIR__ . '/generated_docs';

// --- ROUTING ---
// 1. Generate Full Docs (SSE)
if (isset($_GET['action']) && $_GET['action'] == 'generate' && isset($_GET['path'])) {
  handle_generation_request();
  exit;
}
// 2. Download File
if (isset($_GET['download'])) {
  handle_download_request();
  exit;
}
// 3. Get Structure Only (AJAX JSON)
if (isset($_GET['action']) && $_GET['action'] == 'get_structure' && isset($_GET['path'])) {
  handle_structure_request();
  exit;
}

// --- LOGIC CHÍNH ---

// Xử lý lấy cây thư mục (Structure Only)
function handle_structure_request()
{
  header('Content-Type: application/json');
  try {
    $projectPath = rtrim(urldecode($_GET['path']), '\\/');
    if (!is_dir($projectPath)) throw new Exception("Đường dẫn không hợp lệ.");

    $filesToProcess = get_files_to_process($projectPath);
    $treeString = '';
    generate_directory_tree($projectPath, $filesToProcess, $treeString);

    echo json_encode([
      'status' => 'success',
      'data' => $treeString,
      'count' => count($filesToProcess)
    ]);
  } catch (Throwable $e) {
    echo json_encode([
      'status' => 'error',
      'message' => $e->getMessage()
    ]);
  }
  exit;
}

// Xử lý tạo Full Docs (SSE)
function handle_generation_request()
{
  global $resultsDir;
  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  header('Connection: keep-alive');

  try {
    write_log("================== NEW FULL REQUEST ==================");
    $projectPath = rtrim(urldecode($_GET['path']), '\\/');

    if (!is_dir($projectPath)) throw new Exception("Đường dẫn không hợp lệ.");
    if (!is_dir($resultsDir)) mkdir($resultsDir, 0777, true);

    send_sse_message('log', 'Bước 1: Quét file...');
    $filesToProcess = get_files_to_process($projectPath);
    $totalFiles = count($filesToProcess);

    if ($totalFiles === 0) throw new Exception("Không tìm thấy file hợp lệ.");

    send_sse_message('log', "Tìm thấy {$totalFiles} file. Đang xử lý...");
    $markdownContent = generate_markdown($projectPath, $filesToProcess);

    $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', basename($projectPath));
    $outputFilename = 'docs_' . $safeFilename . '_' . date('YmdHis') . '.md';
    $outputFilePath = $resultsDir . '/' . $outputFilename;

    file_put_contents($outputFilePath, $markdownContent);
    send_sse_message('complete', basename($outputFilePath), ['total' => $totalFiles]);
  } catch (Throwable $e) {
    write_log("Error: " . $e->getMessage());
    send_sse_message('error', $e->getMessage());
  }
}

function handle_download_request()
{
  global $resultsDir;
  $fileName = basename($_GET['download']);
  $filePath = $resultsDir . '/' . $fileName;

  if (file_exists($filePath)) {
    header('Content-Type: text/markdown');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    readfile($filePath);
    exit;
  }
  http_response_code(404);
  die('File not found.');
}

function send_sse_message($event, $data, $extra = [])
{
  echo "event: {$event}\n";
  echo "data: " . json_encode(array_merge(['message' => $data], $extra)) . "\n\n";
  if (ob_get_level() > 0) ob_flush();
  flush();
}

function get_files_to_process($dirPath)
{
  $fileList = [];
  $directoryIterator = new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
  $iterator = new RecursiveIteratorIterator(
    new class($directoryIterator) extends RecursiveFilterIterator {
      public function accept(): bool
      {
        $current = $this->current();
        if ($current->isDir() && in_array($current->getFilename(), EXCLUDED_DIRS)) return false;
        return true;
      }
    },
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $file) {
    if ($file->isFile()) {
      if (!in_array($file->getFilename(), EXCLUDED_FILES) && !in_array(strtolower($file->getExtension()), EXCLUDED_EXTENSIONS)) {
        $fileList[] = $file->getPathname();
      }
    }
  }
  return $fileList;
}

function generate_directory_tree($path, $filesToProcess, &$treeString)
{
  $relativePathRoot = str_replace('\\', '/', $path);
  $structure = [];
  foreach ($filesToProcess as $file) {
    $relativePath = ltrim(str_replace($relativePathRoot, '', str_replace('\\', '/', $file)), '/');
    $parts = explode('/', $relativePath);
    $currentNode = &$structure;
    foreach ($parts as $part) {
      if (!isset($currentNode[$part])) $currentNode[$part] = [];
      $currentNode = &$currentNode[$part];
    }
  }
  $treeString .= basename($path) . "\n";
  build_tree_string($structure, $treeString);
}

function build_tree_string($tree, &$treeString, $prefix = '')
{
  $nodes = array_keys($tree);
  $count = count($nodes);
  foreach ($nodes as $i => $node) {
    $isLast = ($i === $count - 1);
    $treeString .= $prefix . ($isLast ? '└── ' : '├── ') . $node . "\n";
    if (!empty($tree[$node])) {
      build_tree_string($tree[$node], $treeString, $prefix . ($isLast ? '    ' : '│   '));
    }
  }
}

function generate_markdown($dirPath, $filesToProcess)
{
  $treeString = '';
  generate_directory_tree($dirPath, $filesToProcess, $treeString);

  $markdownContent = "# Tài liệu dự án: " . basename($dirPath) . "\n\n";
  $markdownContent .= "Ngày tạo: " . date('Y-m-d H:i:s') . "\n\n";
  $markdownContent .= "## 🌳 Cấu trúc thư mục\n\n```text\n" . $treeString . "```\n\n";
  $markdownContent .= "## 📄 Nội dung chi tiết\n\n";

  $totalFiles = count($filesToProcess);
  foreach ($filesToProcess as $index => $filePath) {
    $processedCount = $index + 1;
    $relativePath = ltrim(str_replace(str_replace('\\', '/', $dirPath), '', str_replace('\\', '/', $filePath)), '/');

    send_sse_message('progress', "Đang xử lý: {$relativePath}", ['progress' => round(($processedCount / $totalFiles) * 100)]);

    $ext = pathinfo($filePath, PATHINFO_EXTENSION);
    $markdownContent .= "### `{$relativePath}`\n\n```{$ext}\n";
    $content = @file_get_contents($filePath);
    $markdownContent .= ($content === false ? "Lỗi đọc file" : htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
    $markdownContent .= "\n```\n\n";
  }
  return $markdownContent;
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Markdown Docs Generator</title>
  <style>
    :root {
      --primary: #3498db;
      --success: #27ae60;
      --dark: #2c3e50;
      --light: #ecf0f1;
      --danger: #e74c3c;
    }

    body {
      font-family: -apple-system, system-ui, sans-serif;
      line-height: 1.6;
      max-width: 800px;
      margin: 40px auto;
      padding: 20px;
      background: #f4f4f4;
      color: #333;
    }

    .container {
      background: #fff;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    h1 {
      text-align: center;
      color: var(--dark);
      margin-bottom: 30px;
    }

    .input-group {
      margin-bottom: 20px;
    }

    label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
    }

    input[type="text"] {
      width: 100%;
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 6px;
      box-sizing: border-box;
      font-size: 16px;
    }

    .btn-group {
      display: flex;
      gap: 10px;
    }

    button {
      flex: 1;
      padding: 12px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      font-size: 16px;
      font-weight: 600;
      transition: 0.2s;
      color: white;
    }

    button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
    }

    #btn-full {
      background-color: var(--primary);
    }

    #btn-full:hover:not(:disabled) {
      background-color: #2980b9;
    }

    #btn-structure {
      background-color: #9b59b6;
    }

    #btn-structure:hover:not(:disabled) {
      background-color: #8e44ad;
    }

    /* Progress & Logs */
    #progress-container {
      display: none;
      margin-top: 25px;
    }

    #progress-bar {
      background: var(--light);
      border-radius: 4px;
      overflow: hidden;
      height: 20px;
    }

    #progress-bar-inner {
      width: 0%;
      height: 100%;
      background: var(--success);
      transition: width 0.3s;
      text-align: center;
      color: #fff;
      font-size: 12px;
      line-height: 20px;
    }

    #log {
      margin-top: 15px;
      padding: 15px;
      background: var(--dark);
      color: var(--light);
      border-radius: 6px;
      height: 150px;
      overflow-y: auto;
      font-family: monospace;
      font-size: 13px;
      white-space: pre-wrap;
    }

    #result-download {
      text-align: center;
      margin-top: 20px;
      display: none;
    }

    .download-link {
      display: inline-block;
      padding: 10px 20px;
      background: var(--success);
      color: white;
      text-decoration: none;
      border-radius: 6px;
    }

    /* Modal Structure Preview */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 100;
      align-items: center;
      justify-content: center;
    }

    .modal-content {
      background: white;
      width: 90%;
      max-width: 800px;
      max-height: 90vh;
      border-radius: 8px;
      display: flex;
      flex-direction: column;
      padding: 20px;
      position: relative;
    }

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 15px;
      border-bottom: 1px solid #eee;
      padding-bottom: 10px;
    }

    .modal-title {
      font-weight: bold;
      font-size: 18px;
    }

    .close-modal {
      background: none;
      border: none;
      color: #999;
      font-size: 24px;
      cursor: pointer;
      padding: 0;
      flex: 0;
    }

    .close-modal:hover {
      color: var(--danger);
    }

    .preview-area {
      flex: 1;
      overflow: auto;
      background: #f8f9fa;
      padding: 15px;
      border-radius: 4px;
      border: 1px solid #ddd;
      font-family: 'Courier New', monospace;
      white-space: pre;
      margin-bottom: 15px;
    }

    .modal-footer {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .btn-copy {
      background-color: var(--success);
      width: auto;
      padding: 10px 25px;
    }
  </style>
</head>

<body>
  <div class="container">
    <h1>Công cụ tạo tài liệu dự án</h1>

    <div class="input-group">
      <label for="project_path">Đường dẫn thư mục dự án:</label>
      <input type="text" id="project_path" placeholder="Ví dụ: C:\laragon\www\my-project" required>
    </div>

    <div class="btn-group">
      <button id="btn-full">🚀 Tạo Full Docs (Nội dung)</button>
      <button id="btn-structure">🌳 Chỉ lấy Structure Tree</button>
    </div>

    <!-- Progress Area -->
    <div id="progress-container">
      <div id="progress-bar">
        <div id="progress-bar-inner">0%</div>
      </div>
      <div id="log"></div>
    </div>
    <div id="result-download"><a href="#" class="download-link" id="download-link">⬇️ Tải xuống file .MD</a></div>
  </div>

  <!-- Modal Preview -->
  <div class="modal-overlay" id="preview-modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title">Structure Tree Preview</div>
        <button class="close-modal" onclick="closeModal()">&times;</button>
      </div>
      <div class="preview-area" id="tree-preview-content">Đang tải...</div>
      <div class="modal-footer">
        <div id="copy-status" style="margin-right: auto; color: var(--success); display: none;">Đã copy!</div>
        <button class="btn-copy" onclick="copyTree()">📋 Copy to Clipboard</button>
      </div>
    </div>
  </div>

  <script>
    const pathInput = document.getElementById('project_path');
    const logDiv = document.getElementById('log');
    const progressContainer = document.getElementById('progress-container');
    const progressBarInner = document.getElementById('progress-bar-inner');
    const resultDownload = document.getElementById('result-download');

    // --- LOGIC FULL DOCS (SSE) ---
    document.getElementById('btn-full').addEventListener('click', function() {
      const path = pathInput.value.trim();
      if (!path) return alert('Vui lòng nhập đường dẫn!');

      resetUI();
      progressContainer.style.display = 'block';
      this.disabled = true;
      document.getElementById('btn-structure').disabled = true;

      const eventSource = new EventSource(`?action=generate&path=${encodeURIComponent(path)}`);

      eventSource.addEventListener('log', e => addLog(JSON.parse(e.data).message));
      eventSource.addEventListener('progress', e => {
        const d = JSON.parse(e.data);
        progressBarInner.style.width = d.progress + '%';
        progressBarInner.textContent = d.progress + '%';
      });
      eventSource.addEventListener('error', e => {
        addLog(JSON.parse(e.data).message, 'error');
        endProcess(eventSource);
      });
      eventSource.addEventListener('complete', e => {
        const d = JSON.parse(e.data);
        progressBarInner.style.width = '100%';
        progressBarInner.textContent = 'Hoàn thành';
        document.getElementById('download-link').href = `?download=${encodeURIComponent(d.message)}`;
        resultDownload.style.display = 'block';
        addLog(`✅ Xong! Tổng cộng ${d.total} files.`);
        endProcess(eventSource);
      });
      eventSource.onerror = () => {
        addLog('Lỗi kết nối server.', 'error');
        endProcess(eventSource);
      };
    });

    // --- LOGIC STRUCTURE ONLY (AJAX) ---
    document.getElementById('btn-structure').addEventListener('click', function() {
      const path = pathInput.value.trim();
      if (!path) return alert('Vui lòng nhập đường dẫn!');

      const btn = this;
      btn.disabled = true;
      btn.textContent = 'Đang quét...';

      fetch(`?action=get_structure&path=${encodeURIComponent(path)}`)
        .then(res => res.json())
        .then(data => {
          if (data.status === 'success') {
            openModal(data.data);
          } else {
            alert('Lỗi: ' + data.message);
          }
        })
        .catch(err => alert('Lỗi kết nối: ' + err))
        .finally(() => {
          btn.disabled = false;
          btn.textContent = '🌳 Chỉ lấy Structure Tree';
        });
    });

    // --- HELPER FUNCTIONS ---
    function resetUI() {
      logDiv.innerHTML = '';
      progressBarInner.style.width = '0%';
      resultDownload.style.display = 'none';
      progressBarInner.style.backgroundColor = '#27ae60';
    }

    function addLog(msg, type = 'info') {
      const div = document.createElement('div');
      div.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
      if (type === 'error') div.style.color = '#e74c3c';
      logDiv.appendChild(div);
      logDiv.scrollTop = logDiv.scrollHeight;
    }

    function endProcess(es) {
      es.close();
      document.getElementById('btn-full').disabled = false;
      document.getElementById('btn-structure').disabled = false;
    }

    // --- MODAL & COPY LOGIC ---
    const modal = document.getElementById('preview-modal');
    const previewContent = document.getElementById('tree-preview-content');

    function openModal(content) {
      previewContent.textContent = content;
      modal.style.display = 'flex';
    }

    function closeModal() {
      modal.style.display = 'none';
      document.getElementById('copy-status').style.display = 'none';
    }

    function copyTree() {
      const text = previewContent.textContent;
      // Copy dạng markdown block
      const markdownText = "```text\n" + text + "\n```";

      navigator.clipboard.writeText(markdownText).then(() => {
        const status = document.getElementById('copy-status');
        status.style.display = 'block';
        setTimeout(() => status.style.display = 'none', 2000);
      }).catch(err => alert('Không thể copy: ' + err));
    }

    // Đóng modal khi click ra ngoài
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal();
    });
  </script>
</body>

</html>