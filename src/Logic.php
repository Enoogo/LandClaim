<?php
declare(strict_types=1);

/**
 * 棍棋 Land Claim — 纯逻辑层（PHP 移植）
 *
 * 与 ServerVersion/index.html 内嵌的 JS `Logic` 模块逐行对应，同一套规则：
 *   - 正六边形棋盘（默认边长 19，可按房间设置 3~40），全部由正三角形格铺成
 *   - 坐标原点 (0,0) 在棋盘正中心（a、b 均可为负），所以小棋盘的棋谱
 *     坐标可以直接放到大棋盘上看
 *   - 棍落在三角网格线上，不能重合、不能压边框
 *   - 末端（线头）延伸 / 新开一串 两种落子模式，互相兜底
 *   - 围地 · 抢地：新棍把区域一分为二后，较小的一块归落子方
 *
 * 状态表示（数组）：
 *   sticks          map: 边键 "a,b|c,d" => 颜色码 'B'|'W'|'R'|'G'|'Y'|'L'
 *   owner           string(6n²): 每个三角格 '0' 无主 / '1'..'6' 对应黑白红绿紫蓝
 *   turn            当前行棋方（颜色码）
 *   order           参赛顺序（颜色码列表，按黑白红绿紫蓝排序；认输会从中移除）
 *   head            [a,b] | null   当前线头（末端）所在网格点
 *   lastStick       边键 | null
 *   anchorRequired  bool           是否必须「新开一串」
 *   over / winner   终局标记与胜者（颜色码 | 'D' 和棋 | null）
 *   moveNo          int
 *
 * 无任何 I/O、无会话依赖，可在 CLI 下直接测试（tests/logic-test.php）。
 */
final class Logic
{
    public const SIDE = 19;        // 默认边长（房间未指定时使用）
    public const MIN_SIDE = 3;
    public const MAX_SIDE = 40;

    /** 棋子颜色（按行棋次序）：黑 白 红 绿 紫 蓝。owner 标记 '1'..'6' 就是下标 +1。 */
    public const COLORS = ['B', 'W', 'R', 'G', 'Y', 'L'];

    /** 颜色码 -> owner 标记字符 */
    public static function markOf(string $color): string
    {
        $i = array_search($color, self::COLORS, true);
        return $i === false ? '0' : (string)($i + 1);
    }

    /** owner 标记字符 -> 颜色码 */
    public static function colorOfMark(string $mark): ?string
    {
        $i = (int)$mark - 1;
        return ($i >= 0 && $i < count(self::COLORS)) ? self::COLORS[$i] : null;
    }

    /* ================= 坐标与键 ================= */

    public static function projX(int $a, int $b): float
    {
        return $a + $b * 0.5;
    }

    public static function projY(int $a, int $b): float
    {
        return $b * sqrt(3) / 2;
    }

    public static function pointKey(int $a, int $b): string
    {
        return $a . ',' . $b;
    }

    public static function edgeKey(int $a1, int $b1, int $a2, int $b2): string
    {
        if ($a1 < $a2 || ($a1 === $a2 && $b1 <= $b2)) {
            return $a1 . ',' . $b1 . '|' . $a2 . ',' . $b2;
        }
        return $a2 . ',' . $b2 . '|' . $a1 . ',' . $b1;
    }

    /**
     * 边键 -> 两端点 [[a1,b1],[a2,b2]]
     * @return array<int, array<int,int>>
     */
    public static function edgePoints(string $ek): array
    {
        $parts = explode('|', $ek);
        $s = explode(',', $parts[0]);
        $t = explode(',', $parts[1]);
        return [
            [(int)$s[0], (int)$s[1]],
            [(int)$t[0], (int)$t[1]],
        ];
    }

    public static function edgeLabel(string $ek): string
    {
        $ps = self::edgePoints($ek);
        $pt = function (array $p): string {
            return '(' . $p[0] . ',' . $p[1] . ')';
        };
        return $pt($ps[0]) . '→' . $pt($ps[1]);
    }

    /* ================= 静态几何（惰性构建一次） ================= */

    private static bool $booted = false;
    /** @var int|null 当前几何对应的六边形边长 */
    private static ?int $side = null;

    /** @var array<int, array<int,int>> 网格点 [a,b] */
    public static array $points = [];
    /** @var array<string,int> pointKey -> 索引 */
    private static array $pointIndex = [];
    /** @var array<int, array{id:int,pts:array,edges:array<string>}> */
    public static array $triangles = [];
    /** @var array<string, array<int,int>> 边键 -> 三角格 id */
    private static array $edgeTris = [];
    /** @var array<int,string> 可落子边（内部边，恰临 2 个三角格） */
    public static array $allEdges = [];
    /** @var array<int,string> 边框边（只临 1 个三角格，不可落子） */
    public static array $frameEdges = [];
    /** @var array<string,bool> 边框上的网格点 */
    public static array $framePoints = [];
    /** @var array<int, array{key:string,pts:array}> */
    public static array $edgeList = [];
    /** @var array<int, array> */
    public static array $frameEdgeList = [];
    /** @var array<string, array<int,string>> 网格点 -> 相邻可落子边 */
    private static array $vertexEdges = [];
    /** @var array<int, array<int,int>> 三角格 -> 3 条边对应的邻格（-1 为边框） */
    private static array $triNeighbors = [];

    /**
     * 构建 / 切换静态几何。
     * 不传 $side = 用当前（或默认 19）边长；传了 $side = 切换到该边长并重建。
     */
    public static function boot(?int $side = null): void
    {
        if ($side !== null) {
            $side = max(self::MIN_SIDE, min(self::MAX_SIDE, $side));
            if (self::$side !== $side) {
                self::$side = $side;
                self::$booted = false;
            }
        }
        if (self::$booted) {
            return;
        }
        if (self::$side === null) {
            self::$side = self::SIDE;
        }
        // 清空旧几何（切换棋盘边长时整体重建）
        self::$pointIndex = [];
        self::$points = [];
        self::$triangles = [];
        self::$edgeTris = [];
        self::$allEdges = [];
        self::$frameEdges = [];
        self::$framePoints = [];
        self::$edgeList = [];
        self::$frameEdgeList = [];
        self::$vertexEdges = [];
        self::$triNeighbors = [];
        self::$bfsStamp = [];
        self::$bfsStampCounter = 0;

        $side = self::$side;

        // ---- 网格点 ----
        for ($a = -$side; $a <= $side; $a++) {
            for ($b = -$side; $b <= $side; $b++) {
                if (abs($a + $b) <= $side) {
                    self::$pointIndex[self::pointKey($a, $b)] = count(self::$points);
                    self::$points[] = [$a, $b];
                }
            }
        }

        // ---- 三角形（格子）----
        for ($a = -$side - 1; $a <= $side; $a++) {
            for ($b = -$side - 1; $b <= $side; $b++) {
                if (self::hasPoint($a, $b) && self::hasPoint($a + 1, $b) && self::hasPoint($a, $b + 1)) {
                    self::addTri([[$a, $b], [$a + 1, $b], [$a, $b + 1]]);
                }
                if (self::hasPoint($a + 1, $b) && self::hasPoint($a, $b + 1) && self::hasPoint($a + 1, $b + 1)) {
                    self::addTri([[$a + 1, $b], [$a, $b + 1], [$a + 1, $b + 1]]);
                }
            }
        }

        // ---- 边 -> 三角格 ----
        foreach (self::$triangles as $t) {
            foreach ($t['edges'] as $ek) {
                self::$edgeTris[$ek][] = $t['id'];
            }
        }

        // ---- 可落子边 / 边框边 ----
        foreach (self::$edgeTris as $ek => $tris) {
            if (count($tris) === 2) {
                self::$allEdges[] = $ek;
            } else {
                self::$frameEdges[] = $ek;
                foreach (self::edgePoints($ek) as $p) {
                    self::$framePoints[self::pointKey($p[0], $p[1])] = true;
                }
            }
        }

        foreach (self::$allEdges as $ek) {
            $ps = self::edgePoints($ek);
            self::$edgeList[] = ['key' => $ek, 'pts' => $ps];
            foreach ($ps as $p) {
                self::$vertexEdges[self::pointKey($p[0], $p[1])][] = $ek;
            }
        }
        foreach (self::$frameEdges as $ek) {
            self::$frameEdgeList[] = self::edgePoints($ek);
        }

        // ---- 三角格邻接 ----
        foreach (self::$triangles as $t) {
            $nb = [];
            foreach ($t['edges'] as $ek) {
                $tris = self::$edgeTris[$ek];
                $nb[] = count($tris) === 2 ? ($tris[0] === $t['id'] ? $tris[1] : $tris[0]) : -1;
            }
            self::$triNeighbors[] = $nb;
        }

        self::$booted = true;
    }

    /** 切换棋盘边长（会重建几何）。 */
    public static function setSize(int $side): void
    {
        self::boot($side);
    }

    /** 当前棋盘边长。 */
    public static function side(): int
    {
        self::boot();
        return (int)self::$side;
    }

    private static function hasPoint(int $a, int $b): bool
    {
        return isset(self::$pointIndex[self::pointKey($a, $b)]);
    }

    /** @param array<int, array<int,int>> $pts */
    private static function addTri(array $pts): void
    {
        $edges = [];
        for ($i = 0; $i < 3; $i++) {
            $p = $pts[$i];
            $q = $pts[($i + 1) % 3];
            $edges[] = self::edgeKey($p[0], $p[1], $q[0], $q[1]);
        }
        self::$triangles[] = ['id' => count(self::$triangles), 'pts' => $pts, 'edges' => $edges];
    }

    /** @return int 三角格 id，找不到返回 -1 */
    public static function findTriangle(array $p1, array $p2, array $p3): int
    {
        self::boot();
        $want = [self::pointKey($p1[0], $p1[1]), self::pointKey($p2[0], $p2[1]), self::pointKey($p3[0], $p3[1])];
        sort($want);
        $want = implode(';', $want);
        foreach (self::$triangles as $t) {
            $have = array_map(function (array $p): string {
                return self::pointKey($p[0], $p[1]);
            }, $t['pts']);
            sort($have);
            if (implode(';', $have) === $want) {
                return $t['id'];
            }
        }
        return -1;
    }

    /* ================= 区域划分 ================= */

    /**
     * 以「棍 + 边框」为墙，对三角格做连通分量。
     * @param array<string,string> $sticks
     * @return array{reg: array<int,int>, sizes: array<int,int>}
     */
    public static function computeRegions(array $sticks): array
    {
        self::boot();
        $n = count(self::$triangles);
        $reg = array_fill(0, $n, -1);
        $sizes = [];
        for ($i = 0; $i < $n; $i++) {
            if ($reg[$i] !== -1) {
                continue;
            }
            $id = count($sizes);
            $stack = [$i];
            $reg[$i] = $id;
            $size = 0;
            while ($stack) {
                $t = array_pop($stack);
                $size++;
                $tinfo = self::$triangles[$t];
                for ($e = 0; $e < 3; $e++) {
                    if (isset($sticks[$tinfo['edges'][$e]])) {
                        continue;
                    }
                    $nb = self::$triNeighbors[$t][$e];
                    if ($nb >= 0 && $reg[$nb] === -1) {
                        $reg[$nb] = $id;
                        $stack[] = $nb;
                    }
                }
            }
            $sizes[] = $size;
        }
        return ['reg' => $reg, 'sizes' => $sizes];
    }

    /** @param array<int,int> $reg */
    private static function firstCellOfRegion(array $reg, int $r): int
    {
        foreach ($reg as $i => $v) {
            if ($v === $r) {
                return $i;
            }
        }
        return -1;
    }

    /* ================= 新开一串的锚点 ================= */

    /**
     * 棋盘边框上的点 + 任何一根已下的棍的端点。
     * @return array<string,bool>
     */
    public static function anchorVertexSet(array $state): array
    {
        self::boot();
        $set = self::$framePoints;
        foreach ($state['sticks'] as $ek => $player) {
            foreach (self::edgePoints($ek) as $p) {
                $set[self::pointKey($p[0], $p[1])] = true;
            }
        }
        return $set;
    }

    /* ================= 落子方式 ================= */

    /** 末端（线头）延伸：接在上一根棍的末端上。 @return array<int,string> */
    public static function tipExtensions(array $state): array
    {
        self::boot();
        if ($state['head'] === null) {
            return [];
        }
        $edges = self::$vertexEdges[self::pointKey($state['head'][0], $state['head'][1])] ?? [];
        $res = [];
        foreach ($edges as $ek) {
            if (!isset($state['sticks'][$ek])) {
                $res[] = $ek;
            }
        }
        return $res;
    }

    /** 新开一串：贴住边框或任何一根已下的棍。 @return array<int,string> */
    public static function anchorMoves(array $state): array
    {
        self::boot();
        $avs = self::anchorVertexSet($state);
        $res = [];
        foreach (self::$allEdges as $ek) {
            if (isset($state['sticks'][$ek])) {
                continue;
            }
            $ps = self::edgePoints($ek);
            if (isset($avs[self::pointKey($ps[0][0], $ps[0][1])]) || isset($avs[self::pointKey($ps[1][0], $ps[1][1])])) {
                $res[] = $ek;
            }
        }
        return $res;
    }

    /* ================= 抢地限制：禁止在对方地盘里「只下一根棍」围出一块地 ================= */

    /** @var array<int,int> BFS 访问标记（用序号标记，避免每次清空） */
    private static array $bfsStamp = [];
    private static int $bfsStampCounter = 0;

    /**
     * 抢地限制（规则③）：围完地之后的第一手，不能在对方已经围的地里围出一块地。
     * 计数器 state['sinceClaim']：每下一手 +1，每次围完地归 0。
     * 这手的计数器读数为 1（= 围完地后的第一手）且这次围地落在对方已经围的地里
     * → 本次围地无效（这手不允许）。平分（两块一样大）不算地（规则④），放行。
     */
    public static function stealBlocked(array $state, string $ek): bool
    {
        self::boot();
        if ($state['sinceClaim'] !== 0) {
            return false;   // 计数器读数 ≠ 1 → 不禁
        }
        $tris = self::$edgeTris[$ek];
        $mine = self::markOf((string)$state['turn']);
        $o1 = $state['owner'][$tris[0]];
        $o2 = $state['owner'][$tris[1]];
        // 条件②：这次围地落在「别人」已经围的地里（两侧同属另一方才有这个问题）
        if ($o1 === '0' || $o1 !== $o2 || $o1 === $mine) {
            return false;
        }

        // 从一侧出发（不跨过新棍），看看能不能绕到另一侧：绕得到 = 没切开 = 不围地
        $stampA = ++self::$bfsStampCounter;
        $sideA = [$tris[0]];
        self::$bfsStamp[$tris[0]] = $stampA;
        $reached = false;
        for ($i = 0; $i < count($sideA) && !$reached; $i++) {
            $t = $sideA[$i];
            $tinfo = self::$triangles[$t];
            for ($e = 0; $e < 3; $e++) {
                $ekey = $tinfo['edges'][$e];
                if ($ekey === $ek || isset($state['sticks'][$ekey])) {
                    continue;
                }
                $nb = self::$triNeighbors[$t][$e];
                if ($nb < 0 || (self::$bfsStamp[$nb] ?? 0) === $stampA) {
                    continue;
                }
                if ($nb === $tris[1]) {
                    $reached = true;
                    break;
                }
                self::$bfsStamp[$nb] = $stampA;
                $sideA[] = $nb;
            }
        }
        if ($reached) {
            return false;
        }

        // 切开了：算另一侧（两边一样大 = 平分不算地 → 放行）
        $stampB = ++self::$bfsStampCounter;
        $sideB = [$tris[1]];
        self::$bfsStamp[$tris[1]] = $stampB;
        for ($i = 0; $i < count($sideB); $i++) {
            $t = $sideB[$i];
            $tinfo = self::$triangles[$t];
            for ($e = 0; $e < 3; $e++) {
                $ekey = $tinfo['edges'][$e];
                if ($ekey === $ek || isset($state['sticks'][$ekey])) {
                    continue;
                }
                $nb = self::$triNeighbors[$t][$e];
                if ($nb < 0 || (self::$bfsStamp[$nb] ?? 0) === $stampB) {
                    continue;
                }
                self::$bfsStamp[$nb] = $stampB;
                $sideB[] = $nb;
            }
        }
        return count($sideA) !== count($sideB);   // 计数器=1 且在对方地里围出一块地 → 本次围地无效
    }

    /**
     * 当前生效的落子方式：末端延伸 / 新开一串（互相兜底，保证棋局不会僵死）。
     * 同时剔除被抢地限制（只下一根棍抢地）禁止的落点。
     * @return array{mode:string, moves: array<int,string>, set: array<string,bool>}
     */
    public static function computeLegal(array $state): array
    {
        if ($state['over']) {
            return ['mode' => 'none', 'moves' => [], 'set' => []];
        }
        if (!$state['anchorRequired']) {
            $mode = 'link';
            $moves = self::filterLegal($state, self::tipExtensions($state));
            if (count($moves) === 0) {
                $mode = 'anchor';
                $moves = self::filterLegal($state, self::anchorMoves($state));
            }
        } else {
            $mode = 'anchor';
            $moves = self::filterLegal($state, self::anchorMoves($state));
            if (count($moves) === 0) {
                $mode = 'link';
                $moves = self::filterLegal($state, self::tipExtensions($state));
            }
        }
        $set = [];
        foreach ($moves as $ek) {
            $set[$ek] = true;
        }
        return ['mode' => $mode, 'moves' => $moves, 'set' => $set];
    }

    /** @param array<int,string> $raw @return array<int,string> */
    private static function filterLegal(array $state, array $raw): array
    {
        $ok = [];
        foreach ($raw as $ek) {
            if (!self::stealBlocked($state, $ek)) {
                $ok[] = $ek;
            }
        }
        return $ok;
    }

    /** @return string|null null 表示合法 */
    public static function whyIllegal(array $state, string $ek): ?string
    {
        self::boot();
        if ($state['over']) {
            return '对局已经结束，请点击「新对局」。';
        }
        if (!isset(self::$edgeTris[$ek])) {
            return '棋盘上没有这个位置。';
        }
        if (count(self::$edgeTris[$ek]) !== 2) {
            return '棍不能压在棋盘边框线上（不能与边界线重合）。';
        }
        if (isset($state['sticks'][$ek])) {
            return '这里已经有一根棍了，不能重合。';
        }
        $legal = self::computeLegal($state);
        if (isset($legal['set'][$ek])) {
            return null;
        }
        if (self::stealBlocked($state, $ek)) {
            return '本次围地无效：不能在<b>围完地之后的第一手</b>就在对方已经围的地里围出一块地。请先在别处落一手，再回来围。';
        }
        return $legal['mode'] === 'link'
            ? '必须接在上一根棍的<b>末端（线头）</b>上，不能接它的另一端。'
            : '现在要<b>新开一串</b>：必须与棋盘边框或<b>任何一根已下的棍</b>连在一起。';
    }

    public static function isLegal(array $state, string $ek): bool
    {
        return self::whyIllegal($state, $ek) === null;
    }

    /* ================= 状态 ================= */

    /**
     * @param array<int,string>|null $order 参赛顺序（颜色码），默认黑白两人
     */
    public static function newGameState(?array $order = null): array
    {
        self::boot();
        $order = $order === null ? ['B', 'W'] : array_values(array_filter($order, function ($c) {
            return in_array($c, self::COLORS, true);
        }));
        return [
            'sticks' => [],
            'owner' => str_repeat('0', count(self::$triangles)),
            'turn' => count($order) > 0 ? $order[0] : 'B',
            'order' => $order,
            'head' => null,
            'lastStick' => null,
            'anchorRequired' => true,
            'over' => false,
            'winner' => null,
            'moveNo' => 0,
            'sinceClaim' => 0,   // 计数器：每下一手 +1，围完地归 0（规则③）
        ];
    }

    public static function cloneState(array $s): array
    {
        return [
            'sticks' => $s['sticks'],
            'owner' => $s['owner'],
            'turn' => $s['turn'],
            'order' => array_values($s['order'] ?? ['B', 'W']),
            'head' => $s['head'] === null ? null : [$s['head'][0], $s['head'][1]],
            'lastStick' => $s['lastStick'],
            'anchorRequired' => $s['anchorRequired'],
            'over' => $s['over'],
            'winner' => $s['winner'],
            'moveNo' => $s['moveNo'],
            'sinceClaim' => $s['sinceClaim'],
        ];
    }

    /** @return array<string,int> 各颜色的地数 ['B'=>..,'W'=>..,'R'=>..,'G'=>..,'Y'=>..,'L'=>..] */
    public static function score(array $state): array
    {
        $counts = array_fill_keys(self::COLORS, 0);
        $n = strlen((string)$state['owner']);
        for ($i = 0; $i < $n; $i++) {
            $m = $state['owner'][$i];
            if ($m !== '0') {
                $c = self::colorOfMark($m);
                if ($c !== null) {
                    $counts[$c]++;
                }
            }
        }
        return $counts;
    }

    /**
     * 按地数定胜负：在仍参赛（order）的颜色里取唯一的最多者；并列或没人 = 和棋 'D'。
     */
    private static function decideWinner(array $state): string
    {
        $sc = self::score($state);
        $best = null;
        $bestN = -1;
        $tie = false;
        foreach ($state['order'] as $c) {
            $n = $sc[$c] ?? 0;
            if ($n > $bestN) {
                $best = $c;
                $bestN = $n;
                $tie = false;
            } elseif ($n === $bestN) {
                $tie = true;
            }
        }
        return ($best === null || $tie) ? 'D' : $best;
    }

    /** 下一个行棋方：按参赛顺序轮转（与前端 JS 同名接口一致；房间层跳过空座也用它）。 */
    public static function nextTurn(array $order, string $mover): string
    {
        $n = count($order);
        if ($n === 0) {
            return $mover;
        }
        $i = array_search($mover, $order, true);
        if ($i === false) {
            return $order[0];
        }
        return $order[($i + 1) % $n];
    }

    /* ================= 落子：包含围地 / 抢地结算 ================= */

    /**
     * @param string|null $player 棋谱回放时指定行棋方（默认用 state['turn']）
     * @return array{ok:bool, reason?:string, claimed?:array{count:int,player:string,indices:array<int,int>}|null}
     */
    public static function placeMove(array &$state, string $ek, ?string $player = null): array
    {
        self::boot();
        $prevTurn = $state['turn'];
        if ($player !== null) {
            $state['turn'] = $player;   // 棋谱回放：按记录上的行棋方来下
        }
        $bad = self::whyIllegal($state, $ek);
        if ($bad !== null) {
            $state['turn'] = $prevTurn;   // 没下成：回合保持原样（重放失败不能把轮次改乱）
            return ['ok' => false, 'reason' => $bad];
        }

        $player = $state['turn'];
        $legal = self::computeLegal($state);
        $anchorSet = $legal['mode'] === 'anchor' ? self::anchorVertexSet($state) : null;
        $before = self::computeRegions($state['sticks']);

        $state['sticks'][$ek] = $player;
        $state['lastStick'] = $ek;

        // 更新线头（末端）
        $ps = self::edgePoints($ek);
        if ($legal['mode'] === 'link') {
            $isOldHead = function (array $p) use ($state): bool {
                return $p[0] === $state['head'][0] && $p[1] === $state['head'][1];
            };
            $state['head'] = $isOldHead($ps[0]) ? $ps[1] : $ps[0];
        } else {
            $inA = function (array $p) use ($anchorSet): bool {
                return isset($anchorSet[self::pointKey($p[0], $p[1])]);
            };
            if ($inA($ps[0]) && !$inA($ps[1])) {
                $state['head'] = $ps[1];
            } elseif ($inA($ps[1]) && !$inA($ps[0])) {
                $state['head'] = $ps[0];
            } else {
                $state['head'] = null; // 两端都贴在结构上：没有自由末端，须重新开一串
            }
        }

        // 围地：一根棍最多把一个区域一分为二，取「比较小的那块」
        // 规则：两块一样大（平分）→ 不算地（不染色、不计数），但下一步照常走「新开一串」
        $after = self::computeRegions($state['sticks']);
        $claimed = null;
        $splitHappened = count($after['sizes']) > count($before['sizes']);
        if ($splitHappened) {
            // 找出被切开的父区域的两块子区域
            $parentOf = [];
            foreach ($after['reg'] as $i => $r) {
                if (!isset($parentOf[$r])) {
                    $parentOf[$r] = $before['reg'][$i];
                }
            }
            $children = [];
            foreach ($parentOf as $r => $parent) {
                $children[$parent][] = $r;
            }
            $pair = null;
            foreach ($children as $list) {
                if (count($list) === 2) {
                    $pair = $list;
                }
            }
            $even = $pair !== null && $after['sizes'][$pair[0]] === $after['sizes'][$pair[1]];
            if (!$even) {
                $best = -1;
                foreach ($after['sizes'] as $r => $sizeR) {
                    $ci = self::firstCellOfRegion($after['reg'], $r);
                    $parent = $before['reg'][$ci];
                    if ($sizeR < $before['sizes'][$parent]) {
                        if ($best === -1 || $sizeR < $after['sizes'][$best]) {
                            $best = $r;
                        }
                    }
                }
                if ($best >= 0) {
                    $indices = [];
                    $mark = self::markOf($player);
                    foreach ($after['reg'] as $i => $region) {
                        if ($region === $best) {
                            $state['owner'][$i] = $mark;
                            $indices[] = $i;
                        }
                    }
                    $claimed = ['count' => count($indices), 'player' => $player, 'indices' => $indices];
                }
            }
        }

        $state['anchorRequired'] = $splitHappened;
        $state['turn'] = self::nextTurn($state['order'] ?? [], $player);
        $state['moveNo']++;
        // 计数器（规则③）：每下一手 +1；每次围完地之后归 0（平分不算围地，照常 +1）
        $state['sinceClaim'] = $claimed !== null ? 0 : $state['sinceClaim'] + 1;
        return ['ok' => true, 'claimed' => $claimed, 'even' => $splitHappened && $claimed === null];
    }

    /* ================= 终局 ================= */

    /** 认输：默认由当前行棋方认输（与 JS Logic.resign 语义一致）。 */
    public static function resign(array &$state): array
    {
        return self::resignAs($state, $state['turn']);
    }

    /**
     * 指定某一方认输（联机模式下由发起者认输，与轮到谁无关）。
     * 多人对局：认输 = 退出比赛，其余人继续下；只剩一人时对局结束、该人胜；
     * 两人对局认输即终局、对方获胜（与旧行为一致）。
     */
    public static function resignAs(array &$state, string $player): array
    {
        if ($state['over']) {
            return ['ok' => false, 'reason' => '对局已经结束。'];
        }
        $order = $state['order'] ?? ['B', 'W'];
        $idx = array_search($player, $order, true);
        $state['order'] = array_values(array_filter($order, function ($c) use ($player) {
            return $c !== $player;
        }));
        if (count($state['order']) <= 1) {
            $state['over'] = true;
            $state['winner'] = count($state['order']) === 1 ? $state['order'][0] : 'D';
        } elseif ($state['turn'] === $player) {
            $n = count($state['order']);
            $state['turn'] = $state['order'][min($idx === false ? 0 : (int)$idx, $n - 1)];
        }
        $state['moveNo']++;
        return ['ok' => true];
    }

    /** 手动结束对局：按地数（三角格数）判定仍在参赛者中最多的一方，相同为和。 */
    public static function endGameAs(array &$state, string $player): array
    {
        if ($state['over']) {
            return ['ok' => false, 'reason' => '对局已经结束。'];
        }
        $state['over'] = true;
        $state['winner'] = self::decideWinner($state);
        $state['moveNo']++;
        return ['ok' => true];
    }

    /**
     * 无处可下即终局（无「停一手」）。返回是否结束。
     */
    public static function maybeEndGame(array &$state): bool
    {
        if ($state['over']) {
            return true;
        }
        $legal = self::computeLegal($state);
        if (count($legal['moves']) > 0) {
            return false;
        }
        $state['over'] = true;
        $state['winner'] = self::decideWinner($state);
        return true;
    }
}
