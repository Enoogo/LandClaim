<?php
declare(strict_types=1);

/**
 * PHP 内置服务器路由（php -S 0.0.0.0:6850 router.php）。
 *
 *   /            -> index.html（联机客户端）
 *   /api         -> api.php（对局 API）
 *   /data /src /tests /start.php -> 一律 404（数据与代码不对外暴露）
 *   其余静态资源 -> 白名单扩展名，交给内置服务器原样输出
 */

$uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$uri = rawurldecode($uri);
if ($uri === '' || $uri[0] !== '/') {
    $uri = '/' . $uri;
}

$blocked = ['/data', '/src', '/tests', '/start.php', '/composer.json'];
foreach ($blocked as $b) {
    if ($uri === $b || strpos($uri, $b . '/') === 0) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        return true;
    }
}

if ($uri === '/api' || $uri === '/api.php') {
    require __DIR__ . '/api.php';
    return true;
}

if ($uri === '/') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');   // 客户端会更新：HTML 不许缓存，刷新必得新代码
    readfile(__DIR__ . '/index.html');
    return true;
}

$ext = strtolower(pathinfo($uri, PATHINFO_EXTENSION));
$allowed = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8',
    'mjs' => 'text/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/plain; charset=utf-8',
];

$target = realpath(__DIR__ . $uri);
$root = realpath(__DIR__);
if (isset($allowed[$ext]) && $target !== false && $root !== false
    && strpos($target, $root . DIRECTORY_SEPARATOR) === 0 && is_file($target)) {
    if (in_array($ext, ['html', 'js', 'mjs'], true)) {
        header('Content-Type: ' . $allowed[$ext]);
        header('Cache-Control: no-store');   // 脚本不许缓存：避免旧 JS 在修复后赖着不走
        readfile($target);
        return true;
    }
    return false; // 交给 PHP 内置服务器原样输出
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not Found';
return true;
