<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

const MAX_ZIP_BYTES = 25 * 1024 * 1024;
const MAX_EXTRACTED_BYTES = 150 * 1024 * 1024;
const MAX_FILE_COUNT = 1200;
const MAX_PATH_LENGTH = 200;
const PROJECT_URL_BASE = "https://glitchlet.digitaldavidson.net/projects/";

$projectsRoot = dirname(__DIR__) . "/projects";
$tempRoot = sys_get_temp_dir();
$blockedNames = [".htaccess", ".htpasswd", ".user.ini"];
$blockedSegments = [".well-known"];
$allowedExtensions = [
    "html", "htm", "css", "js", "json", "txt", "md",
    "png", "jpg", "jpeg", "gif", "webp", "svg", "ico",
    "mp3", "wav", "mp4", "webm", "ogg",
];

function fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(["ok" => false, "error" => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function normalizePath(string $path): string {
    $path = str_replace("\\", "/", $path);
    $path = preg_replace("#/+#", "/", $path);
    return $path ?? "";
}

function isHiddenPath(string $path): bool {
    $trimmed = trim($path, "/");
    if ($trimmed === "") {
        return false;
    }
    foreach (explode("/", $trimmed) as $segment) {
        if ($segment !== "" && $segment[0] === ".") {
            return true;
        }
    }
    return false;
}

function validatePath(string $path, array $blockedNames, array $blockedSegments): string {
    $normalized = normalizePath($path);
    if ($normalized === "") {
        fail("Empty file path.");
    }
    if (strlen($normalized) > MAX_PATH_LENGTH) {
        fail("File path too long.");
    }
    if ($normalized[0] === "/") {
        fail("Absolute paths are not allowed.");
    }
    if (strpos($normalized, "..") !== false) {
        fail("Parent paths are not allowed.");
    }
    if (isHiddenPath($normalized)) {
        fail("Hidden files are not allowed.");
    }
    foreach ($blockedSegments as $segment) {
        if (strpos("/" . $normalized . "/", "/" . $segment . "/") !== false) {
            fail("Blocked path segment.");
        }
    }
    $basename = basename($normalized);
    foreach ($blockedNames as $blocked) {
        if (strcasecmp($basename, $blocked) === 0) {
            fail("Blocked filename.");
        }
    }
    return $normalized;
}

function ensureDir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0755, true)) {
        fail("Failed to create directory.", 500);
    }
}

function moveDirectory(string $source, string $destination): void {
    if (@rename($source, $destination)) {
        return;
    }
    ensureDir($destination);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $target = $destination . "/" . $items->getSubPathName();
        if ($item->isDir()) {
            ensureDir($target);
        } else {
            if (!copy($item->getPathname(), $target)) {
                fail("Failed to move files.", 500);
            }
        }
    }
    $cleanup = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($cleanup as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($source);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    fail("Method not allowed.", 405);
}

if (empty($_FILES["zip"])) {
    fail("Missing zip file.");
}

if (!isset($_FILES["zip"]["size"]) || $_FILES["zip"]["size"] > MAX_ZIP_BYTES) {
    fail("Zip file too large.");
}

if ($_FILES["zip"]["error"] !== UPLOAD_ERR_OK) {
    fail("Upload failed.");
}

ensureDir($projectsRoot);
ensureDir($tempRoot);

$zipPath = $_FILES["zip"]["tmp_name"] ?? "";
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fail("Invalid zip file.");
}

$totalSize = 0;
$fileCount = 0;
$entries = [];

for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if (!$stat || !isset($stat["name"])) {
        continue;
    }
    $name = (string) $stat["name"];
    $normalized = validatePath($name, $blockedNames, $blockedSegments);
    $isDir = substr($normalized, -1) === "/";
    if ($isDir) {
        $entries[] = ["path" => rtrim($normalized, "/"), "dir" => true, "size" => 0, "index" => $i];
        continue;
    }
    $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
    if ($extension === "" || !in_array($extension, $allowedExtensions, true)) {
        fail("File type not allowed.");
    }
    $size = isset($stat["size"]) ? (int) $stat["size"] : 0;
    $totalSize += $size;
    $fileCount++;
    if ($fileCount > MAX_FILE_COUNT) {
        fail("Too many files.");
    }
    if ($totalSize > MAX_EXTRACTED_BYTES) {
        fail("Project too large.");
    }
    $entries[] = ["path" => $normalized, "dir" => false, "size" => $size, "index" => $i];
}

$tempDir = $tempRoot . "/glitchlet_" . bin2hex(random_bytes(8));
ensureDir($tempDir);

foreach ($entries as $entry) {
    $targetPath = $tempDir . "/" . $entry["path"];
    if ($entry["dir"]) {
        ensureDir($targetPath);
        continue;
    }
    ensureDir(dirname($targetPath));
    $read = $zip->getStream($entry["path"]);
    if ($read === false) {
        fail("Failed to read zip contents.");
    }
    $write = fopen($targetPath, "wb");
    if ($write === false) {
        fail("Failed to write extracted file.", 500);
    }
    $copied = stream_copy_to_stream($read, $write);
    fclose($read);
    fclose($write);
    if ($copied === false || $copied !== $entry["size"]) {
        fail("Failed to extract file.");
    }
}

$zip->close();

do {
    $slug = bin2hex(random_bytes(4));
    $destination = $projectsRoot . "/" . $slug;
} while (file_exists($destination));

moveDirectory($tempDir, $destination);

echo json_encode([
    "ok" => true,
    "slug" => $slug,
    "url" => PROJECT_URL_BASE . $slug . "/",
], JSON_UNESCAPED_SLASHES);
