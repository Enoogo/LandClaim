// 棍棋 · 三角版 — 交叉验证的 JS 一侧
// 读取 tests/tmp/playouts.json（PHP 随机对局），用 index.html 内嵌的 JS Logic 重放，
// 输出 tests/tmp/summary-js.json 供 cross-check.php 比对。
// 运行：node tests/replay-node.mjs
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { createHash } from 'node:crypto';

const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8');
const m = html.match(/<script>([\s\S]*?)<\/script>/);
if (!m) throw new Error('未在 index.html 中找到内联脚本');
const moduleShim = { exports: {} };
new Function('module', m[1])(moduleShim);
const Logic = moduleShim.exports;

const md5 = (s) => createHash('md5').update(s).digest('hex');

// 与 index.html 联机层一致：指定某一方认输（多人局=退出比赛，只剩一人时该人胜）
function resignAs(st, player) {
  Logic.resignAs(st, player);
}

// 与 index.html 联机层一致：无处可下即终局（按仍在参赛者的地数定胜负）
function maybeEndGame(st) {
  if (st.over) return;
  if (Logic.computeLegal(st).moves.length > 0) return;
  st.over = true;
  st.winner = Logic.decideWinner(st);
}

function summarize(state) {
  let owner = '';
  for (let i = 0; i < state.owner.length; i++) owner += String(state.owner[i]);
  const keys = [...state.sticks.keys()].sort();
  const parts = keys.map((k) => k + '=' + state.sticks.get(k));
  const legal = Logic.computeLegal(state);
  const lm = legal.moves.slice().sort();
  const sc = Logic.score(state);
  // 字段顺序必须与 cross-check.php 的 summarize() 完全一致
  return {
    moveNo: state.moveNo,
    over: state.over,
    winner: state.winner,
    turn: state.turn,
    head: state.head,
    lastStick: state.lastStick,
    anchorRequired: state.anchorRequired,
    black: sc.B,
    white: sc.W,
    ownerHash: md5(owner),
    sticksHash: md5(parts.join(';')),
    legalMode: legal.mode,
    legalCount: legal.moves.length,
    legalHash: md5(lm.join(';')),
    sinceClaim: state.sinceClaim,
  };
}

const input = JSON.parse(readFileSync(new URL('./tmp/playouts.json', import.meta.url), 'utf8'));
const out = [];

for (const pl of input.playouts) {
  const state = Logic.newGameState();
  const trail = [];
  const claims = [];
  let error = null;
  for (const mv of pl.moves) {
    if (mv.r) {
      resignAs(state, mv.p);
    } else {
      const r = Logic.placeMove(state, mv.e, mv.p);
      if (!r.ok) {
        error = 'JS 拒绝了着法 ' + JSON.stringify(mv) + '：' + r.reason;
        break;
      }
      claims.push(r.claimed ? r.claimed.count : 0);
      maybeEndGame(state);
    }
    trail.push(summarize(state));
  }
  out.push({ seed: pl.seed, error: error, claims: claims, trail: trail });
}

mkdirSync(new URL('./tmp/', import.meta.url), { recursive: true });
writeFileSync(
  new URL('./tmp/summary-js.json', import.meta.url),
  JSON.stringify(out)
);
console.log('JS 重放完成：' + out.length + ' 组对局');
