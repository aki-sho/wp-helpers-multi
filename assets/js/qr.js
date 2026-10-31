/* global qrcodegen */
(() => {
  'use strict';
  const root = document.getElementById('wphm-qr-tool');
  if (!root) return;
  const byId = id => document.getElementById(`wphm-qr-${id}`);
  const message = byId('message'), result = byId('result'), canvas = byId('canvas'), download = byId('download');
  let imageUrl = '';
  const clear = () => {
    if (imageUrl) URL.revokeObjectURL(imageUrl);
    imageUrl = ''; result.hidden = true; download.removeAttribute('href');
  };
  let generation = 0;
  byId('generate').addEventListener('click', () => {
    clear(); const current = ++generation;
    const text = byId('text').value.trim();
    if (!text) { message.textContent = '内容を入力してください。'; return; }
    if (new TextEncoder().encode(text).length > 2953) { message.textContent = '入力が長すぎます。UTF-8で2,953バイト以内にしてください。'; return; }
    try {
      const levels = { L: qrcodegen.QrCode.Ecc.LOW, M: qrcodegen.QrCode.Ecc.MEDIUM, Q: qrcodegen.QrCode.Ecc.QUARTILE, H: qrcodegen.QrCode.Ecc.HIGH };
      const code = qrcodegen.QrCode.encodeText(text, levels[byId('ecc').value] || levels.M);
      const margin = Math.floor(Math.max(0, Math.min(16, Number(byId('margin').value) || 0)));
      const requested = Math.max(120, Math.min(1000, Number(byId('size').value) || 240));
      const modules = code.size + 2 * margin, scale = Math.max(1, Math.floor(requested / modules));
      canvas.width = canvas.height = modules * scale;
      const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.fillStyle = '#000';
      for (let y = 0; y < code.size; y++) for (let x = 0; x < code.size; x++) if (code.getModule(x, y)) ctx.fillRect((x + margin) * scale, (y + margin) * scale, scale, scale);
      canvas.toBlob(blob => {
        if (current !== generation || !blob) return;
        imageUrl = URL.createObjectURL(blob); download.href = imageUrl; result.hidden = false;
        message.textContent = `生成しました（${canvas.width} × ${canvas.height}px）。内容を確認してダウンロードしてください。`;
      }, 'image/png');
    } catch (_) { message.textContent = 'この内容・誤り訂正レベルでは生成できません。内容を短くしてください。'; }
  });
  byId('clear').addEventListener('click', () => { generation++; clear(); byId('text').value = ''; message.textContent = ''; });
  byId('text').addEventListener('input', () => { generation++; clear(); message.textContent = ''; });
  for (const id of ['size', 'margin', 'ecc']) byId(id).addEventListener('input', () => { generation++; clear(); message.textContent = ''; });
  window.addEventListener('pagehide', clear);
})();
