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

$ADMIN_USER = {{ADMIN_USER_LITERAL}};
$ADMIN_PASSWORD_HASH = {{ADMIN_HASH_LITERAL}};
$AUTH_STORE_PATH = {{AUTH_STORE_PATH_LITERAL}};

function fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo $message;
    exit;
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

function writeAuthStore(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
    $adminButton = "<a class=\"admin-btn\" href=\"/projects/admin.php\">Admin</a>";
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
        . "<title>Published Glitchlets</title>"
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
        . "<h1>Published Glitchlets {$adminButton}</h1>"
        . "<div class=\"grid\">{$cards}</div>"
        . "</div></body></html>";
}

function ensureLoggedIn(string $user, string $hash): void {
    if (!empty($_SESSION["admin_auth"]) && $_SESSION["admin_auth"] === true) {
        return;
    }
    if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "login") {
        $inputUser = $_POST["username"] ?? "";
        $inputPass = $_POST["password"] ?? "";
        if (hash_equals($user, (string) $inputUser) && password_verify((string) $inputPass, $hash)) {
            $_SESSION["admin_auth"] = true;
            $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
            header("Location: " . $_SERVER["REQUEST_URI"]);
            exit;
        }
        fail("Login failed.", 403);
    }
    echo "<!doctype html><html><head><meta charset=\"utf-8\" />"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />"
        . "<title>Glitchlet Projects Admin</title>"
        . "<style>body{font-family:Arial,sans-serif;background:#f6f6fb;"
        . "display:grid;place-items:center;min-height:100vh;margin:0;}form{background:#fff;"
        . "padding:24px;border-radius:16px;box-shadow:0 12px 24px rgba(0,0,0,0.12);"
        . "display:grid;gap:12px;min-width:280px;}input{padding:10px;border-radius:8px;"
        . "border:1px solid #ddd;}button{padding:10px;border-radius:999px;border:none;"
        . "background:#4b1cff;color:#fff;font-weight:600;}</style></head><body>"
        . "<form method=\"post\"><input type=\"hidden\" name=\"action\" value=\"login\" />"
        . "<strong>Admin Login</strong><input name=\"username\" placeholder=\"Username\" />"
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

$projectsRoot = dirname(__DIR__) . "/projects";
$indexJson = $projectsRoot . "/index.json";
$indexHtml = $projectsRoot . "/index.html";

ensureLoggedIn($ADMIN_USER, $ADMIN_PASSWORD_HASH);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(16));
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") !== "login") {
    $token = $_POST["csrf_token"] ?? "";
    if (!hash_equals($_SESSION["csrf_token"], (string) $token)) {
        fail("Invalid CSRF token.", 403);
    }
}

$projects = readIndex($indexJson);
$authStore = readAuthStore($AUTH_STORE_PATH);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $slug = basename((string) ($_POST["slug"] ?? ""));
    if ($slug === "") {
        fail("Missing project slug.");
    }
    $found = false;
    foreach ($projects as $idx => $project) {
        if (($project["slug"] ?? "") !== $slug) {
            continue;
        }
        $found = true;
    if ($action === "archive") {
        $projects[$idx]["archived"] = true;
    } elseif ($action === "restore") {
        $projects[$idx]["archived"] = false;
    } elseif ($action === "reset") {
        $password = generateAdminPassword();
        $authStore[$slug] = [
            "hash" => password_hash($password, PASSWORD_DEFAULT),
            "createdAt" => time(),
        ];
        $_SESSION["reset_password"] = ["slug" => $slug, "password" => $password];
    } elseif ($action === "delete") {
        array_splice($projects, $idx, 1);
        deleteDirectory($projectsRoot . "/" . $slug);
        unset($authStore[$slug]);
    }
    break;
  }
    if (!$found) {
        fail("Project not found.", 404);
    }
    writeIndex($indexJson, $indexHtml, $projects);
    writeAuthStore($AUTH_STORE_PATH, $authStore);
    header("Location: " . $_SERVER["REQUEST_URI"]);
    exit;
}

$csrf = htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES);
$resetInfo = $_SESSION["reset_password"] ?? null;
if ($resetInfo) {
    unset($_SESSION["reset_password"]);
}
$rows = "";
foreach ($projects as $project) {
    $slug = htmlspecialchars($project["slug"] ?? "", ENT_QUOTES);
    $name = htmlspecialchars($project["name"] ?? "Untitled Project", ENT_QUOTES);
    $url = htmlspecialchars($project["url"] ?? "#", ENT_QUOTES);
    $creator = htmlspecialchars($project["author"] ?? ($project["creator"] ?? ""), ENT_QUOTES);
    $description = htmlspecialchars($project["description"] ?? "", ENT_QUOTES);
    $archived = !empty($project["archived"]);
    $status = $archived ? "Archived" : "Live";
    $toggleAction = $archived ? "restore" : "archive";
    $toggleLabel = $archived ? "Restore" : "Archive";
    $resetBanner = "";
    if ($resetInfo && $resetInfo["slug"] === ($project["slug"] ?? "")) {
        $safePass = htmlspecialchars($resetInfo["password"], ENT_QUOTES);
        $resetBanner = "<div class=\"reset\">New password: <code>{$safePass}</code></div>";
    }
    $rows .= "<div class=\"row\">"
        . "<div class=\"meta\">"
        . "<strong><a href=\"{$url}\" target=\"_blank\" rel=\"noopener\">{$name}</a></strong>"
        . ($creator ? "<span>By {$creator}</span>" : "")
        . ($description ? "<em>{$description}</em>" : "")
        . "<span>{$slug}</span>"
        . $resetBanner
        . "</div>"
        . "<div class=\"status\">{$status}</div>"
        . "<form method=\"post\"><input type=\"hidden\" name=\"csrf_token\" value=\"{$csrf}\" />"
        . "<input type=\"hidden\" name=\"slug\" value=\"{$slug}\" />"
        . "<button name=\"action\" value=\"{$toggleAction}\">{$toggleLabel}</button>"
        . "<button name=\"action\" value=\"reset\">Reset password</button>"
        . "<button name=\"action\" value=\"delete\" class=\"danger\" onclick=\"return confirm('Delete {$name}?');\">Delete</button>"
        . "</form></div>";
}
if ($rows === "") {
    $rows = "<p class=\"empty\">No projects found.</p>";
}

echo "<!doctype html><html><head><meta charset=\"utf-8\" />"
    . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\" />"
    . "<title>Glitchlet Project Admin</title>"
    . "<style>"
    . "body{margin:0;font-family:Arial,sans-serif;background:#f6f6fb;color:#1b1736;}"
    . ".wrap{max-width:960px;margin:0 auto;padding:32px 20px;}"
    . "h1{margin:0 0 20px;font-size:28px;}"
    . ".row{display:grid;grid-template-columns:1fr auto auto;gap:16px;align-items:center;"
    . "background:#fff;padding:14px;border-radius:14px;box-shadow:0 10px 20px rgba(0,0,0,0.08);"
    . "margin-top:5px;}"
    . ".meta{display:flex;flex-direction:column;gap:6px;}"
    . ".meta span{font-size:12px;color:#777;}"
    . ".meta em{font-size:12px;color:#555;font-style:normal;}"
    . ".reset{margin-top:6px;font-size:12px;color:#3b2d72;}"
    . ".reset code{background:#f1edff;padding:2px 6px;border-radius:6px;}"
    . ".status{font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:#5b5875;}"
    . "form{display:flex;gap:8px;}"
    . "button{padding:8px 14px;border-radius:999px;border:none;font-weight:600;cursor:pointer;}"
    . ".danger{background:#ffe9e6;color:#a01912;}"
    . "</style></head><body><div class=\"wrap\">"
    . "<h1>Glitchlet Projects Admin</h1>"
    . "<div class=\"grid\">{$rows}</div>"
    . "</div></body></html>";
