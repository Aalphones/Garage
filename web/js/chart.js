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
function yScale(values, minSpan) {
    const low = Math.min(...values);
    const high = Math.max(...values);
    const step = niceStep(Math.max(high - low, minSpan) / (GRID_LINES + 1));
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
 * `{ points, forecast, yLabel, ariaLabel, containerWidth, minSpan }` → SVG-String; `minSpan` ist die kleinste Spanne der y-Achse. `points`/`forecast`: `[{ time (ms), value, label, title? }]`. Viewbox 640×220 ab 600 px Containerbreite, sonst 340×200.
 * Jeder echte Punkt ist ein `<g data-point="<Index>" tabindex="0" role="button">` mit `<title>`; der Aufrufer hängt das Popover an.
 */
export function lineChart({ points, forecast = [], yLabel, ariaLabel, containerWidth = WIDE_MIN_CONTAINER_PX, minSpan = 1 }) {
    const isNarrow = containerWidth < WIDE_MIN_CONTAINER_PX;
    const layout = isNarrow ? NARROW : WIDE;
    const all = [...points, ...forecast];
    const times = all.map((point) => point.time);
    const firstTime = Math.min(...times);
    const timeSpan = Math.max(...times) - firstTime;
    const scale = yScale(all.map((point) => point.value), minSpan);
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

// --- Gestapelte Säulen -----------------------------------------------------------------------------------------

const BARS_NARROW = { width: 340, height: 190, maxLabels: 7, minLabelStep: 2 };
const BARS_WIDE = { maxWidth: 900, height: 210, maxLabels: 13, minLabelStep: 1 };
const BARS_PADDING = { left: 52, right: 8, top: 12, bottom: 24 };
const BAR_FILL_RATIO = 0.64;
const BAR_GAP = 2;
const BAR_FONT_SIZE = 12;

/** Runde Obergrenze, deren Hälfte auch rund ist. */
function barMaximum(totals) {
    const highest = Math.max(...totals, 0);
    if (highest <= 0) {
        return 2;
    }

    return niceStep(highest / 2) * 2;
}

/**
 * `{ months, series, ariaLabel, containerWidth }` → SVG-String. `months`: Beschriftungen; `series`: `[{ key, label, color, values }]`,
 * von unten nach oben gestapelt, `color` als CSS-Farbe. Nur Werte über 0 werden gezeichnet. Jedes Segment trägt
 * `data-month="<Index>" data-series="<key>"`; der Aufrufer hängt das Popover an.
 */
export function stackedBars({ months, series, ariaLabel, containerWidth = WIDE_MIN_CONTAINER_PX }) {
    const isNarrow = containerWidth < WIDE_MIN_CONTAINER_PX;
    const layout = isNarrow ? BARS_NARROW : { ...BARS_WIDE, width: Math.min(Math.round(containerWidth), BARS_WIDE.maxWidth) };
    const totals = months.map((month, index) => series.reduce((total, entry) => total + Math.max(entry.values[index], 0), 0));
    const maximum = barMaximum(totals);
    const plotWidth = layout.width - BARS_PADDING.left - BARS_PADDING.right;
    const plotHeight = layout.height - BARS_PADDING.top - BARS_PADDING.bottom;
    const slot = plotWidth / months.length;
    const vertical = (value) => BARS_PADDING.top + (1 - value / maximum) * plotHeight;

    let grid = '';
    for (const value of [0, maximum / 2, maximum]) {
        const lineY = vertical(value).toFixed(1);
        grid += '<line x1="' + BARS_PADDING.left + '" x2="' + (layout.width - BARS_PADDING.right) + '" y1="' + lineY + '" y2="' + lineY + '" stroke="var(--line)"/>';
        grid += '<text x="' + (BARS_PADDING.left - 6) + '" y="' + (Number(lineY) + 4) + '" text-anchor="end" font-size="' + BAR_FONT_SIZE + '" fill="var(--muted)">'
            + escapeXml(formatTick(value, maximum / 2) + ' €') + '</text>';
    }

    let bars = '';
    months.forEach((month, index) => {
        let stacked = 0;
        for (const entry of series) {
            const value = entry.values[index];
            if (value <= 0) {
                continue;
            }
            const bottom = vertical(stacked);
            stacked += value;
            const top = vertical(stacked);
            const title = escapeXml(month + ' · ' + entry.label + ': ' + value.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }));
            bars += '<rect x="' + (BARS_PADDING.left + index * slot + (slot * (1 - BAR_FILL_RATIO)) / 2).toFixed(1) + '" y="' + top.toFixed(1)
                + '" width="' + (slot * BAR_FILL_RATIO).toFixed(1) + '" height="' + Math.max(0, bottom - top).toFixed(1)
                + '" fill="' + escapeXml(entry.color) + '" stroke="var(--surface)" stroke-width="' + BAR_GAP
                + '" data-month="' + index + '" data-series="' + escapeXml(entry.key) + '" tabindex="0" role="button" aria-label="' + title + '"><title>' + title + '</title></rect>';
        }
    });

    const labelStep = Math.max(layout.minLabelStep, Math.ceil(months.length / layout.maxLabels));
    let labels = '';
    for (let index = 0; index < months.length; index += labelStep) {
        labels += '<text x="' + (BARS_PADDING.left + index * slot + slot / 2).toFixed(1) + '" y="' + (layout.height - 6) + '" text-anchor="middle" font-size="' + BAR_FONT_SIZE + '" fill="var(--muted)">' + escapeXml(months[index]) + '</text>';
    }

    return '<svg viewBox="0 0 ' + layout.width + ' ' + layout.height + '" role="group" aria-label="' + escapeXml(ariaLabel) + '">' + grid + '<g class="bars">' + bars + '</g>' + labels + '</svg>';
}

// --- Wert: Verlauf mit Band und Inserate-Streuung ------------------------------------------------------------------

const VALUE_PADDING = { left: 56, right: 14, top: 14, bottom: 30 };
const VALUE_MIN_WIDTH = 340;
const VALUE_WIDE_HEIGHT = 220;
const VALUE_MIN_SPAN_EURO = 2000;
const VALUE_MIN_SPAN_KM = 10000;
const SINGLE_BAND_WIDTH = 10;
const DOT_RADIUS = 5;
const ANCHOR_RADIUS = 6;
const BAND_FILL = 'color-mix(in oklab, var(--primary) 18%, transparent)';

/** Die Viewbox folgt der Containerbreite, damit die Schrift neben- wie untereinander gleich groß bleibt. */
function valueLayout(containerWidth) {
    const width = Math.min(Math.max(Math.round(containerWidth), VALUE_MIN_WIDTH), WIDE.width);
    const isNarrow = width < WIDE_MIN_CONTAINER_PX;
    const height = isNarrow ? NARROW.height : VALUE_WIDE_HEIGHT;

    return { width, height, isNarrow, hitRadius: isNarrow ? NARROW.hitRadius : WIDE.hitRadius, right: width - VALUE_PADDING.right, bottom: height - VALUE_PADDING.bottom };
}

/** Bildet `[from, to]` linear auf `[start, end]` ab; ohne Spanne liegt alles in der Mitte. */
function linear(from, to, start, end) {
    return (value) => (to === from ? (start + end) / 2 : start + ((value - from) / (to - from)) * (end - start));
}

function textMarkup(horizontal, vertical, anchor, text, fill = 'var(--muted)') {
    return '<text x="' + horizontal.toFixed(1) + '" y="' + vertical.toFixed(1) + '" text-anchor="' + anchor + '" font-size="' + FONT_SIZE + '" fill="' + fill + '">' + escapeXml(text) + '</text>';
}

function euroGrid(layout, scale, vertical) {
    let markup = '';
    for (let line = 0; line <= GRID_LINES + 1; line += 1) {
        const value = scale.min + scale.step * line;
        const lineY = vertical(value);
        markup += '<line x1="' + VALUE_PADDING.left + '" x2="' + layout.right + '" y1="' + lineY.toFixed(1) + '" y2="' + lineY.toFixed(1) + '" stroke="var(--line)"/>';
        markup += textMarkup(VALUE_PADDING.left - 6, lineY + 4, 'end', (value / 1000).toLocaleString('de-DE', { maximumFractionDigits: 2 }) + 'k €');
    }

    return markup;
}

/** Antippbarer Punkt: unsichtbare Trefferfläche um den sichtbaren Kreis; `data-point` nennt den Index. */
function dot(layout, index, centerX, centerY, title, circleAttributes) {
    const position = 'cx="' + centerX.toFixed(1) + '" cy="' + centerY.toFixed(1) + '"';

    // Halber Radius: die Punkte liegen eng, volle Trefferflächen würden Nachbarn verdecken.
    return '<g data-point="' + index + '" tabindex="0" role="button" aria-label="' + escapeXml(title) + '"><title>' + escapeXml(title) + '</title>'
        + '<circle ' + position + ' r="' + layout.hitRadius / 2 + '" fill="transparent"/><circle ' + position + ' ' + circleAttributes + '/></g>';
}

function valueSvg(layout, ariaLabel, markup) {
    return '<svg viewBox="0 0 ' + layout.width + ' ' + layout.height + '" role="group" aria-label="' + escapeXml(ariaLabel) + '">' + markup + '</svg>';
}

function bandMarkup(band, horizontal, vertical) {
    if (band.length === 1) {
        const top = vertical(band[0].high);

        return '<rect x="' + (horizontal(band[0].time) - SINGLE_BAND_WIDTH / 2).toFixed(1) + '" y="' + top.toFixed(1) + '" width="' + SINGLE_BAND_WIDTH
            + '" height="' + (vertical(band[0].low) - top).toFixed(1) + '" fill="' + BAND_FILL + '"/>';
    }
    const upper = band.map((point) => horizontal(point.time).toFixed(1) + ',' + vertical(point.high).toFixed(1));
    const lower = band.map((point) => horizontal(point.time).toFixed(1) + ',' + vertical(point.low).toFixed(1)).reverse();

    return '<polygon points="' + [...upper, ...lower].join(' ') + '" fill="' + BAND_FILL + '"/>';
}

/** Beschriftet wird nur, was mit Abstand zur vorigen Beschriftung Platz hat. */
function timeLabels(layout, points, horizontal) {
    const minimumGap = layout.isNarrow ? 52 : 60;
    let markup = '';
    let lastX = -Infinity;
    for (const point of points) {
        const labelX = horizontal(point.time);
        if (labelX - lastX >= minimumGap) {
            markup += textMarkup(Math.min(labelX, layout.width - 24), layout.height - 8, 'middle', point.label);
            lastX = labelX;
        }
    }

    return markup;
}

/**
 * Wertverlauf in Euro → SVG-String. `points`: `[{ time (ms), value, low, high, isAnchor, label, title }]`, nach Zeit
 * aufsteigend. Ankerwerte sind hohle Punkte (ohne `low`/`high`); die übrigen verbindet eine Linie über dem Band `low`–`high`.
 */
export function bandLine({ points, ariaLabel, containerWidth = WIDE_MIN_CONTAINER_PX }) {
    const layout = valueLayout(containerWidth);
    const band = points.filter((point) => !point.isAnchor);
    const scale = yScale(points.flatMap((point) => (point.isAnchor ? [point.value] : [point.value, point.low, point.high])), VALUE_MIN_SPAN_EURO);
    const times = points.map((point) => point.time);
    const horizontal = linear(Math.min(...times), Math.max(...times), VALUE_PADDING.left + ANCHOR_RADIUS, layout.right - ANCHOR_RADIUS);
    const vertical = linear(scale.min, scale.max, layout.bottom, VALUE_PADDING.top);

    let markup = euroGrid(layout, scale, vertical) + timeLabels(layout, points, horizontal);
    if (band.length > 0) {
        markup += bandMarkup(band, horizontal, vertical);
        markup += polyline(band.map((point) => [horizontal(point.time), vertical(point.value)]), 'stroke-width="2"');
    }
    points.forEach((point, index) => {
        let attributes = 'r="' + DOT_RADIUS + '" fill="var(--primary)" stroke="var(--surface)" stroke-width="2"';
        if (point.isAnchor) {
            attributes = 'r="' + ANCHOR_RADIUS + '" fill="var(--surface)" stroke="var(--primary)" stroke-width="2"';
        }
        markup += dot(layout, index, horizontal(point.time), vertical(point.value), point.title, attributes);
    });

    return valueSvg(layout, ariaLabel, markup);
}

function ownMarker(layout, ownX) {
    const isRightHalf = ownX > layout.width / 2;

    return '<line x1="' + ownX.toFixed(1) + '" x2="' + ownX.toFixed(1) + '" y1="' + VALUE_PADDING.top + '" y2="' + layout.bottom + '" stroke="var(--primary)" stroke-width="2" stroke-dasharray="5 4"/>'
        + textMarkup(ownX + (isRightHalf ? -5 : 5), VALUE_PADDING.top + 11, isRightHalf ? 'end' : 'start', 'dein Stand', 'var(--text)');
}

/**
 * Inserate, Preis (Euro) gegen km → SVG-String. `points`: `[{ km, price, counts, title }]`; zählende gefüllt, übrige hohl.
 * `ownKm` (oder `null`) wird eine gestrichelte Senkrechte, `band` `{ low, high }` die mittlere Hälfte.
 */
export function scatter({ points, ownKm, band, ariaLabel, containerWidth = WIDE_MIN_CONTAINER_PX }) {
    const layout = valueLayout(containerWidth);
    const kms = points.map((point) => point.km);
    if (ownKm !== null) {
        kms.push(ownKm);
    }
    const kmScale = yScale(kms, VALUE_MIN_SPAN_KM);
    const priceScale = yScale([...points.map((point) => point.price), band.low, band.high], VALUE_MIN_SPAN_EURO);
    const horizontal = linear(kmScale.min, kmScale.max, VALUE_PADDING.left, layout.right);
    const vertical = linear(priceScale.min, priceScale.max, layout.bottom, VALUE_PADDING.top);

    let markup = euroGrid(layout, priceScale, vertical);
    for (const [km, anchor] of [[kmScale.min, 'start'], [(kmScale.min + kmScale.max) / 2, 'middle'], [kmScale.max, 'end']]) {
        markup += textMarkup(horizontal(km), layout.height - 8, anchor, (km / 1000).toLocaleString('de-DE', { maximumFractionDigits: 1 }) + 'k km');
    }
    const top = vertical(band.high);
    markup += '<rect x="' + VALUE_PADDING.left + '" y="' + top.toFixed(1) + '" width="' + (layout.right - VALUE_PADDING.left) + '" height="' + (vertical(band.low) - top).toFixed(1) + '" fill="' + BAND_FILL + '"/>';
    if (ownKm !== null) {
        markup += ownMarker(layout, horizontal(ownKm));
    }
    points.forEach((point, index) => {
        let attributes = 'r="' + DOT_RADIUS + '" fill="var(--surface)" stroke="var(--muted)" stroke-width="2"';
        if (point.counts) {
            attributes = 'r="' + DOT_RADIUS + '" fill="var(--s1)" stroke="var(--surface)" stroke-width="2"';
        }
        markup += dot(layout, index, horizontal(point.km), vertical(point.price), point.title, attributes);
    });

    return valueSvg(layout, ariaLabel, markup);
}

/** Tippen oder Enter auf ein Diagrammelement, das `selector` trifft. */
export function onChartActivate(chart, selector, activate) {
    chart.addEventListener('click', (event) => {
        const target = event.target.closest(selector);
        if (target !== null) {
            activate(target);
        }
    });
    chart.addEventListener('keydown', (event) => {
        const target = event.target.closest(selector);
        if (target !== null && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            activate(target);
        }
    });
}
