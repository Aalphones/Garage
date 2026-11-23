// Liniendiagramm als SVG-String (ADR-018). Farben nur über CSS-Variablen, damit hell und dunkel ohne Neuzeichnen stimmen.
const WIDE_MIN_CONTAINER_PX = 600;
const WIDE = { width: 640, height: 220, hitRadius: 16 };
const NARROW = { width: 340, height: 200, hitRadius: 22 };
const PADDING = { left: 56, right: 12, top: 26, bottom: 30 };
/** Nach der Skalierung auf Handybreite (etwa 0,9) bleibt die Schrift bei mindestens 11 px. */
const FONT_SIZE = 12.5;
const GRID_LINES = 3;
const POINT_RADIUS = 3.5;
const MAX_LABELS_WIDE = 8;
const MAX_LABELS_NARROW = 4;
const NICE_STEPS = [1, 2, 2.5, 5, 10];

function escapeXml(text) {
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function niceStep(rawStep) {
    const magnitude = 10 ** Math.floor(Math.log10(rawStep));
    const scaled = rawStep / magnitude;
    const factor = NICE_STEPS.find((candidate) => candidate >= scaled);

    return factor * magnitude;
}

/** Wertebereich mit `GRID_LINES + 1` gleichen Abschnitten auf runden Schritten. */
function yScale(values) {
    const low = Math.min(...values);
    const high = Math.max(...values);
    const step = niceStep(Math.max(high - low, 1) / (GRID_LINES + 1));
    const min = Math.floor(low / step) * step;

    return { min, max: min + step * (GRID_LINES + 1), step };
}

function formatTick(value, step) {
    return value.toLocaleString('de-DE', { maximumFractionDigits: step < 1 ? 2 : 0 });
}

/** Die Beschriftungen laufen über alle Punkte; bei vielen Punkten und am Handy nur jede n-te. */
function visibleLabelIndexes(count, isNarrow) {
    const maxLabels = isNarrow ? MAX_LABELS_NARROW : MAX_LABELS_WIDE;
    const step = Math.max(isNarrow ? 2 : 1, Math.ceil(count / maxLabels));
    const indexes = [];
    for (let index = 0; index < count; index += step) {
        indexes.push(index);
    }

    return indexes;
}

function polyline(coordinates, extraAttributes) {
    const points = coordinates.map(([horizontal, vertical]) => horizontal.toFixed(1) + ',' + vertical.toFixed(1)).join(' ');

    return '<polyline points="' + points + '" fill="none" stroke="var(--primary)" ' + extraAttributes + '/>';
}

/**
 * `{ points, forecast, yLabel, ariaLabel, containerWidth }` → SVG-String. `points`/`forecast`: `[{ time (ms), value, label, title? }]`. Viewbox 640×220 ab 600 px Containerbreite, sonst 340×200.
 * Jeder echte Punkt ist ein `<g data-point="<Index>" tabindex="0" role="button">` mit `<title>`; der Aufrufer hängt das Popover an.
 */
export function lineChart({ points, forecast = [], yLabel, ariaLabel, containerWidth = WIDE_MIN_CONTAINER_PX }) {
    const isNarrow = containerWidth < WIDE_MIN_CONTAINER_PX;
    const layout = isNarrow ? NARROW : WIDE;
    const all = [...points, ...forecast];
    const times = all.map((point) => point.time);
    const firstTime = Math.min(...times);
    const timeSpan = Math.max(...times) - firstTime;
    const scale = yScale(all.map((point) => point.value));
    const plotWidth = layout.width - PADDING.left - PADDING.right;
    const plotHeight = layout.height - PADDING.top - PADDING.bottom;

    const horizontal = (time) => (timeSpan === 0 ? PADDING.left + plotWidth / 2 : PADDING.left + ((time - firstTime) / timeSpan) * plotWidth);
    const vertical = (value) => PADDING.top + (1 - (value - scale.min) / (scale.max - scale.min)) * plotHeight;

    let markup = '';
    for (let line = 1; line <= GRID_LINES; line += 1) {
        const value = scale.min + scale.step * line;
        const lineY = vertical(value).toFixed(1);
        markup += '<line x1="' + PADDING.left + '" x2="' + (layout.width - PADDING.right) + '" y1="' + lineY + '" y2="' + lineY + '" stroke="var(--line)"/>';
        markup += '<text x="' + (PADDING.left - 6) + '" y="' + (Number(lineY) + 4) + '" text-anchor="end" font-size="' + FONT_SIZE + '" fill="var(--muted)">' + escapeXml(formatTick(value, scale.step)) + '</text>';
    }
    if (yLabel !== undefined && yLabel !== '') {
        markup += '<text x="' + PADDING.left + '" y="' + (PADDING.top - 10) + '" font-size="' + FONT_SIZE + '" fill="var(--muted)">' + escapeXml(yLabel) + '</text>';
    }
    for (const index of visibleLabelIndexes(all.length, isNarrow)) {
        markup += '<text x="' + horizontal(all[index].time).toFixed(1) + '" y="' + (layout.height - 8) + '" text-anchor="middle" font-size="' + FONT_SIZE + '" fill="var(--muted)">' + escapeXml(all[index].label) + '</text>';
    }

    markup += polyline(points.map((point) => [horizontal(point.time), vertical(point.value)]), 'stroke-width="2.5"');
    if (forecast.length > 0) {
        const lastPoint = points[points.length - 1];
        const path = [lastPoint, ...forecast].map((point) => [horizontal(point.time), vertical(point.value)]);
        markup += polyline(path, 'stroke-width="2" stroke-dasharray="6 5"');
    }

    points.forEach((point, index) => {
        const title = escapeXml(point.title ?? point.label + ': ' + point.value);
        const centerX = horizontal(point.time).toFixed(1);
        const centerY = vertical(point.value).toFixed(1);
        markup += '<g data-point="' + index + '" tabindex="0" role="button" aria-label="' + title + '"><title>' + title + '</title>'
            + '<circle cx="' + centerX + '" cy="' + centerY + '" r="' + layout.hitRadius + '" fill="transparent"/>'
            + '<circle cx="' + centerX + '" cy="' + centerY + '" r="' + POINT_RADIUS + '" fill="var(--primary)"/></g>';
    });

    return '<svg viewBox="0 0 ' + layout.width + ' ' + layout.height + '" role="img" aria-label="' + escapeXml(ariaLabel) + '">' + markup + '</svg>';
}
