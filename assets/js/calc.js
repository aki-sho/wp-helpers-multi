(() => {
  "use strict";
  console.log("calc.js running");

  const OP_PRECEDENCE = { "+": 1, "-": 1, "*": 2, "/": 2 };

  function isOp(ch) {
    return ch === "+" || ch === "-" || ch === "*" || ch === "/";
  }

  function normalizeKey(key) {
    // ボタンが × ÷ を返す場合に備える
    if (key === "×") return "*";
    if (key === "÷") return "/";
    return key;
  }

  function tokenize(expr) {
    const s = String(expr || "").replace(/\s+/g, "");
    const tokens = [];
    let i = 0;

    while (i < s.length) {
      const ch = s[i];

      // unary minus
      if (ch === "-" && (i === 0 || isOp(s[i - 1]))) {
        let j = i + 1;
        let num = "-";
        let dot = false;

        while (j < s.length) {
          const c = s[j];
          if (c >= "0" && c <= "9") { num += c; j++; continue; }
          if (c === "." && !dot) { dot = true; num += c; j++; continue; }
          break;
        }
        if (num === "-" || num === "-.") return null;
        tokens.push(num);
        i = j;
        continue;
      }

      // number
      if ((ch >= "0" && ch <= "9") || ch === ".") {
        let j = i;
        let num = "";
        let dot = false;

        while (j < s.length) {
          const c = s[j];
          if (c >= "0" && c <= "9") { num += c; j++; continue; }
          if (c === "." && !dot) { dot = true; num += c; j++; continue; }
          break;
        }
        if (num === "." || num === "") return null;
        tokens.push(num);
        i = j;
        continue;
      }

      // operator
      if (isOp(ch)) {
        tokens.push(ch);
        i++;
        continue;
      }

      return null; // unknown char
    }

    return tokens;
  }

  function evalTokens(tokens) {
    const vals = [];
    const ops = [];

    function applyOp() {
      const op = ops.pop();
      const b = vals.pop();
      const a = vals.pop();
      if (a === undefined || b === undefined || !op) throw new Error("Bad expression");

      let r;
      if (op === "+") r = a + b;
      else if (op === "-") r = a - b;
      else if (op === "*") r = a * b;
      else if (op === "/") {
        if (b === 0) throw new Error("Division by zero");
        r = a / b;
      } else throw new Error("Bad operator");

      vals.push(r);
    }

    for (const t of tokens) {
      if (isOp(t)) {
        while (ops.length && OP_PRECEDENCE[ops[ops.length - 1]] >= OP_PRECEDENCE[t]) {
          applyOp();
        }
        ops.push(t);
      } else {
        const v = Number(t);
        if (!Number.isFinite(v)) throw new Error("Bad number");
        vals.push(v);
      }
    }

    while (ops.length) applyOp();
    if (vals.length !== 1) throw new Error("Bad expression");
    return vals[0];
  }

  function formatNumber(n) {
    const s = String(n);
    if (!s.includes(".") && !s.includes("e")) return s;
    const rounded = Math.round((n + Number.EPSILON) * 1e12) / 1e12;
    return String(rounded);
  }

  function setup(root) {
    const display = root.querySelector("[data-wphm-calc-display]");
    if (!display) return;

    let expr = "";
    let justEvaluated = false;

    function setDisplay(v) {
      // display が input でも div でも動くように
      if ("value" in display) display.value = v;
      else display.textContent = v;
    }

    function lastNumberSegmentHasDot() {
      let i = expr.length - 1;
      while (i >= 0 && !isOp(expr[i])) i--;
      const seg = expr.slice(i + 1);
      return seg.includes(".");
    }

    function press(rawKey) {
      const key = normalizeKey(rawKey);

      if (key === "AC") {
        expr = "";
        justEvaluated = false;
        setDisplay("");
        return;
      }

      if (key === "=") {
        if (!expr) return;
        const tokens = tokenize(expr);
        if (!tokens) { setDisplay("Error"); justEvaluated = true; return; }

        try {
          const r = evalTokens(tokens);
          const out = formatNumber(r);
          expr = out;
          setDisplay(out);
          justEvaluated = true;
        } catch (e) {
          setDisplay("Error");
          justEvaluated = true;
        }
        return;
      }

      // digits / dot
      if ((key >= "0" && key <= "9") || key === ".") {
        if (justEvaluated) { expr = ""; justEvaluated = false; }

        if (key === ".") {
          if (!expr || isOp(expr[expr.length - 1])) {
            expr += "0.";
            setDisplay(expr);
            return;
          }
          if (lastNumberSegmentHasDot()) return;
        }

        expr += key;
        setDisplay(expr);
        return;
      }

      // operators
      if (isOp(key)) {
        if (!expr) {
          if (key === "-") { expr = "-"; setDisplay(expr); }
          return;
        }

        if (justEvaluated) justEvaluated = false;

        const last = expr[expr.length - 1];
        if (isOp(last)) {
          expr = expr.slice(0, -1) + key;
          setDisplay(expr);
          return;
        }
        if (last === ".") expr += "0";

        expr += key;
        setDisplay(expr);
      }
    }

    root.addEventListener("click", (e) => {
      const btn = e.target?.closest?.("[data-key]");
      if (!btn || !root.contains(btn)) return;

      const key = btn.getAttribute("data-key");
      if (!key) return;

      console.log("[calc] key", { key });
      press(key);
    });

    root.addEventListener("keydown", (e) => {
      const k = e.key;

      if (k >= "0" && k <= "9") { e.preventDefault(); press(k); return; }
      if (k === ".") { e.preventDefault(); press("."); return; }
      if (k === "+" || k === "-" || k === "*" || k === "/") { e.preventDefault(); press(k); return; }
      if (k === "Enter" || k === "=") { e.preventDefault(); press("="); return; }
      if (k === "Escape") { e.preventDefault(); press("AC"); return; }

      if (k === "Backspace") {
        e.preventDefault();
        if (justEvaluated) { expr = ""; justEvaluated = false; setDisplay(""); return; }
        expr = expr.slice(0, -1);
        setDisplay(expr);
      }
    });

    root.tabIndex = 0;
    setDisplay("");
  }

  function boot() {
    document.querySelectorAll("[data-wphm-calc]").forEach(setup);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
