/* global window, document, navigator, localStorage */
(() => {
  "use strict";

  /**
   * タイマーJSの役割:
   * - 3モード（カウントダウン / ストップウォッチ / アラーム）を動かす
   * - 終了時: timer-finish.mp3 / 通知 / タイトル点滅
   * - 操作音: ui-click.mp3
   * - localStorage 保存でリロード復帰
   */

  const CFG = window.WPHM_TIMER || {};
  const LS_KEY = "wphm_timer_state_v1";

  // ---- DOMユーティリティ
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  // ---- 音（管理画面でも一応使えるよう、Audioを使う）
  const finishAudio = new Audio(CFG.finishSoundUrl || "");
  const clickAudio  = new Audio(CFG.clickSoundUrl  || "");

  // iOS/Safariなど「ユーザー操作が無い再生」を弾くことがあるので、失敗は握りつぶす
  const safePlay = (audio) => {
    try {
      const p = audio.play();
      if (p && typeof p.catch === "function") p.catch(() => {});
    } catch (_) {}
  };

  // ---- 設定（トグル/音量）
  const elSoundEnabled = $("#wphm-sound-enabled");
  const elClickEnabled = $("#wphm-click-enabled");
  const elNotifyEnabled = $("#wphm-notify-enabled");
  const elVolume = $("#wphm-volume");

  const state = {
    // 共通
    activeTab: "countdown",
    soundEnabled: true,
    clickEnabled: true,
    notifyEnabled: false,
    volume: 0.8,

    // countdown
    cd: {
      configuredMs: 10 * 60 * 1000, // 初期 10分
      running: false,
      endAt: null,        // running中: Date.now() + remaining
      remainingMs: null,  // paused中: 残り
      startedAt: null,    // 進捗用
      totalMs: null,      // 進捗用
      finished: false
    },

    // stopwatch
    sw: {
      running: false,
      startAt: null,
      elapsedBefore: 0,
      laps: []
    },

    // alarm
    al: {
      running: false,
      targetAt: null,
      timeStr: null,
      finished: false
    }
  };

  // ---- タイトル点滅（終了時の目立たせ）
  let blinkTimer = null;
  const startTitleBlink = (msg) => {
    stopTitleBlink();
    const original = document.title;
    let flip = false;
    blinkTimer = setInterval(() => {
      document.title = flip ? msg : original;
      flip = !flip;
    }, 900);
    // 30秒で自動停止
    setTimeout(stopTitleBlink, 30000);
  };
  const stopTitleBlink = () => {
    if (blinkTimer) {
      clearInterval(blinkTimer);
      blinkTimer = null;
      // 元に戻す（ページ側のタイトルに合わせたいので強制はしない）
    }
  };

  const playClick = () => {
    if (!state.clickEnabled) return;
    clickAudio.currentTime = 0;
    safePlay(clickAudio);
  };

  const playFinish = () => {
    if (!state.soundEnabled) return;
    finishAudio.currentTime = 0;
    safePlay(finishAudio);
  };

  const setVolume = (v01) => {
    state.volume = v01;
    finishAudio.volume = v01;
    clickAudio.volume = v01;
  };

  // ---- Notification
  const tryEnableNotification = async () => {
    if (!("Notification" in window)) return false;
    if (Notification.permission === "granted") return true;
    if (Notification.permission === "denied") return false;

    try {
      const perm = await Notification.requestPermission();
      return perm === "granted";
    } catch (_) {
      return false;
    }
  };

  const notify = (title, body) => {
    if (!state.notifyEnabled) return;
    if (!("Notification" in window)) return;
    if (Notification.permission !== "granted") return;

    try {
      new Notification(title, { body });
    } catch (_) {}
  };

  // ---- localStorage
  const saveState = () => {
    const data = JSON.stringify(state);
    try { localStorage.setItem(LS_KEY, data); } catch (_) {}
  };

  const loadState = () => {
    try {
      const raw = localStorage.getItem(LS_KEY);
      if (!raw) return;
      const obj = JSON.parse(raw);

      // 重要：最低限だけ上書き（破壊的にしない）
      Object.assign(state, obj);

      // オブジェクト深いところは個別に
      if (obj.cd) Object.assign(state.cd, obj.cd);
      if (obj.sw) Object.assign(state.sw, obj.sw);
      if (obj.al) Object.assign(state.al, obj.al);
    } catch (_) {}
  };

  // ---- 表示フォーマット
  const pad2 = (n) => String(Math.max(0, n)).padStart(2, "0");

  const formatHMS = (ms) => {
    const totalSec = Math.max(0, Math.floor(ms / 1000));
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    return `${pad2(h)}:${pad2(m)}:${pad2(s)}`;
  };

  const formatHMSms = (ms) => {
    const t = Math.max(0, ms);
    const totalSec = Math.floor(t / 1000);
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    const d = Math.floor((t % 1000) / 100); // 0.1秒
    return `${pad2(h)}:${pad2(m)}:${pad2(s)}.${d}`;
  };

  const formatTimeHM = (dateMs) => {
    const d = new Date(dateMs);
    return `${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
  };

  // ---- タブ切替
  const tabs = $$(".wphm-tab");
  const panels = $$(".wphm-panel");

  const setTab = (name) => {
    state.activeTab = name;
    tabs.forEach((b) => {
      const on = b.dataset.tab === name;
      b.classList.toggle("is-active", on);
      b.setAttribute("aria-selected", on ? "true" : "false");
    });
    panels.forEach((p) => p.classList.toggle("is-active", p.dataset.panel === name));
    saveState();
  };

  // ---- Countdown DOM
  const cdH = $("#cd-h");
  const cdM = $("#cd-m");
  const cdS = $("#cd-s");
  const cdApply = $("#cd-apply");
  const cdStart = $("#cd-start");
  const cdPause = $("#cd-pause");
  const cdResume = $("#cd-resume");
  const cdReset = $("#cd-reset");
  const cdDisplay = $("#cd-display");
  const cdSub = $("#cd-sub");
  const cdBar = $("#cd-bar");
  const cdCopyRemaining = $("#cd-copy-remaining");
  const cdCopyEnd = $("#cd-copy-end");

  const setCountdownConfigured = (ms) => {
    state.cd.configuredMs = Math.max(0, ms);
    // 入力欄へ反映（00:00:00形式）
    const totalSec = Math.floor(state.cd.configuredMs / 1000);
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    cdH.value = h;
    cdM.value = m;
    cdS.value = s;
    renderCountdown();
    saveState();
  };

  const getCountdownRemaining = () => {
    if (state.cd.running && state.cd.endAt) return Math.max(0, state.cd.endAt - Date.now());
    if (!state.cd.running && typeof state.cd.remainingMs === "number") return Math.max(0, state.cd.remainingMs);
    return Math.max(0, state.cd.configuredMs);
  };

  const renderCountdown = () => {
    const remain = getCountdownRemaining();
    cdDisplay.textContent = formatHMS(remain);

    // サブ表示
    if (!state.cd.running && !state.cd.remainingMs) {
      cdSub.textContent = "未開始";
    } else if (!state.cd.running && state.cd.remainingMs != null) {
      cdSub.textContent = "一時停止中";
    } else if (state.cd.running) {
      cdSub.textContent = `終了予定 ${formatTimeHM(state.cd.endAt)}`;
    }

    // 進捗バー
    const total = state.cd.totalMs || state.cd.configuredMs || 1;
    const done = Math.min(1, Math.max(0, (total - remain) / total));
    cdBar.style.width = `${Math.floor(done * 100)}%`;

    // ボタン状態
    cdPause.disabled = !state.cd.running;
    cdResume.disabled = state.cd.running || state.cd.remainingMs == null;
  };

  const finishCountdown = () => {
    if (state.cd.finished) return;
    state.cd.finished = true;
    state.cd.running = false;
    state.cd.endAt = null;
    state.cd.remainingMs = 0;

    renderCountdown();

    playFinish();
    notify("タイマー終了", "カウントダウンが終了しました");
    startTitleBlink("⏰ タイマー終了");
    saveState();
  };

  // ---- Stopwatch DOM
  const swStart = $("#sw-start");
  const swStop = $("#sw-stop");
  const swReset = $("#sw-reset");
  const swLap = $("#sw-lap");
  const swDisplay = $("#sw-display");
  const swSub = $("#sw-sub");
  const swLaps = $("#sw-laps");
  const swCopy = $("#sw-copy");
  const swClearLaps = $("#sw-clear-laps");

  const getStopwatchElapsed = () => {
    if (state.sw.running && state.sw.startAt) {
      return state.sw.elapsedBefore + (Date.now() - state.sw.startAt);
    }
    return state.sw.elapsedBefore;
  };

  const renderStopwatch = () => {
    const elapsed = getStopwatchElapsed();
    swDisplay.textContent = formatHMSms(elapsed);
    swSub.textContent = state.sw.running ? "計測中" : (elapsed > 0 ? "停止中" : "未開始");

    swStop.disabled = !state.sw.running;
    swLap.disabled = !state.sw.running;
  };

  const renderLaps = () => {
    swLaps.innerHTML = "";
    state.sw.laps.forEach((t, idx) => {
      const li = document.createElement("li");
      li.textContent = `#${idx + 1}  ${formatHMSms(t)}`;
      swLaps.appendChild(li);
    });
  };

  // ---- Alarm DOM
  const alTime = $("#al-time");
  const alSet = $("#al-set");
  const alClear = $("#al-clear");
  const alDisplay = $("#al-display");
  const alSub = $("#al-sub");
  const alCopy = $("#al-copy");

  const computeTargetFromTimeStr = (timeStr) => {
    // "HH:MM" を「今日のその時刻」に変換。過ぎてたら明日にする。
    const [hh, mm] = timeStr.split(":").map((x) => parseInt(x, 10));
    const now = new Date();
    const target = new Date(
      now.getFullYear(),
      now.getMonth(),
      now.getDate(),
      isFinite(hh) ? hh : 0,
      isFinite(mm) ? mm : 0,
      0,
      0
    ).getTime();

    if (target <= Date.now()) {
      // 今日が過ぎてたら明日
      return target + 24 * 60 * 60 * 1000;
    }
    return target;
  };

  const getAlarmRemaining = () => {
    if (!state.al.running || !state.al.targetAt) return null;
    return Math.max(0, state.al.targetAt - Date.now());
  };

  const renderAlarm = () => {
    const remain = getAlarmRemaining();
    if (remain == null) {
      alDisplay.textContent = "--:--:--";
      alSub.textContent = "未設定";
      alClear.disabled = true;
      return;
    }
    alDisplay.textContent = formatHMS(remain);
    alSub.textContent = `次のアラーム: ${formatTimeHM(state.al.targetAt)}`;
    alClear.disabled = false;
  };

  const finishAlarm = () => {
    if (state.al.finished) return;
    state.al.finished = true;
    state.al.running = false;

    renderAlarm();
    playFinish();
    notify("アラーム", "指定時刻になりました");
    startTitleBlink("⏰ アラーム");
    saveState();
  };

  // ---- クリップボード
  const copyText = async (text) => {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (_) {}
    // fallback（古いブラウザ向け）
    try {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.style.position = "fixed";
      ta.style.left = "-9999px";
      document.body.appendChild(ta);
      ta.select();
      document.execCommand("copy");
      document.body.removeChild(ta);
      return true;
    } catch (_) {
      return false;
    }
  };

  // ---- メインtick（1つのintervalで全部更新）
  let ticker = null;
  const startTicker = () => {
    if (ticker) return;
    ticker = setInterval(() => {
      // countdown
      if (state.cd.running && state.cd.endAt) {
        const remain = state.cd.endAt - Date.now();
        if (remain <= 0) finishCountdown();
        renderCountdown();
      } else {
        renderCountdown();
      }

      // stopwatch
      renderStopwatch();

      // alarm
      if (state.al.running && state.al.targetAt) {
        const remain = state.al.targetAt - Date.now();
        if (remain <= 0) finishAlarm();
      }
      renderAlarm();

      saveState();
    }, 200);
  };

  // ---- イベントバインド
  const bind = () => {
    // タブ
    tabs.forEach((b) => b.addEventListener("click", () => { playClick(); setTab(b.dataset.tab); }));

    // 設定
    elSoundEnabled.addEventListener("change", () => { playClick(); state.soundEnabled = !!elSoundEnabled.checked; saveState(); });
    elClickEnabled.addEventListener("change", () => { state.clickEnabled = !!elClickEnabled.checked; playClick(); saveState(); });

    elNotifyEnabled.addEventListener("change", async () => {
      playClick();
      const want = !!elNotifyEnabled.checked;
      if (want) {
        const ok = await tryEnableNotification();
        state.notifyEnabled = ok;
        elNotifyEnabled.checked = ok;
      } else {
        state.notifyEnabled = false;
      }
      saveState();
    });

    elVolume.addEventListener("input", () => {
      const v = Math.max(0, Math.min(100, parseInt(elVolume.value, 10) || 0)) / 100;
      setVolume(v);
      saveState();
    });

    // countdown: apply
    cdApply.addEventListener("click", () => {
      playClick();
      const h = parseInt(cdH.value, 10) || 0;
      const m = parseInt(cdM.value, 10) || 0;
      const s = parseInt(cdS.value, 10) || 0;
      const ms = ((h * 3600) + (m * 60) + s) * 1000;
      // 実行中は上書きしない（事故防止）
      if (state.cd.running) return;
      state.cd.remainingMs = null;
      state.cd.finished = false;
      setCountdownConfigured(ms);
    });

    // countdown: presets
    $$(".wphm-presets [data-preset-sec]").forEach((btn) => {
      btn.addEventListener("click", () => {
        playClick();
        if (state.cd.running) return;
        const sec = parseInt(btn.dataset.presetSec, 10) || 0;
        state.cd.remainingMs = null;
        state.cd.finished = false;
        setCountdownConfigured(sec * 1000);
      });
    });

    // countdown: start
    cdStart.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      state.cd.finished = false;

      const remain = getCountdownRemaining();
      if (remain <= 0) return;

      state.cd.running = true;
      state.cd.totalMs = remain;
      state.cd.startedAt = Date.now();
      state.cd.endAt = Date.now() + remain;
      state.cd.remainingMs = null;
      renderCountdown();
      saveState();
    });

    // countdown: pause
    cdPause.addEventListener("click", () => {
      playClick();
      if (!state.cd.running || !state.cd.endAt) return;
      state.cd.running = false;
      state.cd.remainingMs = Math.max(0, state.cd.endAt - Date.now());
      state.cd.endAt = null;
      renderCountdown();
      saveState();
    });

    // countdown: resume
    cdResume.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      if (state.cd.running) return;
      if (state.cd.remainingMs == null || state.cd.remainingMs <= 0) return;

      state.cd.running = true;
      state.cd.endAt = Date.now() + state.cd.remainingMs;
      // totalMs が無い場合は残りをtotal扱い
      if (!state.cd.totalMs) state.cd.totalMs = state.cd.remainingMs;
      state.cd.remainingMs = null;
      renderCountdown();
      saveState();
    });

    // countdown: reset
    cdReset.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      state.cd.running = false;
      state.cd.endAt = null;
      state.cd.remainingMs = null;
      state.cd.startedAt = null;
      state.cd.totalMs = null;
      state.cd.finished = false;
      renderCountdown();
      saveState();
    });

    // countdown: copy
    cdCopyRemaining.addEventListener("click", async () => {
      playClick();
      const remain = getCountdownRemaining();
      await copyText(`残り ${formatHMS(remain)}`);
    });

    cdCopyEnd.addEventListener("click", async () => {
      playClick();
      const endAt = state.cd.running && state.cd.endAt ? state.cd.endAt : null;
      const text = endAt ? `終了予定 ${formatTimeHM(endAt)}` : "終了予定（未開始）";
      await copyText(text);
    });

    // stopwatch
    swStart.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      if (state.sw.running) return;
      state.sw.running = true;
      state.sw.startAt = Date.now();
      swStop.disabled = false;
      swLap.disabled = false;
      renderStopwatch();
      saveState();
    });

    swStop.addEventListener("click", () => {
      playClick();
      if (!state.sw.running) return;
      state.sw.elapsedBefore = getStopwatchElapsed();
      state.sw.running = false;
      state.sw.startAt = null;
      renderStopwatch();
      saveState();
    });

    swReset.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      state.sw.running = false;
      state.sw.startAt = null;
      state.sw.elapsedBefore = 0;
      renderStopwatch();
      saveState();
    });

    swLap.addEventListener("click", () => {
      playClick();
      if (!state.sw.running) return;
      state.sw.laps.unshift(getStopwatchElapsed());
      renderLaps();
      saveState();
    });

    swClearLaps.addEventListener("click", () => {
      playClick();
      state.sw.laps = [];
      renderLaps();
      saveState();
    });

    swCopy.addEventListener("click", async () => {
      playClick();
      const lines = [];
      lines.push(`経過 ${formatHMSms(getStopwatchElapsed())}`);
      if (state.sw.laps.length) {
        lines.push("ラップ:");
        state.sw.laps.slice().reverse().forEach((t, i) => {
          lines.push(`#${i + 1} ${formatHMSms(t)}`);
        });
      }
      await copyText(lines.join("\n"));
    });

    // alarm
    alSet.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      const t = alTime.value || null;
      if (!t) return;

      state.al.timeStr = t;
      state.al.targetAt = computeTargetFromTimeStr(t);
      state.al.running = true;
      state.al.finished = false;
      renderAlarm();
      saveState();
    });

    alClear.addEventListener("click", () => {
      playClick();
      stopTitleBlink();
      state.al.running = false;
      state.al.targetAt = null;
      state.al.timeStr = null;
      state.al.finished = false;
      renderAlarm();
      saveState();
    });

    alCopy.addEventListener("click", async () => {
      playClick();
      if (!state.al.running || !state.al.targetAt) {
        await copyText("アラーム：未設定");
        return;
      }
      await copyText(`アラーム: ${state.al.timeStr}（次回 ${formatTimeHM(state.al.targetAt)}）`);
    });

    // ボタンに一括で「クリック音」を付けたい場合（漏れを減らす）
    // ※すでに個別でplayClickしてるので必須ではない
  };

  // ---- 初期化
  const init = () => {
    loadState();

    // 設定UIへ反映
    elSoundEnabled.checked = !!state.soundEnabled;
    elClickEnabled.checked = !!state.clickEnabled;
    elNotifyEnabled.checked = !!state.notifyEnabled;

    // volume
    const vol = typeof state.volume === "number" ? state.volume : 0.8;
    elVolume.value = String(Math.round(vol * 100));
    setVolume(vol);

    // countdown設定を入力に反映
    setCountdownConfigured(state.cd.configuredMs || (10 * 60 * 1000));

    // タブ復帰
    setTab(state.activeTab || "countdown");

    // ラップ復帰
    renderLaps();

    // もし countdown running のまま復帰した場合、endAt を見て再開（過去なら即終了）
    if (state.cd.running && state.cd.endAt) {
      if (state.cd.endAt <= Date.now()) finishCountdown();
    }

    // もし alarm running のまま復帰した場合
    if (state.al.running && state.al.targetAt) {
      if (state.al.targetAt <= Date.now()) finishAlarm();
    }

    bind();
    startTicker();
  };

  // DOM ready（deferなので基本OKだが保険）
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();