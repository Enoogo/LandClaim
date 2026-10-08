<?php
declare(strict_types=1);

require_once __DIR__ . '/Logic.php';
require_once __DIR__ . '/RoomStore.php';

/**
 * 房间对局的领域逻辑：建房 / 加入（一律先当观众）/ 坐下上场·接替 / 上场申请与全员同意 /
 * 下来（让出座位）/ 掉线自动下场 / 思考时间与超时踢下场 / 同步 / 落子 / 认输 / 改棋盘大小 /
 * 退出房间 / 载入棋谱·分支续下 / 提议（悔棋·新局·结束对局）/ 回应提议（全员同意）。
 *
 * 成员模型（人数不限，观众随时进出）：
 *   seats[颜色] = 棋手 ['token','id','name','joined','lastSeen'] | null
 *                 颜色取 Logic::COLORS：B 黑 / W 白 / R 红 / G 绿 / Y 紫 / L 蓝（最多 6 人）
 *   guests[]    = 观众 ['token','id','name','joined','lastSeen']
 *   syncPayload 的 you = 坐下的颜色（'B'..'L'）或 'S'（观众）；members 给出所有人的身份。
 *
 * 上场规则：
 *   · 在场棋手不足 2 人：点空位直接坐下，人齐即开打；
 *   · 对局进行中新颜色坐下（已有 2 人及以上）：坐下 = 发「上场申请」，**所有在场棋手都同意**
 *     才能坐上去（于是可以变成 3 人、4 人……最多 6 人）；
 *   · **接替**：座位空出来（主动下来 / 掉线自动下场 / 思考超时被踢下场）时，
 *     该颜色仍留在行棋轮次里、棋局原样保留，新来的人**直接坐上去接替继续下**（不重置棋盘）。
 *
 * 下来 / 超时 = 让出座位但**不认输**：棋局与参赛顺序保持不变，**轮到空着的颜色自动跳过**
 * （棋局继续、绝不卡在下场者的回合上），接替者上场后照常轮到他。
 * 认输（resign）= 退出比赛（座位保留），只剩一人时该人胜；退出房间（leave）= 认输并让出座位。
 *
 * 提议（悔棋 undo / 新对局 new / 结束对局 end）：**所有在场（坐在座位上）的玩家都同意**才生效，
 * 有人拒绝或 2 分钟没集齐同意即作废（提议者可撤销）。
 * 「结束对局」被**拒绝**时对局继续，且**按本该轮到的顺序、转给第一个拒绝的人继续下**
 * （多人局里几乎同时拒绝的，取轮转顺序上最靠前的那个）。
 *
 * 账号（0.8.0）：成员可带登录账号 `user`。同一账号从别的设备登录进同一房间
 * （adoptAccount）→ 接管自己原来的成员身份（令牌换新、旧设备作废）；掉线 / 下来
 * 让出的颜色会记在 `lastColor` 上，账号回来时座位还空着就自动坐回去接着下。
 *
 * 思考时间（thinkLimit 秒，0 = 不限制）：轮到的一方超过时限**自动被请下场**（棋局保留，
 * 可被别人接替；被超时踢下场的人本局不能再上场，新的一局自动解除）。
 *
 * 行棋次序：黑→白→红→绿→紫→蓝 轮流（state['order']）；认输 = 退出比赛，只剩一人时该人胜。
 *
 * 对局事实以「着法序列」为准：
 *   moves = [ ['p'=>'B','e'=>'18,0|18,1'], ['p'=>'W','r'=>true], ['p'=>'R','j'=>true], ... ]
 *   （'e' 落子边键 / 'r' 认输退出 / 'end' 手动终局 / 'j' 进入行棋轮次：中途上场、认输后坐回来）
 * 每个房间缓存一份 state 快照（= 重放 moves 的结果），用于落子校验；悔棋回退一手再重放。
 */
final class RoomService
{
    private const ONLINE_WINDOW = 12;  // 秒：超过则显示离线
    private const REQUEST_TTL = 120;   // 秒：上场申请 / 提议有效期
    private const VACATE_AFTER = 30;   // 秒：掉线超过这个时间自动下场（棋局保留，可被接替）
    private const ID_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // 身份标识（去掉易混的 0/O/1/I/L）
    private const ID_LEN = 4;

    private RoomStore $store;

    public function __construct(?RoomStore $store = null)
    {
        $this->store = $store ?? new RoomStore();
    }

    /* ================= 建房 / 加入 / 退出 ================= */

    /** 建房：创建者也先当观众，在房间里选颜色坐下后才上场。$user = 登录账号（可选）。 */
    public function create(?string $name = null, ?int $size = null, ?string $user = null): array
    {
        $size = self::clampSize($size ?? Logic::SIDE);
        Logic::setSize($size);
        $this->store->gc();
        $token = RoomStore::randomToken();
        $room = $this->store->create(function (array &$room) use ($token, $name, $size, $user): void {
            $room['size'] = $size;
            $room['seats'] = self::emptySeats();
            $room['joinRequest'] = null;
            $room['guests'][] = self::newSeat($token, $name, $user);
            $room['state'] = Logic::newGameState([]);
        });
        return $this->syncPayload($room, 'S', $token);
    }

    /**
     * 加入房间：一律先当观众（带旧 token = 断线重连，保留原身份 / 座位）。
     * $user = 登录账号：同一账号从别的设备登录进来 → 直接接管自己原来的成员身份
     * （座位保留、棋局不动，旧设备的令牌作废），手机退出了电脑登录同一账号就能接着下。
     */
    public function join(string $code, ?string $token = null, ?string $name = null, ?string $user = null): array
    {
        $code = RoomStore::normalizeCode($code);
        if (!RoomStore::validCode($code)) {
            throw new RoomException('房间号格式不对（应为 5 位字母数字）。');
        }
        $issued = null;
        $result = $this->store->update($code, function (array &$room) use ($token, $name, $user, &$issued): array {
            self::normalizeRoom($room);
            // 1) 带旧 token 回来 = 断线重连
            if ($token !== null && $token !== '') {
                $found = self::locate($room, $token);
                if ($found !== null) {
                    self::touch($room, $found);
                    return $this->syncPayload($room, $found['role'], $token);
                }
            }
            // 2) 同一账号从别的设备登录：接管自己原来的成员身份（旧设备令牌作废）
            if ($user !== null && $user !== '') {
                $role = self::adoptAccount($room, $user, $name, $issued);
                if ($role !== null) {
                    return $this->syncPayload($room, $role, $issued);
                }
            }
            // 3) 新成员：一律先当观众，想下棋去「座位」里坐下
            $issued = RoomStore::randomToken();
            $room['guests'][] = self::newSeat($issued, $name, $user);
            return $this->syncPayload($room, 'S', $issued);
        });
        return $result;
    }

    /** 退出房间：棋手退出 = 认输让出座位（对局保留），观众直接移除。 */
    public function leave(string $code, string $token): array
    {
        return $this->store->update($code, function (array &$room) use ($token): array {
            self::normalizeRoom($room);
            $found = self::locate($room, $token);
            if ($found === null) {
                return ['ok' => true]; // 已经不在房间里了
            }
            self::touch($room, $found);
            if ($found['role'] === 'S') {
                array_splice($room['guests'], (int)$found['index'], 1);
                if ($room['joinRequest'] !== null && hash_equals((string)$room['joinRequest']['token'], $token)) {
                    $room['joinRequest'] = null;   // 申请人走了，申请作废
                }
            } else {
                Logic::setSize((int)$room['size']);
                $color = $found['role'];
                if (in_array($color, $room['state']['order'] ?? [], true)
                    && !$room['state']['over']
                    && count($room['state']['order'] ?? []) >= 2) {
                    self::applyResign($room, $color);   // 离场 = 认输退出，别把别人卡住
                    $room['turnStart'] = time();
                }
                $room['seats'][$color] = null;
                $room['state']['order'] = array_values(array_filter($room['state']['order'] ?? [], function ($c) use ($color) {
                    return $c !== $color;
                }));
                self::normalizeTurn($room);   // 轮到离场者的话，跳到下一位在场棋手
            }
            $room['seq']++;
            return ['ok' => true];
        });
    }

    public function sync(string $code, string $token): array
    {
        return $this->store->update($code, function (array &$room) use ($token): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            return $this->syncPayload($room, $found['role'], $token);
        });
    }

    /* ================= 座位：坐下上场 / 上场申请 ================= */

    /**
     * 坐下上场。在场棋手不足 2 人 → 直接坐下；
     * 对局进行中的**新颜色** → 发「上场申请」，等所有在场棋手同意后才坐上去；
     * **接替**（该颜色还在行棋轮次里、座位空着：有人下来 / 掉线 / 超时被踢下场）
     * → 直接坐上去继续下，棋盘不重置。
     */
    public function sit(string $code, string $token, string $color): array
    {
        $color = strtoupper(trim($color));
        return $this->store->update($code, function (array &$room) use ($token, $color): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            if ($found['role'] !== 'S') {
                throw new RoomException('你已经坐在' . self::colorLabel($found['role']) . '位上了。');
            }
            if (!in_array($color, Logic::COLORS, true)) {
                throw new RoomException('没有这种颜色的座位。');
            }
            if ($room['seats'][$color] !== null) {
                throw new RoomException(self::colorLabel($color) . '位已经有人了，换一个空位。');
            }
            $member = $room['guests'][$found['index']];
            if (!empty($member['timeoutBan']) && count($room['moves']) > 0) {
                throw new RoomException('你因思考超时被请下场，本局不能再上场；等新的一局再上。');
            }
            // 接替：颜色还在行棋轮次里（座位是被让出来的）→ 直接坐上去继续下，棋局不重置
            $takeover = in_array($color, $room['state']['order'] ?? [], true);
            if (count(self::seatedColors($room)) >= 2 && !$takeover) {
                // 对局进行中的新颜色：要所有在场棋手同意才能上场
                if ($room['joinRequest'] !== null) {
                    throw new RoomException('已有一个上场申请在等待棋手同意。');
                }
                $room['joinRequest'] = [
                    'color' => $color,
                    'token' => $token,
                    'id' => (string)$member['id'],
                    'name' => (string)$member['name'],
                    'votes' => [],
                    'at' => time(),
                ];
                $room['seq']++;
                return $this->syncPayload($room, 'S', $token);
            }
            self::takeSeat($room, $color, (int)$found['index']);
            $room['seq']++;
            return $this->syncPayload($room, $color, $token);
        });
    }

    /**
     * 下来：主动让出座位（棋局与行棋轮次原样保留，空位可被别人接替继续下）。
     * 想退出比赛请用「认输」；想离开房间请再点「退出房间」。
     */
    public function stand(string $code, string $token): array
    {
        return $this->store->update($code, function (array &$room) use ($token): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $seat = self::requireSeat($found);
            $member = $room['seats'][$seat] ?? [];
            $name = trim((string)($member['name'] ?? '')) !== '' ? (string)$member['name'] : (string)($member['id'] ?? '');
            self::vacateSeat($room, $seat, self::colorLabel($seat) . '（' . $name . '）主动下场：'
                . '棋局继续进行，座位空出，新加入的人可以坐上来接替这个颜色继续下。');
            return $this->syncPayload($room, 'S', $token);
        });
    }

    /** 设置思考时间（秒，0 = 不限制）：轮到的一方超时会被自动请下场。 */
    public function setThink(string $code, string $token, int $seconds): array
    {
        $seconds = max(0, min(86400, $seconds));
        return $this->store->update($code, function (array &$room) use ($token, $seconds): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $seat = self::requireSeat($found);
            $room['thinkLimit'] = $seconds;
            $room['turnStart'] = time();   // 从现在重新给行棋方计时
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /** 对上场申请表态：所有在场棋手都同意 → 申请人坐上去；有人拒绝 → 申请作废。 */
    public function vote(string $code, string $token, bool $accept): array
    {
        return $this->store->update($code, function (array &$room) use ($token, $accept): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $req = $room['joinRequest'];
            if ($req === null) {
                throw new RoomException('没有待处理的上场申请。');
            }
            if (hash_equals((string)$req['token'], $token)) {
                if ($accept) {
                    throw new RoomException('这是你自己的申请：可以「撤销申请」，或等棋手们同意。');
                }
                $room['joinRequest'] = null;
                $room['seq']++;
                return $this->syncPayload($room, $found['role'], $token);
            }
            $seat = self::requireSeat($found);   // 只有在场棋手能表态
            if (!$accept) {
                $room['joinRequest'] = null;     // 有人拒绝 → 申请作废
                $room['seq']++;
                return $this->syncPayload($room, $seat, $token);
            }
            $req['votes'][$seat] = true;
            $allAgree = true;
            foreach (self::seatedColors($room) as $c) {
                if (empty($req['votes'][$c])) {
                    $allAgree = false;
                    break;
                }
            }
            if (!$allAgree) {
                $room['joinRequest'] = $req;
                $room['seq']++;
                return $this->syncPayload($room, $seat, $token);
            }
            $color = (string)$req['color'];
            if ($room['seats'][$color] !== null) {
                $color = '';   // 颜色刚被占：顺延到第一个空位
                foreach (Logic::COLORS as $c) {
                    if ($room['seats'][$c] === null) {
                        $color = $c;
                        break;
                    }
                }
                if ($color === '') {
                    throw new RoomException('座位已满（最多 6 人）。');
                }
            }
            $idx = self::guestIndexOf($room, (string)$req['token']);
            if ($idx === null) {
                $room['joinRequest'] = null;
                $room['seq']++;
                return $this->syncPayload($room, $seat, $token);   // 申请人已经走了
            }
            self::takeSeat($room, $color, $idx);
            $room['joinRequest'] = null;
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /* ================= 棋盘大小 ================= */

    /** 更改棋盘大小：会清空棋局重新开始（客户端会先确认）。 */
    public function setSize(string $code, string $token, int $size): array
    {
        $size = self::clampSize($size);
        return $this->store->update($code, function (array &$room) use ($token, $size): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            if ($found['role'] === 'S') {
                throw new RoomException('观众不能更改棋盘大小，请坐下上场后再改。');
            }
            if ((int)$room['size'] !== $size) {
                Logic::setSize($size);
                $room['size'] = $size;
                $room['moves'] = [];
                $room['state'] = Logic::newGameState(self::seatedColors($room));
                $room['proposal'] = null;
                self::clearTimeoutBans($room);   // 新的一局：解除超时禁座
                $room['turnStart'] = time();
                $room['seq']++;
            }
            return $this->syncPayload($room, $found['role'], $token);
        });
    }

    /* ================= 棋谱载入：分支续下 ================= */

    /**
     * 把一段着法载入房间，从这个局面继续下（「从这里续下」）。
     * 认输 / 终局 / 加入标记会被跳过；逐手服务端重放校验，任何一手非法就整体拒绝。
     */
    public function load(string $code, string $token, array $moves, ?int $size = null): array
    {
        return $this->store->update($code, function (array &$room) use ($token, $moves, $size): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $seat = self::requireSeat($found);

            if ($size !== null && self::clampSize($size) !== (int)$room['size']) {
                $room['size'] = self::clampSize($size);
            }
            Logic::setSize((int)$room['size']);

            $state = Logic::newGameState(self::seatedColors($room));
            $clean = [];
            foreach ($moves as $m) {
                if (!is_array($m) || !empty($m['r']) || !empty($m['end']) || !empty($m['j'])) {
                    continue;   // 认输 / 终局 / 加入不是棋盘上的着法，分支时跳过
                }
                $edge = trim((string)($m['e'] ?? ''));
                if ($edge === '') {
                    continue;
                }
                $who = self::validColor($m['p'] ?? null) ? (string)$m['p'] : $state['turn'];
                $r = Logic::placeMove($state, $edge, $who);
                if (!$r['ok']) {
                    throw new RoomException('第 ' . (count($clean) + 1) . ' 手无法载入：' . strip_tags((string)$r['reason']));
                }
                $clean[] = ['p' => $who, 'e' => $edge];
                Logic::maybeEndGame($state);
                if ($state['over']) {
                    throw new RoomException('载入到第 ' . count($clean) . ' 手已经无处可下，不能续下：请回到上一步再试。');
                }
            }
            if (count($clean) === 0) {
                throw new RoomException('这段棋谱没有可以续下的着法。');
            }
            $room['moves'] = $clean;
            $room['state'] = $state;
            $room['proposal'] = null;
            self::clearTimeoutBans($room);   // 载入新局面 = 新的一局
            $room['turnStart'] = time();
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /* ================= 落子 / 认输 ================= */

    public function move(string $code, string $token, string $edge): array
    {
        $edge = trim($edge);
        return $this->store->update($code, function (array &$room) use ($token, $edge): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);   // 超时的行棋方已被请下场 → 这里会以「观众」身份被拒
            $seat = self::requireSeat($found);
            Logic::setSize((int)$room['size']);
            $state = $room['state'];

            if (self::statusOf($room) === 'waiting') {
                throw new RoomException('还没开打：等第二个人坐下上场后才开始对局。');
            }
            if ($state['over']) {
                throw new RoomException('对局已经结束，无法再落子。');
            }
            if ($state['turn'] !== $seat) {
                throw new RoomException('还没轮到你行棋，现在是' . self::colorLabel($state['turn']) . '的回合。');
            }
            $bad = Logic::whyIllegal($state, $edge);
            if ($bad !== null) {
                throw new RoomException(strip_tags($bad));
            }
            $r = Logic::placeMove($room['state'], $edge);
            if (!$r['ok']) {
                throw new RoomException(strip_tags((string)$r['reason']));
            }
            Logic::maybeEndGame($room['state']);
            $room['moves'][] = ['p' => $seat, 'e' => $edge];
            self::normalizeTurn($room);   // 落子后如果轮到没人坐的颜色 → 跳过（掉线空位）
            $room['turnStart'] = time();   // 下一手重新计思考时间
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /** 认输 = 退出比赛（座位保留，「悔一手」可以回退认输）。只剩一人时对局结束、该人胜。 */
    public function resign(string $code, string $token): array
    {
        return $this->store->update($code, function (array &$room) use ($token): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $seat = self::requireSeat($found);
            Logic::setSize((int)$room['size']);
            if (self::statusOf($room) === 'waiting') {
                throw new RoomException('对局还没有开始。');
            }
            if ($room['state']['over']) {
                throw new RoomException('对局已经结束。');
            }
            self::applyResign($room, $seat);   // 记账并退出行棋轮次
            self::normalizeTurn($room);        // 轮到认输者的话，跳到下一位在场棋手
            $room['turnStart'] = time();
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /** 手动结束对局 = 发「结束对局」提议：所有在场玩家同意后按当前地数（三角格数）结算胜负。 */
    public function end(string $code, string $token): array
    {
        return $this->propose($code, $token, 'end');
    }

    /* ================= 提议：悔一手 / 新对局 / 结束对局（全员同意） ================= */

    /**
     * 发起提议。提议者自己算一票同意；**所有在场（坐在座位上）的玩家都同意**才生效，
     * 有人拒绝或 REQUEST_TTL 秒内没集齐即作废（提议者调用 answer = 撤销）。
     */
    public function propose(string $code, string $token, string $type): array
    {
        if ($type !== 'undo' && $type !== 'new' && $type !== 'end') {
            throw new RoomException('未知的提议类型。');
        }
        return $this->store->update($code, function (array &$room) use ($token, $type): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $seat = self::requireSeat($found);
            if (self::statusOf($room) === 'waiting') {
                throw new RoomException('对局还没有开始。');
            }
            if ($room['proposal'] !== null) {
                throw new RoomException('已有一个提议等待回应。');
            }
            if (($type === 'undo' || $type === 'end') && !empty($room['state']['over'])) {
                throw new RoomException('对局已经结束。');
            }
            if ($type === 'undo' && !self::hasUndoable($room['moves'])) {
                throw new RoomException('还没有可悔的手数。');
            }
            $proposal = [
                'type' => $type,
                'by' => $seat,
                'votes' => [$seat => true],   // 提议者自己就是同意
                'at' => time(),
            ];
            if (self::allSeatedAgreed($room, $proposal)) {
                self::executeProposal($room, $proposal);   // 只剩提议者在场 → 立即生效
                $room['proposal'] = null;
            } else {
                $room['proposal'] = $proposal;
            }
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /**
     * 回应提议。同意 = 记一票，**集齐所有在场玩家的同意**才生效；
     * 拒绝 = 提议作废；提议者调用 = 撤销自己的提议。
     */
    public function answer(string $code, string $token, bool $accept): array
    {
        return $this->store->update($code, function (array &$room) use ($token, $accept): array {
            self::normalizeRoom($room);
            $found = self::stepIn($room, $token);
            $seat = self::requireSeat($found);
            Logic::setSize((int)$room['size']);
            $proposal = $room['proposal'];
            if ($proposal === null) {
                // 「结束对局」刚被拒绝作废：弹窗还开着、几乎同时点「拒绝」的其他人也算数，
                // 并进来按轮转顺序重新选「第一个拒绝的人」（只在还没落子前调整）
                if (!$accept && self::absorbLateEndReject($room, $seat)) {
                    $room['seq']++;
                    return $this->syncPayload($room, $seat, $token);
                }
                throw new RoomException('没有待处理的提议。');
            }
            if ($proposal['by'] === $seat) {
                $room['proposal'] = null;          // 提议者：撤销自己的提议
                $room['seq']++;
                return $this->syncPayload($room, $seat, $token);
            }
            if (!$accept) {
                $room['proposal'] = null;          // 有人拒绝 → 提议作废
                if ((string)($proposal['type'] ?? '') === 'end') {
                    // 不同意「手动结束对局」：对局继续，按本该轮到的顺序，
                    // 转给第一个选了拒绝的人继续下
                    self::recordEndReject($room, [$seat]);
                    self::handTurnToFirstRejecter($room, [$seat], $seat);
                }
                $room['seq']++;
                return $this->syncPayload($room, $seat, $token);
            }
            $votes = is_array($proposal['votes'] ?? null) ? $proposal['votes'] : [];
            $votes[$seat] = true;
            $proposal['votes'] = $votes;
            if (!self::allSeatedAgreed($room, $proposal)) {
                $room['proposal'] = $proposal;     // 还有人没表态 → 继续等
                $room['seq']++;
                return $this->syncPayload($room, $seat, $token);
            }
            self::executeProposal($room, $proposal);   // 所有在场玩家都同意 → 生效
            $room['proposal'] = null;
            $room['seq']++;
            return $this->syncPayload($room, $seat, $token);
        });
    }

    /* ================= 内部工具 ================= */

    private static function clampSize(int $size): int
    {
        return max(Logic::MIN_SIDE, min(Logic::MAX_SIDE, $size));
    }

    private static function emptySeats(): array
    {
        $seats = [];
        foreach (Logic::COLORS as $c) {
            $seats[$c] = null;
        }
        return $seats;
    }

    /** @return array<int,string> 在场棋手的颜色（按 黑白红绿紫蓝 排序） */
    private static function seatedColors(array $room): array
    {
        $colors = [];
        foreach (Logic::COLORS as $c) {
            if (($room['seats'][$c] ?? null) !== null) {
                $colors[] = $c;
            }
        }
        return $colors;
    }

    private static function colorLabel(?string $color): string
    {
        $names = ['B' => '黑方', 'W' => '白方', 'R' => '红方', 'G' => '绿方', 'Y' => '紫方', 'L' => '蓝方'];
        return $names[$color] ?? '观众';
    }

    /** @param array<int,string> $colors @return array<int,string> 按 黑白红绿紫蓝 排序去重 */
    private static function sortColors(array $colors): array
    {
        $pos = array_flip(Logic::COLORS);
        $colors = array_values(array_unique($colors));
        usort($colors, function ($a, $b) use ($pos): int {
            return ($pos[$a] ?? 99) <=> ($pos[$b] ?? 99);
        });
        return $colors;
    }

    private static function validColor($color): bool
    {
        return is_string($color) && in_array($color, Logic::COLORS, true);
    }

    /** @return array{token:string,id:string,name:string,joined:int,lastSeen:int,user?:string} */
    private static function newSeat(string $token, ?string $name = null, ?string $user = null): array
    {
        $seat = [
            'token' => $token,
            'id' => self::newMemberId(),
            'name' => self::cleanName($name),
            'joined' => time(),
            'lastSeen' => time(),
        ];
        $user = self::cleanUser($user);
        if ($user !== '') {
            $seat['user'] = $user;
            if ($seat['name'] === '') {
                $seat['name'] = $user;   // 登录用户：显示名就是用户名
            }
        }
        return $seat;
    }

    /** 登录账号名（房间里用它认「同一个人」，换设备登录也认得出来）。 */
    private static function cleanUser(?string $user): string
    {
        $user = trim(strip_tags((string)$user));
        return preg_match('/^[\w\x{4e00}-\x{9fa5}]{2,16}$/u', $user) ? $user : '';
    }

    /**
     * 同一账号从别的设备回来：接管自己原来的成员身份（换新令牌，旧设备的令牌作废）。
     * · 原来坐在颜色座位上 → 座位原样保留（棋局不动），直接回到那个颜色接着下；
     * · 原来是观众、但那个颜色是自己掉线 / 下来让出来的（lastColor）且座位还空着
     *   → 自动坐回去接着下（与「空位直接接替」同规则；被超时禁座的不算）。
     * @param string|null $issued 输出：新发的令牌
     * @return string|null 接管到的颜色（'S' = 仍是观众）；房间里没有这个账号返回 null
     */
    private static function adoptAccount(array &$room, string $user, ?string $name, ?string &$issued): ?string
    {
        $role = null;
        $member = null;
        foreach (Logic::COLORS as $c) {
            $p = $room['seats'][$c] ?? null;
            if ($p !== null && hash_equals((string)($p['user'] ?? ''), $user)) {
                $role = $c;
                $member = $p;
                break;
            }
        }
        if ($member === null) {
            foreach ($room['guests'] as $i => $g) {
                if (hash_equals((string)($g['user'] ?? ''), $user)) {
                    $role = 'S';
                    $member = $g;
                    array_splice($room['guests'], $i, 1);
                    break;
                }
            }
        }
        if ($member === null) {
            return null;
        }
        $issued = RoomStore::randomToken();
        $member['token'] = $issued;
        $member['lastSeen'] = time();
        $name = self::cleanName($name);
        if ($name !== '') {
            $member['name'] = $name;
        }
        if ($role !== 'S') {
            $room['seats'][$role] = $member;
            return $role;
        }
        $room['guests'][] = $member;
        $idx = count($room['guests']) - 1;
        // 自己让出来 / 掉线留下的座位还空着：自动坐回去接着下（棋局不重置）
        $last = (string)($member['lastColor'] ?? '');
        if ($last !== ''
            && empty($member['timeoutBan'])
            && count($room['moves']) > 0
            && empty($room['state']['over'])
            && in_array($last, $room['state']['order'] ?? [], true)
            && ($room['seats'][$last] ?? null) === null) {
            self::takeSeat($room, $last, $idx);
            return $last;
        }
        return 'S';
    }

    /** 成员身份标识（显示用，例如 K7QD）。 */
    private static function newMemberId(): string
    {
        $alpha = self::ID_ALPHABET;
        $n = strlen($alpha);
        $s = '';
        for ($i = 0; $i < self::ID_LEN; $i++) {
            $s .= $alpha[random_int(0, $n - 1)];
        }
        return $s;
    }

    private static function cleanName(?string $name): string
    {
        $name = trim(strip_tags((string)$name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        return function_exists('mb_substr') ? mb_substr($name, 0, 12) : substr($name, 0, 24);
    }

    /** 旧房间文件（players / 无 seats / 无 joinRequest）自动补全。 */
    private static function normalizeRoom(array &$room): void
    {
        if (!isset($room['size']) || !is_numeric($room['size'])) {
            $room['size'] = Logic::SIDE;
        }
        $room['size'] = self::clampSize((int)$room['size']);
        if (!isset($room['guests']) || !is_array($room['guests'])) {
            $room['guests'] = [];
        }
        if (!isset($room['seats']) || !is_array($room['seats'])) {
            $room['seats'] = self::emptySeats();
            foreach (['B', 'W'] as $c) {   // 旧格式：players
                if (isset($room['players'][$c]) && is_array($room['players'][$c])) {
                    $room['seats'][$c] = $room['players'][$c];
                }
            }
        }
        foreach (Logic::COLORS as $c) {
            $p = $room['seats'][$c] ?? null;
            if ($p !== null) {
                $p['id'] = $p['id'] ?? self::newMemberId();
                $p['name'] = $p['name'] ?? '';
                $room['seats'][$c] = $p;
            } else {
                $room['seats'][$c] = null;
            }
        }
        foreach ($room['guests'] as $i => $g) {
            $g['id'] = $g['id'] ?? self::newMemberId();
            $g['name'] = $g['name'] ?? '';
            $room['guests'][$i] = $g;
        }
        if (!array_key_exists('joinRequest', $room)) {
            $room['joinRequest'] = null;
        }
        if (!isset($room['state']['order']) || !is_array($room['state']['order'])) {
            $room['state']['order'] = self::seatedColors($room);
        }
        if (!isset($room['thinkLimit']) || !is_numeric($room['thinkLimit'])) {
            $room['thinkLimit'] = 0;   // 0 = 不限制
        }
        $room['thinkLimit'] = max(0, (int)$room['thinkLimit']);
        if (!isset($room['turnStart']) || !is_numeric($room['turnStart'])) {
            $room['turnStart'] = time();
        }
        $room['turnStart'] = (int)$room['turnStart'];
        if (!array_key_exists('notice', $room)) {
            $room['notice'] = null;
        }
        if ($room['proposal'] !== null && !isset($room['proposal']['votes'])) {
            $room['proposal']['votes'] = [(string)$room['proposal']['by'] => true];
        }
    }

    /** 上场申请过期作废。 */
    private static function expireRequest(array &$room): void
    {
        if ($room['joinRequest'] !== null && time() - (int)$room['joinRequest']['at'] > self::REQUEST_TTL) {
            $room['joinRequest'] = null;
        }
    }

    /** 提议（悔棋 / 新对局 / 结束对局）过期作废：一直没集齐所有人的同意就别再挂着。 */
    private static function expireProposal(array &$room): void
    {
        if ($room['proposal'] !== null && time() - (int)$room['proposal']['at'] > self::REQUEST_TTL) {
            $room['proposal'] = null;
            self::setNotice($room, '有提议长时间没有得到所有在场玩家同意，已自动作废。');
        }
    }

    /** 每个动作前的例行维护：申请/提议过期、思考超时踢下场、掉线自动下场。 */
    private static function maintain(array &$room): void
    {
        self::expireRequest($room);
        self::expireProposal($room);
        self::expireThink($room);
        self::expireOffline($room);
    }

    /** 房间公告（同步给所有人，客户端按内容去重显示一次）。 */
    private static function setNotice(array &$room, string $text): void
    {
        $room['notice'] = ['at' => time(), 'text' => $text];
    }

    /**
     * 让出座位（下来 / 掉线 / 思考超时）：座位空出，但**棋局与行棋轮次原样保留**——
     * 颜色还留在轮次里，轮到它时等接替者上场，新人坐下即可继续下（不重置棋盘）。
     */
    private static function vacateSeat(array &$room, string $color, string $reason): bool
    {
        $member = $room['seats'][$color] ?? null;
        if ($member === null) {
            return false;
        }
        $wasTurn = (string)($room['state']['turn'] ?? '') === $color;
        $room['seats'][$color] = null;
        $member['lastSeen'] = (int)($member['lastSeen'] ?? time());
        $member['lastColor'] = $color;   // 记下让出的颜色：同一账号回来可自动坐回去接着下
        $room['guests'][] = $member;   // 人还在房间里（观众），本人回来还能接替
        self::normalizeTurn($room);    // 关键：轮到空位就跳过去，棋局继续（不卡在掉线的颜色上）
        if ($wasTurn) {
            $room['turnStart'] = time();   // 下一位在场棋手重新计思考时间
        }
        self::setNotice($room, $reason);
        $room['seq']++;
        return true;
    }

    /** 思考超时：轮到的一方超过时限被请下场（棋局保留，可被接替）。 */
    private static function expireThink(array &$room): void
    {
        $limit = (int)($room['thinkLimit'] ?? 0);
        if ($limit <= 0) {
            return;   // 没设置思考时间
        }
        if (self::statusOf($room) !== 'playing' || !empty($room['state']['over'])) {
            return;
        }
        $turn = (string)($room['state']['turn'] ?? '');
        $p = $turn !== '' ? ($room['seats'][$turn] ?? null) : null;
        if ($p === null) {
            return;   // 座位空着：等接替者，不计时
        }
        $start = (int)($room['turnStart'] ?? 0);
        if ($start <= 0) {
            $room['turnStart'] = time();
            return;
        }
        if (time() - $start <= $limit) {
            return;
        }
        $p['timeoutBan'] = true;   // 超时被踢下场的人本局不能再上场（新的一局自动解除）
        $room['seats'][$turn] = $p;
        $name = trim((string)($p['name'] ?? '')) !== '' ? (string)$p['name'] : (string)($p['id'] ?? '');
        self::vacateSeat($room, $turn, self::colorLabel($turn) . '（' . $name . '）思考超时，已被请下场'
            . '（本局不能再上场）：棋局继续进行，等有人接替后该颜色再上场。');
    }

    /** 掉线自动下场：超过 VACATE_AFTER 秒没动静的棋手让出座位（棋局保留，可被接替）。 */
    private static function expireOffline(array &$room): void
    {
        $now = time();
        foreach (Logic::COLORS as $c) {
            $p = $room['seats'][$c] ?? null;
            if ($p === null) {
                continue;
            }
            if ($now - (int)($p['lastSeen'] ?? $now) <= self::VACATE_AFTER) {
                continue;
            }
            $name = trim((string)($p['name'] ?? '')) !== '' ? (string)$p['name'] : (string)($p['id'] ?? '');
            self::vacateSeat($room, $c, self::colorLabel($c) . '（' . $name . '）掉线，已自动下场：'
                . '棋局继续进行，新加入的人可以坐上' . self::colorLabel($c) . '位接替继续下。');
        }
    }

    /** 新的一局（新对局 / 改棋盘 / 载入棋谱）：解除所有人的超时禁座。 */
    private static function clearTimeoutBans(array &$room): void
    {
        foreach (Logic::COLORS as $c) {
            if (isset($room['seats'][$c]) && is_array($room['seats'][$c])) {
                unset($room['seats'][$c]['timeoutBan']);
            }
        }
        foreach ($room['guests'] as $i => $g) {
            if (isset($g['timeoutBan'])) {
                unset($room['guests'][$i]['timeoutBan']);
            }
        }
    }

    /** 提议是否已经集齐所有在场（坐在座位上）玩家的同意。 */
    private static function allSeatedAgreed(array $room, array $proposal): bool
    {
        $votes = is_array($proposal['votes'] ?? null) ? $proposal['votes'] : [];
        foreach (self::seatedColors($room) as $c) {
            if (empty($votes[$c])) {
                return false;
            }
        }
        return true;
    }

    /* ---- 「结束对局」被拒绝：转给第一个拒绝的人继续下（多人局按轮转顺序取） ---- */

    /**
     * 把回合交给「第一个选了拒绝的人」：按**本该轮到的顺序**（从提议时该落子的人起轮转）
     * 在拒绝者里取最靠前的一个；都找不到就用兜底色。
     * @param array<int,string> $rejects 拒绝者的颜色（可能不止一个：多人几乎同时点拒绝）
     * @param string|null $from 轮转起点（= 提议打断前该行棋的颜色），默认当前行棋方
     */
    private static function handTurnToFirstRejecter(array &$room, array $rejects, string $fallback, ?string $from = null): void
    {
        $rejects = array_values(array_unique(array_filter($rejects, function ($c): bool {
            return in_array($c, Logic::COLORS, true);
        })));
        if (count($rejects) === 0) {
            $rejects = [$fallback];
        }
        $order = $room['state']['order'] ?? [];
        $start = $from !== null && $from !== '' ? $from : (string)($room['state']['turn'] ?? '');
        $target = null;
        $c = $start;
        for ($i = 0; $i < count($order); $i++) {
            if (in_array($c, $rejects, true)) {
                $target = $c;
                break;
            }
            $c = Logic::nextTurn($order, $c);
        }
        if ($target === null) {
            $target = $rejects[0];
        }
        $room['state']['turn'] = $target;
        $room['turnStart'] = time();
        self::setNotice($room, self::colorLabel($target) . '不同意结束对局：对局继续，转为'
            . self::colorLabel($target) . '继续下。');
    }

    /** 记下「结束对局」被拒的瞬间（含提议前该落子的颜色，供几乎同时点拒绝的其他人并进来比轮转顺序）。 */
    private static function recordEndReject(array &$room, array $rejects): void
    {
        $room['endReject'] = [
            'at' => time(),
            'turnAt' => (string)($room['state']['turn'] ?? ''),
            'rejects' => array_values(array_unique($rejects)),
            'movesAt' => count($room['moves']),
        ];
    }

    /**
     * 「结束对局」提议刚被拒绝作废后又收到的拒绝（弹窗还开着、几乎同时点的）：
     * 并进拒绝名单，按本该轮到的顺序重新选出「第一个拒绝的人」继续下。
     * 已经过了 15 秒、或期间已经落过子，就不再改轮次（返回 false，走「没有待处理的提议」）。
     */
    private static function absorbLateEndReject(array &$room, string $seat): bool
    {
        $rec = $room['endReject'] ?? null;
        if (!is_array($rec) || time() - (int)($rec['at'] ?? 0) > 15) {
            return false;
        }
        if ((int)($rec['movesAt'] ?? -1) !== count($room['moves'])) {
            return false;   // 已经又落子了：别再动轮次
        }
        $rejects = is_array($rec['rejects'] ?? null) ? array_values($rec['rejects']) : [];
        if (in_array($seat, $rejects, true)) {
            return false;
        }
        $rejects[] = $seat;
        $rec['rejects'] = $rejects;
        $room['endReject'] = $rec;
        self::handTurnToFirstRejecter($room, $rejects, $seat, (string)($rec['turnAt'] ?? ''));
        return true;
    }

    /** 执行已获全员同意的提议：悔一手 / 新对局 / 结束对局。 */
    private static function executeProposal(array &$room, array $proposal): void
    {
        Logic::setSize((int)$room['size']);
        $type = (string)$proposal['type'];
        $by = (string)$proposal['by'];
        if ($type === 'undo') {
            // 回退一手（可连续回退）。「重新加入（j）」不是棋上的动作，
            // 不作为回退对象：找到最后一条真正的着法 / 认输 / 终局并去掉它。
            $idx = -1;
            for ($i = count($room['moves']) - 1; $i >= 0; $i--) {
                if (empty($room['moves'][$i]['j'])) {
                    $idx = $i;
                    break;
                }
            }
            if ($idx === -1) {
                throw new RoomException('已经没有可以回退的着法。');
            }
            array_splice($room['moves'], $idx, 1);
            // 重放时的参赛顺序 = 现在的行棋轮次（含空着等接替的颜色），轮次才不会错位
            $room['state'] = self::rebuildState($room['moves'], (int)$room['size'], $room['state']['order'] ?? self::seatedColors($room));
            self::normalizeTurn($room);   // 悔棋后轮到没人坐的颜色 → 跳过
            $room['turnStart'] = time();
        } elseif ($type === 'new') {
            $room['moves'] = [];           // 新对局
            $room['state'] = Logic::newGameState(self::seatedColors($room));
            self::clearTimeoutBans($room);
            $room['turnStart'] = time();
        } elseif ($type === 'end') {
            if (!empty($room['state']['over'])) {
                throw new RoomException('对局已经结束。');
            }
            Logic::endGameAs($room['state'], $by);
            $room['moves'][] = ['p' => $by, 'end' => true];
            $room['turnStart'] = 0;
        }
    }

    /**
     * 用 token 找到成员：['role' => 颜色|'S', 'index' => 观众下标]，找不到返回 null。
     * @return array{role:string,index:?int}|null
     */
    private static function locate(array $room, string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        foreach (Logic::COLORS as $c) {
            $p = $room['seats'][$c] ?? null;
            if ($p !== null && hash_equals((string)$p['token'], $token)) {
                return ['role' => $c, 'index' => null];
            }
        }
        foreach ($room['guests'] as $i => $g) {
            if (hash_equals((string)$g['token'], $token)) {
                return ['role' => 'S', 'index' => $i];
            }
        }
        return null;
    }

    private static function guestIndexOf(array $room, string $token): ?int
    {
        foreach ($room['guests'] as $i => $g) {
            if (hash_equals((string)$g['token'], $token)) {
                return $i;
            }
        }
        return null;
    }

    /** 着法序列里还有可以回退的着法吗（「重新加入（j）」不算）。 */
    private static function hasUndoable(array $moves): bool
    {
        foreach ($moves as $m) {
            if (empty($m['j'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * 刷新最后活跃时间；顺带统一校验身份：
     * 所有操作都是「locate → touch」，令牌失效（例如房间数据被清过）时
     * 直接给出可恢复的提示，而不是内部错误。
     * @param array{role:string,index:?int}|null $found
     */
    private static function touch(array &$room, ?array $found): void
    {
        if ($found === null) {
            throw new RoomException('身份不存在或已失效，请重新加入房间。');
        }
        if ($found['role'] === 'S') {
            $room['guests'][$found['index']]['lastSeen'] = time();
        } else {
            $room['seats'][$found['role']]['lastSeen'] = time();
        }
    }

    /**
     * 每个动作的统一入口：先 touch（正在操作的人不算掉线），再例行维护
     * （申请/提议过期、思考超时踢下场、掉线自动下场），最后**重新定位身份**
     * （自己可能刚被请下场 → 变成观众，后续动作自然被拦）。
     * @return array{role:string,index:?int}
     */
    private static function stepIn(array &$room, string $token): array
    {
        self::touch($room, self::locate($room, $token));
        self::maintain($room);
        $found = self::locate($room, $token);
        if ($found === null) {
            throw new RoomException('身份不存在或已失效，请重新加入房间。');
        }
        return $found;
    }

    /** 只有坐在颜色座位上的棋手才能落子 / 认输 / 提议。 @return string 颜色码 */
    private static function requireSeat(array $found): string
    {
        if ($found['role'] === 'S') {
            throw new RoomException('你目前是观众，只能观看；想下棋请在「座位」里坐下。');
        }
        return $found['role'];
    }

    /**
     * 观众进座位：从 guests 挪到 seats。
     * · 接替（颜色还在行棋轮次里、座位被让出来）：直接坐回去继续下，
     *   棋局不动、不记「j」，轮到该颜色时正好接着下；
     * · 新颜色：进入行棋轮次（黑白红绿紫蓝排序），中途上场记一笔「重新加入（j）」，
     *   有了它，悔棋重放才能还原「加入前」的行棋轮次（该谁落子不会错位）。
     */
    private static function takeSeat(array &$room, string $color, ?int $guestIndex): void
    {
        $member = null;
        if ($guestIndex !== null && isset($room['guests'][$guestIndex])) {
            $member = $room['guests'][$guestIndex];
            array_splice($room['guests'], $guestIndex, 1);
        }
        if ($member === null) {
            $member = self::newSeat(RoomStore::randomToken());
        }
        $member['lastSeen'] = time();
        unset($member['timeoutBan'], $member['lastColor']);   // 坐下正常计时（能坐进来就没被禁）
        $room['seats'][$color] = $member;

        $order = $room['state']['order'] ?? [];
        if (!in_array($color, $order, true)) {
            $order[] = $color;
            $order = self::sortColors($order);
            $room['state']['order'] = $order;
            // 中途上场 / 认输后又坐回来：记一笔「重新加入（j）」
            if (count($room['moves']) > 0) {
                $room['moves'][] = ['p' => $color, 'j' => true];
            }
        }
        self::normalizeTurn($room);   // 轮次兜底：轮到没人坐的颜色就跳过（掉线留下的空位）
        if (count($room['moves']) === 0 || (string)$room['state']['turn'] === $color) {
            $room['turnStart'] = time();   // 开局、或正好轮到上场的人：重新计思考时间
        }
    }

    /** 认输：记账并退出行棋轮次（不动座位，座位由调用方处理）。 */
    private static function applyResign(array &$room, string $color): void
    {
        Logic::resignAs($room['state'], $color);
        $room['moves'][] = ['p' => $color, 'r' => true];
    }

    /** 只按参赛顺序兜底 turn（裸 state 用，例如重放着法时）。 */
    private static function normalizeTurnInState(array &$state): void
    {
        $order = $state['order'] ?? [];
        if (count($order) === 0) {
            return;
        }
        if (!in_array($state['turn'] ?? '', $order, true)) {
            $state['turn'] = $order[0];
        }
    }

    /**
     * 行棋轮次收尾（掉线处理的关键一环）：**轮到的颜色没人坐就跳过去**，
     * 棋局绝不会卡在掉线 / 下来 / 被踢下场的颜色上（否则所有人都会看到
     * 「还没轮到你行棋，现在是 X 的回合」，可 X 座位是空的）。
     * 颜色本身仍留在 state['order'] 里：接替者坐回来后照常轮到他，棋局不重置。
     */
    private static function normalizeTurn(array &$room): void
    {
        $order = $room['state']['order'] ?? [];
        if (count($order) === 0) {
            return;
        }
        self::normalizeTurnInState($room['state']);
        $occupied = self::seatedColors($room);
        if (count($occupied) === 0) {
            return;   // 所有人都下场了：轮次放着不动，等有人坐下（takeSeat 会再收尾）
        }
        for ($i = 0; $i < count($order) && !in_array($room['state']['turn'], $occupied, true); $i++) {
            $room['state']['turn'] = Logic::nextTurn($order, (string)$room['state']['turn']);
        }
        if (!in_array($room['state']['turn'], $occupied, true)) {
            $room['state']['turn'] = $occupied[0];
        }
    }

    private static function statusOf(array $room): string
    {
        // 不足 2 人且还没落子 = 等待开打；已经下起来的棋局不因有人退出而回到等待状态
        if (count($room['moves']) === 0 && count(self::seatedColors($room)) < 2) {
            return 'waiting';
        }
        return $room['state']['over'] ? 'over' : 'playing';
    }

    /**
     * 重放全部着法得到棋局快照（悔棋 / 恢复时使用）。
     *
     * $order = 现在在座的颜色，用来还原「开局时」的行棋轮次：
     *   · 记录里第一次出现就是「重新加入（j）」的颜色 = 中途上场，不算开局成员；
     *   · 其余出现过的颜色 + 记录里没出现过的在座颜色 = 开局就在轮次里。
     * 重放时每条 'j' 把颜色加进轮次、每条 'r' 把颜色移出，
     * 于是中途有人上场 / 让座之后再悔棋，「该谁落子」仍然和当时完全一致。
     *
     * @param array<int,string>|null $order 现在在座（或曾坐下）的颜色
     */
    public static function rebuildState(array $moves, int $size = Logic::SIDE, ?array $order = null): array
    {
        Logic::setSize(self::clampSize($size));
        $seen = [];
        $initial = [];
        foreach ($moves as $m) {
            $p = (string)($m['p'] ?? '');
            if ($p === '' || isset($seen[$p])) {
                continue;
            }
            $seen[$p] = true;
            if (empty($m['j'])) {
                $initial[$p] = true;   // 第一次出现就是着法 / 认输 = 开局就在轮次里
            }
        }
        foreach ($order ?? [] as $c) {
            if (!isset($seen[$c])) {
                $initial[$c] = true;   // 记录里从没出现过 = 一直坐在轮次里
            }
        }
        $state = Logic::newGameState(self::sortColors(array_keys($initial)));
        foreach ($moves as $m) {
            if (!empty($m['j'])) {
                // 重新加入行棋轮次（中途上场 / 认输后又坐回来）
                if (!in_array($m['p'], $state['order'], true)) {
                    $state['order'][] = (string)$m['p'];
                    $state['order'] = self::sortColors($state['order']);
                    self::normalizeTurnInState($state);
                }
                continue;
            }
            if (!empty($m['r'])) {
                Logic::resignAs($state, (string)$m['p']);
                continue;
            }
            if (!empty($m['end'])) {
                Logic::endGameAs($state, (string)$m['p']);
                continue;
            }
            $p = (string)($m['p'] ?? '');
            $r = Logic::placeMove($state, (string)$m['e'], $p !== '' ? $p : null);
            if (!$r['ok']) {
                // 记录与规则冲突（正常情况不会发生）：停止重放，保留已还原的部分
                break;
            }
            Logic::maybeEndGame($state);
        }
        return $state;
    }

    /** 成员的公开信息（不含令牌）。 */
    private static function publicMember(array $member, string $role): array
    {
        return [
            'id' => (string)($member['id'] ?? ''),
            'name' => (string)($member['name'] ?? ''),
            'role' => $role,
            'online' => (time() - (int)$member['lastSeen']) <= self::ONLINE_WINDOW,
        ];
    }

    /** 同步给客户端的完整状态（不含任何密钥信息）。 */
    private function syncPayload(array $room, string $role, ?string $token = null): array
    {
        self::normalizeRoom($room);
        Logic::setSize((int)$room['size']);
        $state = $room['state'];

        $members = [];
        foreach (Logic::COLORS as $c) {
            $p = $room['seats'][$c];
            if ($p !== null) {
                $members[] = self::publicMember($p, $c);
            }
        }
        foreach ($room['guests'] as $g) {
            $members[] = self::publicMember($g, 'S');
        }

        // 自己的身份：按令牌找（可能刚被请下场变成观众，role 以现在的实际情况为准）
        $me = null;
        if ($token !== null && $token !== '') {
            foreach (Logic::COLORS as $c) {
                $p = $room['seats'][$c];
                if ($p !== null && hash_equals((string)$p['token'], $token)) {
                    $me = $p;
                    $role = $c;
                    break;
                }
            }
            if ($me === null) {
                foreach ($room['guests'] as $g) {
                    if (hash_equals((string)$g['token'], $token)) {
                        $me = $g;
                        $role = 'S';
                        break;
                    }
                }
            }
        }
        if ($me === null && $role !== 'S') {
            $me = $room['seats'][$role] ?? null;
        }

        $req = $room['joinRequest'];
        $joinReq = null;
        if ($req !== null) {
            $joinReq = [
                'color' => (string)$req['color'],
                'id' => (string)$req['id'],
                'name' => (string)$req['name'],
                'votes' => $req['votes'] ?? [],
                'at' => (int)($req['at'] ?? 0),
                'mine' => $token !== null && hash_equals((string)$req['token'], $token),
            ];
        }

        $notice = $room['notice'];
        if (is_array($notice) && time() - (int)($notice['at'] ?? 0) > 60) {
            $notice = null;   // 只把最近一分钟的公告发给客户端
        }

        $payload = [
            'ok' => true,
            'room' => $room['code'],
            'you' => $role,
            'youId' => (string)($me['id'] ?? ''),
            'youName' => (string)($me['name'] ?? ''),
            'size' => (int)$room['size'],
            'seq' => $room['seq'],
            'status' => self::statusOf($room),
            'turn' => $state['turn'],
            'order' => $state['order'] ?? [],
            'moves' => $room['moves'],
            'over' => $state['over'],
            'winner' => $state['winner'],
            'proposal' => $room['proposal'],
            'joinRequest' => $joinReq,
            'members' => $members,
            'thinkLimit' => (int)$room['thinkLimit'],
            'turnStart' => (int)$room['turnStart'],
            'notice' => $notice,
            'serverTime' => time(),
        ];
        if ($token !== null) {
            $payload['token'] = $token;
        }
        return $payload;
    }
}
