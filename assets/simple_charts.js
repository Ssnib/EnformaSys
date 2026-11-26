(() => {
  const clamp = (n, a, b) => Math.max(a, Math.min(b, n));

  const fitCanvas = (canvas) => {
    const parent = canvas.parentElement;
    const w = parent ? parent.clientWidth : canvas.clientWidth;
    const h = parent ? parent.clientHeight : canvas.clientHeight;
    const dpr = window.devicePixelRatio || 1;
    const width = Math.max(1, Math.floor(w));
    const height = Math.max(1, Math.floor(h));
    if (canvas.width !== Math.floor(width * dpr) || canvas.height !== Math.floor(height * dpr)) {
      canvas.width = Math.floor(width * dpr);
      canvas.height = Math.floor(height * dpr);
      canvas.style.width = width + 'px';
      canvas.style.height = height + 'px';
    }
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    return { ctx, width, height };
  };

  const clear = (ctx, w, h) => {
    ctx.clearRect(0, 0, w, h);
  };

  const drawText = (ctx, text, x, y, opts) => {
    opts = opts || {};
    ctx.save();
    ctx.fillStyle = opts.color || '#0f172a';
    ctx.font = opts.font || '600 11px Inter, system-ui, -apple-system, sans-serif';
    ctx.textAlign = opts.align || 'left';
    ctx.textBaseline = opts.baseline || 'alphabetic';
    ctx.fillText(text, x, y);
    ctx.restore();
  };

  const wrapLabelLines = (label, maxCharsPerLine = 12, maxLines = 2) => {
    const words = String(label || '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return [''];
    const lines = [];
    let current = words[0];
    for (let i = 1; i < words.length; i++) {
      const candidate = current + ' ' + words[i];
      if (candidate.length <= maxCharsPerLine) {
        current = candidate;
      } else {
        lines.push(current);
        current = words[i];
        if (lines.length >= maxLines - 1) {
          const rest = [current, ...words.slice(i + 1)].join(' ');
          current = rest.length > maxCharsPerLine ? rest.slice(0, maxCharsPerLine - 1) + '…' : rest;
          break;
        }
      }
    }
    lines.push(current);
    return lines.slice(0, maxLines);
  };

  const drawLine = (ctx, x1, y1, x2, y2, color, width, dash) => {
    ctx.save();
    ctx.strokeStyle = color;
    ctx.lineWidth = width;
    if (dash) ctx.setLineDash(dash);
    ctx.beginPath();
    ctx.moveTo(x1, y1);
    ctx.lineTo(x2, y2);
    ctx.stroke();
    ctx.restore();
  };

  const drawRoundedTopBar = (ctx, x, y, w, h, r, fillStyle) => {
    if (h <= 0 || w <= 0) return;
    const radius = Math.min(r, w / 2, h);
    ctx.save();
    ctx.fillStyle = fillStyle;
    ctx.beginPath();
    ctx.moveTo(x, y + h);
    ctx.lineTo(x, y + radius);
    ctx.quadraticCurveTo(x, y, x + radius, y);
    ctx.lineTo(x + w - radius, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
    ctx.lineTo(x + w, y + h);
    ctx.closePath();
    ctx.fill();
    ctx.restore();
  };

  const defaultPalette = ['#fda4af', '#fcd34d', '#93c5fd', '#6366f1', '#312e81'];

  const isDarkTheme = () => document.documentElement.getAttribute('data-theme') === 'dark';

  const getThemeColors = () => {
    const dark = isDarkTheme();
    return {
      dark,
      axisBase: dark ? 'rgba(245, 240, 236, 0.22)' : '#cbd5e1',
      axisGrid: dark ? 'rgba(245, 240, 236, 0.08)' : '#f1f5f9',
      axisTick: dark ? '#b8aea7' : '#64748b',
      label: dark ? '#b8aea7' : '#475569',
      value: dark ? '#f5f0ec' : '#334155',
      centerValue: dark ? '#f5f0ec' : '#0f172a',
      centerLabel: dark ? '#b8aea7' : '#64748b',
      surface: dark ? '#1b1817' : '#ffffff',
      emptyTrack: dark ? '#2a2422' : '#f1f5f9'
    };
  };

  const drawAxes = (ctx, rect, yMin, yMax, steps) => {
    const tc = getThemeColors();
    const { x, y, w, h } = rect;
    const s = Math.max(1, steps || 5);
    for (let i = 0; i <= s; i += 1) {
      const t = i / s;
      const v = yMin + (yMax - yMin) * t;
      const yy = y + h - t * h;
      drawLine(ctx, x, yy, x + w, yy, i === 0 ? tc.axisBase : tc.axisGrid, 1, i === 0 ? null : [4, 4]);
      drawText(ctx, v.toFixed(1), x - 10, yy + 4, {
        align: 'right',
        color: tc.axisTick,
        font: '600 11px Inter, system-ui, sans-serif'
      });
    }
  };

  const bar = (canvas, labels, values, opts) => {
    opts = opts || {};
    const draw = () => {
      const tc = getThemeColors();
      const { ctx, width, height } = fitCanvas(canvas);
      clear(ctx, width, height);
      const padding = { top: 18, right: 16, bottom: 46, left: 42 };
      const rect = { x: padding.left, y: padding.top, w: width - padding.left - padding.right, h: height - padding.top - padding.bottom };
      const yMin = opts.yMin != null ? opts.yMin : 0;
      const yMax = opts.yMax != null ? opts.yMax : 5;
      drawAxes(ctx, rect, yMin, yMax, opts.steps != null ? opts.steps : 5);
      const n = Math.max(1, (labels || []).length);
      const slotW = rect.w / n;
      const barW = Math.min(42, Math.max(16, slotW * 0.52));
      const color = opts.color || '#4f46e5';

      for (let i = 0; i < n; i += 1) {
        const raw = values && values[i];
        const v = raw == null ? null : clamp(Number(raw), yMin, yMax);
        const cx = rect.x + slotW * (i + 0.5);
        const x = cx - barW / 2;
        const y0 = rect.y + rect.h;
        const y1 = v == null ? y0 : rect.y + rect.h - ((v - yMin) / (yMax - yMin)) * rect.h;
        if (v != null) {
          const grad = ctx.createLinearGradient(0, y1, 0, y0);
          grad.addColorStop(0, color);
          grad.addColorStop(1, opts.colorEnd || color);
          drawRoundedTopBar(ctx, x, y1, barW, Math.max(0, y0 - y1), 8, grad);
          drawText(ctx, v.toFixed(2), cx, y1 - 6, {
            align: 'center',
            color: tc.value,
            font: '700 10px Inter, system-ui, sans-serif'
          });
        }
        const label = String(labels && labels[i] != null ? labels[i] : '');
        if (label !== '') {
          const lines = wrapLabelLines(label, 12, 2);
          lines.forEach((lineStr, lineIdx) => {
            drawText(ctx, lineStr, cx, rect.y + rect.h + 16 + lineIdx * 13, {
              align: 'center',
              color: tc.label,
              font: '600 10.5px Inter, system-ui, sans-serif'
            });
          });
        }
      }
    };
    draw();
    window.addEventListener('resize', draw, { passive: true });
    window.addEventListener('enformasys:themechange', draw);
  };

  const line = (canvas, labels, values, opts) => {
    opts = opts || {};
    const draw = () => {
      const tc = getThemeColors();
      const { ctx, width, height } = fitCanvas(canvas);
      clear(ctx, width, height);
      const padding = { top: 18, right: 20, bottom: 42, left: 42 };
      const rect = { x: padding.left, y: padding.top, w: width - padding.left - padding.right, h: height - padding.top - padding.bottom };
      const yMin = opts.yMin != null ? opts.yMin : 0;
      const yMax = opts.yMax != null ? opts.yMax : 5;
      drawAxes(ctx, rect, yMin, yMax, opts.steps != null ? opts.steps : 5);

      const n = Math.max(1, (labels || []).length);
      const getX = (i) => rect.x + (n === 1 ? rect.w / 2 : (i / (n - 1)) * rect.w);
      const getY = (v) => rect.y + rect.h - ((v - yMin) / (yMax - yMin)) * rect.h;
      const pts = [];
      for (let i = 0; i < n; i += 1) {
        const raw = values && values[i];
        if (raw == null) continue;
        const v = clamp(Number(raw), yMin, yMax);
        pts.push({ x: getX(i), y: getY(v), i, v });
      }
      const stroke = opts.stroke || '#4f46e5';
      const fillTop = opts.fill || 'rgba(79, 70, 229, 0.25)';

      if (pts.length === 1) {
        // Graceful single-point handling: reference guide line + highlighted point
        drawLine(ctx, rect.x, pts[0].y, rect.x + rect.w, pts[0].y, stroke, 1.5, [6, 6]);
      } else if (pts.length >= 2) {
        const grad = ctx.createLinearGradient(0, rect.y, 0, rect.y + rect.h);
        grad.addColorStop(0, fillTop);
        grad.addColorStop(1, tc.dark ? 'rgba(27, 24, 23, 0.0)' : 'rgba(255, 255, 255, 0.0)');

        ctx.save();
        ctx.beginPath();
        ctx.moveTo(pts[0].x, pts[0].y);
        for (let i = 0; i < pts.length - 1; i += 1) {
          const xc = (pts[i].x + pts[i + 1].x) / 2;
          ctx.bezierCurveTo(xc, pts[i].y, xc, pts[i + 1].y, pts[i + 1].x, pts[i + 1].y);
        }
        ctx.strokeStyle = stroke;
        ctx.lineWidth = 2.5;
        ctx.stroke();

        ctx.lineTo(pts[pts.length - 1].x, rect.y + rect.h);
        ctx.lineTo(pts[0].x, rect.y + rect.h);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();
        ctx.restore();
      }

      for (const p of pts) {
        ctx.save();
        ctx.beginPath();
        ctx.arc(p.x, p.y, pts.length === 1 ? 7 : 4.5, 0, Math.PI * 2);
        ctx.fillStyle = tc.surface;
        ctx.fill();
        ctx.lineWidth = 2.5;
        ctx.strokeStyle = stroke;
        ctx.stroke();
        ctx.restore();
      }

      for (let i = 0; i < n; i += 1) {
        const label = String(labels && labels[i] != null ? labels[i] : '');
        if (label === '') continue;
        drawText(ctx, label, getX(i), rect.y + rect.h + 20, {
          align: 'center',
          color: tc.label,
          font: '600 11px Inter, system-ui, sans-serif'
        });
      }
    };
    draw();
    window.addEventListener('resize', draw, { passive: true });
    window.addEventListener('enformasys:themechange', draw);
  };

  const doughnut = (canvas, labels, values, opts) => {
    opts = opts || {};
    const draw = () => {
      const tc = getThemeColors();
      const { ctx, width, height } = fitCanvas(canvas);
      clear(ctx, width, height);
      const cx = width / 2;
      const cy = height / 2;
      const r = Math.max(14, Math.min(width, height) * 0.4);
      const cutout = r * 0.72;
      const vals = (values || []).map((v) => Math.max(0, Number(v) || 0));
      const sum = vals.reduce((a, b) => a + b, 0);
      const colors = opts.colors || defaultPalette;
      let start = -Math.PI / 2;

      if (sum <= 0) {
        ctx.save();
        ctx.beginPath();
        ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.fillStyle = tc.emptyTrack;
        ctx.fill();
        ctx.restore();
      } else {
        for (let i = 0; i < vals.length; i += 1) {
          const v = vals[i];
          if (v <= 0) continue;
          const frac = v / sum;
          const end = start + frac * Math.PI * 2;
          ctx.save();
          ctx.beginPath();
          ctx.moveTo(cx, cy);
          ctx.arc(cx, cy, r, start, end);
          ctx.closePath();
          ctx.fillStyle = colors[i % colors.length];
          ctx.fill();
          ctx.restore();
          start = end;
        }
      }

      ctx.save();
      ctx.beginPath();
      ctx.arc(cx, cy, cutout, 0, Math.PI * 2);
      ctx.fillStyle = tc.surface;
      ctx.fill();
      ctx.restore();

      if (opts.centerValue) {
        drawText(ctx, String(opts.centerValue), cx, cy - 2, {
          align: 'center',
          baseline: 'middle',
          color: tc.centerValue,
          font: '800 22px "Plus Jakarta Sans", Inter, sans-serif'
        });
        drawText(ctx, String(opts.centerLabel || 'AVG RATING'), cx, cy + 18, {
          align: 'center',
          baseline: 'middle',
          color: tc.centerLabel,
          font: '700 9.5px Inter, sans-serif'
        });
      }
    };
    draw();
    window.addEventListener('resize', draw, { passive: true });
    window.addEventListener('enformasys:themechange', draw);
  };

  window.SimpleCharts = { bar, line, doughnut, wrapLabelLines };
})();
