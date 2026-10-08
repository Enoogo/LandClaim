<?php
declare(strict_types=1);

/**
 * PHP 规则引擎 ↔ JS 规则引擎 交叉验证
 *
 *  1. php tests/cross-check.php gen   用 PHP Logic 随机对局，写 tests/tmp/playouts.json
 *  2. node tests/replay-node.mjs      用 JS Logic（ServerVersion/index.html 里的模块）重放同一批着法
 *  3. php tests/cross-check.php cmp   逐步比对两者的结果（含每手围地数 / 合法落点哈希）
 *
 * 直接 `php tests/cross-check.php` 会自动串起 1→2→3（需要 node 在 PATH 上）。
 */

require __DIR__ . '/../src/Logic.php';

$tmp = __DIR__ . '/tmp';
$playoutsFile = $tmp . '/playouts.json';
$summaryFile = $tmp . '/summary-js.json';

const SEEDS = 8;
const MAX_MOVES = 150;

function summarize(array $state): array
{
    $keys = array_keys($state['sticks']);
    sort($keys, SORT_STRING);
    $parts = [];
    foreach ($keys as $k) {
        $parts[] = $k . '=' . $state['sticks'][$k];
    }
    $legal = Logic::computeLegal($state);
    $lm = $legal['moves'];
    sort($lm, SORT_STRING);
    $sc = Logic::score($state);
    // 字段顺序必须与 replay-node.mjs 完全一致（比对时按 JSON 文本比较）
    return [
        'moveNo' => $state['moveNo'],
        'over' => $state['over'],
        'winner' => $state['winner'],
        'turn' => $state['turn'],
        'head' => $state['head'],
        'lastStick' => $state['lastStick'],
        'anchorRequired' => $state['anchorRequired'],
        'black' => $sc['B'],
        'white' => $sc['W'],
        'ownerHash' => md5($state['owner']),
        'sticksHash' => md5(implode(';', $parts)),
        'legalMode' => $legal['mode'],
        'legalCount' => count($legal['moves']),
        'legalHash' => md5(implode(';', $lm)),
        'sinceClaim' => $state['sinceClaim'],
    ];
}

function generate(): array
{
    $playouts = [];
    for ($seed = 1; $seed <= SEEDS; $seed++) {
        mt_srand($seed * 7919);
        $state = Logic::newGameState();
        $moves = [];
        $claims = [];
        $trail = [];
        // 每 3 组安排一次认输（其中一半由「非行棋方」认输，覆盖 resignAs 分支）
        $resignAt = $seed % 3 === 0 ? mt_rand(5, 40) : null;

        while (count($moves) < MAX_MOVES && !$state['over']) {
            if ($resignAt !== null && count($moves) >= $resignAt) {
                $who = ($seed % 2 === 0) ? $state['turn'] : ($state['turn'] === 'B' ? 'W' : 'B');
                Logic::resignAs($state, $who);
                $moves[] = ['p' => $who, 'r' => true];
                $trail[] = summarize($state);
                break;
            }
            $legal = Logic::computeLegal($state);
            if (count($legal['moves']) === 0) {
                break;
            }
            $player = $state['turn'];
            $ek = $legal['moves'][mt_rand(0, count($legal['moves']) - 1)];
            $r = Logic::placeMove($state, $ek);
            if (!$r['ok']) {
                throw new RuntimeException('PHP 引擎拒绝了自己的着法：' . $ek);
            }
            $claims[] = $r['claimed'] !== null ? $r['claimed']['count'] : 0;
            Logic::maybeEndGame($state);
            $moves[] = ['p' => $player, 'e' => $ek];
            $trail[] = summarize($state);
        }
        $playouts[] = ['seed' => $seed, 'moves' => $moves, 'claims' => $claims, 'trail' => $trail];
    }
    return $playouts;
}

function gen(): void
{
    global $tmp, $playoutsFile;
    if (!is_dir($tmp) && !mkdir($tmp, 0775, true)) {
        fwrite(STDERR, "无法创建 {$tmp}\n");
        exit(1);
    }
    $t0 = microtime(true);
    $playouts = generate();
    file_put_contents($playoutsFile, json_encode(
        ['playouts' => $playouts],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));
    $n = 0;
    foreach ($playouts as $p) {
        $n += count($p['moves']);
    }
    printf("PHP 生成完毕：%d 组对局 / %d 手，用时 %.2fs\n", count($playouts), $n, microtime(true) - $t0);
    echo "  → {$playoutsFile}\n";
}

function cmp(): void
{
    global $playoutsFile, $summaryFile;
    if (!is_file($playoutsFile) || !is_file($summaryFile)) {
        fwrite(STDERR, "缺少中间文件，请先运行 gen 与 node tests/replay-node.mjs\n");
        exit(1);
    }
    $php = json_decode((string)file_get_contents($playoutsFile), true);
    $js = json_decode((string)file_get_contents($summaryFile), true);

    $failed = 0;
    $checked = 0;
    foreach ($php['playouts'] as $i => $pl) {
        $jl = $js[$i] ?? null;
        if ($jl === null || ($jl['seed'] ?? null) !== $pl['seed']) {
            echo "  ✗ 第 " . ($i + 1) . " 组：JS 侧缺失或 seed 不符\n";
            $failed++;
            continue;
        }
        if (!empty($jl['error'])) {
            echo "  ✗ 第 " . ($i + 1) . " 组（seed {$pl['seed']}）：JS 重放失败 —— {$jl['error']}\n";
            $failed++;
            continue;
        }
        if (json_encode($pl['claims']) !== json_encode($jl['claims'])) {
            echo "  ✗ 第 " . ($i + 1) . " 组（seed {$pl['seed']}）：每手围地数不一致\n";
            echo "     PHP: " . json_encode($pl['claims']) . "\n";
            echo "     JS : " . json_encode($jl['claims']) . "\n";
            $failed++;
            continue;
        }
        $bad = -1;
        foreach ($pl['trail'] as $k => $entry) {
            $checked++;
            $other = $jl['trail'][$k] ?? null;
            if (json_encode($entry) !== json_encode($other)) {
                $bad = $k;
                break;
            }
        }
        if ($bad >= 0) {
            echo "  ✗ 第 " . ($i + 1) . " 组（seed {$pl['seed']}）：第 " . ($bad + 1) . " 手之后局面不一致\n";
            echo "     PHP: " . json_encode($pl['trail'][$bad], JSON_UNESCAPED_UNICODE) . "\n";
            echo "     JS : " . json_encode($jl['trail'][$bad] ?? null, JSON_UNESCAPED_UNICODE) . "\n";
            $failed++;
        } else {
            echo "  ✓ 第 " . ($i + 1) . " 组（seed {$pl['seed']}，" . count($pl['moves']) . " 手）逐步一致\n";
        }
    }
    echo "\n比对 {$checked} 个局面快照，{$failed} 组不一致\n";
    exit($failed > 0 ? 1 : 0);
}

$cmd = $argv[1] ?? 'all';
switch ($cmd) {
    case 'gen':
        gen();
        break;
    case 'cmp':
        cmp();
        break;
    case 'all':
        gen();
        echo "\n— 用 JS 引擎重放（node tests/replay-node.mjs）—\n";
        passthru('node ' . escapeshellarg(__DIR__ . '/replay-node.mjs'), $code);
        if (!is_file($summaryFile)) {
            fwrite(STDERR, "node 未执行成功（退出码 " . var_export($code, true) . "），请手动运行：\n");
            fwrite(STDERR, "  node tests/replay-node.mjs && php tests/cross-check.php cmp\n");
            exit(1);
        }
        echo "\n— 比对 —\n";
        cmp();
        break;
    default:
        fwrite(STDERR, "用法：php tests/cross-check.php [gen|cmp|all]\n");
        exit(1);
}
