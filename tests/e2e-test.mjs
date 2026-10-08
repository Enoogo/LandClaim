// 棍棋 · 三角版 — 联机端到端测试
// 自己拉起 PHP 服务器（api.php + router.php），用两个「玩家」走一遍完整流程：
//   建房 → 加入 → 轮流落子 → 越权/非法落子被拒 → 悔一手提议（全员同意）→ 认输 → 新对局
//   → 手动结束对局（全员同意）→ 资源保护 → 上场申请（全员同意）→ 退出/让座 → 棋谱分支
//   → 下来 / 接替 / 思考时间超时踢下场
// 运行：node tests/e2e-test.mjs        （可选环境变量 PHP_BIN=php 的路径，E2E_PORT=6851）
import { spawn } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const PHP = process.env.PHP_BIN || 'php';
const PORT = Number(process.env.E2E_PORT || 6851);
const BASE = 'http://127.0.0.1:' + PORT;
// 与 start.php 一致：关闭 OPcache（部分 Windows 环境 OPcache + 强制 ASLR 会让内置服务器启动即崩）
// 额外参数可用 PHP_ARGS 传入，如 PHP_ARGS="-d foo=1"
const PHP_ARGS = [
  '-d', 'opcache.enable=0',
  '-d', 'opcache.enable_cli=0',
  ...(process.env.PHP_ARGS || '').split(/\s+/).filter(Boolean)
];

// —— 本地规则引擎（与浏览器端同源），用于挑选合法着法、校验服务器结果 ——
const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8');
const script = html.match(/<script>([\s\S]*?)<\/script>/)[1];
const moduleShim = { exports: {} };
new Function('module', script)(moduleShim);
const Logic = moduleShim.exports;

// 客户端构建号：从 api.php 解析（与被测服务器天然一致，不会漂移）
const CLIENT_BUILD = (readFileSync(new URL('../api.php', import.meta.url), 'utf8')
  .match(/const CLIENT_BUILD = '([^']+)'/) || [])[1];

function rebuild(moves, order) {
  const derived = [...new Set(moves.map((m) => m.p).filter(Boolean))];
  const ord = order && order.length ? order : (derived.length >= 2 ? derived : ['B', 'W']);
  const st = Logic.newGameState(ord);
  for (const mv of moves) {
    if (mv.j) {
      if (st.order.indexOf(mv.p) < 0) st.order.push(mv.p);
      continue;
    }
    if (mv.r) {
      Logic.resignAs(st, mv.p);
      continue;
    }
    const r = Logic.placeMove(st, mv.e, mv.p);
    if (!r.ok) throw new Error('本地重放失败：' + JSON.stringify(mv) + ' / ' + r.reason);
    if (!st.over && Logic.computeLegal(st).moves.length === 0) {
      st.over = true;
      st.winner = Logic.decideWinner(st);
    }
  }
  return st;
}

let passed = 0, failed = 0;
function ok(cond, name, extra) {
  if (cond) { passed++; console.log('  ✓ ' + name); }
  else {
    failed++;
    console.log('  ✗ ' + name + (extra !== undefined ? '  → ' + JSON.stringify(extra) : ''));
  }
}

async function post(payload) {
  const res = await fetch(BASE + '/api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(Object.assign({ ver: CLIENT_BUILD }, payload))
  });
  return res.json();
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function main() {
  console.log('\n启动测试服务器：' + PHP + ' -S 127.0.0.1:' + PORT + ' router.php');
  const server = spawn(PHP, [...PHP_ARGS, '-S', '127.0.0.1:' + PORT, 'router.php'], {
    cwd: fileURLToPath(new URL('..', import.meta.url)),
    stdio: 'ignore'
  });

  let up = false;
  for (let i = 0; i < 75 && !up; i++) {
    try {
      const r = await fetch(BASE + '/');
      up = r.ok;
    } catch (e) { /* 还没起来 */ }
    if (!up) await sleep(200);
  }
  if (!up) {
    server.kill();
    console.error('服务器未能启动（检查 PHP_BIN / 端口）');
    process.exit(1);
  }

  try {
    console.log('\n[1] 建房与加入（一律先当观众，坐下才上场）');
    const a = await post({ action: 'create', name: '甲' });
    ok(a.ok === true, 'A 创建房间成功');
    ok(/^[A-Z2-9]{5}$/.test(a.room), '房间号格式正确', a.room);
    ok(a.you === 'S' && a.status === 'waiting', '创建者也先当观众，状态 waiting', a);
    ok(Array.isArray(a.moves) && a.moves.length === 0, '新房间没有着法');

    const bad = await post({ action: 'join', room: 'ZZZZZ' });
    ok(bad.ok === false, '加入不存在的房间被拒绝', bad);

    const b = await post({ action: 'join', room: a.room, name: '白棋手' });
    ok(b.ok === true && b.you === 'S', 'B 加入后也是观众', b);
    ok(b.size === 19, '默认棋盘 19 路', b.size);
    ok(Array.isArray(b.members) && b.members.length === 2, '成员列表有 2 人', b.members);
    ok(b.members.some((m) => m.role === 'S' && m.name === '白棋手' && m.id), '成员带昵称与身份 id', b.members);

    // 选颜色坐下：黑白两个座位坐满即开打
    const sitB = await post({ action: 'sit', room: a.room, token: a.token, color: 'B' });
    ok(sitB.ok && sitB.you === 'B', 'A 坐上黑方位', sitB.error || sitB.you);
    const sitW = await post({ action: 'sit', room: a.room, token: b.token, color: 'W' });
    ok(sitW.ok && sitW.you === 'W', 'B 坐上白方位', sitW.error || sitW.you);
    ok(sitW.status === 'playing' && sitW.order.join('') === 'BW', '两人坐满开始对局（黑白轮流）', sitW.order);

    // 第三、第四个人：人数不限，进来就是观众
    const full = await post({ action: 'join', room: a.room, name: '观众甲' });
    ok(full.ok === true && full.you === 'S', '第三人加入是观众（人数不限）', full);
    const watch = await post({ action: 'join', room: a.room, name: '观众乙' });
    ok(watch.ok === true && watch.you === 'S' && watch.members.length === 4, '第四人加入同样是观众', watch.members && watch.members.length);

    // 观众不能代替棋手操作
    const specMove = await post({ action: 'move', room: a.room, token: full.token, edge: '18,0|18,1' });
    ok(specMove.ok === false && /观众/.test(specMove.error), '观众落子被拒', specMove.error);
    const specResign = await post({ action: 'resign', room: a.room, token: full.token });
    ok(specResign.ok === false, '观众认输被拒', specResign.error);
    const specSize = await post({ action: 'size', room: a.room, token: full.token, size: 11 });
    ok(specSize.ok === false, '观众改棋盘大小被拒', specSize.error);

    // 对局中坐下 = 上场申请，申请人自己可以撤销
    const specSit = await post({ action: 'sit', room: a.room, token: full.token, color: 'R' });
    ok(specSit.ok && specSit.joinRequest, '对局中观众坐下 → 变成上场申请', specSit.joinRequest);
    const cancelReq = await post({ action: 'vote', room: a.room, token: full.token, accept: false });
    ok(cancelReq.ok && !cancelReq.joinRequest, '申请人自己可以撤销申请', cancelReq.joinRequest);

    const aState = await post({ action: 'state', room: a.room, token: a.token });
    ok(aState.ok && aState.order.join('') === 'BW' && aState.status === 'playing', 'A 看到两位棋手已上场', aState.order);
    ok(aState.members.length === 4 && aState.youId, '同步带成员列表与自己的身份 id', aState.members);

    console.log('\n[2] 轮流落子并同步');
    const tokens = { B: a.token, W: b.token };
    let moves = [];
    for (let i = 0; i < 6; i++) {
      const st = rebuild(moves);
      const mover = st.turn;
      const legal = Logic.computeLegal(st);
      const edge = legal.moves[0];
      const r = await post({ action: 'move', room: a.room, token: tokens[mover], edge: edge });
      ok(r.ok === true, '第 ' + (i + 1) + ' 手（' + (mover === 'B' ? '黑' : '白') + '）提交成功', r.error);
      moves = r.moves;
      const local = rebuild(moves);
      ok(r.turn === local.turn, '第 ' + (i + 1) + ' 手后服务器回合与本地一致', r.turn);
    }
    ok(moves.length === 6, '服务器记录 6 手', moves.length);

    console.log('\n[3] 越权与非法落子被拒');
    const st6 = rebuild(moves);
    const wrongTurn = st6.turn === 'B' ? 'W' : 'B';
    const illegalTurn = await post({ action: 'move', room: a.room, token: tokens[wrongTurn], edge: Logic.computeLegal(st6).moves[0] });
    ok(illegalTurn.ok === false && /轮到/.test(illegalTurn.error), '不是自己的回合 → 被拒', illegalTurn.error);

    const bogus = await post({ action: 'move', room: a.room, token: tokens[st6.turn], edge: '99,0|99,1' });
    ok(bogus.ok === false, '不存在的格线 → 被拒', bogus);

    console.log('\n[4] 悔一手提议');
    const mover = st6.turn;
    const other = mover === 'B' ? 'W' : 'B';
    let r = await post({ action: 'propose', room: a.room, token: tokens[mover], type: 'undo' });
    ok(r.ok && r.proposal && r.proposal.type === 'undo' && r.proposal.by === mover, '发起悔棋提议', r.proposal);
    r = await post({ action: 'answer', room: a.room, token: tokens[mover], accept: true });
    ok(r.ok && r.proposal === null && r.moves.length === 6, '提议者自己调用 answer = 撤销', r.proposal);

    r = await post({ action: 'propose', room: a.room, token: tokens[other], type: 'undo' });
    ok(r.ok && r.proposal && r.proposal.by === other, '对方发起悔棋提议');
    r = await post({ action: 'answer', room: a.room, token: tokens[mover], accept: true });
    ok(r.ok && r.moves.length === 5, '同意后回退一手', r.moves && r.moves.length);
    moves = r.moves;
    const afterUndo = rebuild(moves);
    ok(afterUndo.moveNo === 5, '本地重放与服务器手数一致', afterUndo.moveNo);

    r = await post({ action: 'propose', room: a.room, token: tokens[other], type: 'undo' });
    ok(r.ok, '再次发起悔棋提议（可连续回退）');
    r = await post({ action: 'answer', room: a.room, token: tokens[mover], accept: false });
    ok(r.ok && r.moves.length === 5 && r.proposal === null, '拒绝提议 → 不回退', r.moves && r.moves.length);

    console.log('\n[5] 认输与终局');
    for (let i = 0; i < 2; i++) {
      const st = rebuild(moves);
      const edge = Logic.computeLegal(st).moves[0];
      const res = await post({ action: 'move', room: a.room, token: tokens[st.turn], edge: edge });
      ok(res.ok, '补两手棋（第 ' + (i + 1) + ' 手）', res.error);
      moves = res.moves;
    }
    r = await post({ action: 'resign', room: a.room, token: a.token });
    ok(r.ok && r.over === true && r.winner === 'W', 'A（黑方）认输 → 白方胜', r);
    ok(r.status === 'over', '状态为 over');
    const afterResign = await post({ action: 'move', room: a.room, token: b.token, edge: '18,0|18,1' });
    ok(afterResign.ok === false, '终局后落子被拒', afterResign.error);

    console.log('\n[6] 新对局提议');
    r = await post({ action: 'propose', room: a.room, token: b.token, type: 'new' });
    ok(r.ok && r.proposal && r.proposal.type === 'new', '发起新对局提议');
    r = await post({ action: 'answer', room: a.room, token: a.token, accept: false });
    ok(r.ok && r.moves.length > 0, '拒绝新对局 → 棋局保留', r.moves && r.moves.length);
    r = await post({ action: 'propose', room: a.room, token: b.token, type: 'new' });
    r = await post({ action: 'answer', room: a.room, token: a.token, accept: true });
    ok(r.ok && r.moves.length === 0 && r.over === false && r.status === 'playing', '同意新对局 → 清空重开', r);
    moves = r.moves;

    console.log('\n[6b] 手动结束对局（= 提议，需所有在场玩家同意）');
    for (let i = 0; i < 2; i++) {
      const st = rebuild(moves);
      const edge = Logic.computeLegal(st).moves[0];
      const res = await post({ action: 'move', room: a.room, token: tokens[st.turn], edge: edge });
      ok(res.ok, '补两手棋（第 ' + (i + 1) + ' 手）', res.error);
      moves = res.moves;
    }
    let endRes = await post({ action: 'end', room: a.room, token: a.token });
    ok(endRes.ok && endRes.proposal && endRes.proposal.type === 'end', '手动结束对局 → 发起「结束对局」提议', endRes);
    endRes = await post({ action: 'answer', room: a.room, token: b.token, accept: true });
    ok(endRes.ok && endRes.over === true, '所有在场玩家同意 → 手动结束对局生效', endRes);
    ok(endRes.moves.length > 0 && endRes.moves[endRes.moves.length - 1].end === true,
      '着法记录带 end 标记', endRes.moves[endRes.moves.length - 1]);
    ok(['B', 'W', 'D'].includes(endRes.winner), '按地数给出胜负 / 和棋', endRes.winner);
    const afterEnd = await post({ action: 'move', room: a.room, token: b.token, edge: '18,0|18,1' });
    ok(afterEnd.ok === false, '终局后落子被拒', afterEnd.error);

    console.log('\n[7] 页面与数据保护');
    const page = await fetch(BASE + '/');
    const pageText = await page.text();
    ok(page.ok && pageText.includes('棍棋'), '首页可打开');
    const byCode = await fetch(BASE + '/?room=' + a.room);
    ok(byCode.ok, '邀请链接（?room=房间号）可打开');
    const leak = await fetch(BASE + '/data/rooms/' + a.room + '.php');
    ok(leak.status === 404, '房间数据文件被屏蔽（404）', leak.status);
    const leak2 = await fetch(BASE + '/src/Logic.php');
    ok(leak2.status === 404, '服务端源码目录被屏蔽（404）', leak2.status);
    const rejoin = await post({ action: 'join', room: a.room, token: a.token });
    ok(rejoin.ok && rejoin.you === 'B', '旧令牌断线重连保留座位', rejoin.you);
    const noAuth = await post({ action: 'move', room: a.room, token: 'deadbeef', edge: '18,0|18,1' });
    ok(noAuth.ok === false, '伪造令牌被拒', noAuth.error);

    // 旧版客户端（请求不带当前构建号）被拒绝并提示刷新——避免旧页面的 JS 静默乱同步
    const stale = await (await fetch(BASE + '/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'state', room: a.room, token: a.token })
    })).json();
    ok(stale.ok === false && stale.stale === true && /强制刷新/.test(stale.error),
      '旧版客户端请求被拒并提示「强制刷新」', stale);

    console.log('\n[8] 棋盘大小可改（坐标原点在棋盘中心）');
    const sz = await post({ action: 'size', room: a.room, token: a.token, size: 13 });
    ok(sz.ok && sz.size === 13, '棋手可以把棋盘改成 13 路', sz.error || sz.size);
    ok(sz.moves.length === 0 && sz.over === false && sz.status === 'playing',
      '改棋盘大小 → 清空棋局重新开始', sz.moves && sz.moves.length);
    Logic.setSize(13);                       // 本地规则引擎跟随房间棋盘
    ok(Logic.SIDE === 13 && Logic.computeRegions(new Map()).sizes[0] === 6 * 13 * 13,
      '13 路几何正确（1014 个三角格）');
    let moves2 = sz.moves;
    for (let i = 0; i < 2; i++) {
      const st = rebuild(moves2);
      const edge = Logic.computeLegal(st).moves[0];
      const res = await post({ action: 'move', room: a.room, token: tokens[st.turn], edge: edge });
      ok(res.ok, '13 路棋盘落子（第 ' + (i + 1) + ' 手）', res.error);
      ok(res.size === 13, '同步返回棋盘大小', res.size);
      moves2 = res.moves;
    }
    const st13 = rebuild(moves2);
    ok(st13.sticks.size === 2, '13 路棋盘本地重放与服务器一致', st13.sticks.size);
    const bad19 = await post({ action: 'move', room: a.room, token: tokens[rebuild(moves2).turn], edge: '18,0|18,1' });
    ok(bad19.ok === false, '19 路才有的坐标在 13 路棋盘上被拒', bad19.error);

    console.log('\n[9] 上场申请：所有棋手同意 → 变成 3 人轮流下');
    const third = await post({ action: 'join', room: a.room, name: '想上场的人' });
    let rq = await post({ action: 'sit', room: a.room, token: third.token, color: 'R' });
    ok(rq.ok && rq.you === 'S' && rq.joinRequest, '对局中坐下 → 变成上场申请', rq.joinRequest);
    let vt = await post({ action: 'vote', room: a.room, token: a.token, accept: true });
    ok(vt.ok && vt.joinRequest, '一位棋手同意 → 还要等另一位', vt.joinRequest && vt.joinRequest.votes);
    vt = await post({ action: 'vote', room: a.room, token: b.token, accept: true });
    ok(vt.ok && !vt.joinRequest && vt.order.join('') === 'BWR', '所有棋手同意 → 红方上场（3 人）', vt.order);

    // 回归：中途有人上场后「悔一手」，轮次要交还给原来该落子的人（不是新上场的人）
    const one = await post({
      action: 'move', room: a.room, token: a.token,
      edge: Logic.computeLegal(rebuild(moves2, ['B', 'W', 'R'])).moves[0]
    });
    ok(one.ok && one.turn === 'W', '三人局：黑方落子后轮到白方', one.turn || one.error);
    let rg = await post({ action: 'propose', room: a.room, token: a.token, type: 'undo' });
    ok(rg.ok && rg.proposal, '发起悔棋提议（拿回刚下的这手）', rg.error);
    rg = await post({ action: 'answer', room: a.room, token: b.token, accept: true });
    ok(rg.ok && rg.proposal !== null && rg.moves.length === one.moves.length,
      '只有白方同意还不够：悔棋要所有在场玩家同意', rg.proposal);
    rg = await post({ action: 'answer', room: a.room, token: third.token, accept: true });
    ok(rg.ok && rg.moves.length === one.moves.length - 1, '红方也同意后才回退这一手（「加入比赛」记录保留）', rg.moves && rg.moves.length);
    ok(rg.turn === 'B', '悔棋后轮到原来该落子的黑方（不是中途上场的红方）', rg.turn);

    // 「结束对局」同样要全员同意：有人拒绝 → 不结束（0.8.0：且转为不同意的人继续下）
    let eg = await post({ action: 'end', room: a.room, token: a.token });
    ok(eg.ok && eg.proposal && eg.proposal.type === 'end', '发起「结束对局」提议', eg.error);
    eg = await post({ action: 'answer', room: a.room, token: b.token, accept: true });
    ok(eg.ok && eg.proposal !== null && eg.status === 'playing', '白方同意还不够：仍在等红方表态', eg);
    eg = await post({ action: 'answer', room: a.room, token: third.token, accept: false });
    ok(eg.ok && !eg.proposal && eg.status === 'playing', '红方拒绝 → 结束对局提议作废，棋局继续', eg);
    ok(eg.turn === 'R', '不同意结束对局 → 转为不同意的红方继续下', eg.turn);

    // 有人拒绝 → 申请作废
    const fourth = await post({ action: 'join', room: a.room, name: '被拒的人' });
    rq = await post({ action: 'sit', room: a.room, token: fourth.token, color: 'G' });
    ok(rq.ok && rq.joinRequest, '又一份上场申请');
    vt = await post({ action: 'vote', room: a.room, token: a.token, accept: false });
    ok(vt.ok && !vt.joinRequest, '有人拒绝 → 申请作废', vt.joinRequest);

    // 黑白红三人轮流下（红方刚拒绝了结束对局 → 由它继续下）
    const tokens3 = { B: a.token, W: b.token, R: third.token };
    const order3 = ['B', 'W', 'R'];
    let m3 = moves2;
    let next = eg.turn;   // 不同意结束对局 → 转为不同意的人（红方）继续下
    for (let i = 0; i < 3; i++) {
      const st = rebuild(m3, order3);
      const edge = Logic.computeLegal(st).moves[0];
      const res = await post({ action: 'move', room: a.room, token: tokens3[next], edge: edge });
      ok(res.ok, '三人局第 ' + (i + 1) + ' 手（' + next + '）', res.error);
      ok(res.turn === order3[(order3.indexOf(next) + 1) % 3], '黑白红轮流下', res.turn);
      next = res.turn;
      m3 = res.moves;
    }

    console.log('\n[10] 退出房间 / 让出座位');
    let lv = await post({ action: 'leave', room: a.room, token: full.token });
    ok(lv.ok, '观众可以退出房间', lv.error);
    let st10 = await post({ action: 'state', room: a.room, token: a.token });
    ok(!st10.members.some((m) => m.role === 'S' && m.name === '观众甲'), '观众退出后成员列表更新', st10.members.length);

    lv = await post({ action: 'leave', room: a.room, token: b.token });
    ok(lv.ok, '棋手可以退出房间（认输让出座位）', lv.error);
    st10 = await post({ action: 'state', room: a.room, token: a.token });
    ok(st10.order.join('') === 'BR', '白方退出后剩下两人继续下', st10.order);
    ok(!st10.members.some((m) => m.role === 'W'), '白方座位空出来', st10.members);
    ok(st10.status === 'playing', '棋局不因退出而丢失', st10.status);

    const gone = await post({ action: 'state', room: a.room, token: b.token });
    ok(gone.ok === false, '退出后旧令牌失效', gone.error);

    // 新人坐进空位接手：对局进行中，同样要所有棋手同意
    const takeover = await post({ action: 'join', room: a.room, name: '接手的人' });
    let tk = await post({ action: 'sit', room: a.room, token: takeover.token, color: 'W' });
    ok(tk.ok && tk.joinRequest, '接手也是上场申请（对局进行中）', tk.joinRequest);
    await post({ action: 'vote', room: a.room, token: a.token, accept: true });
    tk = await post({ action: 'vote', room: a.room, token: third.token, accept: true });
    ok(tk.ok && !tk.joinRequest && tk.order.join('') === 'BWR', '全员同意 → 新人接手空位、回到行棋轮次', tk.order);
    ok(tk.moves.some((m) => m.j), '重新上场记了一笔（保证悔棋重放一致）', tk.moves);

    console.log('\n[11] 棋谱载入 · 分支续下');
    const seatTokens = { B: a.token, W: takeover.token, R: third.token };
    const rec = [];
    for (let i = 0; i < 4; i++) {
      const st = rebuild(rec);
      rec.push({ p: st.turn, e: Logic.computeLegal(st).moves[0] });
    }
    let ld = await post({
      action: 'load', room: a.room, token: a.token,
      moves: rec.concat([{ p: 'B', r: true }, { p: 'W', end: true }])
    });
    ok(ld.ok && ld.moves.length === 4, '载入棋谱成功（认输 / 终局标记自动跳过）', ld.error || (ld.moves && ld.moves.length));
    ok(ld.moves.every((m, i) => m.p === (i % 2 === 0 ? 'B' : 'W')), '黑白标记按实际行棋次序规整', ld.moves);
    ok(!ld.over && ld.status === 'playing', '载入后的局面可以继续下（分支）', ld);

    const specLoad = await post({ action: 'load', room: a.room, token: fourth.token, moves: rec.slice(0, 2) });
    ok(specLoad.ok === false && /观众/.test(specLoad.error), '观众不能载入棋谱', specLoad.error);

    const badLoad = await post({ action: 'load', room: a.room, token: a.token, moves: [{ p: 'B', e: '18,0|18,1' }] });
    ok(badLoad.ok === false, '非法着法整体拒绝', badLoad.error);
    const unchanged = await post({ action: 'state', room: a.room, token: a.token });
    ok(unchanged.moves.length === 4, '载入失败后房间棋局保持原样', unchanged.moves.length);

    const contEdge = Logic.computeLegal(rebuild(ld.moves, order3)).moves[0];
    const cont = await post({ action: 'move', room: a.room, token: seatTokens[ld.turn], edge: contEdge });
    ok(cont.ok && cont.moves.length === 5, '分支续下后可以接着落子', cont.error);

    // 棋谱比棋盘大：客户端带上需要的路数，一并切过去
    ld = await post({ action: 'load', room: a.room, token: a.token, size: 19, moves: [{ p: 'B', e: '18,0|18,1' }] });
    ok(ld.ok && ld.size === 19 && ld.moves.length === 1, '棋谱比棋盘大 → 自动切到需要的路数再续下', ld.error || ld.size);
    Logic.setSize(19);
    ok(rebuild(ld.moves).turn === 'W', '切换后本地重放与服务器一致', rebuild(ld.moves).turn);
    console.log('\n[12] 下来 / 接替 / 思考时间超时踢下场（新房间）');
    const p1 = await post({ action: 'create', name: '主人' });
    const p2 = await post({ action: 'join', room: p1.room, name: '对手' });
    await post({ action: 'sit', room: p1.room, token: p1.token, color: 'B' });
    await post({ action: 'sit', room: p1.room, token: p2.token, color: 'W' });
    const m0 = await post({ action: 'move', room: p1.room, token: p1.token, edge: '18,0|18,1' });
    ok(m0.ok && m0.moves.length === 1 && m0.turn === 'W', '新房间：黑方落第 1 手、轮到白方', m0.error);

    // 「下来」：让出座位但棋局保留，轮次跳过空位（不卡在下场者的回合）
    const dn = await post({ action: 'stand', room: p1.room, token: p2.token });
    ok(dn.ok && dn.you === 'S', '白方点「下来」→ 变回观众', dn.error || dn.you);
    ok(dn.order.join('') === 'BW' && dn.moves.length === 1 && dn.status === 'playing',
      '下来不认输：轮次与棋局原样保留（不重置棋盘）', dn);
    ok(dn.turn === 'B', '轮次跳过空着的白方 → 轮到黑方（不卡在白方回合）', dn.turn);

    // 新人直接接替（不用上场申请）
    const p3 = await post({ action: 'join', room: p1.room, name: '接手的人' });
    let tk12 = await post({ action: 'sit', room: p1.room, token: p3.token, color: 'W' });
    ok(tk12.ok && tk12.you === 'W' && !tk12.joinRequest, '新人直接坐上空位接替（不用申请）', tk12);
    ok(tk12.moves.length === 1 && tk12.order.join('') === 'BW', '接替后棋盘不重置', tk12);

    // 思考时间：1 秒 → 行棋方（黑方）超时被请下场
    const think = await post({ action: 'think', room: p1.room, token: p1.token, seconds: 1 });
    ok(think.ok && think.thinkLimit === 1, '设置思考时间 1 秒', think.error || think.thinkLimit);
    await sleep(2300);
    const kicked = await post({ action: 'state', room: p1.room, token: p3.token });
    ok(kicked.ok && !kicked.members.some((m) => m.role === 'B'), '黑方思考超时 → 被踢下场', kicked.members);
    ok(kicked.turn === 'W', '超时下场后轮次跳到在场的一方（棋局继续）', kicked.turn);
    ok(kicked.moves.length === 1 && kicked.status === 'playing', '超时不重置棋盘、不结束棋局', kicked);
    const back = await post({ action: 'sit', room: p1.room, token: p1.token, color: 'B' });
    ok(back.ok === false && /超时/.test(back.error), '被超时踢下场的人本局不能再上场', back.error);

    // 只剩一位在场玩家：「结束对局」提议立即生效（= 全员同意）
    const solo = await post({ action: 'end', room: p1.room, token: p3.token });
    ok(solo.ok && !solo.proposal && solo.over === true, '只剩一位在场玩家：结束对局提议立即生效', solo);
    console.log('\n[13] 注册 / 登录：同一账号换个设备登录接着下（0.8.0）');
    // 稳定账号：注册；已被上次测试注册过就改成登录（重名拒绝是正确行为）
    async function ensureLogin(user, pass) {
      let r = await post({ action: 'register', user: user, pass: pass });
      if (!r.ok) r = await post({ action: 'login', user: user, pass: pass });
      return r;
    }
    const uniq = '测' + Math.random().toString(36).slice(2, 8);
    const reg1 = await post({ action: 'register', user: uniq, pass: 'pass1234' });
    ok(reg1.ok && reg1.user === uniq && reg1.auth, '注册成功（用户名 + 密码 → 登录令牌）', reg1.error || reg1.user);
    const regDup = await post({ action: 'register', user: uniq, pass: 'pass1234' });
    ok(regDup.ok === false, '重复注册同一个用户名被拒', regDup.error);
    const badLogin = await post({ action: 'login', user: uniq, pass: '错的密码' });
    ok(badLogin.ok === false, '密码不对登录被拒', badLogin.error);
    const lg1 = await post({ action: 'login', user: uniq, pass: 'pass1234' });
    ok(lg1.ok && lg1.auth, '用户名 + 密码再次登录成功（换设备通用）', lg1.error);
    const chk = await post({ action: 'auth', user: uniq, auth: lg1.auth });
    ok(chk.ok === true, '登录令牌校验通过（打开页面恢复登录状态）', chk.error);
    const badAuth = await post({ action: 'auth', user: uniq, auth: 'deadbeef' });
    ok(badAuth.ok === false, '伪造登录令牌被拒', badAuth.error);

    const accA = await ensureLogin('棋手小明', 'pass1234');
    const accB = await ensureLogin('棋手小红', 'pass1234');
    ok(accA.ok && accA.auth && accB.ok && accB.auth, '两个对局账号就绪', accA.error || accB.error);
    const ac1 = await post({ action: 'create', name: '棋手小明', user: '棋手小明', auth: accA.auth });
    ok(ac1.ok, '登录用户创建房间', ac1.error);
    const ac2 = await post({ action: 'join', room: ac1.room, name: '棋手小红', user: '棋手小红', auth: accB.auth });
    ok(ac2.ok && ac2.members.some((m) => m.name === '棋手小明'), '登录用户进房（成员显示用户名）', ac2.members);
    await post({ action: 'sit', room: ac1.room, token: ac1.token, color: 'B' });
    await post({ action: 'sit', room: ac1.room, token: ac2.token, color: 'W' });
    const acMove = await post({ action: 'move', room: ac1.room, token: ac1.token, edge: '18,0|18,1' });
    ok(acMove.ok && acMove.turn === 'W', '两位登录用户轮流落子', acMove.error);

    // 手机退出 → 电脑登录同一账号：接管黑方座位、棋局不重置，接着下
    const lg2 = await post({ action: 'login', user: '棋手小明', pass: 'pass1234' });
    const ac3 = await post({ action: 'join', room: ac1.room, name: '棋手小明', user: '棋手小明', auth: lg2.auth });
    ok(ac3.ok && ac3.you === 'B' && ac3.token !== ac1.token,
      '同一账号从「电脑」登录 → 接管黑方座位接着下', ac3.you || ac3.error);
    ok(ac3.moves.length === 1, '接管后棋局不重置（同一盘棋）', ac3.moves && ac3.moves.length);
    const staleDev = await post({ action: 'state', room: ac1.room, token: ac1.token });
    ok(staleDev.ok === false, '旧设备（手机）的令牌作废', staleDev.error);
    const acMove2 = await post({
      action: 'move', room: ac1.room, token: ac2.token,
      edge: Logic.computeLegal(rebuild(ac3.moves)).moves[0]
    });
    ok(acMove2.ok, '白方接着下', acMove2.error);
    const acMove3 = await post({
      action: 'move', room: ac1.room, token: ac3.token,
      edge: Logic.computeLegal(rebuild(acMove2.moves)).moves[0]
    });
    ok(acMove3.ok && acMove3.moves.length === 3, '换设备接管后接着下同一盘棋', acMove3.error || acMove3.moves.length);

    console.log('\n[14] 回归（0.8.1）：纯数字用户名注册 / 免登录游客进房');
    // 纯数字用户名：JSON 往返后数组键变 int，查重不能报错，更不能把之后的注册全弄坏
    const numUser = String(10000000 + Math.floor(Math.random() * 89999999));
    const regNum = await post({ action: 'register', user: numUser, pass: 'pass1234' });
    ok(regNum.ok && regNum.user === numUser, '纯数字用户名可以注册（不再抛内部错误）', regNum.error || regNum.user);
    const regAfter = await post({ action: 'register', user: uniq + '后', pass: 'pass1234' });
    ok(regAfter.ok, '纯数字用户名注册后，其他人照样能注册（回归）', regAfter.error);
    const dupNum = await post({ action: 'register', user: numUser, pass: 'pass1234' });
    ok(dupNum.ok === false && /注册过/.test(dupNum.error), '纯数字用户名重复注册照常被拒', dupNum.error);
    const loginNum = await post({ action: 'login', user: numUser, pass: 'pass1234' });
    ok(loginNum.ok && loginNum.auth, '纯数字用户名可以正常登录', loginNum.error);
    // 内部错误不许带服务器路径（回归：UserStore::lower() 那次报错带了 E:\wwwroot\... ）
    ok(!/服务器内部错误：/.test(String(regNum.error || '')) && !/[A-Za-z]:\\|\/(src|data)\//.test(String(regNum.error || '')),
      '注册报错不带服务器路径', regNum.error);

    // 不登录也能建房 / 进房：游客临时身份
    const guest1 = await post({ action: 'create', name: '游客' });
    ok(guest1.ok && guest1.you === 'S', '不登录也能创建房间（游客临时身份）', guest1.error);
    const guest2 = await post({ action: 'join', room: guest1.room, name: '游客' });
    ok(guest2.ok && guest2.members.length === 2, '不登录也能加入房间', guest2.error);
  } finally {
    server.kill();
  }

  console.log('\n结果：' + passed + ' 通过, ' + failed + ' 失败\n');
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error('测试脚本异常：', e); process.exit(1); });
