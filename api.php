<?php
declare(strict_types=1);

/**
 * 联机对局 HTTP API（POST JSON）。
 *
 *   action=create   {name?, size?, user?, auth?}    建房（创建者也先当观众；带 user+auth = 登录用户）
 *   action=join     {room, token?, name?, user?, auth?}  加入 / 断线重连（一律先当观众，人数不限；
 *                                                 同一账号换设备登录 → 接管原来的成员身份接着下）
 *   action=register {user, pass}                 注册新账号（用户名 + 密码）
 *   action=login    {user, pass}                 登录（返回登录令牌 auth，换设备通用）
 *   action=auth     {user, auth}                 校验登录令牌（页面打开时恢复登录状态）
 *   action=sit      {room, token, color}          坐下上场（黑白红绿紫蓝；对局中的新颜色=上场申请，
 *                                                 接替空位=直接坐下继续下，棋盘不重置）
 *   action=stand    {room, token}                 下来：主动让出座位（棋局保留，可被别人接替）
 *   action=vote     {room, token, accept}         对上场申请表态（全部棋手同意才上场）
 *   action=leave    {room, token}                 退出房间（棋手=认输让座；观众=直接离开）
 *   action=state    {room, token}                 轮询同步（着法、棋盘、成员、申请、思考时间等）
 *   action=move     {room, token, edge}           落子
 *   action=resign   {room, token}                 认输（退出比赛，让出座位）
 *   action=end      {room, token}                 手动结束对局（= 发提议；全员同意后按地数结算）
 *   action=think    {room, token, seconds}        设置思考时间（秒，0 = 不限制；超时被踢下场）
 *   action=size     {room, token, size}           更改棋盘大小（清空棋局重新开始）
 *   action=load     {room, token, moves, size?}   载入棋谱并从这里续下（分支）
 *   action=propose  {room, token, type}           提议：undo 悔一手 / new 新对局 / end 结束对局
 *   action=answer   {room, token, accept}         回应提议（全部在场玩家同意才生效；提议者调用 = 撤销）
 *
 * 成员角色：you = 'B' 黑 / 'W' 白 / 'R' 红 / 'G' 绿 / 'Y' 紫 / 'L' 蓝 / 'S' 观众。
 * 所有响应均为 JSON：成功 {ok:true, ...}，失败 {ok:false, error:"..."}。
 */

require __DIR__ . '/src/RoomService.php';
require __DIR__ . '/src/UserStore.php';

/** 客户端构建号：与 index.html 的 CLIENT_BUILD 必须一致（tests/ui-check.cjs 会校验）。
 *  对不上 = 浏览器还在跑旧版页面的 JS：一律拒绝并提示强制刷新，避免界面提示与服务器轮次对不上。 */
const CLIENT_BUILD = 'v5';

// 任何 PHP 警告 / 错误都不回显给浏览器（警告里会带服务器绝对路径）：只写进服务器日志
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $msg): void
{
    respond(['ok' => false, 'error' => $msg]);
}

$raw = file_get_contents('php://input');
$in = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($in)) {
    $in = $_POST;
}
if (!is_array($in)) {
    fail('请求格式错误（需要 JSON）。');
}

$str = function (string $key, string $default = '') use ($in): string {
    return isset($in[$key]) && is_scalar($in[$key]) ? trim((string)$in[$key]) : $default;
};

$action = $str('action');
$room = $str('room');
$token = $str('token');

$int = function (string $key, ?int $default = null) use ($in): ?int {
    return isset($in[$key]) && is_numeric($in[$key]) ? (int)$in[$key] : $default;
};

// 客户端构建号校验：旧页面（跑着旧 JS）请求一律拒绝，提示强制刷新（客户端收到后会自动刷新）
if ($str('ver') !== CLIENT_BUILD) {
    respond([
        'ok' => false,
        'stale' => true,
        'error' => '这个页面还在跑【旧版客户端】（页面 ' . ($str('ver') !== '' ? $str('ver') : '未知')
            . ' / 服务器 ' . CLIENT_BUILD . '）：请按 【Ctrl+F5】 强制刷新，或关掉旧标签页重新打开。',
    ]);
}

try {
    $svc = new RoomService();
    $users = new UserStore();

    /** 建房 / 进房带 user+auth = 已登录的账号（同一账号换设备登录可接管原身份）；都不带 = 游客身份。 */
    $account = function () use ($users, $str): ?string {
        $user = $str('user');
        $auth = $str('auth');
        if ($user === '' && $auth === '') {
            return null;
        }
        if ($user === '' || $auth === '' || !$users->verify($user, $auth)) {
            fail('登录已过期或无效，请重新「登录」后再建房 / 进房。');
        }
        return $user;
    };

    switch ($action) {
        case 'register':
            respond($users->register($str('user'), (string)($in['pass'] ?? '')));

        case 'login':
            respond($users->login($str('user'), (string)($in['pass'] ?? '')));

        case 'auth':
            if (!$users->verify($str('user'), $str('auth'))) {
                fail('登录已过期，请重新登录。');
            }
            respond(['ok' => true, 'user' => $str('user')]);

        case 'create':
            respond($svc->create($str('name') ?: null, $int('size'), $account()));

        case 'join':
            respond($svc->join($room, $token !== '' ? $token : null, $str('name') ?: null, $account()));

        case 'sit':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            $color = strtoupper($str('color'));
            if ($color === '') {
                fail('缺少座位颜色。');
            }
            respond($svc->sit($room, $token, $color));

        case 'stand':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            respond($svc->stand($room, $token));

        case 'think':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            $seconds = $int('seconds');
            if ($seconds === null || $seconds < 0) {
                fail('缺少思考时间（seconds，秒；0 = 不限制）。');
            }
            respond($svc->setThink($room, $token, $seconds));

        case 'vote':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            respond($svc->vote($room, $token, isset($in['accept']) && filter_var($in['accept'], FILTER_VALIDATE_BOOLEAN)));

        case 'leave':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            respond($svc->leave($room, $token));

        case 'state':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            respond($svc->sync($room, $token));

        case 'size':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            $size = $int('size');
            if ($size === null) {
                fail('缺少棋盘大小。');
            }
            respond($svc->setSize($room, $token, $size));

        case 'load':
            if ($room === '' || $token === '') {
                fail('缺少房间号或身份令牌。');
            }
            $moves = isset($in['moves']) && is_array($in['moves']) ? $in['moves'] : null;
            if ($moves === null) {
                fail('缺少棋谱着法（moves）。');
            }
            respond($svc->load($room, $token, $moves, $int('size')));

        case 'move':
            $edge = $str('edge');
            if ($edge === '') {
                fail('缺少落子位置。');
            }
            respond($svc->move($room, $token, $edge));

        case 'resign':
            respond($svc->resign($room, $token));

        case 'end':
            respond($svc->end($room, $token));

        case 'propose':
            respond($svc->propose($room, $token, $str('type')));

        case 'answer':
            $accept = isset($in['accept']) && filter_var($in['accept'], FILTER_VALIDATE_BOOLEAN);
            respond($svc->answer($room, $token, $accept));

        default:
            fail('未知的操作：' . ($action === '' ? '(空)' : $action));
    }
} catch (RoomException $e) {
    fail($e->getMessage());
} catch (Throwable $e) {
    // 未预料的异常：只写服务器日志，回给浏览器的提示绝不带异常文本 / 服务器路径
    error_log('[TriangleServer] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('服务器内部错误，请稍后再试。（详细信息只记录在服务器日志里）');
}
