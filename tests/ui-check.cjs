// 棍棋 · 三角版（联机版）— 界面层静态检查
//   1. 各段脚本语法可编译
//   2. getElementById 引用的元素 id 都存在（含脚本里动态生成的 id）
//   3. 单机版源文件未改动（规则层指纹固定）+ 联机版规则层可加载
// 运行：node tests/ui-check.cjs
const fs = require('node:fs');
const path = require('node:path');

const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

let passed = 0, failed = 0;
function ok(cond, name, extra) {
  if (cond) { passed++; console.log('  ✓ ' + name); }
  else { failed++; console.log('  ✗ ' + name + (extra !== undefined ? '  → ' + JSON.stringify(extra) : '')); }
}

console.log('\n[1] 脚本语法');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
ok(scripts.length >= 2, 'index.html 包含逻辑层与界面层脚本（' + scripts.length + ' 段）');
const recordJs = fs.readFileSync(path.join(__dirname, '..', 'record.js'), 'utf8');
const codeList = scripts.map((c, i) => ['第 ' + (i + 1) + ' 段脚本', c]).concat([['record.js', recordJs]]);
for (const [name, code] of codeList) {
  try {
    new Function(code);
    ok(true, name + ' 语法正确');
  } catch (e) {
    ok(false, name + ' 语法正确', e.message);
  }
}

console.log('\n[2] 元素 id 一致性');
const ids = new Set([...html.matchAll(/\bid="([^"]+)"/g)].map((m) => m[1]));
const used = new Set();
for (const code of codeList.map((x) => x[1])) {
  for (const m of code.matchAll(/getElementById\('([^']+)'\)/g)) used.add(m[1]);
  for (const m of code.matchAll(/\$\('([^']+)'\)/g)) used.add(m[1]);
}
ok(used.size > 0, '界面层引用了 ' + used.size + ' 个元素 id');
for (const id of [...used].sort()) {
  ok(ids.has(id), '元素 #' + id + ' 存在');
}

console.log('\n[3] 单机版源文件未改动 + 规则层可加载');
const { createHash } = require('node:crypto');
const solo = path.join(__dirname, '..', '..', 'index.html');
const pat = /const Logic = \(function \(\) \{[\s\S]*?module\.exports = Logic; \}/;
if (fs.existsSync(solo)) {
  const b = (fs.readFileSync(solo, 'utf8').match(pat) || [''])[0];
  const hash = createHash('sha256').update(b).digest('hex');
  // 单机版规则层的固定指纹：联机版加了新规则（②③④），但单机版源文件必须保持原样
  const SOLO_LOGIC_SHA256 = '5a8e0d80c723cfc6372c45a7767811230ac6f1c7f3ac521f63f54e3cc98247b2';
  ok(hash === SOLO_LOGIC_SHA256, '单机版 index.html 的规则层未被改动', hash);
} else {
  ok(true, '（未找到单机版 index.html，跳过源文件完整性检查）');
}
let L = null;
const RecordKit = require(path.join(__dirname, '..', 'record.js'));
try {
  const moduleShim = { exports: {} };
  new Function('module', scripts[0])(moduleShim);
  L = moduleShim.exports;
  const api = ['computeLegal', 'whyIllegal', 'placeMove', 'anchorVertexSet', 'newGameState', 'score', 'edgeLabel'];
  ok(api.every((f) => typeof L[f] === 'function'), '联机版规则层导出齐全');
  const st = L.newGameState();
  const legal = L.computeLegal(st);
  ok(legal.mode === 'anchor' && legal.moves.length > 0, '规则层可运行（开局有合法落点）');
} catch (e) {
  ok(false, '规则层可加载', e.message);
}

console.log('\n[4] 新规则行为（②③④）');
try {
  // 规则②：新开一串可以贴住任何一根已下的棍
  const s2 = L.newGameState();
  L.placeMove(s2, L.edgeKey(18, 0, 18, 1));
  L.placeMove(s2, L.edgeKey(18, 0, 19, 0));
  s2.sticks.set(L.edgeKey(5, 0, 6, 0), 'B');   // 没围出地的棍
  s2.anchorRequired = true;
  ok(L.whyIllegal(s2, L.edgeKey(5, 0, 5, 1)) === null, '规则②：贴住「没围出地」的棍可以落子');

  // 规则④：平分不算（不染色、不计数）
  const s7 = L.newGameState();
  s7.sticks.set(L.edgeKey(18, 0, 19, 0), 'W');
  s7.sticks.set(L.edgeKey(18, 1, 17, 1), 'W');
  s7.sticks.set(L.edgeKey(17, 1, 18, 0), 'W');
  const tA = L.findTriangle([18, 0], [19, 0], [18, 1]);
  const tB = L.findTriangle([18, 0], [17, 1], [18, 1]);
  s7.owner[tA] = 2; s7.owner[tB] = 2;
  s7.turn = 'B'; s7.anchorRequired = true;
  const r7 = L.placeMove(s7, L.edgeKey(18, 0, 18, 1));
  ok(r7.ok === true && r7.claimed === null, '规则④：平分可以下但不算地', r7);
  ok(s7.owner[tA] === 2 && s7.owner[tB] === 2, '规则④：不染色');
  ok(s7.anchorRequired === true, '规则④：下一步走「新开一串」');
  ok(s7.sinceClaim === 1, '规则④：平分不算围地，计数器照常 +1');

  // 规则③：计数器=1（围完地后的第一手）时，不能在对方已围的地里围地
  function strip3() {
    const s = L.newGameState();
    s.sticks.set(L.edgeKey(18, 0, 19, 0), 'W');
    s.sticks.set(L.edgeKey(17, 1, 18, 0), 'W');
    s.sticks.set(L.edgeKey(17, 2, 17, 1), 'W');
    const a = L.findTriangle([18, 0], [19, 0], [18, 1]);
    const b = L.findTriangle([18, 0], [18, 1], [17, 1]);
    const c = L.findTriangle([17, 1], [18, 1], [17, 2]);
    s.owner[a] = 2; s.owner[b] = 2; s.owner[c] = 2;
    s.turn = 'B'; s.anchorRequired = true;
    s.sinceClaim = 0;   // 刚围完地：计数器归 0
    return { s: s, a: a };
  }
  const w = strip3();
  ok(L.whyIllegal(w.s, L.edgeKey(18, 0, 18, 1)) !== null, '规则③：计数器=1 时一步抢地被拒');
  ok(L.placeMove(w.s, L.edgeKey(18, 0, 18, 1)).ok === false, '规则③：placeMove 同样拒绝');
  ok(w.s.owner[w.a] === 2, '规则③：没有被翻色');
  const w2 = strip3();
  w2.s.sinceClaim = 1;   // 围完地后已经下过一手 → 这手读数为 2
  const r8 = L.placeMove(w2.s, L.edgeKey(18, 0, 18, 1));
  ok(r8.ok && r8.claimed && r8.claimed.count === 1, '规则③：计数器=2 时同一手围地允许', r8);
  ok(w2.s.owner[w2.a] === 1, '规则③：抢到的那格归黑方');
  ok(w2.s.sinceClaim === 0, '规则③：围完地计数器归 0');
  const w3 = L.newGameState();
  L.placeMove(w3, L.edgeKey(18, 0, 19, 0));
  w3.sinceClaim = 0;
  const r9 = L.placeMove(w3, L.edgeKey(18, 0, 18, 1));
  ok(r9.ok && r9.claimed && r9.claimed.count === 1, '规则③：计数器=1 但在空地围地 → 允许', r9);

  // 手动结束对局：按地数判定胜负
  const eg = L.newGameState();
  L.placeMove(eg, L.edgeKey(18, 0, 19, 0));   // 黑
  L.placeMove(eg, L.edgeKey(18, 0, 18, 1));   // 白围 1 格
  const rEnd = L.endGameAs(eg, 'W');
  ok(rEnd.ok && eg.over && eg.winner === 'W', '手动结束对局：白地多 → 白胜');
  const eg2 = L.newGameState();
  L.endGameAs(eg2, 'B');
  ok(eg2.over && eg2.winner === 'D', '手动结束对局：地数相同 → 和棋');

  // 对局记录：格式化 → 解析 回环
  const recMoves = [{ p: 'B', e: L.edgeKey(18, 0, 19, 0) }, { p: 'W', e: L.edgeKey(18, 0, 18, 1) }, { p: 'B', r: true }];
  const text = RecordKit.formatRecord(recMoves, L);
  const back = RecordKit.parseRecord(text);
  ok(back.errors.length === 0 && back.moves.length === 3, '对局记录导出 → 导入回环', back);
  const review = RecordKit.buildReview(L, back.moves);
  ok(review.states.length === 4 && review.errors.length === 0, '棋谱可重放成逐步局面', review.errors);
} catch (e) {
  ok(false, '新规则行为检查可运行', e.message);
}

console.log('\n[5] 新功能：棋盘大小 / 播放暂停合一 / 多人观战 / 退出房间');
ok(!html.includes('id="recordPause"'), '「暂停」独立按钮已移除');
ok(/id="recordPlay"[^>]*>播放</.test(html), '「播放」按钮初始字样是「播放」');
ok(!recordJs.includes('recordPause'), 'record.js 不再引用「暂停」按钮');
ok(/toggleAuto/.test(recordJs) && /textContent = rev\.timer \? '暂停' : '播放'/.test(recordJs),
  '点「播放」变「暂停」并开始，点「暂停」变回「播放」并停下');
ok(html.includes('id="btnLeaveRoom"'), '左上角有「退出房间」按钮');
ok(html.includes('id="memberList"'), '有「在线成员」列表');
ok(html.includes('id="sizeSelect"') && html.includes('id="btnSize"'), '有「棋盘大小」控件');
ok(html.includes('id="recordBranch"'), '有「从这里续下」按钮');
ok(/branchFlow/.test(recordJs) && /loadMoves/.test(recordJs), 'record.js 实现了分支续下（loadMoves）');
ok(/sizeDirty/.test(scripts[1]), '棋盘大小选择带 sizeDirty 保护（不被轮询覆盖）');
ok(html.includes('id="authUser"') && html.includes('id="authPass"')
  && html.includes('id="btnRegister"') && html.includes('id="btnLogin"'),
  '大厅改成注册 / 登录（用户名 + 密码），选色坐下在房间里（黑白红绿紫蓝）');
ok(html.includes('id="seatGrid"'), '大厅里有座位入口（进房间后坐下上场）');
ok(html.includes('id="joinBar"') && html.includes('id="btnJoinYes"'), '有「上场申请」全员同意条');
ok(!html.includes('id="nickName"'), '填昵称的输入框已移除（改成注册 / 登录）');
ok(!/LAST_STICK_GOLD|金色包边/.test(scripts[1]), '新落子的金色高亮已移除');
ok(/DROP_ANIM_MS/.test(scripts[1]) && /kLen/.test(scripts[1]) && /kW/.test(scripts[1]),
  '新落子改为动画：3 倍大、10 倍粗迅速缩回正常大小（位置不动）');
ok(/id="recordBranch"/.test(html), '保留「从这里续下」按钮');
// 客户端构建号：index.html 与 api.php 必须一致（不一致 = 旧页面会被服务器拒绝并提示刷新）
const apiSrc = fs.readFileSync(path.join(__dirname, '..', 'api.php'), 'utf8');
const htmlBuild = (html.match(/const CLIENT_BUILD = '([^']+)'/) || [])[1];
const apiBuild = (apiSrc.match(/const CLIENT_BUILD = '([^']+)'/) || [])[1];
ok(!!htmlBuild && htmlBuild === apiBuild, '客户端构建号一致（index.html ↔ api.php：' + htmlBuild + '）', { htmlBuild, apiBuild });
try {
  // 坐标原点在棋盘中心：小棋盘的坐标放到大棋盘上依然有效
  const small = L.edgeKey(18, 0, 18, 1);
  L.setSize(25);
  ok(L.SIDE === 25 && L.EDGE_LIST.some((e) => e.key === small), '19 路棋谱的坐标在 25 路棋盘上有效（原点居中）');
  ok(L.computeRegions(new Map()).sizes[0] === 6 * 25 * 25, '25 路几何正确（3750 格）');
  L.setSize(11);
  ok(L.SIDE === 11 && !L.EDGE_LIST.some((e) => e.key === small), '11 路棋盘上 19 路坐标越界（被拒）');
  ok(L.computeRegions(new Map()).sizes[0] === 6 * 11 * 11, '11 路几何正确（726 格）');
  L.setSize(19);
  ok(L.SIDE === 19 && L.computeRegions(new Map()).sizes[0] === 2166, '切回 19 路恢复正常几何');
  const st19 = L.newGameState();
  ok(L.placeMove(st19, L.edgeKey(18, 0, 18, 1)).ok, '切回 19 路后规则照常工作');
} catch (e) {
  ok(false, '棋盘大小切换可运行', e.message);
}

console.log('\n[6] 本次改版：紫色棋子 / 全员同意 / 弹窗申请 / 下来接替 / 思考时间 / 界面调整');
// 1) 黄色棋子 → 紫色
ok(!/黄/.test(html), '界面里不再出现「黄」（黄 → 紫）');
ok(/Y: '#8a2be2'/.test(scripts[1]) && /Y: '紫'/.test(scripts[1]), '紫色棋子：Y 墨色为紫色、名字叫「紫」');
ok(/紫|黄/.test(recordJs) && /Y: '紫'/.test(recordJs), '棋谱里紫色写作「紫」（旧「黄」仍可导入）');
// 2) 全员同意（悔棋 / 结束对局）
const svc = fs.readFileSync(path.join(__dirname, '..', 'src', 'RoomService.php'), 'utf8');
ok(/allSeatedAgreed/.test(svc) && /executeProposal/.test(svc), '服务器：提议要集齐所有在场玩家同意才执行');
ok(/'votes' => \[\$seat => true\]/.test(svc), '提议记录同意票（votes，提议者自己一票）');
ok(/type !== 'undo' && \$type !== 'new' && \$type !== 'end'/.test(svc), '「结束对局」也是提议（end）');
// 3) 所有申请都以弹窗出现
ok(html.includes('id="reqModal"') && html.includes('id="btnReqYes"') && html.includes('id="btnReqClose"'),
  '有申请 / 提议弹窗（含同意·拒绝·撤销·关闭）');
ok(/renderRequest/.test(scripts[1]), '界面层实现了弹窗渲染');
ok(/keydown/.test(scripts[1]) && /Escape/.test(scripts[1]), '弹窗支持 Esc 关闭');
// 4) 坐着的人可以「下来」，掉线自动下场、可接替
ok(html.includes('id="btnStand"') && /sendStand/.test(scripts[1]), '有「下来（让出座位）」按钮');
ok(/'stand'/.test(fs.readFileSync(path.join(__dirname, '..', 'api.php'), 'utf8')), 'API 有 stand 动作');
ok(/vacateSeat/.test(svc) && /expireOffline/.test(svc), '服务器：掉线自动下场（棋局保留可接替）');
ok(/takeover/.test(svc), '服务器：空位可直接接替（不重置棋盘）');
// 5) 思考时间
ok(html.includes('id="thinkInput"') && html.includes('id="btnThink"') && html.includes('id="thinkClock"'),
  '有思考时间设置与倒计时显示');
ok(/'think'/.test(fs.readFileSync(path.join(__dirname, '..', 'api.php'), 'utf8')), 'API 有 think 动作');
ok(/expireThink/.test(svc) && /timeoutBan/.test(svc), '服务器：超时自动请下场（本局禁座）');
// 6) 界面调整
ok(!html.includes('id="logList"'), '「落子记录」板块已删除');
ok(/updateLiveRecord/.test(scripts[1]), '棋谱实时显示在棋谱粘贴框里');
ok(html.indexOf('id="opsCard"') < html.indexOf('id="recordCard"'), '「对局操作」在「对局记录 · 回放 / 导入」上方');
ok(/card compact/.test(html), '「地盘」面板整体缩小（compact）');

console.log('\n[7] 二次改版：规则默认折叠 / 掉线不卡轮次 / 棋谱自动匹配棋盘 / 关闭等待遮罩 / 局域网→联网');
ok(!/<details class="card rules"[^>]*open/.test(html), '「规则说明」默认折叠（点击才展开）');
ok(html.includes('id="btnCloseWait"') && /btnCloseWait/.test(scripts[1]), '「等待棋手加入」有「关闭」按钮');
ok(html.indexOf('id="btnCopyLink2"') < html.indexOf('id="btnCloseWait"'), '「关闭」按钮在「复制邀请链接」右边');
ok(/useSize/.test(recordJs) && /自动匹配/.test(recordJs), '导入棋谱自动匹配棋盘大小并开始播放');
ok(/B\.roomSize/.test(recordJs), '分支续下改按房间棋盘判断装不装得下');
ok(/useSize/.test(scripts[1]) && /roomSize/.test(scripts[1]), '界面层提供 useSize / roomSize 桥接');
ok(/restoreLive[\s\S]{0,400}setSize\(net\.size\)/.test(scripts[1]), '「回到对局」换回房间的棋盘大小');
ok(/normalizeTurnInState/.test(svc), '服务器：轮次收尾拆出 state 级 / 房间级两层');
ok(/没人坐就跳过去|跳过没人坐的颜色/.test(svc) && /Logic::nextTurn/.test(svc),
  '服务器：轮次跳过没人坐的颜色（掉线不卡回合）');
const startSrc = fs.readFileSync(path.join(__dirname, '..', 'start.php'), 'utf8');
const readmeSrc = fs.readFileSync(path.join(__dirname, '..', 'README.md'), 'utf8');
ok(!/局域网/.test(html) && !/局域网/.test(recordJs), '界面文字「局域网」全部改成「联网」');
ok(!/局域网/.test(startSrc) && !/局域网/.test(readmeSrc), '启动器横幅 / README 文字也改成「联网」');

console.log('\n[8] 0.8.0：起端→终端棋谱 / 压缩棋谱导出导入 / 导出二选一 / 新对局清空棋子 / 不同意结束对局 / 注册登录');
try {
  // 1) 棋谱坐标：每根棍从起端指向终端，前后首尾衔接
  const s8 = L.newGameState();
  const e8a = L.computeLegal(s8).moves[0];
  const p8a = s8.turn;
  L.placeMove(s8, e8a, p8a);
  const e8b = L.computeLegal(s8).moves[0];   // link 模式：接在上一手的终端（线头）上
  const p8b = s8.turn;
  L.placeMove(s8, e8b, p8b);
  const t8 = RecordKit.formatRecord([{ p: p8a, e: e8a }, { p: p8b, e: e8b }], L);
  const pts8 = [...t8.matchAll(/\((-?\d+),(-?\d+)\)→\((-?\d+),(-?\d+)\)/g)];
  ok(pts8.length === 2 && pts8[0][3] === pts8[1][1] && pts8[0][4] === pts8[1][2],
    '棋谱每根棍从起端指向终端（上一根的终端 = 下一根的起端，首尾衔接）', t8);

  // 2) 压缩棋谱：导出 →（无「→」）导入回环
  const big = [];
  const stB = L.newGameState();
  for (let i = 0; i < 14; i++) {
    const legal8 = L.computeLegal(stB);
    if (!legal8.moves.length) break;
    const mover = stB.turn;
    const ek8 = legal8.moves[i % legal8.moves.length];
    if (!L.placeMove(stB, ek8, mover).ok) break;
    big.push({ p: mover, e: ek8 });
  }
  big.push({ p: 'W', r: true });
  const comp = RecordKit.compressedText(big, L);
  ok(!/→/.test(comp), '压缩棋谱里没有「→」（导入按「有无 →」区分老格式 / 压缩格式）', comp);
  const back2 = RecordKit.parseRecord(comp);
  ok(back2.errors.length === 0 && JSON.stringify(back2.moves) === JSON.stringify(big),
    '压缩棋谱导入回环（着法与原来完全一致）', back2.errors);
  const plain8 = RecordKit.formatRecord(big, L);
  ok(JSON.stringify(RecordKit.parseRecord(plain8).moves) === JSON.stringify(big),
    '老棋谱（带「→」）导入回环照常', null);
  const flat = comp.replace(/\s+/g, '');
  ok(/棍棋·压缩谱/.test(comp) && /[1-6]-?\d{2}-?\d{2}-?\d{2}-?\d{2}/.test(flat),
    '压缩谱：一串棋以第一根棍的坐标开头（4 位数坐标）', flat);
  ok(/[1-6][a-f]/.test(flat), '压缩谱：后面的棍记成方向码 abcdef', flat);

  // 3) 坐标写法：(-31,6) → -3106（31 路棋盘上贴边框的第一手）
  L.setSize(31);
  const far = [{ p: 'B', e: L.edgeKey(-31, 6, -30, 6) }];
  const farComp = RecordKit.compressRecord(far, L);
  ok(farComp.indexOf('-3106') >= 0, '坐标 (-31,6) 写成 -3106（去括号、每维 2 位数字、负数带负号）', farComp);
  const farBack = RecordKit.parseCompressed(farComp);
  ok(farBack.moves.length === 1 && farBack.moves[0].e === L.edgeKey(-31, 6, -30, 6),
    '压缩坐标能原样导回（含负坐标）', farBack);
  L.setSize(19);
} catch (e) {
  ok(false, '0.8.0 棋谱功能可运行', e.message);
}

ok(html.includes('id="recordExportBar"') && html.includes('id="recordCopyPlain"') && html.includes('id="recordCopyZip"'),
  '点「导出棋谱」可选：复制显示的棋谱 / 复制压缩后的棋谱');
ok(/compressedText/.test(recordJs) && /parseCompressed/.test(recordJs), 'record.js 实现压缩导出与压缩解析');
ok(/→\|\->/.test(recordJs), '导入自动识别：文本里有「→」按老棋谱，没有按压缩棋谱');
ok(/clearBoard/.test(scripts[1]) && /resetReview/.test(scripts[1]),
  '开始新对局先「清空棋子」；复盘没播完就开新局会先结束回放再清空');
ok(/不同意结束对局/.test(scripts[1]), '客户端：不同意结束对局 → 提示轮到你继续下');
ok(/不同意结束对局：对局继续/.test(svc), '服务器：拒绝「结束对局」→ 对局继续，转为不同意的人继续下');
ok(fs.existsSync(path.join(__dirname, '..', 'src', 'UserStore.php')), '有账号存储 src/UserStore.php');
ok(/case 'register'/.test(apiSrc) && /case 'login'/.test(apiSrc) && /case 'auth'/.test(apiSrc),
  'API 有 register / login / auth 动作');
ok(/adoptAccount/.test(svc) && /lastColor/.test(svc),
  '服务器：同一账号换设备登录接管原身份（掉线让出的颜色自动坐回接着下）');

console.log('\n[9] 0.8.1：电脑代下（α-β 枚举）/ 棍加粗·深绿 / 复制链接分端 / 免登录游客 / 报错不带路径 / 拒绝排序');
try {
  // 1) 电脑代下：可设枚举深度的 α-β 搜索
  ok(html.includes('id="btnAiPlay"') && html.includes('id="aiDepthInput"') && html.includes('id="aiHint"'),
    '有「电脑代下」按钮、枚举深度输入与状态提示');
  ok(/const AutoPlay = \(function \(\)/.test(scripts[1]) && /bestMove/.test(scripts[1])
    && /alpha/.test(scripts[1]) && /NODE_BUDGET/.test(scripts[1]),
    '电脑代下 = 迭代加深 + minimax + α-β 剪枝（带节点 / 时间预算）');
  ok(/scheduleAiMove/.test(scripts[1]) && /手动接管/.test(scripts[1]),
    '开启后一直代下，直到点「手动接管」（或自己落子）收回');
  const am = scripts[1].match(/const AutoPlay = \(function \(\) \{[\s\S]*?\n  \}\)\(\);/);
  const AutoPlay2 = new Function('Logic', am[0] + '\nreturn AutoPlay;')(L);
  const aiSt = L.newGameState();
  const aiEdge = AutoPlay2.bestMove(aiSt, 'B', 2);
  ok(!!aiEdge && L.isLegal(aiSt, aiEdge), '电脑代下能给出合法着法（深度 2）', aiEdge);

  // 2) 棍加粗 2 倍 + 绿色改深绿
  ok(/stickW = Math\.max\(2, s \* 0\.056\)/.test(scripts[1]), '棍的粗细是原来的 2 倍（0.028 → 0.056）');
  ok(/G: '#0a5c22'/.test(scripts[1]), '绿色棋子改成深绿色');

  // 3) 复制邀请链接：电脑端直接复制（不弹窗）、手机端维持弹窗
  ok(/isMobileDevice/.test(scripts[1]) && /copyOnDesktop/.test(scripts[1]),
    '复制邀请链接分端：电脑直接复制 / 手机维持弹窗');
  ok(/isMobileDevice/.test(recordJs) && /copyViaTextarea/.test(recordJs),
    '导出棋谱的复制同样分端处理（电脑端不弹窗）');

  // 4) 不登录也能进：游客临时身份
  ok(!/requireLogin/.test(scripts[1]), '不再强制登录（不填用户名也能建房 / 进房）');
  ok(/'游客'/.test(scripts[1]), '未登录以「游客」临时身份进入');

  // 5) 引号里的 <b></b> 不被识别 → 改【】格式；任何报错不暴露路径
  ok(/function plainFormat/.test(scripts[1]) && scripts[1].includes('【$1】'),
    '纯文本显示场合把 <b></b> 改成【】格式');
  const userSrc = fs.readFileSync(path.join(__dirname, '..', 'src', 'UserStore.php'), 'utf8');
  const storeSrc = fs.readFileSync(path.join(__dirname, '..', 'src', 'RoomStore.php'), 'utf8');
  ok(/lower\(\(string\)\$u\)/.test(userSrc), '注册查重兼容纯数字用户名（数组键是 int 不再 TypeError）');
  ok(!/服务器内部错误：' \. \$e->getMessage\(\)/.test(apiSrc), '未预料异常不回显异常文本（不带路径）');
  ok(!/无法创建数据目录：'/.test(userSrc) && !/无法创建数据目录：'/.test(storeSrc),
    '数据目录报错不带服务器路径');
  ok(/ini_set\('display_errors', '0'\)/.test(apiSrc), 'PHP 警告 / 错误不回显到浏览器');

  // 6) 拒绝「结束对局」：按本该轮到的顺序，转给第一个拒绝的人
  ok(/handTurnToFirstRejecter/.test(svc) && /absorbLateEndReject/.test(svc) && /'rejects'/.test(svc),
    '服务器：结束对局被拒 → 按轮转顺序转给第一个拒绝的人（多人同时拒绝也取最靠前的）');
} catch (e) {
  ok(false, '0.8.1 检查可运行', e.message);
}

console.log('\n结果：' + passed + ' 通过, ' + failed + ' 失败\n');
process.exit(failed > 0 ? 1 : 0);
