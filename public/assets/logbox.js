(() => {
    'use strict';
    const states = new WeakMap();
    const normalizeLevel = level => {
        const value = String(level || '').toUpperCase();
        if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(value)) return 'ERROR';
        if (['WARNING', 'WARN'].includes(value)) return 'WARNING';
        if (['INFO', 'NOTICE', 'DEBUG'].includes(value)) return 'INFO';
        return null;
    };
    const lineLevel = line => {
        try {
            const record = JSON.parse(line);
            if (record && typeof record === 'object') return normalizeLevel(record.level);
        } catch { /* Older/plain-text log formats are handled by the fallback. */ }
        const match = line.match(/(?:^|[\s"'\[])level["']?\s*[:=]\s*["']?([a-z]+)/i) || line.match(/\b(INFO|NOTICE|DEBUG|WARNING|WARN|ERROR|CRITICAL|ALERT|EMERGENCY)\b/i);
        return normalizeLevel(match?.[1]);
    };
    const initialize = root => {
        const output = root.querySelector('.logbox-output');
        if (!output || states.has(output)) return states.get(output);
        const lines = (output.textContent || '').split('\n');
        const selected = new Set(['INFO', 'WARNING', 'ERROR']);
        const counts = {INFO: 0, WARNING: 0, ERROR: 0};
        const levels = lines.map(line => { const level = lineLevel(line); if (level) counts[level]++; return level; });
        const state = {lines, levels, selected, counts}; states.set(output, state);
        for (const level of Object.keys(counts)) {
            const count = root.querySelector(`[data-logbox-count="${level}"]`); if (count) count.textContent = String(counts[level]);
        }
        return state;
    };
    const apply = root => {
        const output = root.querySelector('.logbox-output'); const state = initialize(root); if (!output || !state) return;
        const visible = []; let shown = 0;
        state.lines.forEach((line, index) => { const level = state.levels[index]; if (level === null || state.selected.has(level)) { visible.push(line); if (level !== null) shown++; } });
        output.textContent = visible.join('\n').trim() || '\u0647\u06cc\u0686 \u0631\u0648\u06cc\u062f\u0627\u062f\u06cc \u0628\u0627 \u0641\u06cc\u0644\u062a\u0631\u0647\u0627\u06cc \u0641\u0639\u0644\u06cc \u067e\u06cc\u062f\u0627 \u0646\u0634\u062f.';
        const status = root.querySelector('[data-logbox-status]'); if (status) status.textContent = `${shown} \u0631\u0648\u06cc\u062f\u0627\u062f \u0627\u0632 ${state.levels.filter(Boolean).length}`;
    };
    document.addEventListener('shown.bs.modal', event => { if (event.target?.id === 'seo-logbox') apply(event.target); });
    document.addEventListener('click', async event => {
        const filter = event.target.closest('[data-logbox-level]');
        if (filter) {
            const root = filter.closest('#seo-logbox'); const state = initialize(root); const level = filter.dataset.logboxLevel;
            if (state.selected.has(level)) state.selected.delete(level); else state.selected.add(level);
            const active = state.selected.has(level); filter.classList.toggle('is-active', active); filter.setAttribute('aria-pressed', active ? 'true' : 'false'); apply(root); return;
        }
        const button = event.target.closest('[data-logbox-copy]');
        if (!button) return;
        const output = button.closest('#seo-logbox')?.querySelector('.logbox-output'); if (!output) return;
        const original = button.innerHTML;
        try { await navigator.clipboard.writeText(output.textContent || ''); button.textContent = '\u06a9\u067e\u06cc \u0634\u062f'; }
        catch { const selection = window.getSelection(); const range = document.createRange(); range.selectNodeContents(output); selection.removeAllRanges(); selection.addRange(range); button.textContent = '\u0645\u062a\u0646 \u0627\u0646\u062a\u062e\u0627\u0628 \u0634\u062f'; }
        window.setTimeout(() => { button.innerHTML = original; }, 1800);
    });
})();
