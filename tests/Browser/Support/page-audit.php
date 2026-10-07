<?php

/**
 * Shared by PageAuditTest and ScenarioTest: runs in the page and returns axe (critical/serious),
 * JavaScript errors, an error-page title and layout findings, or null for non-HTML responses.
 */
const PAGE_AUDIT_SCRIPT = <<<'JS'
async () => {
    if (document.readyState !== 'complete') {
        await new Promise((resolve) => addEventListener('load', resolve, { once: true }));
    }
    if (!document.contentType.startsWith('text/html')) {
        return null;
    }
    // Livewire's navigation progress bar (<div role="bar">) lingers briefly after a redirect; audit the settled page.
    for (let i = 0; i < 50 && document.querySelector('#nprogress'); i++) {
        await new Promise((resolve) => setTimeout(resolve, 100));
    }
    const out = { error: null, js: [], axe: [], layout: [] };
    if (/server error|not found|forbidden|page expired|unauthorized/i.test(document.title)) {
        out.error = document.title;
    }
    out.js = [...new Set((window.__pestBrowser?.jsErrors ?? []).map((e) => String(e.message).slice(0, 120)))].sort();

    const axe = await window.axe.run(document, { resultTypes: ['violations'] });
    const violations = axe.violations.filter((v) => ['critical', 'serious'].includes(v.impact));
    out.axe = [...new Set(violations.map((v) => v.id))].sort();
    // Which elements failed, so a fix can be traced back to its template (see PAGE_AUDIT_DETAILS).
    const describe = (target) => {
        const el = document.querySelector(target);
        if (!el) return target;
        const key = [...el.attributes].find((a) => /^(wire:model|name|id|href)/.test(a.name));
        const text = (el.innerText || '').trim().replace(/\s+/g, ' ').slice(0, 30);
        return `${el.tagName.toLowerCase()}${key ? `[${key.name}=${key.value.slice(0, 40)}]` : ''}${text ? ` "${text}"` : ''}`;
    };
    out.details = Object.fromEntries(violations.map((v) => [v.id, [...new Set(v.nodes.map((n) => describe(n.target[0])))]]));

    const viewport = document.documentElement.clientWidth;
    const label = (kind, el) => {
        // Issue numbers differ between runs, so keep them out of the (baselined) finding text.
        const text = (el.innerText || el.value || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').replace(/#\d+/g, '#N').slice(0, 40);
        out.layout.push(`${kind}: ${el.tagName.toLowerCase()}${text ? ` "${text}"` : ''}`);
    };
    const style = (el) => getComputedStyle(el);
    const scrollsX = (el) => /auto|scroll/.test(style(el).overflowX);
    const clipsX = (el) => /hidden|clip/.test(style(el).overflowX);
    const boxed = (el) => {
        const s = style(el);
        return parseFloat(s.borderRightWidth) > 0 || !['rgba(0, 0, 0, 0)', 'transparent'].includes(s.backgroundColor);
    };

    if (document.scrollingElement.scrollWidth > viewport + 1) {
        out.layout.push('page: horizontal scroll');
    }

    // Tables, code blocks and [data-scroll-x] boxes (e.g. the Gantt chart) may scroll sideways; anything else hiding content behind a scrollbar
    // (e.g. a header menu whose scrollbar macOS hides) reads as cut off.
    for (const el of document.body.querySelectorAll('*')) {
        if (el.offsetParent === null || !scrollsX(el) || el.scrollWidth <= el.clientWidth + 1) continue;
        if (el.hasAttribute('data-scroll-x') || el.querySelector('table, pre, code') || /^(TABLE|PRE|CODE|TEXTAREA)$/.test(el.tagName)) continue;
        label('hidden-scroll', el);
    }

    for (const el of document.body.querySelectorAll('*')) {
        if (el.offsetParent === null || style(el).visibility === 'hidden') continue;
        const isControl = /^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(el.tagName);
        const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim());
        if (!isControl && !ownText) continue;
        const rect = el.getBoundingClientRect();
        if (rect.width <= 1 || rect.height <= 1) continue;

        let finding = null;
        let insideScroller = false;
        let clippedByAncestor = false; // text cut off inside a clipping box cannot widen the page
        for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
            if (scrollsX(a)) { insideScroller = true; break; }
            const box = a.getBoundingClientRect();
            const escapes = rect.right > box.right + 1 || rect.left < box.left - 1;
            if (clipsX(a)) { clippedByAncestor = true; if (escapes && !el.title && !a.title) finding = 'clipped'; break; } // a title shows the full text
            if (boxed(a)) { if (escapes) finding = 'overflow'; break; }
        }
        if (!finding && !insideScroller && !clippedByAncestor && rect.right > viewport + 1) finding = 'overflow';
        if (!finding && ownText && !el.title && !el.getAttribute('aria-label')) {
            const s = style(el);
            if ((clipsX(el) || s.textOverflow === 'ellipsis') && el.scrollWidth > el.clientWidth + 1) finding = 'clipped';
        }
        if (finding) label(finding, el);
    }

    // A dropdown (<details> panel) inside a box that clips overflow can never be seen when opened.
    for (const el of document.body.querySelectorAll('details')) {
        if (el.offsetParent === null) continue;
        for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
            const s = style(a);
            if (s.overflowX !== 'visible' || s.overflowY !== 'visible') { label('popover-clipped', el.querySelector('summary') ?? el); break; }
        }
    }

    // A short button label squeezed onto two lines ("CSVエクスポー / ト").
    for (const el of document.body.querySelectorAll('button, a')) {
        const text = (el.innerText || '').trim();
        if (el.offsetParent === null || !boxed(el) || text.length === 0 || text.length > 20 || text.includes('\n')) continue;
        const s = style(el);
        const lineHeight = parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.5;
        const content = el.clientHeight - parseFloat(s.paddingTop) - parseFloat(s.paddingBottom);
        if (content > lineHeight * 1.5) label('wrapped-label', el);
    }

    // A select's chosen text cut off under its arrow.
    const canvas = document.createElement('canvas').getContext('2d');
    for (const el of document.body.querySelectorAll('select:not([multiple])')) {
        if (el.offsetParent === null || !el.selectedOptions[0]) continue;
        const s = style(el);
        canvas.font = s.font;
        const room = el.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
        if (canvas.measureText(el.selectedOptions[0].text).width > room + 1) label('select-cut', el);
    }

    // Inputs, selects and buttons sitting side by side at different heights.
    const controls = [...document.body.querySelectorAll(
        'select, button, input:not([type=checkbox]):not([type=radio]):not([type=hidden]):not([type=file]), textarea:not([rows])'
    )].filter((el) => el.offsetParent !== null && boxed(el)); // link-styled buttons are meant to be shorter
    for (const parent of new Set(controls.map((el) => el.parentElement))) {
        const row = controls.filter((el) => el.parentElement === parent).map((el) => el.getBoundingClientRect());
        for (let i = 0; i < row.length; i++) {
            for (let j = i + 1; j < row.length; j++) {
                const sameLine = row[i].top < row[j].bottom && row[j].top < row[i].bottom;
                if (sameLine && Math.abs(row[i].height - row[j].height) > 4) { label('height-mismatch', parent); i = row.length; break; }
            }
        }
    }

    out.layout = [...new Set(out.layout)].sort();
    return out;
}
JS;

/**
 * Flattens one audit result into comparable "kind: finding" strings.
 *
 * @param  array{error: ?string, js: list<string>, axe: list<string>, layout: list<string>}  $result
 * @return list<string>
 */
function pageAuditFindings(array $result): array
{
    return [
        ...($result['error'] !== null ? ["error page: {$result['error']}"] : []),
        ...array_map(fn (string $message) => "JavaScript error: {$message}", $result['js']),
        ...array_map(fn (string $rule) => "axe {$rule}", $result['axe']),
        ...$result['layout'],
    ];
}
