<?php
declare(strict_types=1);

require_once __DIR__ . '/Logic.php';

/**
 * 房间存储：每个房间一个文件，带文件锁的「读-改-写」。
 *
 * 文件写成 "PHP 封印 + JSON"（<?php exit(0); ?> 后接 JSON）：
 * 万一直接用 `php -S` 起服务而没有走 router.php，浏览器请求到该文件也只会得到空响应，
 * 不会泄露房间数据。router.php 另外还会直接屏蔽 /data 目录（双保险）。
 */
final class RoomException extends Exception
{
}

final class RoomStore
{
    private const SEAL = "<?php exit(0); ?>\n";
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // 去掉易混的 0/O/1/I/L
    private const CODE_LEN = 5;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__) . '/data/rooms';
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function ensureReady(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            // 报错信息不带服务器路径（避免泄露目录结构）
            throw new RoomException('无法创建数据目录（请检查 data 目录写权限）。');
        }
    }

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function validCode(string $code): bool
    {
        return (bool)preg_match('/^[A-Z2-9]{5}$/', $code);
    }

    public static function randomToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function randomCode(): string
    {
        $alpha = self::CODE_ALPHABET;
        $n = strlen($alpha);
        $s = '';
        for ($i = 0; $i < self::CODE_LEN; $i++) {
            $s .= $alpha[random_int(0, $n - 1)];
        }
        return $s;
    }

    public function path(string $code): string
    {
        return $this->dir . '/' . self::normalizeCode($code) . '.php';
    }

    public function exists(string $code): bool
    {
        return is_file($this->path($code));
    }

    /**
     * 创建房间。$init($room) 在写盘前调用，可填入玩家等初始数据。
     * @return array 房间数据
     */
    public function create(callable $init): array
    {
        $this->ensureReady();
        for ($try = 0; $try < 24; $try++) {
            $code = self::randomCode();
            $room = [
                'code' => $code,
                'created' => time(),
                'seq' => 0,
                'size' => Logic::SIDE,
                'seats' => ['B' => null, 'W' => null, 'R' => null, 'G' => null, 'Y' => null, 'L' => null],
                'guests' => [],
                'joinRequest' => null,
                'moves' => [],
                'state' => Logic::newGameState(),
                'proposal' => null,
            ];
            $init($room);
            $fp = @fopen($this->path($code), 'x+'); // 独占创建，天然防碰撞
            if ($fp === false) {
                continue;
            }
            flock($fp, LOCK_EX);
            fwrite($fp, self::encode($room));
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            return $room;
        }
        throw new RoomException('生成房间号失败，请重试。');
    }

    /** 只读打开（不加锁，供调试）。 */
    public function read(string $code): ?array
    {
        $p = $this->path($code);
        if (!is_file($p)) {
            return null;
        }
        $raw = @file_get_contents($p);
        return $raw === false ? null : self::decode($raw);
    }

    /**
     * 原子「读-改-写」。回调签名：function (array &$room)，返回值原样透传。
     * @template T
     * @param callable $fn
     * @return T
     */
    public function update(string $code, callable $fn)
    {
        $this->ensureReady();
        $p = $this->path($code);
        $fp = @fopen($p, 'c+');
        if ($fp === false) {
            throw new RoomException('无法打开房间文件（请检查 data 目录写权限）。');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RoomException('房间正被占用，请稍后再试。');
            }
            $raw = stream_get_contents($fp);
            $room = ($raw === false || $raw === '' || $raw === false) ? null : self::decode($raw);
            if ($room === null) {
                throw new RoomException('房间 ' . self::normalizeCode($code) . ' 不存在或已失效。');
            }
            $result = $fn($room);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, self::encode($room));
            fflush($fp);
            flock($fp, LOCK_UN);
            return $result;
        } finally {
            fclose($fp);
        }
    }

    /** 清理长期没人用的房间文件。 */
    public function gc(int $maxAgeDays = 7): void
    {
        if (!is_dir($this->dir)) {
            return;
        }
        $deadline = time() - $maxAgeDays * 86400;
        foreach (glob($this->dir . '/*.php') ?: [] as $f) {
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < $deadline) {
                @unlink($f);
            }
        }
    }

    private static function encode(array $room): string
    {
        return self::SEAL . json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function decode(string $raw): ?array
    {
        $pos = strpos($raw, '?>');
        $json = trim($pos === false ? $raw : substr($raw, $pos + 2));
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}
