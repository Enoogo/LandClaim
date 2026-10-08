<?php
declare(strict_types=1);

/**
 * 联网对战服务器启动器（命令行运行）。
 *
 *   php start.php          使用默认端口 6850
 *   php start.php 7000     指定其它端口
 *
 * 启动 PHP 内置服务器（监听 0.0.0.0），并打印本机与联网访问地址。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Windows 控制台：用官方 API 切到 UTF-8，保证中文横幅正常显示。
// （批处理启动脚本本身必须保持纯 ASCII，中文都由本文件输出）
if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_cp_set')) {
    @sapi_windows_cp_set(65001);
}

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    fwrite(STDERR, "[错误] 需要 PHP 8.0 及以上版本，当前为 " . PHP_VERSION . "\n");
    exit(1);
}

$root = __DIR__;
$port = 6850;
if (isset($argv[1]) && preg_match('/^\d{2,5}$/', (string)$argv[1])) {
    $port = (int)$argv[1];
}
$addr = '0.0.0.0:' . $port;

// ---- 数据目录可写性检查 ----
require $root . '/src/RoomStore.php';
try {
    $store = new RoomStore();
    $store->ensureReady();
    $probe = $store->dir() . '/.write-probe';
    if (@file_put_contents($probe, 'ok') === false) {
        throw new RoomException('数据目录不可写');
    }
    @unlink($probe);
} catch (Throwable $e) {
    fwrite(STDERR, "[错误] 数据目录不可用：" . $e->getMessage() . "\n");
    fwrite(STDERR, "       请确认 ServerVersion/data 目录可写。\n");
    exit(1);
}

/** 找出本机可能的联网 IPv4 地址。 */
function lanIps(): array
{
    $ips = [];
    // UDP「连接」不发包，只用于让系统选出出口网卡
    $s = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
    if (is_resource($s)) {
        $name = (string)stream_socket_get_name($s, false);
        fclose($s);
        $host = explode(':', $name)[0];
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ips[] = $host;
        }
    }
    $hn = (string)@gethostbyname((string)gethostname());
    if (filter_var($hn, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ips[] = $hn;
    }
    return array_values(array_unique(array_filter($ips, function ($ip) {
        return strpos($ip, '127.') !== 0;
    })));
}

$ips = lanIps();
$line = str_repeat('=', 58);
echo "\n{$line}\n";
echo "  棍棋 · 三角版 — 联网双人对战服务器（PHP）\n";
$line2 = str_repeat('-', 58);
echo "{$line2}\n";
echo "  本机（做主机的这台）：  http://localhost:{$port}/\n";
foreach ($ips as $ip) {
    echo "  联网（对手用这个）：  http://{$ip}:{$port}/\n";
}
if (count($ips) === 0) {
    echo "  （没检测到联网 IP，请用 ipconfig / ifconfig 查看本机地址）\n";
}
echo "{$line2}\n";
echo "  · 对手打开上面的联网地址 → 输入房间号 → 即可同屏对战\n";
echo "  · 防火墙提示：首次运行请允许 php.exe 访问「专用网络」\n";
echo "  · 按 Ctrl+C 停止服务器\n";
echo "{$line}\n\n";

$php = PHP_BINARY;
// 关闭 OPcache：某些 Windows 环境（系统强制 ASLR）下 OPcache 会让内置服务器启动即崩
// （Fatal Error: Opcode handlers are unusable due to ASLR）。本项目很小，不需要 OPcache。
$cmd = escapeshellarg($php)
     . ' -d opcache.enable=0 -d opcache.enable_cli=0'
     . ' -S ' . escapeshellarg($addr)
     . ' -t ' . escapeshellarg($root) . ' '
     . escapeshellarg($root . DIRECTORY_SEPARATOR . 'router.php');

echo "正在启动：php -S {$addr}\n\n";
passthru($cmd, $exitCode);
echo "\n服务器已停止。\n";
exit($exitCode);
