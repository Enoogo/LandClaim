<?php
declare(strict_types=1);

/**
 * 房间领域逻辑测试（不需要起 HTTP 服务器）：
 *   · 「下来」让出座位：棋局 / 行棋轮次保留，空位可被接替（不重置棋盘）
 *   · **掉线不卡轮次**（回归）：座位空出来后轮次跳过空位，换别的颜色上场照样能下
 *   · 掉线自动下场（超过 VACATE_AFTER 没动静）：同样保局可接替
 *   · 提议（悔棋 / 结束对局 / 新对局）必须**所有在场玩家同意**才生效，有人拒绝即作废
 *   · 思考时间：轮到的一方超时被踢下场，本局不能再上场；新的一局解除
 *
 * 运行：php tests/service-test.php
 */

require __DIR__ . '/../src/RoomService.php';

$tmp = sys_get_temp_dir() . '/triangle-svc-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);

$passed = 0;
$failed = 0;
function ok(bool $cond, string $name, $extra = null): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✓ $name\n";
    } else {
        $failed++;
        echo '  ✗ ' . $name . ($extra !== null ? '  → ' . json_encode($extra, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }
}

$store = new RoomStore($tmp);
$svc = new RoomService($store);

/** 直接改房间文件：模拟「很久没动静」或「思考超时」。 */
function patchRoom(RoomStore $store, string $code, callable $fn): void
{
    $store->update($code, function (array &$room) use ($fn): array {
        $fn($room);
        return [];
    });
}

function rolesOf(array $payload): array
{
    $roles = [];
    foreach ($payload['members'] as $m) {
        $roles[] = $m['role'];
    }
    return $roles;
}

/** 用规则引擎给当前房间挑一手合法着法（免得测试里手写坐标）。 */
function pickMove(RoomStore $store, string $code): string
{
    $room = $store->read($code);
    Logic::setSize((int)$room['size']);
    $st = RoomService::rebuildState($room['moves'], (int)$room['size'], $room['state']['order'] ?? null);
    $st['turn'] = $room['state']['turn'];   // 轮次以房间为准（重放算不出跳空位）
    $legal = Logic::computeLegal($st);
    $moves = $legal['moves'] ?? [];
    if (count($moves) === 0) {
        throw new RuntimeException('没有合法着法可下');
    }
    return (string)$moves[0];
}

echo "\n[1] 下来（让出座位）：棋局保留、可接替；轮次跳过空位\n";
$a = $svc->create('甲');
$room = $a['room'];
$b = $svc->join($room, null, '乙');
$svc->sit($room, $a['token'], 'B');
$svc->sit($room, $b['token'], 'W');
$m = $svc->move($room, $a['token'], '18,0|18,1');
ok($m['ok'] && count($m['moves']) === 1 && $m['turn'] === 'W', '两人开打，黑方落第 1 手', $m['turn'] ?? null);

$st = $svc->stand($room, $b['token']);
ok($st['ok'] && $st['you'] === 'S', '白方「下来」→ 变回观众', $st['you'] ?? null);
ok(($st['order'] ?? []) === ['B', 'W'], '下来不认输：白方仍在行棋轮次里', $st['order'] ?? null);
ok(count($st['moves']) === 1 && $st['status'] === 'playing', '棋局原样保留（不重置棋盘）', $st['status'] ?? null);
ok($st['turn'] === 'B', '轮次跳过空着的白方 → 轮到黑方（不卡在白方回合）', $st['turn'] ?? null);

$c = $svc->join($room, null, '接手的人');
$tk = $svc->sit($room, $c['token'], 'W');
ok($tk['ok'] && $tk['you'] === 'W' && $tk['joinRequest'] === null, '新人直接坐上空位接替（不用上场申请）', $tk['you'] ?? null);
ok(count($tk['moves']) === 1 && ($tk['order'] ?? []) === ['B', 'W'], '接替后棋盘不重置、轮次不变', $tk['order'] ?? null);
$m2 = $svc->move($room, $a['token'], pickMove($store, $room));
ok($m2['ok'] && $m2['turn'] === 'W', '黑方落子后轮到接替的白方', $m2['turn'] ?? $m2);
$m3 = $svc->move($room, $c['token'], pickMove($store, $room));
ok($m3['ok'] && $m3['turn'] === 'B', '接替者可以接着下（再轮到黑方）', $m3['turn'] ?? $m3);

echo "\n[2] 掉线自动下场 + 回归：掉线后换颜色上场照样能下（不卡在原颜色的回合）\n";
patchRoom($store, $room, function (array &$r) use ($c): void {
    foreach ($r['seats'] as $k => $p) {
        if ($p !== null && hash_equals((string)$p['token'], (string)$c['token'])) {
            $r['seats'][$k]['lastSeen'] = time() - 120;
        }
    }
});
$sy = $svc->sync($room, $a['token']);   // 别人的同步会把掉线的人请下场
ok(!in_array('W', rolesOf($sy), true), '掉线的白方被自动请下场（座位空出）', rolesOf($sy));
ok(count($sy['moves']) === 3 && ($sy['order'] ?? []) === ['B', 'W'], '掉线下场不认输：棋局与轮次保留', $sy['order'] ?? null);
ok($sy['turn'] === 'B', '掉线下场后轮次跳过空位（不是白方的回合）', $sy['turn'] ?? null);
ok(strpos((string)($sy['notice']['text'] ?? ''), '掉线') !== false, '有掉线公告', $sy['notice'] ?? null);

// 回归：新人**换一个颜色**上场（红方），不该被「还是白方的回合」挡住
$d = $svc->join($room, null, '换色上场的人');
$jr = $svc->sit($room, $d['token'], 'R');
ok($jr['ok'] && $jr['you'] === 'R', '掉线后换红方上场（白方还空着也照样坐）', $jr['you'] ?? $jr);
$m4 = $svc->move($room, $a['token'], pickMove($store, $room));
ok($m4['ok'] && $m4['turn'] === 'R', '黑方落子后直接轮到红方（跳过空着的白方）', $m4['turn'] ?? $m4);
$m5 = $svc->move($room, $d['token'], pickMove($store, $room));
ok($m5['ok'], '换色上场的红方能正常落子（BUG 已修）', $m5['turn'] ?? $m5);

// 掉线者回来：还能坐回自己的颜色接替
$st2 = $svc->sync($room, $c['token']);
ok($st2['ok'] && $st2['you'] === 'S', '掉线者回来后是观众（可以再接替）', $st2['you'] ?? null);
$tk2 = $svc->sit($room, $c['token'], 'W');
ok($tk2['ok'] && $tk2['you'] === 'W', '掉线者可以坐回来接替继续下', $tk2['you'] ?? $tk2);

echo "\n[3] 提议必须所有在场玩家同意\n";
$before = count($tk2['moves']);
$pr = $svc->propose($room, $a['token'], 'undo');
ok($pr['ok'] && $pr['proposal'] !== null, '黑方发起悔棋提议', $pr['proposal'] ?? null);
$ans = $svc->answer($room, $c['token'], true);
ok($ans['ok'] && $ans['proposal'] !== null && count($ans['moves']) === $before, '只有白方同意还不够（要所有在场玩家同意）', $ans['proposal'] ?? null);
$ans2 = $svc->answer($room, $d['token'], true);
ok($ans2['ok'] && $ans2['proposal'] === null && count($ans2['moves']) === $before - 1, '三位都同意 → 悔棋生效', $ans2['moves'] ?? null);

// 拒绝 = 作废
$before2 = count($ans2['moves']);
$pr3 = $svc->propose($room, $a['token'], 'undo');
ok($pr3['ok'] && $pr3['proposal'] !== null, '再次发起悔棋提议', null);
$pr4 = $svc->answer($room, $d['token'], false);
ok($pr4['ok'] && $pr4['proposal'] === null && count($pr4['moves']) === $before2, '有人拒绝 → 提议作废（不回退）', $pr4['proposal'] ?? null);

// 结束对局：有人拒绝即作废，且转为不同意的人继续下（0.8.0）
$eg = $svc->propose($room, $a['token'], 'end');
ok($eg['ok'] && $eg['proposal'] !== null, '发起「结束对局」提议', null);
$svc->answer($room, $c['token'], true);
$eg2 = $svc->answer($room, $d['token'], false);
ok($eg2['ok'] && $eg2['proposal'] === null && $eg2['status'] === 'playing', '有人拒绝 → 结束对局作废，棋局继续', $eg2['status'] ?? null);
ok($eg2['turn'] === 'R', '不同意结束对局 → 转为不同意的人（红方）继续下', $eg2['turn'] ?? null);

// 结束对局：全员同意 → 生效
$eg3 = $svc->propose($room, $a['token'], 'end');
$svc->answer($room, $c['token'], true);
$eg4 = $svc->answer($room, $d['token'], true);
ok($eg4['ok'] && $eg4['over'] === true && $eg4['moves'][count($eg4['moves']) - 1]['end'] === true,
    '所有在场玩家同意 → 对局结束（按地数结算）', $eg4['winner'] ?? null);

echo "\n[4] 思考时间：超时被踢下场，本局不能再上场\n";
$e = $svc->create('丙');
$room2 = $e['room'];
$f = $svc->join($room2, null, '丁');
$svc->sit($room2, $e['token'], 'B');
$svc->sit($room2, $f['token'], 'W');
$svc->move($room2, $e['token'], '18,0|18,1');   // 轮到白方
$tk3 = $svc->setThink($room2, $e['token'], 5);
ok($tk3['ok'] && $tk3['thinkLimit'] === 5, '设置思考时间 5 秒', $tk3['thinkLimit'] ?? null);
patchRoom($store, $room2, function (array &$r): void {
    $r['turnStart'] = time() - 30;   // 白方已经想了 30 秒
});
$sy2 = $svc->sync($room2, $e['token']);
ok(!in_array('W', rolesOf($sy2), true), '白方思考超时 → 被踢下场（座位空出）', rolesOf($sy2));
ok($sy2['turn'] === 'B', '超时下场后轮次跳过空位（棋局继续）', $sy2['turn'] ?? null);
ok(count($sy2['moves']) === 1 && $sy2['status'] === 'playing', '超时不重置棋盘、不结束棋局', $sy2['status'] ?? null);
ok(strpos((string)($sy2['notice']['text'] ?? ''), '超时') !== false, '有超时公告', $sy2['notice'] ?? null);

$again = null;
$err = '';
try {
    $again = $svc->sit($room2, $f['token'], 'W');
} catch (RoomException $ex) {
    $err = $ex->getMessage();
}
ok($again === null && strpos($err, '超时') !== false, '被超时踢下场的人本局不能再上场', $err);

// 只剩一位在场玩家：提议立即生效（= 全员同意）
$np = $svc->propose($room2, $e['token'], 'new');
ok($np['ok'] && $np['proposal'] === null && count($np['moves']) === 0, '只剩一位在场：「新对局」提议立即生效', $np['status'] ?? null);
$back2 = $svc->sit($room2, $f['token'], 'W');
ok($back2['ok'] && $back2['you'] === 'W', '新的一局解除了超时禁座', $back2['you'] ?? $back2);

echo "\n[5] 注册账号换设备登录：接管原身份接着下（0.8.0）\n";
$u1 = $svc->create('老张', null, 'laozhang');
$room3 = $u1['room'];
$u2 = $svc->join($room3, null, '小李', 'xiaoli');
$svc->sit($room3, $u1['token'], 'B');
$svc->sit($room3, $u2['token'], 'W');
$m1 = $svc->move($room3, $u1['token'], '18,0|18,1');
ok($m1['ok'] && $m1['turn'] === 'W', '登录用户（黑方）落第 1 手', $m1['error'] ?? $m1['turn'] ?? null);

// 手机退出 / 掉线：电脑登录同一账号 → 直接接管黑方座位，棋局不重置
$u3 = $svc->join($room3, null, '老张', 'laozhang');
ok($u3['ok'] && $u3['you'] === 'B' && $u3['token'] !== $u1['token'],
    '同一账号从别的设备登录 → 接管黑方座位（换新令牌）', $u3['you'] ?? null);
ok(count($u3['moves']) === 1 && ($u3['order'] ?? []) === ['B', 'W'], '接管后棋局不重置、轮次不变', $u3['order'] ?? null);
$err = '';
try {
    $svc->sync($room3, $u1['token']);
} catch (RoomException $ex) {
    $err = $ex->getMessage();
}
ok($err !== '' && strpos($err, '身份') !== false, '旧设备的令牌作废（一台设备一个会话）', $err);

// 主动下来后账号回来：自动坐回自己让出的颜色接着下
$svc->stand($room3, $u3['token']);
$u4 = $svc->join($room3, null, '老张', 'laozhang');
ok($u4['ok'] && $u4['you'] === 'B', '账号回来自动坐回黑方接着下（掉线 / 下来让出的颜色）', $u4['you'] ?? null);

echo "\n[6] 「结束对局」被多人拒绝：按本该轮到的顺序，转给第一个拒绝的人（0.8.1）\n";
$p1 = $svc->create('发起人');
$room4 = $p1['room'];
$p2 = $svc->join($room4, null, '拒绝人乙');
$p3 = $svc->join($room4, null, '拒绝人丙');
$svc->sit($room4, $p1['token'], 'B');
$svc->sit($room4, $p2['token'], 'W');
$req4 = $svc->sit($room4, $p3['token'], 'R');
ok($req4['ok'] && $req4['joinRequest'] !== null, '第三位坐下 = 上场申请', $req4['joinRequest'] ?? null);
$svc->vote($room4, $p1['token'], true);
$vr4 = $svc->vote($room4, $p2['token'], true);
ok($vr4['ok'] && in_array('R', $vr4['order'] ?? [], true), '全员同意 → 黑白红三位上场', $vr4['order'] ?? null);
$mm4 = $svc->move($room4, $p1['token'], pickMove($store, $room4));
ok($mm4['ok'] && $mm4['turn'] === 'W', '黑方落子后轮到白方（提议前该落子的人）', $mm4['turn'] ?? null);

// 白方提议「手动结束对局」：黑方先拒绝 → 先转给拒绝的黑方
$ep4 = $svc->propose($room4, $p2['token'], 'end');
ok($ep4['ok'] && $ep4['proposal'] !== null, '白方发起「结束对局」提议', $ep4['error'] ?? null);
$rj1 = $svc->answer($room4, $p1['token'], false);
ok($rj1['ok'] && $rj1['proposal'] === null && $rj1['turn'] === 'B', '黑方拒绝 → 提议作废、转给拒绝的黑方', $rj1['turn'] ?? null);

// 红方几乎同时也点了拒绝：按本该轮到的顺序（白→红→黑）红方排在黑方前面 → 转给红方
$rj2 = $svc->answer($room4, $p3['token'], false);
ok($rj2['ok'] && $rj2['turn'] === 'R', '红方的拒绝也并进来：按轮转顺序取第一个拒绝的人（红方）继续下', $rj2['turn'] ?? null);

$rm4 = $svc->move($room4, $p3['token'], pickMove($store, $room4));
ok($rm4['ok'] && $rm4['turn'] === 'B', '对局继续：红方落下这一手后照常轮转', $rm4['turn'] ?? $rm4);

// 已经落子之后再来迟到的拒绝：不该再改轮次（走「没有待处理的提议」）
$err = '';
try {
    $svc->answer($room4, $p1['token'], false);
} catch (RoomException $ex) {
    $err = $ex->getMessage();
}
$after4 = $svc->sync($room4, $p1['token']);
ok($err !== '' && $after4['turn'] === 'B', '落子后的迟到拒绝不再改轮次', ['err' => $err, 'turn' => $after4['turn'] ?? null]);

echo "\n结果：$passed 通过, $failed 失败\n";
exit($failed > 0 ? 1 : 0);
