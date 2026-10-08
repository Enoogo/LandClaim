// 棍棋 · 三角版（联机版）— 三人对局「轮到谁」同步冒烟测试
// 用 DOM 桩把 index.html 的真实脚本跑成三个「浏览器」，完整复现报告的场景：
//   三人进房 → 黑坐下 → 白坐下 → 红申请上场、黑白同意 → 黑落子 → 白落子 → 红落子
// 每一步断言每个界面的「轮到谁」提示与服务器一致，且真的该落子的人点得下去。
// 运行：node tests/three-player-smoke.cjs   （可选 PHP_BIN=php 的路径，TPS_PORT=6854）
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

const PHP = process.env.PHP_BIN || 'php';
const PORT = Number(process.env.TPS_PORT || 6854);
const BASE = 'http://127.0.0.1:' + PORT;

let passed = 0, failed = 0;
function ok(cond, name, extra) {
  if (cond) { passed++; console.log('  ✓ ' + name); }
  else {
    failed++;
    console.log('  ✗ ' + name + (extra !== undefined ? '  → ' + JSON.stringify(extra) : ''));
  }
}

/* ---------------- DOM 桩（与 ui-smoke.cjs 相同） ---------------- */
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

/* ---------------- 造一个「浏览器」：跑真实脚本（Logic + 界面层 + record.js） ---------------- */
function makeClient() {
  const html = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');
  const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].map((m) => m[1]);
  const recordSrc = fs.readFileSync(path.join(__dirname, '..', 'record.js'), 'utf8');
  const built = buildDom();
  const document = built.document;

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

  const fetchAbs = (url, opts) => fetch(new URL(String(url), BASE + '/').href, opts);
  const factory = new Function(
    'window', 'document', 'navigator', 'sessionStorage', 'location', 'fetch', 'requestAnimationFrame', 'self',
    scripts[0] + '\n' + scripts[1] + '\n' + recordSrc
  );
  factory(
    windowStub, document,
    { clipboard: null }, sessionStorageStub,
    { origin: BASE, pathname: '/', search: '' },
    fetchAbs,
    function () { /* 不跑渲染循环 */ },
    windowStub
  );
  return {
    el: (id) => document.getElementById(id),
    window: windowStub,
  };
}

/* ---------------- 复刻客户端 resize() 的画布坐标换算，用于真实点击 ---------------- */
function makeBoardClicker(L) {
  const cssW = 640, cssH = 560;
  let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
  for (const p of L.points) {
    const x = L.projX(p[0], p[1]), y = L.projY(p[0], p[1]);
    if (x < minX) minX = x;
    if (x > maxX) maxX = x;
    if (y < minY) minY = y;
    if (y > maxY) maxY = y;
  }
  const pad = Math.min(cssW, cssH) * 0.055;
  const s = Math.min((cssW - 2 * pad) / (maxX - minX), (cssH - 2 * pad) / (maxY - minY));
  const ox = (cssW - (maxX - minX) * s) / 2 - minX * s;
  const oy = (cssH - (maxY - minY) * s) / 2 - minY * s;
  return {
    // 用当前着法序列重建局面，挑一个合法落点，算出它的画布中点
    pick: function (moves, order) {
      const st = L.newGameState(order);
      for (const mv of moves) {
        if (mv.j) { if (st.order.indexOf(mv.p) < 0) st.order.push(mv.p); continue; }
        if (mv.r) { L.resignAs(st, mv.p); continue; }
        const r = L.placeMove(st, mv.e, mv.p);
        if (!r.ok) throw new Error('重放失败：' + JSON.stringify(mv));
      }
      const legal = L.computeLegal(st);
      const ek = legal.moves[0];
      const ps = L.edgePoints(ek);
      const a = [ox + L.projX(ps[0][0], ps[0][1]) * s, oy + L.projY(ps[0][0], ps[0][1]) * s];
      const b = [ox + L.projX(ps[1][0], ps[1][1]) * s, oy + L.projY(ps[1][0], ps[1][1]) * s];
      return { edge: ek, evt: { clientX: (a[0] + b[0]) / 2, clientY: (a[1] + b[1]) / 2 } };
    }
  };
}

function turnText(c) { return c.el('turnText').textContent; }

/** 注册 / 登录（0.8.0：昵称改为账号）：注册过就改为登录（重名拒绝是正确行为）。 */
async function loginAs(c, name) {
  c.el('authUser').value = name;
  c.el('authPass').value = 'pass1234';
  c.el('btnRegister').dispatch('click');
  await sleep(600);
  if (!/已登录/.test(c.el('authStatus').innerHTML)) {
    c.el('btnLogin').dispatch('click');
    await sleep(600);
  }
  return /已登录/.test(c.el('authStatus').innerHTML);
}

/* ---------------- 主流程：完整复现报告的三人场景 ---------------- */
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
    const A = makeClient();   // 黑方
    const B = makeClient();   // 白方
    const C = makeClient();   // 红方（中途申请上场的人）
    await sleep(600);         // boot() 走完

    console.log('\n[1] 三个用户都注册 / 登录并进房间');
    ok(await loginAs(A, '甲方'), '甲方注册 / 登录', A.el('authStatus').innerHTML);
    A.el('btnCreate').dispatch('click');
    await sleep(700);
    const roomCode = A.el('netBar').innerHTML.match(/房间 <b>([A-Z2-9]{5})/)[1];
    ok(!!roomCode, '甲方创建房间 ' + roomCode, A.el('netBar').innerHTML);
    ok(await loginAs(B, '乙方'), '乙方注册 / 登录', B.el('authStatus').innerHTML);
    ok(await loginAs(C, '丙方'), '丙方注册 / 登录', C.el('authStatus').innerHTML);
    B.el('joinCode').value = roomCode;
    B.el('btnJoin').dispatch('click');
    C.el('joinCode').value = roomCode;
    C.el('btnJoin').dispatch('click');
    await sleep(800);
    ok(/观战中|观众/.test(B.el('netBar').innerHTML), '乙进入房间（先当观众）', B.el('netBar').innerHTML);
    ok(/观战中|观众/.test(C.el('netBar').innerHTML), '丙进入房间（先当观众）', C.el('netBar').innerHTML);

    console.log('\n[2] 黑方坐下、白方坐下');
    A.el('seatGrid').dispatch('click', { target: { closest: () => ({ disabled: false, getAttribute: () => 'B' }) } });
    await sleep(700);
    ok(/黑方/.test(A.el('netBar').innerHTML), '甲坐上黑方位', A.el('netBar').innerHTML);
    B.el('seatGrid').dispatch('click', { target: { closest: () => ({ disabled: false, getAttribute: () => 'W' }) } });
    await sleep(800);
    ok(/白方/.test(B.el('netBar').innerHTML), '乙坐上白方位', B.el('netBar').innerHTML);
    ok(/轮到你（黑方）/.test(turnText(A)), '开打后黑方界面提示「轮到你（黑方）」', turnText(A));
    ok(/他人行棋（黑方）/.test(turnText(B)), '白方界面提示「他人行棋（黑方）」', turnText(B));

    console.log('\n[3] 红方申请上场，黑白同意');
    C.el('seatGrid').dispatch('click', { target: { closest: () => ({ disabled: false, getAttribute: () => 'R' }) } });
    await sleep(800);
    ok(/申请以 <b>红方<\/b> 加入比赛/.test(A.el('joinText').innerHTML), '黑方看到上场申请', A.el('joinText').innerHTML);
    A.el('btnJoinYes').dispatch('click');
    await sleep(700);
    B.el('btnJoinYes').dispatch('click');
    await sleep(900);
    ok(/红方/.test(C.el('netBar').innerHTML), '红方坐上了场', C.el('netBar').innerHTML);

    console.log('\n[4] 黑方落子、白方落子');
    const L = A.window.RecordBridge.logic;          // 三份客户端共用同一套规则
    const clicker = makeBoardClicker(L);
    const order3 = ['B', 'W', 'R'];

    let pick = clicker.pick(A.window.RecordBridge.moves(), order3);
    A.el('board').dispatch('click', pick.evt);
    await sleep(800);
    ok(A.window.RecordBridge.moves().length === 1, '黑方落子成功（第 1 手）', A.window.RecordBridge.moves());

    pick = clicker.pick(B.window.RecordBridge.moves(), order3);
    B.el('board').dispatch('click', pick.evt);
    await sleep(800);
    ok(B.window.RecordBridge.moves().length === 2, '白方落子成功（第 2 手）', B.window.RecordBridge.moves());

    console.log('\n[5] 关键：此刻该红方落子，三个界面必须一致');
    ok(/轮到你（红方）/.test(turnText(C)),
      '红方界面提示「轮到你（红方）」（不是黑方！）', turnText(C));
    ok(/他人行棋（红方）/.test(turnText(A)),
      '黑方界面提示「他人行棋（红方）」（虚影不该出现，轮次已给红方）', turnText(A));
    ok(/他人行棋（红方）/.test(turnText(B)),
      '白方界面提示「他人行棋（红方）」', turnText(B));

    // 黑方此刻点棋盘：必须被本地拦下并指名红方（不能出现互相指对方）
    pick = clicker.pick(A.window.RecordBridge.moves(), order3);
    A.el('board').dispatch('click', pick.evt);
    await sleep(300);
    ok(/还没轮到你行棋，现在是红方的回合/.test(A.el('message').innerHTML),
      '黑方点击被拦下：提示「现在是红方的回合」', A.el('message').innerHTML);
    ok(A.window.RecordBridge.moves().length === 2, '黑方没有落下去子', A.window.RecordBridge.moves());

    console.log('\n[6] 红方点击落子——必须成功');
    pick = clicker.pick(C.window.RecordBridge.moves(), order3);
    C.el('board').dispatch('click', pick.evt);
    await sleep(800);
    ok(C.window.RecordBridge.moves().length === 3,
      '红方落子成功（第 3 手）——报错的「红方落不了子」不复现', C.window.RecordBridge.moves());
    ok(/围得|落子/.test(C.el('message').innerHTML) && !/还没轮到你/.test(C.el('message').innerHTML),
      '红方界面消息是落子成功，而不是「还没轮到你」', C.el('message').innerHTML);
  } finally {
    server.kill();
  }

  console.log('\n结果：' + passed + ' 通过, ' + failed + ' 失败\n');
  process.exit(failed > 0 ? 1 : 0);
}

main().catch((e) => { console.error('测试脚本异常：', e); process.exit(1); });
