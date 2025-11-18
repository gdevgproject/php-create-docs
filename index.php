<?php
// --- THIẾT LẬP MÔI TRƯỜNG VÀ XỬ LÝ LỖI ---
set_time_limit(0);
error_reporting(E_ALL);
// Tắt hiển thị lỗi trực tiếp, thay vào đó sẽ log vào file
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$php_error_log_file = __DIR__ . '/php_error.log';
ini_set('error_log', $php_error_log_file);

// Tắt bộ đệm đầu ra để gửi tiến trình real-time
@ini_set('zlib.output_compression', 0);
if (function_exists('apache_setenv')) {
  @apache_setenv('no-gzip', 1);
}
@ini_set('implicit_flush', 1);
ob_implicit_flush(1);
while (ob_get_level() > 0) {
  ob_end_flush();
}

// File log tùy chỉnh cho tiến trình của script
$log_file = __DIR__ . '/docs_generator.log';

function write_log($message)
{
  global $log_file;
  file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
}

// --- CẤU HÌNH ---
const EXCLUDED_DIRS = ['node_modules', '.next', 'vendor', '.git', 'public', 'dist', 'build', 'storage'];
const EXCLUDED_FILES = ['.env', '.env.local', 'package-lock.json', 'composer.lock', '.DS_Store'];
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
  'phar'
];
$resultsDir = __DIR__ . '/generated_docs';

// --- ROUTING ---
if (isset($_GET['action']) && $_GET['action'] == 'generate' && isset($_GET['path'])) {
  handle_generation_request();
  exit;
}
if (isset($_GET['download'])) {
  handle_download_request();
  exit;
}

// --- LOGIC CHÍNH ---

function handle_generation_request()
{
  global $resultsDir;

  header('Content-Type: text/event-stream');
  header('Cache-Control: no-cache');
  header('Connection: keep-alive');

  try {
    write_log("================== NEW REQUEST ==================");
    $projectPath = rtrim(urldecode($_GET['path']), '\\/');
    write_log("Bắt đầu xử lý đường dẫn: {$projectPath}");

    if (!is_dir($projectPath)) throw new Exception("Đường dẫn không hợp lệ hoặc không phải là thư mục.");

    if (!is_dir($resultsDir)) {
      if (!mkdir($resultsDir, 0777, true)) throw new Exception("Không thể tạo thư mục lưu trữ '{$resultsDir}'. Vui lòng kiểm tra quyền ghi.");
    }

    send_sse_message('log', 'Bước 1: Quét và lập danh sách các file...');
    $filesToProcess = get_files_to_process($projectPath);
    $totalFiles = count($filesToProcess);
    if ($totalFiles === 0) throw new Exception("Không tìm thấy file hợp lệ nào để xử lý trong thư mục đã chọn.");

    send_sse_message('log', "Tìm thấy {$totalFiles} file hợp lệ. Bắt đầu tạo tài liệu...");

    $markdownContent = generate_markdown($projectPath, $filesToProcess);

    $safeFilename = preg_replace('/[^A-Za-z0-9_\-]/', '_', basename($projectPath));
    $outputFilename = 'docs_' . $safeFilename . '_' . date('YmdHis') . '.md';
    $outputFilePath = $resultsDir . '/' . $outputFilename;
    write_log("Đang lưu kết quả vào file: {$outputFilePath}");

    if (file_put_contents($outputFilePath, $markdownContent) === false) {
      throw new Exception("Không thể ghi file vào thư mục: " . htmlspecialchars($resultsDir));
    }

    $downloadUrl = basename($outputFilePath);
    send_sse_message('complete', $downloadUrl, ['total' => $totalFiles]);
    write_log("HOÀN THÀNH: File đã được tạo thành công.");
  } catch (Throwable $e) {
    $errorMessage = "Lỗi nghiêm trọng: " . $e->getMessage() . " tại file " . $e->getFile() . " dòng " . $e->getLine();
    write_log($errorMessage);
    send_sse_message('error', "Đã xảy ra lỗi. Vui lòng kiểm tra file 'docs_generator.log' để biết chi tiết.");
  }
}

function handle_download_request()
{
  global $resultsDir;
  $fileName = basename($_GET['download']);
  $filePath = $resultsDir . '/' . $fileName;

  if (preg_match('/^[a-zA-Z0-9_\-]+\.md$/', $fileName) && file_exists($filePath)) {
    header('Content-Description: File Transfer');
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
  } else {
    http_response_code(404);
    write_log("Lỗi tải file: File không tồn tại hoặc tên file không hợp lệ - {$fileName}");
    die('File not found or invalid filename.');
  }
}

function send_sse_message($event, $data, $extra = [])
{
  $payload = json_encode(array_merge(['message' => $data], $extra));
  echo "event: {$event}\n";
  echo "data: {$payload}\n\n";
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
        // Lấy đối tượng file/thư mục hiện tại
        $current = $this->current();

        // Lọc bỏ các thư mục không mong muốn
        // SỬA LỖI: Sử dụng $current->isDir() và $current->getFilename()
        if ($current->isDir() && in_array($current->getFilename(), EXCLUDED_DIRS)) {
          return false;
        }
        return true;
      }
    },
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($iterator as $file) {
    if ($file->isFile()) {
      $fileName = $file->getFilename();
      $extension = strtolower($file->getExtension());
      if (!in_array($fileName, EXCLUDED_FILES) && !in_array($extension, EXCLUDED_EXTENSIONS)) {
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
  foreach ($nodes as $i => $node) {
    $isLast = ($i === count($nodes) - 1);
    $treeString .= $prefix . ($isLast ? '└── ' : '├── ') . $node . "\n";
    if (!empty($tree[$node])) {
      build_tree_string($tree[$node], $treeString, $prefix . ($isLast ? '    ' : '│   '));
    }
  }
}

function generate_markdown($dirPath, $filesToProcess)
{
  send_sse_message('log', 'Bước 2.1: Đang tạo cây cấu trúc thư mục...');
  $treeString = '';
  generate_directory_tree($dirPath, $filesToProcess, $treeString);

  $markdownContent = "# Tài liệu dự án: " . basename($dirPath) . "\n\n";
  $markdownContent .= "Tài liệu này được tạo tự động vào ngày: " . date('Y-m-d H:i:s') . "\n\n";
  $markdownContent .= "## 🌳 Cấu trúc thư mục\n\n```text\n" . $treeString . "```\n\n";
  $markdownContent .= "## 📄 Nội dung chi tiết các file\n\n";

  send_sse_message('log', 'Bước 2.2: Đang đọc nội dung các file...');
  $totalFiles = count($filesToProcess);
  foreach ($filesToProcess as $index => $filePath) {
    $processedCount = $index + 1;
    $relativePath = ltrim(str_replace(str_replace('\\', '/', $dirPath), '', str_replace('\\', '/', $filePath)), '/');

    send_sse_message('progress', "Đang xử lý file {$processedCount}/{$totalFiles}: {$relativePath}", ['progress' => round(($processedCount / $totalFiles) * 100)]);

    $extension = pathinfo($filePath, PATHINFO_EXTENSION);
    $markdownContent .= "### `{$relativePath}`\n\n```{$extension}\n";

    $fileContent = @file_get_contents($filePath);
    if ($fileContent === false) {
      $error = error_get_last();
      $errorMessage = "!!! LỖI: Không thể đọc file. Lý do: " . htmlspecialchars($error['message'] ?? 'Không rõ lý do');
      $markdownContent .= $errorMessage . "\n";
      write_log("Lỗi đọc file {$filePath}: {$errorMessage}");
    } else {
      $markdownContent .= htmlspecialchars($fileContent, ENT_QUOTES, 'UTF-8');
    }
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
  <title>Tạo File Markdown Tài Liệu Dự Án</title>
  <style>
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      line-height: 1.6;
      color: #333;
      max-width: 800px;
      margin: 40px auto;
      padding: 20px;
      background-color: #f4f4f4;
    }

    .container {
      background-color: #fff;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
    }

    h1 {
      color: #2c3e50;
      text-align: center;
    }

    label {
      display: block;
      margin-bottom: 8px;
      font-weight: bold;
    }

    input[type="text"] {
      width: 100%;
      padding: 12px;
      border: 1px solid #ddd;
      border-radius: 4px;
      box-sizing: border-box;
      margin-bottom: 20px;
    }

    button {
      display: block;
      width: 100%;
      padding: 12px;
      background-color: #3498db;
      color: white;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 16px;
      transition: background-color 0.3s;
    }

    button:hover {
      background-color: #2980b9;
    }

    button:disabled {
      background-color: #bdc3c7;
      cursor: not-allowed;
    }

    .note {
      font-size: 0.9em;
      color: #7f8c8d;
      background-color: #ecf0f1;
      padding: 15px;
      border-left: 4px solid #3498db;
      border-radius: 4px;
      margin-top: 20px;
    }

    code {
      background-color: #eee;
      padding: 2px 4px;
      border-radius: 3px;
    }

    #progress-container {
      display: none;
      margin-top: 20px;
    }

    #progress-bar {
      width: 100%;
      background-color: #ecf0f1;
      border-radius: 5px;
      overflow: hidden;
    }

    #progress-bar-inner {
      width: 0%;
      height: 20px;
      background-color: #2ecc71;
      border-radius: 5px;
      text-align: center;
      color: white;
      line-height: 20px;
      font-size: 12px;
      transition: width 0.4s ease;
    }

    #log {
      margin-top: 10px;
      padding: 15px;
      background-color: #2c3e50;
      color: #ecf0f1;
      border-radius: 5px;
      height: 200px;
      overflow-y: scroll;
      font-family: 'Courier New', Courier, monospace;
      font-size: 14px;
      white-space: pre-wrap;
    }

    #log .error {
      color: #e74c3c;
      font-weight: bold;
    }

    #result {
      text-align: center;
      margin-top: 20px;
      display: none;
    }

    #download-link {
      display: inline-block;
      padding: 12px 25px;
      background-color: #27ae60;
      color: white;
      text-decoration: none;
      border-radius: 5px;
      font-size: 18px;
    }
  </style>
</head>

<body>
  <div class="container">
    <h1>Công cụ tạo tài liệu Markdown</h1>

    <form id="generator-form">
      <label for="project_path">Đường dẫn thư mục dự án:</label>
      <input type="text" id="project_path" name="project_path" placeholder="Ví dụ: C:\laragon\www\my-project" required>
      <button type="submit" id="submit-btn">Bắt đầu tạo tài liệu</button>
    </form>

    <div id="progress-container">
      <div id="progress-bar">
        <div id="progress-bar-inner">0%</div>
      </div>
      <div id="log"></div>
    </div>

    <div id="result"><a href="#" id="download-link">Tải xuống file tài liệu</a></div>

    <div class="note">
      <strong>Lưu ý:</strong> Mọi lỗi phát sinh sẽ được ghi vào file <code>docs_generator.log</code>. Nếu gặp sự cố, vui lòng kiểm tra file này.
    </div>
  </div>

  <script>
    document.getElementById('generator-form').addEventListener('submit', function(e) {
      e.preventDefault();
      const path = document.getElementById('project_path').value;
      if (!path) {
        alert('Vui lòng nhập đường dẫn thư mục dự án.');
        return;
      }

      const progressContainer = document.getElementById('progress-container');
      const progressBarInner = document.getElementById('progress-bar-inner');
      const log = document.getElementById('log');
      const resultDiv = document.getElementById('result');
      const submitBtn = document.getElementById('submit-btn');

      progressContainer.style.display = 'block';
      resultDiv.style.display = 'none';
      log.innerHTML = '';
      progressBarInner.style.backgroundColor = '#2ecc71';
      progressBarInner.style.width = '0%';
      progressBarInner.textContent = '0%';
      submitBtn.disabled = true;
      submitBtn.textContent = 'Đang xử lý...';

      const eventSource = new EventSource(`?action=generate&path=${encodeURIComponent(path)}`);

      function addLog(message, type = 'info') {
        const p = document.createElement('p');
        p.textContent = `[${new Date().toLocaleTimeString()}] ${message}`;
        if (type === 'error') p.className = 'error';
        log.appendChild(p);
        log.scrollTop = log.scrollHeight;
      }

      eventSource.addEventListener('log', e => addLog(JSON.parse(e.data).message));

      eventSource.addEventListener('progress', e => {
        const data = JSON.parse(e.data);
        progressBarInner.style.width = data.progress + '%';
        progressBarInner.textContent = data.progress + '%';
        if (data.progress === 0 || data.progress === 100 || data.progress % 10 === 0) {
          addLog(data.message);
        }
      });

      eventSource.addEventListener('error', e => {
        const data = JSON.parse(e.data);
        addLog(data.message, 'error');
        progressBarInner.style.backgroundColor = '#e74c3c';
        eventSource.close();
        submitBtn.disabled = false;
        submitBtn.textContent = 'Thử lại';
      });

      eventSource.addEventListener('complete', e => {
        const data = JSON.parse(e.data);
        addLog(`Hoàn thành! Đã xử lý ${data.total} file.`);
        progressBarInner.style.width = '100%';
        progressBarInner.textContent = 'Hoàn thành!';

        document.getElementById('download-link').href = `?download=${encodeURIComponent(data.message)}`;
        resultDiv.style.display = 'block';

        eventSource.close();
        submitBtn.disabled = false;
        submitBtn.textContent = 'Bắt đầu tạo tài liệu';
      });

      eventSource.onerror = function() {
        addLog('Mất kết nối với máy chủ. Lỗi nghiêm trọng đã xảy ra ở phía server. Vui lòng kiểm tra file "docs_generator.log" và "php_error.log" để biết nguyên nhân.', 'error');
        progressBarInner.style.backgroundColor = '#e74c3c';
        eventSource.close();
        submitBtn.disabled = false;
        submitBtn.textContent = 'Thử lại';
      };
    });
  </script>

</body>

</html>