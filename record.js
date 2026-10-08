/* ============================================================
   棍棋 · 三角版（联机版）—— 对局记录 · 回放 / 导入
   · 「手动结束对局」：提议经所有在场玩家同意后按当前地数结算，
     随后以每秒 10 步回放整盘动画，并把纯文本对局记录写进棋谱框
     （平时棋谱框里也实时显示当前对局的记录，见 index.html 的 updateLiveRecord）。
   · 「对局记录导入」：粘贴纯文本 → 自动播放，或「上一步 / 下一步」手动查看，
     随时显示当时的各方地数（三角格数）。
   纯文本格式（导出即此格式；导入另兼容若干简写）：
       棍棋 · 三角版 对局记录
       1 黑 (18,0)→(18,1)
       2 白 (18,1)→(19,0)（围得 1 格）
       3 黑 认输
   每根棍都从「起端」指向「终端」（起端接上一根的终端），前后首尾衔接顺下来。
   「导出棋谱」可选：复制显示的棋谱 / 复制压缩后的棋谱（一串棋 = 第一根棍的
   4 位数坐标 + 之后每根棍的方向码 abcdef，另带 1 位颜色码）；
   导入时文本里有「→」按老格式解析，没有就按压缩格式解析。
   纯函数部分（formatRecord / parseRecord / compressRecord / buildReview）无 DOM 依赖，
   浏览器端暴露 window.RecordKit，Node 端可 require 用于测试。
   依赖 index.html 脚本 #1 的 Logic（浏览器端为全局词法绑定，测试时显式传入）。
   ============================================================ */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.RecordKit = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const SPEED_MS = 100;          // 每秒 10 步

  /* ==================== 纯文本对局记录 ==================== */

  const COLOR_NAME = { B: '黑', W: '白', R: '红', G: '绿', Y: '紫', L: '蓝' };
  const COLOR_PATTERNS = [
    { p: 'B', re: /黑|\bB\b/i },
    { p: 'W', re: /白|\bW\b/i },
    { p: 'R', re: /红|\bR\b/i },
    { p: 'G', re: /绿|\bG\b/i },
    { p: 'Y', re: /紫|黄|\bY\b/i },   // 紫（旧棋谱里的「黄」也认）
    { p: 'L', re: /蓝|\bL\b/i }
  ];

  /** 认出一行写的是黑白红绿紫蓝哪一方（认不出 / 认出多个都算失败） */
  function detectColor(line) {
    const hits = COLOR_PATTERNS.filter(function (c) { return c.re.test(line); });
    return hits.length === 1 ? hits[0].p : null;
  }

  /** 棋谱里的行棋顺序 = 各方第一次出现的先后（不足两方时用默认黑白） */
  function orderOf(moves) {
    const order = [];
    for (let i = 0; i < moves.length; i++) {
      const p = moves[i] && moves[i].p;
      if (p && order.indexOf(p) < 0) order.push(p);
    }
    return order.length >= 2 ? order : undefined;
  }

  function edgeKey(a1, b1, a2, b2) {
    if (a1 < a2 || (a1 === a2 && b1 <= b2)) return a1 + ',' + b1 + '|' + a2 + ',' + b2;
    return a2 + ',' + b2 + '|' + a1 + ',' + b1;
  }

  /* ==================== 坐标方向：起端 → 终端 ====================
     每根棍都从「起端」写到「终端」：起端 = 接在已有结构上的那一端
     （末端延伸时就是上一根棍的终端），终端 = 落子后的新线头。
     于是首尾衔接的棍写下来就是 …→A、A→B、B→C…，一路顺下来。 */

  function samePt(p, q) { return !!p && !!q && p[0] === q[0] && p[1] === q[1]; }

  /** 计算一根棍的起端 / 终端（在 placeMove 之后调用：st.head 就是新线头 = 终端）。 */
  function oriented(L, st, ek, prevTo) {
    const ps = L.edgePoints(ek);
    if (st.head && samePt(st.head, ps[0])) return { from: ps[1], to: ps[0] };
    if (st.head && samePt(st.head, ps[1])) return { from: ps[0], to: ps[1] };
    if (samePt(prevTo, ps[0])) return { from: ps[0], to: ps[1] };
    if (samePt(prevTo, ps[1])) return { from: ps[1], to: ps[0] };
    return { from: ps[0], to: ps[1] };
  }

  function ptLabel(p) { return '(' + p[0] + ',' + p[1] + ')'; }
  function linkLabel(o) { return ptLabel(o.from) + '→' + ptLabel(o.to); }

  /** 把着法序列渲染成纯文本棋谱（含围地手数注记）。moves: [{p,e}|{p,r}|{p,end}|{p,j}] */
  function formatRecord(moves, L) {
    const lines = ['棍棋 · 三角版 对局记录'];
    const st = L.newGameState(orderOf(moves));
    let prevTo = null;
    for (let i = 0; i < moves.length; i++) {
      const mv = moves[i];
      const no = i + 1;
      const who = COLOR_NAME[mv.p] || mv.p;
      if (mv.j) {
        lines.push(no + ' ' + who + ' 加入比赛');
        if (st.order.indexOf(mv.p) < 0) st.order.push(mv.p);
        continue;
      }
      if (mv.r) {
        lines.push(no + ' ' + who + ' 认输');
        L.resignAs(st, mv.p);
        continue;
      }
      if (mv.end) {
        lines.push(no + ' ' + who + ' 终局');
        L.endGameAs(st, mv.p);
        continue;
      }
      const r = L.placeMove(st, mv.e, mv.p);
      let o, note = '';
      if (r.ok) {
        o = oriented(L, st, mv.e, prevTo);   // 起端接上一根的终端，终端是新线头
        prevTo = o.to;
        if (r.claimed) note = '（围得 ' + r.claimed.count + ' 格）';
        else if (st.log.length && st.log[st.log.length - 1].even) note = '（平分不计）';
      } else {
        const ps = L.edgePoints(mv.e);
        o = { from: ps[0], to: ps[1] };
      }
      lines.push(no + ' ' + who + ' ' + linkLabel(o) + note);
    }
    return lines.join('\n') + '\n';
  }

  /* ==================== 解析纯文本棋谱 ==================== */

  /**
   * 宽容解析（老格式）：行首可带手数；颜色写「黑 / 白」或 B / W；坐标写
   * (a,b)→(c,d)、(a,b)->(c,d) 或 a,b|c,d 均可；「认输」「终局」是特殊着法；
   * # 或 ; 开头为注释；（围得 N 格）等注记会被忽略。
   * 返回 { moves: [...], errors: [...] }
   */
  function parsePlain(text) {
    const moves = [];
    const errors = [];
    const lines = String(text == null ? '' : text).split(/\r?\n/);
    for (let i = 0; i < lines.length; i++) {
      const lineNo = i + 1;
      const line = lines[i].trim();
      if (!line || line.charAt(0) === '#' || line.charAt(0) === ';') continue;
      if (/^(棍棋|棋盘|对局记录|结果|立体围棋)/.test(line)) continue;

      const p = detectColor(line);
      if (!p) {
        errors.push('第 ' + lineNo + ' 行：认不出黑白红绿紫蓝哪一方（请写「黑 / 白 / 红 / 绿 / 紫 / 蓝」）');
        continue;
      }
      if (/加入/.test(line)) { moves.push({ p: p, j: true }); continue; }
      if (/认输|resign/i.test(line)) { moves.push({ p: p, r: true }); continue; }
      if (/终局|手动结束|结束对局|end/i.test(line)) { moves.push({ p: p, end: true }); continue; }

      const pairs = line.match(/-?\d+\s*[,，]\s*-?\d+/g);
      if (!pairs || pairs.length < 2) {
        errors.push('第 ' + lineNo + ' 行：认不出坐标（格式如「1 黑 (18,0)→(18,1)」）');
        continue;
      }
      const a = pairs[0].split(/[,，]/).map(function (n) { return parseInt(n, 10); });
      const b = pairs[1].split(/[,，]/).map(function (n) { return parseInt(n, 10); });
      moves.push({ p: p, e: edgeKey(a[0], a[1], b[0], b[1]) });
    }
    return { moves: moves, errors: errors };
  }

  /* ==================== 压缩棋谱 ====================
     每「一串棋」以第一根棍的坐标开头（起端坐标 + 终端坐标，各写成 4 位数：
     括号逗号去掉、每维 2 位数字、负数加负号，如 (-31,6) → -3106）。
     第一根棍定了之后，后面的棍只可能朝 6 个方向走（其中一端必须接在线头上）：
     a=(+1,0) b=(0,+1) c=(-1,+1) d=(-1,0) e=(0,-1) f=(+1,-1)。
     每根棍前带 1 位颜色码（1 黑 2 白 3 红 4 绿 5 紫 6 蓝），保证导入后
     各方地盘不串色；认输 / 终局 / 加入 = R / E / J + 颜色码。 */

  const DIRS = [
    ['a', 1, 0], ['b', 0, 1], ['c', -1, 1],
    ['d', -1, 0], ['e', 0, -1], ['f', 1, -1]
  ];
  const COLOR_CODE = { B: 1, W: 2, R: 3, G: 4, Y: 5, L: 6 };
  const CODE_COLOR = { 1: 'B', 2: 'W', 3: 'R', 4: 'G', 5: 'Y', 6: 'L' };
  const COMPRESSED_HEADER = '棍棋·压缩谱';

  function dirLetter(from, to) {
    const da = to[0] - from[0], db = to[1] - from[1];
    for (let i = 0; i < DIRS.length; i++) {
      if (DIRS[i][1] === da && DIRS[i][2] === db) return DIRS[i][0];
    }
    return null;
  }

  /** 一个坐标点写成 4 位数：(-31,6) → "-3106"（每维 2 位数字，负数前加负号）。 */
  function encPoint(p) {
    function enc2(n) {
      const s = String(Math.abs(n));
      return (n < 0 ? '-' : '') + (Math.abs(n) < 10 ? '0' : '') + s;
    }
    return enc2(p[0]) + enc2(p[1]);
  }

  /** 把着法序列压成压缩棋谱正文（不含标题行）。moves: [{p,e}|{p,r}|{p,end}|{p,j}] */
  function compressRecord(moves, L) {
    const st = L.newGameState(orderOf(moves));
    let prevTo = null;
    const out = [];
    for (let i = 0; i < moves.length; i++) {
      const mv = moves[i];
      const c = COLOR_CODE[mv.p] || 0;
      if (mv.j) { out.push('J' + c); continue; }
      if (mv.r) { out.push('R' + c); continue; }
      if (mv.end) { out.push('E' + c); continue; }
      const r = L.placeMove(st, mv.e, mv.p);
      const o = r.ok ? oriented(L, st, mv.e, prevTo) : null;
      if (o && c && samePt(o.from, prevTo)) {
        const d = dirLetter(o.from, o.to);
        if (d) { out.push(c + d); prevTo = o.to; continue; }   // 接在线头上：只写方向
      }
      if (!o) {
        const ps = L.edgePoints(mv.e);
        out.push(c + encPoint(ps[0]) + encPoint(ps[1]));        // 重放失败：按边键原样写
        continue;
      }
      out.push(c + encPoint(o.from) + encPoint(o.to));          // 新开一串：写全坐标
      prevTo = o.to;
    }
    return out.join('');
  }

  /** 导出用：标题 + 每行 80 字符折行（解析时标题行与空白都会被忽略）。 */
  function compressedText(moves, L) {
    const s = compressRecord(moves, L);
    const rows = [];
    for (let i = 0; i < s.length; i += 80) rows.push(s.slice(i, i + 80));
    return COMPRESSED_HEADER + '\n' + rows.join('\n') + '\n';
  }

  /** 解析压缩棋谱。返回 { moves: [...], errors: [...] } */
  function parseCompressed(text) {
    const moves = [];
    const errors = [];
    const body = String(text == null ? '' : text)
      .split(/\r?\n/)
      .filter(function (l) {
        const t = l.trim();
        return t !== '' && !/^(棍棋|棋盘|对局记录|结果|立体围棋|#|;)/.test(t);
      })
      .join('').replace(/\s+/g, '');
    let i = 0;
    let prevTo = null;

    function readNum() {
      let sign = 1;
      if (body.charAt(i) === '-') { sign = -1; i++; }
      else if (body.charAt(i) === '+') { i++; }
      const d = body.substr(i, 2);
      if (!/^\d{2}$/.test(d)) return null;
      i += 2;
      return sign * parseInt(d, 10);
    }
    function readPoint() {
      const a = readNum();
      if (a === null) return null;
      const b = readNum();
      if (b === null) return null;
      return [a, b];
    }

    while (i < body.length) {
      const at = i + 1;
      const ch = body.charAt(i);
      if (ch === 'R' || ch === 'E' || ch === 'J') {
        const c = CODE_COLOR[body.charAt(i + 1)];
        if (!c) { errors.push('压缩棋谱第 ' + at + ' 个字符：' + ch + ' 后面要跟颜色码 1-6'); break; }
        i += 2;
        moves.push(ch === 'R' ? { p: c, r: true } : (ch === 'E' ? { p: c, end: true } : { p: c, j: true }));
        continue;
      }
      const c = CODE_COLOR[ch];
      if (!c) {
        errors.push('压缩棋谱第 ' + at + ' 个字符：认不出「' + ch + '」（每根棍前要有颜色码 1-6）');
        break;
      }
      i++;
      const next = body.charAt(i);
      if (/[a-f]/.test(next)) {
        // 接着上一根棍的终端：只写方向
        if (!prevTo) {
          errors.push('压缩棋谱第 ' + at + ' 个字符：开头必须先写完整坐标，不能只写方向');
          break;
        }
        let delta = null;
        for (let k = 0; k < DIRS.length; k++) { if (DIRS[k][0] === next) delta = [DIRS[k][1], DIRS[k][2]]; }
        i++;
        const to = [prevTo[0] + delta[0], prevTo[1] + delta[1]];
        moves.push({ p: c, e: edgeKey(prevTo[0], prevTo[1], to[0], to[1]) });
        prevTo = to;
      } else {
        const from = readPoint();
        const to = from === null ? null : readPoint();
        if (from === null || to === null) {
          errors.push('压缩棋谱第 ' + at + ' 个字符：坐标不完整（每维 2 位数字，如 -3106 = (-31,6)）');
          break;
        }
        moves.push({ p: c, e: edgeKey(from[0], from[1], to[0], to[1]) });
        prevTo = to;
      }
    }
    return { moves: moves, errors: errors };
  }

  /**
   * 导入解析（自动识别两种格式）：文本里有「→」就是老棋谱、按老格式解析；
   * 否则按压缩格式解析（老格式的「认输 / 终局 / 加入」行兜底也走老格式）。
   * 返回 { moves: [...], errors: [...] }
   */
  function parseRecord(text) {
    const s = String(text == null ? '' : text);
    if (/→|->/.test(s) || /认输|终局|加入/.test(s)) return parsePlain(s);
    return parseCompressed(s);
  }

  /**
   * 用规则引擎把着法序列重放成逐步局面快照。
   * 返回 { states: [开局, 第1手后, …, 第N手后], errors: [...] }
   */
  function buildReview(L, moves) {
    const states = [];
    const errors = [];
    const st = L.newGameState(orderOf(moves));
    states.push(L.cloneState(st));
    for (let i = 0; i < moves.length; i++) {
      const mv = moves[i];
      if (mv.j) {
        if (st.order.indexOf(mv.p) < 0) st.order.push(mv.p);   // 重新加入行棋轮次
      } else if (mv.r) {
        L.resignAs(st, mv.p);
      } else if (mv.end) {
        L.endGameAs(st, mv.p);
      } else {
        const r = L.placeMove(st, mv.e, mv.p);
        if (!r.ok) {
          errors.push('第 ' + (i + 1) + ' 手无法重放：' + String(r.reason || '').replace(/<[^>]+>/g, ''));
          break;
        }
        if (!st.over && L.computeLegal(st).moves.length === 0) {
          st.over = true;
          st.winner = L.decideWinner(st);
        }
      }
      states.push(L.cloneState(st));
    }
    return { states: states, errors: errors };
  }

  /* ==================== 浏览器端：回放 / 导入界面 ==================== */

  function init() {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    const B = window.RecordBridge;
    const L = B && B.logic;
    const $ = function (id) { return document.getElementById(id); };
    if (!L || !$('recordText') || !$('btnEndGame')) return;

    const rev = { moves: [], states: [], cur: 0, timer: null, fromLive: false };

    function total() { return Math.max(0, rev.states.length - 1); }

    function reviewing() {
      return !(rev.fromLive && rev.cur === total());
    }

    function updateStatus() {
      const el = $('recordStatus');
      if (!rev.states.length) {
        el.textContent = '尚无棋谱：点「手动结束对局」生成，或在下方粘贴棋谱后点「导入」。';
        return;
      }
      const sc = L.score(rev.states[rev.cur]);
      const colors = (rev.states[rev.cur].order && rev.states[rev.cur].order.length)
        ? rev.states[rev.cur].order : ['B', 'W'];
      const parts = colors.map(function (c) {
        return COLOR_NAME[c] + '地 <b>' + (sc[c] || 0) + '</b>';
      }).join(' · ');
      el.innerHTML = '第 <b>' + rev.cur + '</b> / ' + total() + ' 手 · ' + parts;
    }

    function syncButtons() {
      $('recordPrev').disabled = rev.cur <= 0;
      $('recordNext').disabled = rev.cur >= total();
      $('recordBranch').disabled = rev.states.length === 0 || rev.cur <= 0;
      // 「播放 / 暂停」是同一个按钮：播放中显示「暂停」，停下时显示「播放」
      $('recordPlay').textContent = rev.timer ? '暂停' : '播放';
      $('recordPlay').disabled = !rev.timer && (rev.states.length === 0 || rev.cur >= total());
    }

    function stopAuto() {
      if (rev.timer) { clearInterval(rev.timer); rev.timer = null; }
      syncButtons();
    }

    /** 播放 / 暂停 切换：点「播放」开始并变成「暂停」，点「暂停」停下并变回「播放」。 */
    function toggleAuto() {
      if (rev.timer) {
        stopAuto();
        updateStatus();
        return;
      }
      startAuto();
    }

    function stepTo(n) {
      if (!rev.states.length) return;
      rev.cur = Math.max(0, Math.min(n, total()));
      B.showState(rev.states[rev.cur], reviewing());
      updateStatus();
      syncButtons();
    }

    function startAuto() {
      if (!rev.states.length) return;
      stopAuto();
      rev.timer = setInterval(function () {
        if (rev.cur >= total()) { stopAuto(); return; }
        stepTo(rev.cur + 1);
      }, SPEED_MS);
      syncButtons();
    }

    /** 「手动结束对局」= 发提议：所有在场玩家同意后才生效。
     *  真正结束时界面层会回调 onLiveEnd → 自动回放整盘 + 生成棋谱。 */
    async function endGameFlow() {
      const status = B.status();
      if (status === 'lobby' || status === 'waiting') {
        B.message('对局还没有开始。', 'warn');
        return;
      }
      if (status === 'playing' && !window.confirm('提议结束对局？（按当前地数结算胜负，需要所有在场玩家都同意）')) return;
      stopAuto();
      const res = await B.endGame();
      if (!res.ok) {
        B.message(res.error || '未能结束对局', 'warn');
        return;
      }
      if (res.pending) {
        B.message('已发起「结束对局」提议：等<b>所有在场玩家都同意</b>后，按当前地数结算并自动回放棋谱。', 'info');
      }
      // 没有 pending（只剩你一位在场）：提议立即生效，回放走 onLiveEnd
    }

    /** 手动结束对局生效 → 生成纯文本棋谱 → 每秒 10 步回放整盘 */
    function liveEndReplay() {
      stopAuto();
      rev.moves = B.moves();
      const built = buildReview(L, rev.moves);
      rev.states = built.states;
      rev.fromLive = true;
      rev.cur = 0;
      $('recordText').value = formatRecord(rev.moves, L);
      B.showState(rev.states[0], reviewing());
      updateStatus();
      startAuto();
      B.message('对局已结束，正在以每秒 10 步回放棋谱……下方是纯文本对局记录，可复制或粘贴给对方导入。', 'info');
    }

    /** 棋谱需要的最小棋盘边长（坐标原点在棋盘中心：|a|、|b|、|a+b| 都不能超过边长）。 */
    function requiredSide(moves) {
      let n = 0;
      for (let i = 0; i < moves.length; i++) {
        const e = moves[i] && moves[i].e;
        if (!e) continue;
        const ps = L.edgePoints(e);
        for (let k = 0; k < ps.length; k++) {
          const a = ps[k][0], b = ps[k][1];
          n = Math.max(n, Math.abs(a), Math.abs(b), Math.abs(a + b));
        }
      }
      return n;
    }

    /** 导入：解析并校验下方文本框里的棋谱 → **自动匹配棋盘大小** → 自动播放；
     *  可随时上一步 / 下一步。棋谱与棋盘一一对应（第一手贴着棋盘边框，
     *  路数就写在坐标里），所以不用手动去调「棋盘大小」。 */
    function importFlow() {
      stopAuto();
      const parsed = parseRecord($('recordText').value);
      const need = requiredSide(parsed.moves);
      if (need > 0 && typeof B.useSize === 'function') {
        B.useSize(Math.max(need, L.MIN_SIDE));   // 只改本地回放用的棋盘，不动房间里的棋局
      }
      const built = buildReview(L, parsed.moves);
      const problems = parsed.errors.concat(built.errors);
      if (rev.states.length === 0 && built.states.length === 0) {
        updateStatus();
        return;
      }
      if (built.states.length < 2) {
        $('recordStatus').textContent = problems.length
          ? ('棋谱有问题：' + problems.join('；'))
          : '棋谱是空的：请先粘贴纯文本对局记录。';
        syncButtons();
        return;
      }
      rev.moves = parsed.moves;
      rev.states = built.states;
      rev.fromLive = false;
      rev.cur = 0;
      B.showState(rev.states[0], true);
      updateStatus();
      if (problems.length) {
        B.message('棋谱有 ' + problems.length + ' 处问题（' + problems[0] + '），已回放合法部分。', 'warn');
      } else if (need > 0) {
        B.message('已载入棋谱并<b>自动匹配为 ' + L.SIDE + ' 路棋盘</b>，正在自动播放……'
          + '可用「上一步 / 下一步」手动查看。', 'info');
      } else {
        B.message('已载入棋谱，正在自动播放……可用「上一步 / 下一步」手动查看。', 'info');
      }
      startAuto();
    }

    /** 分支续下：把回放停在的局面（前 rev.cur 手）载入房间，从这里继续下 */
    async function branchFlow() {
      if (!rev.states.length) {
        B.message('还没有可续下的棋谱：先「导入下方棋谱」，或点「手动结束对局」生成本局棋谱。', 'warn');
        return;
      }
      if (rev.cur <= 0) {
        B.message('请先用「上一步 / 下一步」停在你想续下的那一步（现在停在开局）。', 'warn');
        return;
      }
      if (B.status() === 'lobby') {
        B.message('请先进入房间，再从棋谱续下。', 'warn');
        return;
      }
      if (B.color() === 'S') {
        B.message('你是观众，不能续下：请让对局双方来操作。', 'warn');
        return;
      }
      const at = rev.cur;
      // 认输 / 终局不是棋盘上的着法，分支时去掉；局面 = 前 at 手之后
      const moves = rev.moves.slice(0, at)
        .filter(function (m) { return !m.r && !m.end && !m.j; })
        .map(function (m) { return { p: m.p, e: m.e }; });
      if (!moves.length) {
        B.message('第 ' + at + ' 手之前没有可以续下的着法。', 'warn');
        return;
      }
      const need = requiredSide(moves);
      // 回放棋盘已按棋谱自动匹配；分支续下要看**房间里的棋盘**装不装得下
      const roomSize = typeof B.roomSize === 'function' ? B.roomSize() : L.SIDE;
      if (need > roomSize
          && !window.confirm('这段棋谱需要 ' + need + ' 路棋盘（房间现在是 ' + roomSize + ' 路）：把棋盘切到 '
              + need + ' 路再从这里续下吗？（切换会重新开局）')) {
        return;
      }
      const res = await B.loadMoves(moves, need > roomSize ? need : null);
      if (!res.ok) {
        B.message(res.error || '未能从这里续下', 'warn');
        return;
      }
      exitReview();
      B.message('已从第 <b>' + at + '</b> 手分支续下：现在可以接着落子，对方也会同步看到这个新局面。', 'info');
    }

    /** 手机端判定：手机 / 平板维持弹窗手动复制，电脑端一律直接复制。 */
    function isMobileDevice() {
      try {
        const nav = navigator || {};
        if (nav.userAgentData && typeof nav.userAgentData.mobile === 'boolean') {
          return nav.userAgentData.mobile;
        }
        const ua = String(nav.userAgent || '');
        if (/Android|iPhone|iPad|iPod|Mobile|Windows Phone|HarmonyOS/i.test(ua)) return true;
        if (/Macintosh/.test(ua) && (nav.maxTouchPoints || 0) > 1) return true;
        return false;
      } catch (e) { return false; }
    }

    /** 隐藏 textarea + execCommand 复制（电脑端剪贴板 API 失败时的兜底，不弹窗）。 */
    function copyViaTextarea(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.position = 'fixed';
      ta.style.top = '-1000px';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      document.body.removeChild(ta);
      return ok;
    }

    /** 把一段文本复制到剪贴板（不改动棋谱框里的显示内容）。
     *  电脑端：直接复制，绝不再弹窗；手机端：维持弹窗手动复制。 */
    function copyText(text, okMsg, failMsg) {
      const done = function () { B.message(okMsg, 'info'); };
      const fail = function () { B.message(failMsg || '请手动复制文本框内容。', 'warn'); };
      const fallback = function () {
        if (isMobileDevice()) {
          try { window.prompt('请手动复制下面的内容：', text); done(); }
          catch (e) { fail(); }
          return;
        }
        try { copyViaTextarea(text) ? done() : fail(); } catch (e) { fail(); }
      };
      if (navigator.clipboard && navigator.clipboard.writeText && text) {
        navigator.clipboard.writeText(text).then(done, fallback);
      } else {
        fallback();
      }
    }

    function copyRecord() {
      const ta = $('recordText');
      ta.focus();
      ta.select();
      const done = function () { B.message('对局记录已复制。', 'info'); };
      if (navigator.clipboard && navigator.clipboard.writeText && ta.value) {
        navigator.clipboard.writeText(ta.value).then(done, function () {
          try { document.execCommand('copy'); done(); } catch (e) { B.message('请手动复制文本框内容。', 'warn'); }
        });
      } else {
        try { document.execCommand('copy'); done(); } catch (e) { B.message('请手动复制文本框内容。', 'warn'); }
      }
    }

    /** 「导出棋谱」：弹出两个选择——复制显示的棋谱 / 复制压缩后的棋谱 */
    function toggleExport() {
      const bar = $('recordExportBar');
      bar.hidden = !bar.hidden;
    }

    /** 复制压缩后的棋谱（按框里当前的棋谱压缩；老棋谱、压缩棋谱都认）。 */
    function copyCompressed() {
      const parsed = parseRecord($('recordText').value);
      if (!parsed.moves.length) {
        B.message('棋谱是空的或认不出：先在框里放一份棋谱，再导出压缩版。'
          + (parsed.errors.length ? '（' + parsed.errors[0] + '）' : ''), 'warn');
        return;
      }
      copyText(compressedText(parsed.moves, L),
        '压缩棋谱已复制（比原谱短很多）：粘贴给对方，点「导入下方棋谱」即可回放——'
        + '文本里<b>没有「→」</b>就自动按压缩格式解析，老棋谱照旧。',
        '压缩棋谱没能自动复制：请手动复制文本框里的内容。');
    }

    function exitReview() {
      stopAuto();
      rev.moves = [];
      rev.states = [];
      rev.cur = 0;
      rev.fromLive = false;
      B.restoreLive();
      updateStatus();
      syncButtons();
    }

    $('btnEndGame').addEventListener('click', endGameFlow);
    $('recordPlay').addEventListener('click', toggleAuto);
    $('recordPrev').addEventListener('click', function () { stopAuto(); stepTo(rev.cur - 1); });
    $('recordNext').addEventListener('click', function () { stopAuto(); stepTo(rev.cur + 1); });
    $('recordBranch').addEventListener('click', function () { stopAuto(); branchFlow(); });
    $('recordImport').addEventListener('click', importFlow);
    $('recordCopy').addEventListener('click', toggleExport);
    $('recordCopyPlain').addEventListener('click', copyRecord);
    $('recordCopyZip').addEventListener('click', copyCompressed);
    $('recordExit').addEventListener('click', exitReview);

    // 供界面层调用：棋盘大小改变 / 退出房间时结束回放，回到实时棋局
    B.resetReview = exitReview;
    // 供界面层调用：「手动结束对局」经所有在场玩家同意生效后，自动回放整盘
    B.onLiveEnd = liveEndReplay;

    updateStatus();
    syncButtons();
  }

  if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', init);
    } else {
      init();
    }
  }

  return {
    SPEED_MS: SPEED_MS,
    edgeKey: edgeKey,
    oriented: oriented,
    formatRecord: formatRecord,
    parsePlain: parsePlain,
    parseCompressed: parseCompressed,
    parseRecord: parseRecord,
    compressRecord: compressRecord,
    compressedText: compressedText,
    buildReview: buildReview,
  };
});
