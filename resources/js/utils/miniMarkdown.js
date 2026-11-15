/**
 * Renderizador mínimo y seguro de Markdown para apuntes de estudio.
 *
 * Seguridad: escapa todo HTML antes de transformar sintaxis (cero riesgo XSS),
 * sin dependencias externas (~2 kB). Soporta encabezados, negrita, cursiva,
 * resaltado (==texto==), código en línea, citas, listas y tablas contables.
 */

function escapeHtml(raw) {
    return String(raw || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatInline(escapedLine) {
    return escapedLine
        .replace(/`([^`\n]+)`/g, '<code class="rounded px-1 py-0.5 text-[11px] font-mono bg-black/10 dark:bg-white/10">$1</code>')
        .replace(/\*\*([^*\n]+)\*\*/g, '<strong class="font-bold">$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em class="italic">$2</em>')
        .replace(/==([^=\n]+)==/g, '<mark class="rounded px-1 bg-amber-300/40 dark:bg-amber-400/25 text-inherit">$1</mark>');
}

function splitTableRow(line) {
    return line
        .trim()
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .split('|')
        .map((cell) => cell.trim());
}

function isTableSeparator(line) {
    const trimmed = line.trim();
    if (!trimmed.includes('|')) return false;
    const cells = splitTableRow(trimmed);
    return cells.length >= 2 && cells.every((c) => /^:?-{3,}:?$/.test(c));
}

export function renderMiniMarkdown(rawText) {
    const escaped = escapeHtml(rawText).replace(/\r\n/g, '\n');
    const lines = escaped.split('\n');
    const html = [];

    let i = 0;
    while (i < lines.length) {
        const line = lines[i];
        const trimmed = line.trim();

        // Línea vacía
        if (!trimmed) {
            i += 1;
            continue;
        }

        // Tabla Markdown (ej. Asiento contable Debe / Haber)
        if (trimmed.includes('|') && i + 1 < lines.length && isTableSeparator(lines[i + 1])) {
            const headers = splitTableRow(line);
            const alignments = splitTableRow(lines[i + 1]).map((sep) => {
                if (sep.startsWith(':') && sep.endsWith(':')) return 'text-center';
                if (sep.endsWith(':')) return 'text-right';
                return 'text-left';
            });

            let tableHtml = '<div class="my-2 overflow-x-auto rounded-lg border border-current/15"><table class="w-full text-[11px] border-collapse">';
            tableHtml += '<thead><tr class="border-b border-current/15 bg-black/5 dark:bg-white/5">';
            headers.forEach((cell, idx) => {
                tableHtml += `<th class="px-2.5 py-1.5 font-bold ${alignments[idx] || 'text-left'}">${formatInline(cell)}</th>`;
            });
            tableHtml += '</tr></thead><tbody>';

            i += 2;
            while (i < lines.length && lines[i].trim().includes('|') && !isTableSeparator(lines[i])) {
                const cells = splitTableRow(lines[i]);
                tableHtml += '<tr class="border-b border-current/10 last:border-b-0">';
                headers.forEach((_, idx) => {
                    tableHtml += `<td class="px-2.5 py-1.5 ${alignments[idx] || 'text-left'}">${formatInline(cells[idx] ?? '')}</td>`;
                });
                tableHtml += '</tr>';
                i += 1;
            }
            tableHtml += '</tbody></table></div>';
            html.push(tableHtml);
            continue;
        }

        // Encabezados (#, ##, ###)
        const headingMatch = trimmed.match(/^(#{1,3})\s+(.+)$/);
        if (headingMatch) {
            const level = headingMatch[1].length;
            const cls =
                level === 1
                    ? 'text-sm font-bold mt-3 mb-1.5'
                    : level === 2
                      ? 'text-[13px] font-bold mt-2.5 mb-1'
                      : 'text-xs font-bold mt-2 mb-1';
            html.push(`<h${level} class="${cls}">${formatInline(headingMatch[2])}</h${level}>`);
            i += 1;
            continue;
        }

        // Cita (>)
        if (trimmed.startsWith('&gt; ')) {
            const quoteLines = [];
            while (i < lines.length && lines[i].trim().startsWith('&gt; ')) {
                quoteLines.push(formatInline(lines[i].trim().slice(5)));
                i += 1;
            }
            html.push(
                `<blockquote class="my-1.5 border-l-2 border-sky-500/60 pl-2.5 italic opacity-85">${quoteLines.join('<br>')}</blockquote>`
            );
            continue;
        }

        // Lista con viñetas (- o *)
        if (/^[-*]\s+/.test(trimmed)) {
            const items = [];
            while (i < lines.length && /^[-*]\s+/.test(lines[i].trim())) {
                items.push(`<li>${formatInline(lines[i].trim().replace(/^[-*]\s+/, ''))}</li>`);
                i += 1;
            }
            html.push(`<ul class="my-1.5 list-disc pl-4 space-y-0.5">${items.join('')}</ul>`);
            continue;
        }

        // Lista numerada (1. )
        if (/^\d+\.\s+/.test(trimmed)) {
            const items = [];
            while (i < lines.length && /^\d+\.\s+/.test(lines[i].trim())) {
                items.push(`<li>${formatInline(lines[i].trim().replace(/^\d+\.\s+/, ''))}</li>`);
                i += 1;
            }
            html.push(`<ol class="my-1.5 list-decimal pl-4 space-y-0.5">${items.join('')}</ol>`);
            continue;
        }

        // Párrafo normal (agrupa líneas seguidas, deteniéndose ante bloques especiales)
        const paraLines = [];
        while (
            i < lines.length &&
            lines[i].trim() !== '' &&
            !/^(#{1,3})\s+/.test(lines[i].trim()) &&
            !lines[i].trim().startsWith('&gt; ') &&
            !/^[-*]\s+/.test(lines[i].trim()) &&
            !/^\d+\.\s+/.test(lines[i].trim()) &&
            !(lines[i].trim().includes('|') && i + 1 < lines.length && isTableSeparator(lines[i + 1]))
        ) {
            paraLines.push(formatInline(lines[i]));
            i += 1;
        }
        html.push(`<p class="my-1.5 leading-relaxed">${paraLines.join('<br>')}</p>`);
    }

    return html.join('');
}

/**
 * Limpia la sintaxis Markdown para mostrar un resumen legible en las tarjetas del listado.
 */
export function stripMarkdown(rawText) {
    return String(rawText || '')
        .replace(/^#{1,3}\s+/gm, '')
        .replace(/\*\*([^*\n]+)\*\*/g, '$1')
        .replace(/\*([^*\n]+)\*/g, '$1')
        .replace(/==([^=\n]+)==/g, '$1')
        .replace(/`([^`\n]+)`/g, '$1')
        .replace(/^>\s+/gm, '')
        .replace(/\|/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}
