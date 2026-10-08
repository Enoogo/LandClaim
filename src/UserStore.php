<?php
declare(strict_types=1);

/**
 * 账号存储（0.8.0：注册 / 登录，替代原来的「填昵称」）。
 *
 *   · 注册：用户名 + 密码 → 开户（密码用 password_hash 存哈希，不存明文）；
 *   · 登录：用户名 + 密码 → 换一个登录令牌（auth）；手机 / 电脑用同一账号登录，
 *     进同一个房间就能接管自己原来的成员身份、接着下同一盘棋；
 *   · 会话令牌存 data/users.php（"PHP 封印 + JSON"，与房间数据同款防泄露格式）。
 */
final class UserStore
{
    private const SEAL = "<?php exit(0); ?>\n";
    private const SESSION_TTL = 30 * 86400;   // 登录令牌有效期：30 天

    private string $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?? dirname(__DIR__) . '/data/users.php';
    }

    /** 用户名：2-16 位字母 / 数字 / 下划线 / 中文。 */
    public static function validUser(string $user): bool
    {
        return (bool)preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,16}$/u', $user);
    }

    /** 注册新账号（重名拒绝），成功返回 ['ok'=>true,'user'=>..,'auth'=>..]。 */
    public function register(string $user, string $pass): array
    {
        $user = trim($user);
        if (!self::validUser($user)) {
            throw new RoomException('用户名要 2-16 位（字母 / 数字 / 下划线 / 中文）。');
        }
        if (strlen($pass) < 4 || strlen($pass) > 64) {
            throw new RoomException('密码要 4-64 位。');
        }
        return $this->update(function (array &$data) use ($user, $pass): array {
            foreach ($data['users'] as $u => $info) {
                // $u 可能是 int（纯数字用户名经 JSON 往返后数组键会变成整数）：先转成字符串再比较
                if (self::lower((string)$u) === self::lower($user)) {
                    throw new RoomException('这个用户名已经注册过了，请换一个，或直接「登录」。');
                }
            }
            $data['users'][$user] = [
                'hash' => password_hash($pass, PASSWORD_DEFAULT),
                'created' => time(),
            ];
            $auth = $this->newSession($data, $user);
            return ['ok' => true, 'user' => $user, 'auth' => $auth];
        });
    }

    /** 登录（用户名 + 密码），成功返回 ['ok'=>true,'user'=>..,'auth'=>..]。 */
    public function login(string $user, string $pass): array
    {
        $user = trim($user);
        return $this->update(function (array &$data) use ($user, $pass): array {
            $info = $data['users'][$user] ?? null;
            if ($info === null || !password_verify($pass, (string)($info['hash'] ?? ''))) {
                throw new RoomException('用户名或密码不对。');
            }
            $auth = $this->newSession($data, $user);
            return ['ok' => true, 'user' => $user, 'auth' => $auth];
        });
    }

    /** 校验登录令牌（建房 / 进房时带上，同一账号换设备也能认出来）。 */
    public function verify(string $user, string $auth): bool
    {
        if ($user === '' || $auth === '') {
            return false;
        }
        $data = $this->readData();
        $s = $data['sessions'][$auth] ?? null;
        return is_array($s) && hash_equals((string)($s['user'] ?? ''), $user);
    }

    /** 统一小写比较用户名（没有 mbstring 扩展也能跑；纯数字用户名的键可能是 int，先转字符串）。 */
    private static function lower(string|int $s): string
    {
        $s = (string)$s;
        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }

    /** @return string 新登录令牌 */
    private function newSession(array &$data, string $user): string
    {
        $this->gcSessions($data);
        $auth = bin2hex(random_bytes(20));
        $data['sessions'][$auth] = ['user' => $user, 'at' => time()];
        return $auth;
    }

    /** 会话清理：超过有效期的登录令牌直接丢掉。 */
    private function gcSessions(array &$data): void
    {
        $deadline = time() - self::SESSION_TTL;
        foreach ($data['sessions'] as $k => $s) {
            if ((int)($s['at'] ?? 0) < $deadline) {
                unset($data['sessions'][$k]);
            }
        }
    }

    /** @return array{users:array,sessions:array} */
    private function readData(): array
    {
        $raw = @file_get_contents($this->file);
        $data = $raw === false ? null : self::decode($raw);
        return $data ?? ['users' => [], 'sessions' => []];
    }

    /** 原子「读-改-写」。 @return mixed */
    private function update(callable $fn)
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            // 报错信息不带服务器路径（避免泄露目录结构）
            throw new RoomException('无法创建数据目录（请检查 data 目录写权限）。');
        }
        $fp = @fopen($this->file, 'c+');
        if ($fp === false) {
            throw new RoomException('无法打开账号数据文件（请检查 data 目录写权限）。');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RoomException('账号数据正被占用，请稍后再试。');
            }
            $raw = stream_get_contents($fp);
            $data = ($raw === false || trim((string)$raw) === '') ? null : self::decode((string)$raw);
            $data = $data ?? ['users' => [], 'sessions' => []];
            $result = $fn($data);
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, self::SEAL . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            fflush($fp);
            flock($fp, LOCK_UN);
            return $result;
        } finally {
            fclose($fp);
        }
    }

    /** @return array{users:array,sessions:array}|null */
    private static function decode(string $raw): ?array
    {
        $pos = strpos($raw, '?>');
        $json = trim($pos === false ? $raw : substr($raw, $pos + 2));
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? ($data + ['users' => [], 'sessions' => []]) : null;
    }
}
