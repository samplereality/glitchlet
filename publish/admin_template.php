<?php
declare(strict_types=1);

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/",
    "secure" => !empty($_SERVER["HTTPS"]),
    "httponly" => true,
    "samesite" => "Strict",
]);
session_start();

$AUTH_STORE_PATH = {{AUTH_STORE_PATH_LITERAL}};

function fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo $message;
    exit;
}

function readAuthStore(string $path): array {
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
    file_put_contents($htmlPath, buildIndexHtml($sorted));
}

function buildIndexHtml(array $projects): string {
    $cards = "";
    foreach ($projects as $project) {
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
        . "h1{margin:0 0 20px;font-size:28px;}"
        . ".grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));}"
        . ".card{background:#fff;border-radius:16px;padding:16px;box-shadow:0 12px 24px rgba(0,0,0,0.08);}"
        . ".card h2{margin:0 0 6px;font-size:18px;}"
        . ".card a{text-decoration:none;color:#4b1cff;}"
        . ".meta{font-size:12px;color:#5b5875;margin-bottom:8px;}"
        . ".slug{font-size:11px;color:#8b87a7;}"
        . ".empty{color:#5b5875;}"
        . "</style></head><body><div class=\"wrap\">"
        . "<h1>Published Projects</h1>"
        . "<div class=\"grid\">{$cards}</div>"
        . "</div></body></html>";
}

function ensureLoggedIn(string $authPath, string $slug): void {
    if (!empty($_SESSION["admin_auth"]) && $_SESSION["admin_auth"] === true) {
        return;
    }
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "login") {
        $inputPass = $_POST["password"] ?? "";
        $store = readAuthStore($authPath);
        $hash = $store[$slug]["hash"] ?? "";
        if ($hash && password_verify((string) $inputPass, $hash)) {
            $_SESSION["admin_auth"] = true;
            $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
            header("Location: " . $_SERVER["REQUEST_URI"]);
            exit;
        }
        fail("Login failed.", 403);
    }
    echo "<!doctype html><html><head><meta charset=\"utf-8\" />"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />"
        . "<title>Project Admin</title>"
        . "<style>body{font-family:Arial,sans-serif;background:#f6f6fb;"
        . "display:grid;place-items:center;min-height:100vh;margin:0;}form{background:#fff;"
        . "padding:24px;border-radius:16px;box-shadow:0 12px 24px rgba(0,0,0,0.12);"
        . "display:grid;gap:12px;min-width:280px;}input{padding:10px;border-radius:8px;"
        . "border:1px solid #ddd;}button{padding:10px;border-radius:999px;border:none;"
        . "background:#4b1cff;color:#fff;font-weight:600;}</style></head><body>"
        . "<form method=\"post\"><input type=\"hidden\" name=\"action\" value=\"login\" />"
        . "<strong>Admin Login</strong>"
        . "<input name=\"password\" type=\"password\" placeholder=\"Password\" />"
        . "<button type=\"submit\">Sign in</button></form></body></html>";
    exit;
}

function deleteDirectory(string $dir): void {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

$projectsRoot = dirname(__DIR__);
$slug = basename(__DIR__);
$indexJson = $projectsRoot . "/index.json";
$indexHtml = $projectsRoot . "/index.html";
$projectJson = __DIR__ . "/project.json";

ensureLoggedIn($AUTH_STORE_PATH, $slug);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") !== "login") {
    $token = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["csrf_token"], (string) $token)) {
        fail("Invalid CSRF token.", 403);
    }
}

$index = readIndex($indexJson);
$current = [
    "slug" => $slug,
    "name" => "Untitled Project",
    "description" => "",
    "author" => "",
];
if (file_exists($projectJson)) {
    $raw = file_get_contents($projectJson);
    $decoded = $raw ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $current = array_merge($current, $decoded);
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    if ($action === "delete") {
        $index = array_values(array_filter($index, function (array $project) use ($slug): bool {
            return ($project["slug"] ?? "") !== $slug;
        }));
        writeIndex($indexJson, $indexHtml, $index);
        deleteDirectory(__DIR__);
        header("Location: /projects/index.html");
        exit;
    }
    if ($action === "update") {
        $current["name"] = trim((string) ($_POST["name"] ?? $current["name"]));
        $current["description"] = trim((string) ($_POST["description"] ?? ""));
        $current["author"] = trim((string) ($_POST["author"] ?? ""));
        $current["updatedAt"] = time();
        file_put_contents($projectJson, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $updated = false;
        foreach ($index as $idx => $project) {
            if (($project["slug"] ?? "") === $slug) {
                $index[$idx] = array_merge($project, $current);
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $index[] = $current;
        }
        writeIndex($indexJson, $indexHtml, $index);
        header("Location: " . $_SERVER["REQUEST_URI"]);
        exit;
    }
}

$name = htmlspecialchars($current["name"] ?? "Untitled Project", ENT_QUOTES);
$description = htmlspecialchars($current["description"] ?? "", ENT_QUOTES);
$author = htmlspecialchars($current["author"] ?? "", ENT_QUOTES);
$csrf = htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES);
$projectUrl = htmlspecialchars(($current["url"] ?? ""), ENT_QUOTES);
$projectsUrl = "/projects/index.html";
$appUrl = "https://glitchlet.digitaldavidson.net/";

echo "<!doctype html><html><head><meta charset=\"utf-8\" />"
    . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />"
    . "<title>Project Admin</title>"
    . "<style>body{font-family:Arial,sans-serif;background:#f6f6fb;margin:0;padding:32px;}"
    . ".card{max-width:720px;margin:0 auto;background:#fff;padding:24px;border-radius:18px;"
    . "box-shadow:0 16px 32px rgba(0,0,0,0.12);}label{font-size:12px;color:#555;"
    . "text-transform:uppercase;letter-spacing:0.08em;font-weight:700;}"
    . "input,textarea{width:100%;padding:10px;border-radius:10px;border:1px solid #ddd;"
    . "font-size:14px;}textarea{min-height:120px;resize:vertical;}"
    . "button{padding:10px 18px;border-radius:999px;border:none;font-weight:600;}"
    . ".primary{background:#4b1cff;color:#fff;}"
    . ".danger{background:#ffe9e6;color:#a01912;}"
    . ".nav{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px;}"
    . ".nav a{font-size:12px;text-decoration:none;color:#fff;background:#3b2d72;"
    . "padding:6px 10px;border-radius:999px;transition:transform 0.2s ease,box-shadow 0.2s ease;}"
    . ".nav a.outline{background:#fff;color:#3b2d72;border:1px solid #3b2d72;}"
    . ".nav a:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(31,29,26,0.12);}"
    . ".row{display:grid;gap:12px;margin-top:16px;}"
    . ".actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}"
    . "</style></head><body><div class=\"card\">"
    . "<div class=\"nav\">"
    . ($projectUrl ? "<a href=\"{$projectUrl}\" target=\"_blank\" rel=\"noopener\">This Project</a>" : "")
    . "<a class=\"outline\" href=\"{$projectsUrl}\">All Published Projects</a>"
    . "<a class=\"outline\" href=\"{$appUrl}\">Glitchlet</a>"
    . "</div>"
    . "<h1>Project Admin</h1>"
    . "<form method=\"post\" class=\"row\">"
    . "<input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\" />"
    . "<input type=\"hidden\" name=\"action\" value=\"update\" />"
    . "<label>Project name</label>"
    . "<input name=\"name\" value=\"{$name}\" />"
    . "<label>Author</label>"
    . "<input name=\"author\" value=\"{$author}\" />"
    . "<label>Description</label>"
    . "<textarea name=\"description\">{$description}</textarea>"
    . "<div class=\"actions\"><button type=\"submit\" class=\"primary\">Save</button></div>"
    . "</form>"
    . "<form method=\"post\" onsubmit=\"return confirm('Delete this project?');\">"
    . "<input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\" />"
    . "<input type=\"hidden\" name=\"action\" value=\"delete\" />"
    . "<div class=\"actions\"><button type=\"submit\" class=\"danger\">Delete project</button></div>"
    . "</form></div></body></html>";
