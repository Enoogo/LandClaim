// 棍棋 · 三角版（联机版）— 界面层运行时冒烟测试
// 用 DOM 桩把 index.html 的两段脚本 + record.js 真正跑起来，背后连一台真实的 PHP 服务器：
//   启动 → 创建房间 → 成员列表 → 棋盘大小 → 棋谱播放/暂停 → 退出房间回大厅
// 运行：node tests/ui-smoke.cjs        （可选环境变量 PHP_BIN=php 的路径，SMOKE_PORT=6853）
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

const PHP = process.env.PHP_BIN || 'php';
const PORT = Number(process.env.SMOKE_PORT || 6853);
const BASE = 'http://127.0.0.1:' + PORT;

let passed = 0, failed = 0;
function ok(cond, name, extra) {
  if (cond) { passed++; console.log('  ✓ ' + name); }
  else {
    failed++;
    console.log('  ✗ ' + name + (extra !== undefined ? '  → ' + JSON.stringify(extra) : ''));
  }
}

/* ---------------- DOM 桩 ---------------- */
function makeElement(id) {
  const el = {
    id: id,
    hidden: false,
    textContent: '',
    innerHTML: '',
    value: '',
    checked: false,
    disabled: false,
    className: '',
    style: {},
    scrollTop: 0,
    scrollHeight: 0,
    handlers: {},
    addEventListener: function (type, fn) { (this.handlers[type] = this.handlers[type] || []).push(fn); },
    dispatch: function (type, evt) {
      (this.handlers[type] || []).forEach((fn) => fn.call(this, evt || { clientX: 0, clientY: 0 }));
    },
    focus: function () {},
    select: function () {},
    getBoundingClientRect: function () { return { left: 0, top: 0, width: 640, height: 560 }; },
    clientWidth: 640,
    clientHeight: 560,
    width: 0,
    height: 0,
  };
  el.getContext = function () {
    const grad = function () { return { addColorStop: function () {} }; };
    return new Proxy({}, {
      get(t, prop) {
        if (prop === 'createLinearGradient') return grad;
        return t[prop] !== undefined ? t[prop] : function () {};
      },
      set(t, prop, v) { t[prop] = v; return true; }
    });
  };
  return el;
}

function buildDom() {
  const els = new Map();
  const document = {
    readyState: 'complete',
    getElementById: function (id) {
      if (!els.has(id)) els.set(id, makeElement(id));
      return els.get(id);
    },
    querySelector: function () { return { value: 'B' }; },
    addEventListener: function () {},
    execCommand: function () {},
    activeElement: null,
  };
  return { document: document, els: els };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/* ---------------- 主流程 ---------------- */
async function main() {
  console.log('\n启动测试服务器：' + PHP + ' -S 127.0.0.1:' + PORT + ' router.php');
  const server = spawn(PHP, ['-d', 'opcache.enable=0', '-d', 'opcache.enable_cli=0',
    '-S', '127.0.0.1:' + PORT, 'router.php'], {
    cwd: path.join(__dirname, '..'),
    stdio: 'ignore'
  });

  let up = false;
  for (let i = 0; i < 75 && !up; i++) {
    try { up = (await fetch(BASE + '/')).ok; } catch (e) { /* 还没起来 */ }
    if (!up) await sleep(200);
  }
  if (!up) {
    server.kill();
    console.error('服务器未能启动（检查 PHP_BIN / 端口）');
    process.exit(1);
  }

  try {
    const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
    const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
    const recordSrc = fs.readFileSync(path.join(__dirname, '..', 'record.js'), 'utf8');
    const built = buildDom();
    const document = built.document;
    const el = (id) => document.getElementById(id);

    const windowStub = {
      addEventListener: function () {},
      confirm: function () { return true; },
      prompt: function () {},
      devicePixelRatio: 1,
    };
    windowStub.window = windowStub;

    const sessionStorageStub = {
      _d: {},
      getItem: function (k) { return this._d[k] || null; },
      setItem: function (k, v) { this._d[k] = v; },
      removeItem: function (k) { delete this._d[k]; },
    };

    const factory = new Function(
      'window', 'document', 'navigator', 'sessionStorage', 'location', 'fetch', 'requestAnimationFrame', 'self',
      scripts[0] + '\n' + scripts[1] + '\n' + recordSrc
    );
    // 页面里用的是相对地址（'api.php'），Node 的 fetch 需要绝对地址
    const fetchAbs = (url, opts) => fetch(new URL(String(url), BASE + '/').href, opts);
    factory(
      windowStub, document,
      { clipboard: null }, sessionStorageStub,
      { origin: BASE, pathname: '/', search: '' },
      fetchAbs,
      function () { /* 不跑渲染循环 */ },
      windowStub
    );

    await sleep(600);   // boot() 走完
    ok(el('lobby').hidden === false, '启动后停在大厅');
    ok(/请先创建或加入一个房间/.test(el('message').innerHTML), '大厅提示语出现', el('message').innerHTML);

    // ---- 注册 / 登录（0.8.0：填昵称改为注册或登录），再创建房间（创建者也是观众）----
    el('authUser').value = '冒烟测试';
    el('authPass').value = 'pass1234';
    el('btnRegister').dispatch('click');
    await sleep(600);
    if (!/已登录/.test(el('authStatus').innerHTML)) {
      el('btnLogin').dispatch('click');   // 上次测试已注册过 → 改为登录
      await sleep(600);
    }
    ok(/已登录/.test(el('authStatus').innerHTML), '注册 / 登录成功（用户名 + 密码）', el('authStatus').innerHTML);
    el('btnCreate').dispatch('click');
    await sleep(600);
    ok(el('lobby').hidden === true, '创建房间后进入棋盘');
    ok(/房间 <b>[A-Z2-9]{5}<\/b>/.test(el('netBar').innerHTML), '顶栏显示房间号', el('netBar').innerHTML);
    ok(el('btnLeaveRoom').hidden === false, '左上角出现「退出房间」按钮');
    ok(/观众/.test(el('memberList').innerHTML) && /冒烟测试/.test(el('memberList').innerHTML),
      '创建者先当观众，成员列表显示昵称', el('memberList').innerHTML);
    ok(/class="nm">冒烟测试<\/span><span class="mid">/.test(el('memberList').innerHTML),
      '用户名在前、id 小字淡色备注在旁边', el('memberList').innerHTML);
    ok(/19<\/b> 路棋盘/.test(el('netBar').innerHTML), '顶栏显示 19 路棋盘', el('netBar').innerHTML);
    ok(/已连接/.test(el('netBar').innerHTML), '同步正常（没有被静默异常打断）', el('netBar').innerHTML);

    // ---- 选颜色坐下上场（黑白红绿紫蓝）----
    el('seatGrid').dispatch('click', { target: { closest: () => ({ disabled: false, getAttribute: () => 'B' }) } });
    await sleep(600);
    ok(/你坐<b>黑方<\/b>位/.test(el('netBar').innerHTML), '点座位坐下后成为黑方', el('netBar').innerHTML);
    ok(/黑方/.test(el('memberList').innerHTML), '成员列表里显示黑方', el('memberList').innerHTML);
    ok(el('btnStand').disabled === false, '坐下后「下来（让出座位）」按钮可用');

    // ---- 思考时间：设置 / 取消 ----
    el('thinkInput').value = '60';
    el('btnThink').dispatch('click');
    await sleep(600);
    ok(/60/.test(el('thinkHint').innerHTML), '思考时间设置生效', el('thinkHint').innerHTML);
    el('btnThinkOff').dispatch('click');
    await sleep(600);
    ok(/不限时/.test(el('thinkHint').innerHTML), '可以取消思考时间限制（不限时）', el('thinkHint').innerHTML);

    // ---- 改棋盘大小 ----
    el('sizeSelect').value = '13';
    el('sizeSelect').dispatch('change');
    el('btnSize').dispatch('click');
    await sleep(600);
    ok(/13<\/b> 路棋盘/.test(el('netBar').innerHTML), '棋盘大小改成 13 路后顶栏更新', el('netBar').innerHTML);
    ok(el('sizeSelect').value === '13', '棋盘大小下拉框跟着同步');

    // ---- 棋谱：播放 / 暂停 是同一个按钮（13 路棋谱，在 13 路棋盘上导入）----
    el('recordText').value = '棍棋 · 三角版 对局记录\n1 黑 (12,0)→(12,1)\n2 白 (12,0)→(11,0)\n3 黑 认输\n';
    el('recordImport').dispatch('click');
    await sleep(30);
    ok(el('recordPlay').textContent === '暂停', '导入后自动播放：按钮字样变成「暂停」', el('recordPlay').textContent);
    el('recordPlay').dispatch('click');
    await sleep(60);
    ok(el('recordPlay').textContent === '播放', '点「暂停」后字样变回「播放」并停下', el('recordPlay').textContent);
    el('recordPlay').dispatch('click');
    await sleep(60);
    ok(el('recordPlay').textContent === '暂停', '再点「播放」又开始播放', el('recordPlay').textContent);
    el('recordExit').dispatch('click');
    await sleep(100);
    ok(el('recordPlay').textContent === '播放', '回到对局后播放键复位', el('recordPlay').textContent);

    // ---- 棋谱 ↔ 棋盘一一对应：导入自动匹配棋盘并开始播放 ----
    el('recordText').value = '棍棋 · 三角版 对局记录\n1 黑 (18,0)→(18,1)\n';
    el('recordImport').dispatch('click');
    await sleep(200);
    ok(windowStub.RecordBridge.logic.SIDE === 19,
      '导入 19 路棋谱 → 自动把棋盘匹配成 19 路（不用手动调「棋盘大小」）', windowStub.RecordBridge.logic.SIDE);
    ok(el('recordPlay').textContent === '暂停', '自动匹配后自动开始播放', el('recordPlay').textContent);
    el('recordExit').dispatch('click');
    await sleep(100);
    ok(windowStub.RecordBridge.logic.SIDE === 13, '「回到对局」换回房间的 13 路棋盘', windowStub.RecordBridge.logic.SIDE);

    // ---- 0.8.0：导出棋谱二选一 + 压缩棋谱导入（没有「→」按压缩格式解析）----
    const barWas = el('recordExportBar').hidden;
    el('recordCopy').dispatch('click');
    ok(el('recordExportBar').hidden !== barWas, '点「导出棋谱」→ 切换导出选项栏', el('recordExportBar').hidden);
    ok(!!el('recordCopyPlain') && !!el('recordCopyZip'), '导出选项：复制显示的棋谱 / 复制压缩后的棋谱');
    const kit = windowStub.RecordKit;
    const L19 = windowStub.RecordBridge.logic;
    const roomSide = L19.SIDE;
    L19.setSize(19);                       // 造一份 19 路的压缩棋谱
    const compMoves = [];
    const stC = L19.newGameState();
    for (let i = 0; i < 3; i++) {
      const mover = stC.turn;
      const ek = L19.computeLegal(stC).moves[0];
      if (!ek || !L19.placeMove(stC, ek, mover).ok) break;
      compMoves.push({ p: mover, e: ek });
    }
    const compText = kit.compressedText(compMoves, L19);
    L19.setSize(roomSide);
    el('recordText').value = compText;
    el('recordImport').dispatch('click');
    await sleep(200);
    ok(windowStub.RecordBridge.logic.SIDE === 19, '压缩棋谱导入 → 也自动匹配棋盘大小', windowStub.RecordBridge.logic.SIDE);
    ok(el('recordPlay').textContent === '暂停', '压缩棋谱导入后自动播放', el('recordPlay').textContent);
    el('recordCopyZip').dispatch('click');
    await sleep(50);
    ok(/压缩/.test(el('message').innerHTML), '复制压缩棋谱有提示', el('message').innerHTML);
    el('recordExit').dispatch('click');
    await sleep(100);
    ok(windowStub.RecordBridge.logic.SIDE === roomSide, '看完压缩棋谱「回到对局」换回房间棋盘', windowStub.RecordBridge.logic.SIDE);

    // ---- 分支续下：重新载入棋谱，停在第 1 手，从这里继续下 ----
    el('recordText').value = '棍棋 · 三角版 对局记录\n1 黑 (12,0)→(12,1)\n2 白 (12,0)→(11,0)\n3 黑 认输\n';
    el('recordImport').dispatch('click');
    await sleep(30);
    el('recordPlay').dispatch('click');                 // 立刻暂停
    for (let i = 0; i < 3; i++) el('recordPrev').dispatch('click');   // 退到开局（下限 0）
    el('recordNext').dispatch('click');                 // 停在第 1 手
    await sleep(30);
    ok(!el('recordBranch').disabled, '停在第 1 手时「从这里续下」可用');
    el('recordBranch').dispatch('click');
    await sleep(800);
    ok(/已从第 <b>1<\/b> 手分支续下/.test(el('message').innerHTML), '从第 1 手分支续下成功', el('message').innerHTML);
    ok(/12,0/.test(el('recordText').value), '棋谱框里实时显示分支局面的着法（落子记录已并入棋谱框）', el('recordText').value);
    ok(!/id="logList"/.test(html), '「落子记录」板块已删除');
    ok(el('recordPlay').textContent === '播放', '续下后退出回放模式', el('recordPlay').textContent);

    // ---- 回归：挑棋盘大小不能被轮询打回原来的路数 ----
    el('sizeSelect').value = '21';
    el('sizeSelect').dispatch('change');
    await sleep(1300);                                  // 跨过两轮 600ms 轮询
    ok(el('sizeSelect').value === '21', '选好棋盘大小后不会被轮询打回房间原路数', el('sizeSelect').value);
    el('btnSize').dispatch('click');
    await sleep(700);
    ok(/21<\/b> 路棋盘/.test(el('netBar').innerHTML), '这时点「应用」生效：棋盘切到 21 路', el('netBar').innerHTML);

    // ---- 0.8.0 回归：复盘动画没播完就开始新对局 → 棋子全部清空（不留遗留）----
    const L21 = windowStub.RecordBridge.logic;
    const pile = [];
    const st6 = L21.newGameState();
    for (let i = 0; i < 12; i++) {
      const mover = st6.turn;
      const ek = L21.computeLegal(st6).moves[0];
      if (!ek || !L21.placeMove(st6, ek, mover).ok) break;
      pile.push({ p: mover, e: ek });
    }
    await windowStub.RecordBridge.loadMoves(pile, null);   // 先铺 12 手棋（对局进行中）
    await sleep(300);
    el('btnEndGame').dispatch('click');       // 手动结束对局（只剩一位棋手 → 立即生效）
    for (let i = 0; i < 30; i++) {           // 等回放真正动起来（按钮变「暂停」且棋盘上有回放的棋子）
      if (el('recordPlay').textContent === '暂停' && windowStub.RecordBridge.stickCount() > 0) break;
      await sleep(50);
    }
    ok(el('recordPlay').textContent === '暂停', '手动结束对局后自动回放中（复盘动画没播完）', el('recordPlay').textContent);
    ok(windowStub.RecordBridge.stickCount() > 0, '复盘中棋盘上还有棋子', windowStub.RecordBridge.stickCount());
    el('btnNew').dispatch('click');            // 复盘没播完就开始新对局
    await sleep(700);
    ok(el('recordPlay').textContent === '播放', '开新对局后回放停下（按钮复位为「播放」）', el('recordPlay').textContent);
    ok(windowStub.RecordBridge.stickCount() === 0,
      '新对局开始后棋子被清空（棋盘上没有上一局的遗留棋子）', windowStub.RecordBridge.stickCount());
    ok(windowStub.RecordBridge.moves().length === 0,
      '新对局的着法记录也是空的', windowStub.RecordBridge.moves());
    ok(el('recordText').value === '', '棋谱框也换成新对局（空棋谱）', el('recordText').value);

    // ---- 观众加入（人数不限） ----
    const roomCode = el('netBar').innerHTML.match(/房间 <b>([A-Z2-9]{5})/)[1];
    const CLIENT_BUILD = (fs.readFileSync(path.join(__dirname, '..', 'api.php'), 'utf8')
      .match(/const CLIENT_BUILD = '([^']+)'/) || [])[1];
    const joinRes = await (await fetch(BASE + '/api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'join', room: roomCode, name: '路过的观众', color: 'S', ver: CLIENT_BUILD })
    })).json();
    ok(joinRes.ok === true && joinRes.you === 'S', '第二个人以观众身份加入（人数不限）', joinRes);
    await sleep(900);   // 等一轮轮询
    ok(/路过的观众/.test(el('memberList').innerHTML) && /观众/.test(el('memberList').innerHTML),
      '在线成员列表里出现观众', el('memberList').innerHTML);

    // ---- 退出房间回大厅 ----
    el('btnLeaveRoom').dispatch('click');
    await sleep(600);
    ok(el('lobby').hidden === false, '点「退出房间」回到大厅');
    ok(el('btnLeaveRoom').hidden === true, '大厅里不显示「退出房间」按钮');
  } finally {
    server.kill();
  }

  console.log('\n结果：' + passed + ' 通过, ' + failed + ' 失败\n');
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error('测试脚本异常：', e); process.exit(1); });
