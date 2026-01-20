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
const REMIX_ZIP_NAME = "remix.zip";
const PROJECT_INDEX_JSON = "index.json";
const PROJECT_INDEX_HTML = "index.html";
const PROJECTS_ADMIN_PHP = "admin.php";
const ADMIN_USER = "admin";
const ADMIN_PASSWORD_HASH = '$2y$10$XhgH5ocb7EK3e48H3stI8eHiQjarNIVYMKNeOOGkCUYJdu2aWKvl6';
const AUTH_STORE_PATH = __DIR__ . "/../private/project_auth.json";

$adjectives = [
    "brave", "silent", "cosmic", "sunny", "misty", "electric", "gentle", "swift", "bright", "hidden",
    "lucky", "neon", "crimson", "mellow", "sparkly", "wild", "sleepy", "rapid", "glowing", "frosty",
    "curious", "bold", "playful", "stellar", "tidy", "velvet", "golden", "lunar", "cobalt", "amber",
    "silver", "dusky", "vivid", "quiet", "sunset", "ancient", "vibrant", "breezy", "lively", "shy",
    "proud", "patient", "whispering", "soaring", "crisp", "sandy", "gleaming", "magic", "gentle",
    "pearly", "plucky", "radiant", "rusty", "sleepy", "steady", "stormy", "sweet", "nimble",
    "twinkling", "mystic", "earthy", "dreamy", "citrus", "striped", "silky", "violet", "sunlit",
    "wooden", "velvet", "opal", "icy", "salty", "fresh", "calm", "stormy", "lucky", "royal",
    "brisk", "teal", "lavish", "glossy", "bubbly", "snappy", "daring", "blooming", "harmonic",
];
$nouns = [
    "llama", "fox", "beast", "otter", "raven", "comet", "river", "forest", "ember", "shadow",
    "aurora", "meadow", "whale", "falcon", "breeze", "summit", "orchid", "monarch", "lagoon", "quartz",
    "nebula", "canyon", "panda", "harbor", "voyage", "meerkat", "blossom", "galaxy", "island", "trail",
    "harbor", "brook", "tide", "reef", "maple", "cabin", "tower", "garden", "marsh", "grove",
    "ridge", "harvest", "lantern", "compass", "drift", "glade", "meadow", "valley", "horizon",
    "ember", "spark", "mirror", "meadowlark", "spirit", "sprout", "fern", "rocket", "orbit",
    "thunder", "pebble", "pavilion", "studio", "signal", "cicada", "firefly", "ripple", "creek",
    "eagle", "owl", "robin", "tiger", "panther", "lighthouse", "workshop", "harbor", "cascade",
    "voyager", "paradox", "weaver", "composer", "harvester", "sentinel", "atlas", "keystone",
];

$projectsRoot = dirname(__DIR__) . "/projects";
$tempRoot = sys_get_temp_dir();
$adminTemplatePath = __DIR__ . "/admin_template.php";
$projectsAdminTemplatePath = __DIR__ . "/projects_admin_template.php";
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

function sanitizeMeta(?string $value, int $maxLength = 140): string {
    $trimmed = trim((string) $value);
    if ($trimmed === "") {
        return "";
    }
    $length = function_exists("mb_strlen") ? mb_strlen($trimmed) : strlen($trimmed);
    if ($length > $maxLength) {
        return function_exists("mb_substr")
            ? mb_substr($trimmed, 0, $maxLength)
            : substr($trimmed, 0, $maxLength);
    }
    return $trimmed;
}

function ensureAuthStoreDir(string $path): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        fail("Failed to create auth storage.", 500);
    }
    if (!file_exists($path)) {
        file_put_contents($path, json_encode([], JSON_PRETTY_PRINT));
    }
}

function readAuthStore(string $path): array {
    ensureAuthStoreDir($path);
    $handle = fopen($path, "c+");
    if ($handle === false) {
        fail("Failed to read auth store.", 500);
    }
    flock($handle, LOCK_SH);
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    $decoded = json_decode($contents ?: "[]", true);
    return is_array($decoded) ? $decoded : [];
}

function writeAuthStore(string $path, array $data): void {
    ensureAuthStoreDir($path);
    $handle = fopen($path, "c+");
    if ($handle === false) {
        fail("Failed to write auth store.", 500);
    }
    flock($handle, LOCK_EX);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

function generateAdminPassword(int $length = 12): string {
    $alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789";
    $chars = [];
    $maxIndex = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $chars[] = $alphabet[random_int(0, $maxIndex)];
    }
    return implode("", $chars);
}

function readIndex(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function writeIndex(string $jsonPath, string $htmlPath, array $projects): void {
  $sorted = $projects;
  usort($sorted, function (array $a, array $b): int {
    return ($b["publishedAt"] ?? 0) <=> ($a["publishedAt"] ?? 0);
  });
  file_put_contents($jsonPath, json_encode($sorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  $html = buildIndexHtml($sorted);
  file_put_contents($htmlPath, $html);
}

function buildIndexHtml(array $projects): string {
    $cards = "";
    $adminButton = "<a class=\"admin-btn\" href=\"" . PROJECT_URL_BASE . "admin.php\">Admin</a>";
  foreach ($projects as $project) {
    if (!empty($project["archived"])) {
      continue;
    }
    $name = htmlspecialchars($project["name"] ?? "Untitled Project", ENT_QUOTES);
        $url = htmlspecialchars($project["url"] ?? "#", ENT_QUOTES);
        $slug = htmlspecialchars($project["slug"] ?? "", ENT_QUOTES);
        $description = htmlspecialchars($project["description"] ?? "", ENT_QUOTES);
        $author = htmlspecialchars($project["author"] ?? "", ENT_QUOTES);
        $timestamp = isset($project["publishedAt"])
            ? date("M j, Y", (int) $project["publishedAt"])
            : "";
        $meta = trim(($author ? "By " . $author : "") . ($timestamp ? " · " . $timestamp : ""), " ·");
    $cards .= "<article class=\"card\">"
      . "<h2><a href=\"{$url}\">{$name}</a></h2>"
      . "<div class=\"meta\">{$meta}</div>"
      . ($description ? "<p>{$description}</p>" : "")
      . "<div class=\"slug\">{$slug}</div>"
      . "</article>";
  }
    if ($cards === "") {
        $cards = "<p class=\"empty\">No projects published yet.</p>";
    }
    return "<!doctype html>"
        . "<html lang=\"en\"><head><meta charset=\"utf-8\" />"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />"
        . "<title>Published Projects</title>"
        . "<style>"
        . "body{margin:0;font-family:Arial,sans-serif;background:#f6f6fb;color:#1b1736;}"
        . ".wrap{max-width:960px;margin:0 auto;padding:32px 20px;}"
        . "h1{margin:0 0 20px;font-size:28px;display:flex;align-items:center;gap:12px;}"
        . ".admin-btn{font-size:12px;text-decoration:none;color:#fff;background:#3b2d72;"
        . "padding:6px 10px;border-radius:999px;}"
        . ".grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));}"
        . ".card{background:#fff;border-radius:16px;padding:16px;box-shadow:0 12px 24px rgba(0,0,0,0.08);}"
        . ".card h2{margin:0 0 6px;font-size:18px;}"
        . ".card a{text-decoration:none;color:#4b1cff;}"
        . ".meta{font-size:12px;color:#5b5875;margin-bottom:8px;}"
        . ".slug{font-size:11px;color:#8b87a7;}"
        . ".empty{color:#5b5875;}"
        . "</style></head><body><div class=\"wrap\">"
      . "<h1>Published Projects {$adminButton}</h1>"
      . "<div class=\"grid\">{$cards}</div>"
      . "</div></body></html>";
}

function createZipFromDir(string $source, string $zipPath, array $skipFiles): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("Failed to create remix archive.", 500);
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $relative = $items->getSubPathName();
        $basename = basename($relative);
        if (in_array($basename, $skipFiles, true)) {
            continue;
        }
        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($item->getPathname(), $relative);
        }
    }
    $zip->close();
}

function generateSlug(array $adjectives, array $nouns, string $projectsRoot): string {
    $maxAttempts = 20;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $adj = $adjectives[random_int(0, count($adjectives) - 1)];
        $noun = $nouns[random_int(0, count($nouns) - 1)];
        $slug = $adj . "-" . $noun;
        if (!file_exists($projectsRoot . "/" . $slug)) {
            return $slug;
        }
    }
    return bin2hex(random_bytes(4));
}

function injectRemixFab(string $projectDir): void {
  $snippet = "\n<!-- remix-fab -->\n"
      . "<a href=\"admin.php\" class=\"admin-fab\" data-admin-fab>Admin</a>\n"
      . "<a href=\"" . REMIX_ZIP_NAME . "\" class=\"remix-fab\" data-remix-fab download>Remix</a>\n"
      . "<style>\n"
      . ".remix-fab,.admin-fab{position:fixed;right:20px;z-index:9999;"
      . "padding:12px 16px;border-radius:999px;color:#fff;"
      . "font:600 14px/1.1 Arial,sans-serif;text-decoration:none;"
      . "box-shadow:0 10px 24px rgba(255,92,173,0.35);}\n"
      . ".admin-fab{bottom:70px;background:#3b2d72;box-shadow:0 10px 24px rgba(59,45,114,0.35);}\n"
      . ".remix-fab{bottom:20px;background:#ff5cad;}\n"
      . ".admin-fab:hover,.remix-fab:hover{transform:translateY(-2px);}\n"
      . "</style>\n";
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            continue;
        }
        $ext = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, ["html", "htm"], true)) {
            continue;
        }
        $path = $item->getPathname();
        $contents = file_get_contents($path);
        if ($contents === false || strpos($contents, "data-remix-fab") !== false) {
            continue;
        }
        $pos = stripos($contents, "</body>");
        if ($pos !== false) {
            $contents = substr($contents, 0, $pos) . $snippet . substr($contents, $pos);
        } else {
            $contents .= $snippet;
        }
        file_put_contents($path, $contents);
    }
}

function writeAdminDashboard(string $projectDir, string $templatePath): void {
    if (!file_exists($templatePath)) {
        fail("Missing admin template.", 500);
    }
    $template = file_get_contents($templatePath);
    if ($template === false) {
        fail("Failed to read admin template.", 500);
    }
    $template = str_replace(
        ["{{AUTH_STORE_PATH_LITERAL}}"],
        [var_export(AUTH_STORE_PATH, true)],
        $template
    );
    file_put_contents($projectDir . "/admin.php", $template);
}

function writeProjectsAdminDashboard(string $projectsRoot, string $templatePath): void {
    if (!file_exists($templatePath)) {
        fail("Missing projects admin template.", 500);
    }
    $template = file_get_contents($templatePath);
    if ($template === false) {
        fail("Failed to read projects admin template.", 500);
    }
    $template = str_replace(
        ["{{ADMIN_USER_LITERAL}}", "{{ADMIN_HASH_LITERAL}}", "{{AUTH_STORE_PATH_LITERAL}}"],
        [var_export(ADMIN_USER, true), var_export(ADMIN_PASSWORD_HASH, true), var_export(AUTH_STORE_PATH, true)],
        $template
    );
    file_put_contents($projectsRoot . "/" . PROJECTS_ADMIN_PHP, $template);
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    fail("Method not allowed.", 405);
}

if (empty($_FILES["zip"])) {
    fail("Missing zip file.");
}

if (!class_exists("ZipArchive")) {
    fail("ZipArchive extension is not available.", 500);
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
    $normalized = rtrim($normalized, "/");
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

$slug = generateSlug($adjectives, $nouns, $projectsRoot);
$destination = $projectsRoot . "/" . $slug;

moveDirectory($tempDir, $destination);

injectRemixFab($destination);
writeAdminDashboard($destination, $adminTemplatePath);
createZipFromDir($destination, $destination . "/" . REMIX_ZIP_NAME, ["admin.php", "project.json", REMIX_ZIP_NAME]);

$authStore = readAuthStore(AUTH_STORE_PATH);
$adminPassword = generateAdminPassword();
$authStore[$slug] = [
    "hash" => password_hash($adminPassword, PASSWORD_DEFAULT),
    "createdAt" => time(),
];
writeAuthStore(AUTH_STORE_PATH, $authStore);

$projectName = sanitizeMeta($_POST["name"] ?? "", 120);
if ($projectName === "") {
    $projectName = "Untitled Project";
}
$projectCreator = sanitizeMeta($_POST["creator"] ?? "", 80);
$projectDescription = sanitizeMeta($_POST["description"] ?? "", 240);

$metadata = [
    "slug" => $slug,
    "name" => $projectName,
    "description" => $projectDescription,
    "author" => $projectCreator,
    "creator" => $projectCreator,
    "archived" => false,
    "publishedAt" => time(),
    "updatedAt" => time(),
    "url" => PROJECT_URL_BASE . $slug . "/",
];

file_put_contents($destination . "/project.json", json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$indexJsonPath = $projectsRoot . "/" . PROJECT_INDEX_JSON;
$indexHtmlPath = $projectsRoot . "/" . PROJECT_INDEX_HTML;
$projects = readIndex($indexJsonPath);
$found = false;
foreach ($projects as $idx => $project) {
    if (($project["slug"] ?? "") === $slug) {
        $projects[$idx] = $metadata;
        $found = true;
        break;
    }
}
if (!$found) {
    $projects[] = $metadata;
}
writeIndex($indexJsonPath, $indexHtmlPath, $projects);
writeProjectsAdminDashboard($projectsRoot, $projectsAdminTemplatePath);

echo json_encode([
    "ok" => true,
    "slug" => $slug,
    "url" => PROJECT_URL_BASE . $slug . "/",
    "adminPassword" => $adminPassword,
], JSON_UNESCAPED_SLASHES);
