<?php
declare(strict_types=1);

/**
 * 棍棋 · 三角版 — PHP 逻辑层单元测试
 * 断言与 ../../tests/logic-test.mjs（JS 版）同源，并覆盖联机版新增的三条规则：
 *   ② 新开一串可以贴住任何一根已下的棍
 *   ③ 禁止在对方地盘里只下一根棍就围出一块地（一步抢地）
 *   ④ 分成一样大的两块（平分）不算地：不染色、不计数
 * 运行：php tests/logic-test.php
 */

require __DIR__ . '/../src/Logic.php';

$passed = 0;
$failed = 0;

function ok(bool $cond, string $name, $extra = null): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        $s = $extra === null ? '' : '  → 实际值: ' . json_encode($extra, JSON_UNESCAPED_UNICODE);
        echo "  ✗ {$name}{$s}\n";
    }
}

function eq($a, $b, string $name): void
{
    ok($a === $b, $name . '（期望 ' . json_encode($b, JSON_UNESCAPED_UNICODE) . '）', $a);
}

Logic::boot(); // 构建静态几何

echo "\n[1] 六边形三角棋盘几何（边长 19）\n";
eq(Logic::SIDE, 19, '六边形边长');
eq(count(Logic::$points), 3 * 19 * 19 + 3 * 19 + 1, '网格点 3n²+3n+1 = 1141');
eq(count(Logic::$points), 1141, '网格点总数');
eq(count(Logic::$triangles), 6 * 19 * 19, '正三角形格 6n² = 2166');
eq(count(Logic::$triangles), 2166, '三角格总数');
eq(count(Logic::$frameEdges), 6 * 19, '边框边 6n = 114');
eq(count(Logic::$allEdges), 9 * 19 * 19 + 3 * 19 - 6 * 19, '可落子边 = 总边 9n²+3n − 边框 6n = 3192');
eq(count(Logic::$allEdges), 3192, '可落子边总数');
eq(count(Logic::$points) - (count(Logic::$allEdges) + count(Logic::$frameEdges)) + count(Logic::$triangles), 1,
    '欧拉公式 V-E+F=1 自洽');

echo "\n[2] 区域划分\n";
$empty = Logic::newGameState();
$r0 = Logic::computeRegions($empty['sticks']);
eq(count($r0['sizes']), 1, '空棋盘只有一个区域');
eq($r0['sizes'][0], 2166, '空棋盘区域 = 全部 2166 格');

echo "\n[3] 开局：必须新开一串（贴边框）\n";
$legal = Logic::computeLegal($empty);
eq($legal['mode'], 'anchor', '开局为「新开一串」模式');
ok(count($legal['moves']) > 0, '开局有合法落点（' . count($legal['moves']) . ' 个）');
$allTouchFrame = true;
foreach ($legal['moves'] as $ek) {
    $ps = Logic::edgePoints($ek);
    $touch = isset(Logic::$framePoints[Logic::pointKey($ps[0][0], $ps[0][1])])
        || isset(Logic::$framePoints[Logic::pointKey($ps[1][0], $ps[1][1])]);
    if (!$touch) {
        $allTouchFrame = false;
        break;
    }
}
ok($allTouchFrame, '所有开局落点都贴住棋盘边框');
$brute = 0;
foreach (Logic::$allEdges as $ek) {
    $ps = Logic::edgePoints($ek);
    if (isset(Logic::$framePoints[Logic::pointKey($ps[0][0], $ps[0][1])])
        || isset(Logic::$framePoints[Logic::pointKey($ps[1][0], $ps[1][1])])) {
        $brute++;
    }
}
eq(count($legal['moves']), $brute, '合法落点数与暴力枚举一致');
ok(Logic::whyIllegal($empty, Logic::edgeKey(5, 0, 6, 0)) !== null, '不贴边框的首手非法');
ok(Logic::whyIllegal($empty, Logic::edgeKey(18, 0, 18, 1)) === null, '贴边框的棍合法');
ok(Logic::whyIllegal($empty, Logic::edgeKey(19, 0, 18, 1)) !== null, '压在边框线上的棍非法');

echo "\n[4] 末端规则：只能接在上一手的末端（线头）上\n";
$s4 = Logic::newGameState();
$res = Logic::placeMove($s4, Logic::edgeKey(18, 0, 18, 1)); // 黑：贴边框（端点 (18,1) 在边框上）
ok($res['ok'] === true && $res['claimed'] === null, '黑方首手 (18,0)→(18,1) 合法且未围地');
ok($s4['head'] !== null && $s4['head'][0] === 18 && $s4['head'][1] === 0, '线头（末端）在 (18,0)', $s4['head']);
$legal = Logic::computeLegal($s4);
eq($legal['mode'], 'link', '下一手为「末端延伸」模式');
eq(count($legal['moves']), 5, '线头处 5 个可延伸方向');
ok(!isset($legal['set'][Logic::edgeKey(18, 1, 17, 1)]), '接在棍的另一端（非末端）→ 不合法');
ok(Logic::whyIllegal($s4, Logic::edgeKey(18, 1, 17, 1)) !== null, '接另一端被拒绝');
ok(isset($legal['set'][Logic::edgeKey(18, 0, 19, 0)]), '接在末端 (18,0) → 合法');
$res = Logic::placeMove($s4, Logic::edgeKey(18, 0, 19, 0)); // 白
ok($res['ok'] === true, '白方接在末端合法');
ok($s4['head'][0] === 19 && $s4['head'][1] === 0, '线头前进到 (19,0)', $s4['head']);
ok(Logic::whyIllegal($s4, Logic::edgeKey(18, 0, 18, 1)) !== null, '重合位置非法');

echo "\n[5] 围地：较小的那块归落子方\n";
$s5 = Logic::newGameState();
Logic::placeMove($s5, Logic::edgeKey(18, 0, 19, 0));        // 黑：贴边框
$res = Logic::placeMove($s5, Logic::edgeKey(18, 0, 18, 1)); // 白：接末端，三面合围一个三角格
$triCorner = Logic::findTriangle([18, 0], [19, 0], [18, 1]);
ok($triCorner >= 0, '目标三角格存在');
ok($res['ok'] && $res['claimed'] !== null && $res['claimed']['count'] === 1, '白方围得 1 格', $res['claimed']);
eq($s5['owner'][$triCorner], '2', '该三角格归白方');
eq(Logic::score($s5)['B'], 0, '黑方 0 格');
eq(Logic::score($s5)['W'], 1, '白方 1 格');
eq($s5['anchorRequired'], true, '围地后必须重新「新开一串」');

echo "\n[6] 围地后：新开一串可以贴住任何一根已下的棍（规则②）\n";
ok(Logic::whyIllegal($s5, Logic::edgeKey(5, 0, 6, 0)) !== null, '不贴边框也不贴任何棍 → 非法');
ok(Logic::whyIllegal($s5, Logic::edgeKey(18, 0, 17, 0)) === null, '贴到已下的棍的端点 → 合法');
$s5b = $s5;
$s5b['sticks'][Logic::edgeKey(5, 0, 6, 0)] = 'B';   // 手工放一根远离地盘、没围出地的棍
ok(Logic::whyIllegal($s5b, Logic::edgeKey(5, 0, 5, 1)) === null,
    '贴住「没围出地」的棍也能落子（规则②）');
ok(Logic::whyIllegal($s5b, Logic::edgeKey(4, 0, 4, 1)) !== null,
    '离这根棍很远的地方仍然不能随便落子');

echo "\n[7] 平分不算（规则④）：分成一样大的两块 → 不染色、不计数\n";
$s7 = Logic::newGameState();
// 手工摆出白方 2 格地盘（菱形 = 上三角 tA + 下三角 tB，3 根白棍 + 1 条边框围住）
$s7['sticks'][Logic::edgeKey(18, 0, 19, 0)] = 'W';
$s7['sticks'][Logic::edgeKey(18, 1, 17, 1)] = 'W';
$s7['sticks'][Logic::edgeKey(17, 1, 18, 0)] = 'W';
$tA = Logic::findTriangle([18, 0], [19, 0], [18, 1]);
$tB = Logic::findTriangle([18, 0], [17, 1], [18, 1]);
$s7['owner'][$tA] = '2';
$s7['owner'][$tB] = '2';
$s7['turn'] = 'B';
$s7['anchorRequired'] = true;
$reg7 = Logic::computeRegions($s7['sticks']);
eq($reg7['reg'][$tA], $reg7['reg'][$tB], '两格同属一个区域');
eq($reg7['sizes'][$reg7['reg'][$tA]], 2, '这个区域正好 2 格');
$res = Logic::placeMove($s7, Logic::edgeKey(18, 0, 18, 1)); // 黑：从中间切成 1+1
ok($res['ok'] === true, '平分的这步棋可以下', $res);
ok($res['claimed'] === null, '平分不算地：不染色、不计数', $res['claimed']);
eq($s7['owner'][$tA], '2', '左半块仍是白方');
eq($s7['owner'][$tB], '2', '右半块仍是白方');
eq($s7['anchorRequired'], true, '平分之后下一步走「新开一串」');
eq($s7['sinceClaim'], 1, '平分不算围地：计数器照常 +1（不归零）');

echo "\n[8] 禁止一步抢地（规则③）：计数器=1 时不能在对方已围的地里围地\n";
// 白方刚围完地（计数器归 0）：3 格地盘沿边框的长条 tA-tB-tC
//   tA=(18,0)(19,0)(18,1)  tB=(18,0)(18,1)(17,1)  tC=(17,1)(18,1)(17,2)
//   围边：白棍 (18,0)-(19,0)、(17,1)-(18,0)、(17,2)-(17,1) + 边框 (19,0)-(18,1)、(18,1)-(17,2)
function strip3State(): array
{
    $s = Logic::newGameState();
    $s['sticks'][Logic::edgeKey(18, 0, 19, 0)] = 'W';
    $s['sticks'][Logic::edgeKey(17, 1, 18, 0)] = 'W';
    $s['sticks'][Logic::edgeKey(17, 2, 17, 1)] = 'W';
    $tA = Logic::findTriangle([18, 0], [19, 0], [18, 1]);
    $tB = Logic::findTriangle([18, 0], [18, 1], [17, 1]);
    $tC = Logic::findTriangle([17, 1], [18, 1], [17, 2]);
    $s['owner'][$tA] = '2';
    $s['owner'][$tB] = '2';
    $s['owner'][$tC] = '2';
    $s['turn'] = 'B';
    $s['anchorRequired'] = true;
    $s['sinceClaim'] = 0;   // 刚围完地：计数器归 0，下一手读数为 1
    return $s;
}
$tA8 = Logic::findTriangle([18, 0], [19, 0], [18, 1]);
$s8 = strip3State();
ok(Logic::findTriangle([18, 0], [18, 1], [17, 1]) >= 0 && Logic::findTriangle([17, 1], [18, 1], [17, 2]) >= 0,
    '三个目标三角格都存在');
$reg8 = Logic::computeRegions($s8['sticks']);
eq($reg8['sizes'][$reg8['reg'][$tA8]], 3, '白方地盘正好 3 格');
// 计数器=1 + 在对方已围的地里围地 → 两个条件同时满足 → 本次围地无效（不允许）
$bad = Logic::whyIllegal($s8, Logic::edgeKey(18, 0, 18, 1));
ok($bad !== null, '计数器=1 且在对方地里围地 → 不允许', $bad);
$res = Logic::placeMove($s8, Logic::edgeKey(18, 0, 18, 1));
ok($res['ok'] === false, 'placeMove 同样拒绝这步棋', $res);
eq($s8['owner'][$tA8], '2', '白方地盘没有被翻色');
// 计数器=2（围完地后已经下过一手）：同一手围地允许
$s8b = strip3State();
$s8b['sinceClaim'] = 1;
$res = Logic::placeMove($s8b, Logic::edgeKey(18, 0, 18, 1));
ok($res['ok'] === true && $res['claimed'] !== null && $res['claimed']['count'] === 1,
    '计数器=2：同一手围地允许，抢到 1 格', $res);
eq($s8b['owner'][$tA8], '1', '抢到的那格归黑方');
eq($s8b['sinceClaim'], 0, '围完地之后计数器归 0');
// 条件②不满足（围的是空地）：计数器=1 也允许
$s8c = Logic::newGameState();
Logic::placeMove($s8c, Logic::edgeKey(18, 0, 19, 0));     // 黑：贴边框
$s8c['sinceClaim'] = 0;                                    // 模拟：这是围完地后的第一手
$res = Logic::placeMove($s8c, Logic::edgeKey(18, 0, 18, 1)); // 白：在空地里围出 1 格
ok($res['ok'] === true && $res['claimed'] !== null && $res['claimed']['count'] === 1,
    '计数器=1 但在空地里围地 → 允许', $res);
eq($s8c['sinceClaim'], 0, '围完地之后计数器归 0');
// 没有围地：计数器照常 +1
$s8d = Logic::newGameState();
Logic::placeMove($s8d, Logic::edgeKey(18, 0, 18, 1));
eq($s8d['sinceClaim'], 1, '下一手没有围地：计数器 +1');
Logic::placeMove($s8d, Logic::edgeKey(18, 0, 17, 0));
eq($s8d['sinceClaim'], 2, '再下一手仍没围地：计数器 +1');

echo "\n[9] 末端被堵死 → 自动改走「新开一串」（棋局不僵死）\n";
$s9 = Logic::newGameState();
$s9['sticks'][Logic::edgeKey(18, 0, 19, 0)] = 'B';
$s9['head'] = [19, 0];          // 线头在棋盘角上，唯一可延伸的边已被占
$s9['anchorRequired'] = false;
$legal = Logic::computeLegal($s9);
eq($legal['mode'], 'anchor', '末端无路可走时转为新开一串');
ok(count($legal['moves']) > 0, '仍有合法落点（' . count($legal['moves']) . ' 个）');

echo "\n[10] 不设「停一手」；认输与终局\n";
ok(!method_exists(Logic::class, 'pass'), '规则里没有 pass（停一手）');
$s10 = Logic::newGameState();
Logic::placeMove($s10, Logic::edgeKey(18, 0, 19, 0));
Logic::resign($s10); // 此时轮到白方，白方认输
ok($s10['over'] && $s10['winner'] === 'B', '白方认输 → 黑胜', $s10['winner']);

echo "\n[11] 悔棋快照\n";
$s11 = Logic::newGameState();
$snap = Logic::cloneState($s11);
Logic::placeMove($s11, Logic::edgeKey(18, 0, 19, 0));
eq(count($s11['sticks']), 1, '快照后落子 1 根');
eq(count($snap['sticks']), 0, '快照不受后续落子影响');
ok($snap['head'] === null && $s11['head'] !== null, '快照保留落子前线头状态');

echo "\n[12] 联机附加：指定认输方 / 无处可下终局\n";
$s12 = Logic::newGameState();
Logic::placeMove($s12, Logic::edgeKey(18, 0, 19, 0));   // 黑落子，轮到白
Logic::resignAs($s12, 'W');                              // 白方（行棋方）认输
ok($s12['over'] && $s12['winner'] === 'B', 'resignAs(W) → 黑胜', $s12['winner']);
$s12b = Logic::newGameState();
Logic::placeMove($s12b, Logic::edgeKey(18, 0, 19, 0));
Logic::resignAs($s12b, 'B');                             // 未行棋方（黑）也可认输
ok($s12b['over'] && $s12b['winner'] === 'W', 'resignAs(B) → 白胜', $s12b['winner']);
$s12c = Logic::newGameState();
ok(Logic::maybeEndGame($s12c) === false, '开局有合法落点，不会立即终局');
eq($s12c['over'], false, '对局继续');
// 手动结束对局：按地数（三角格数）判定
$s12d = Logic::newGameState();
Logic::placeMove($s12d, Logic::edgeKey(18, 0, 19, 0));   // 黑
Logic::placeMove($s12d, Logic::edgeKey(18, 0, 18, 1));   // 白围得 1 格
Logic::endGameAs($s12d, 'W');
ok($s12d['over'] === true, '手动结束对局 → 终局');
eq($s12d['winner'], 'W', '白方地数多 → 白胜');
$s12e = Logic::newGameState();
Logic::endGameAs($s12e, 'B');
eq($s12e['winner'], 'D', '地数相同 → 和棋');

echo "\n[13] 落子记录标签\n";
eq(Logic::edgeLabel(Logic::edgeKey(18, 0, 19, 0)), '(18,0)→(19,0)', 'edgeLabel 格式');

echo "\n[14] 多人对局（黑白红绿紫蓝 轮流下）\n";
$mp = Logic::newGameState(['B', 'W', 'R']);
eq($mp['turn'], 'B', '三人局：黑先');
Logic::placeMove($mp, Logic::edgeKey(18, 0, 18, 1));
eq($mp['turn'], 'W', '黑落子后轮到白');
Logic::placeMove($mp, Logic::edgeKey(18, 0, 19, 0));
eq($mp['turn'], 'R', '白落子后轮到红');
eq($mp['order'], ['B', 'W', 'R'], '行棋顺序 = 黑白红');
Logic::resignAs($mp, 'R');
ok(!$mp['over'], '红方认输：还有两人，继续下');
eq($mp['turn'], 'W', '红认输后轮到白');
Logic::resignAs($mp, 'W');
ok($mp['over'] && $mp['winner'] === 'B', '只剩黑方 → 黑胜');
$mp6 = Logic::newGameState(Logic::COLORS);
eq($mp6['turn'], 'B', '六人局：黑先');
Logic::placeMove($mp6, Logic::edgeKey(18, 0, 18, 1));
eq($mp6['turn'], 'W', '六人局照样轮流下');
eq(Logic::markOf('R'), '3', '红色的格子标记 = 3');
eq(Logic::colorOfMark('5'), 'Y', '标记 5 = 紫方');

echo "\n结果：{$passed} 通过, {$failed} 失败\n";
exit($failed > 0 ? 1 : 0);
