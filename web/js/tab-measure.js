// Reiter „Messwerte“: Kilometerverlauf mit Prognose, Profiltiefe je Rad, übrige Werte mit jüngstem Stand.
import { request } from './api.js';
import { actionButton, clearChildren, element, formatDay } from './dom.js';
import { lineChart } from './chart.js';
import * as icons from './icons.js';
import { badge, closeTip, fillProvenance, tipContent } from './provenance.js';
import { deleteRecord } from './record-delete.js';
import { emptyBlock, errorBlock, formatKm, stateWord } from './widgets.js';

const DAY_MS = 86_400_000;
const FORECAST_MONTHS = 4;
const TREAD_LEGAL_MM = 1.6;
const TREAD_RECOMMENDED_MM = 3;
const WHEEL_POSITIONS = ['vl', 'vr', 'hl', 'hr'];
const WHEEL_LABELS = { vl: 'VL', vr: 'VR', hl: 'HL', hr: 'HR', vorne: 'Vorne', hinten: 'Hinten', gesamt: 'Gesamt' };
const POSITION_WORDS = { vl: 'vorn links', vr: 'vorn rechts', hl: 'hinten links', hr: 'hinten rechts', vorne: 'vorne', hinten: 'hinten' };

const QUANTITY_LABELS = {
    profiltiefe: 'Profiltiefe',
    reifendruck: 'Reifendruck',
    bremsbelag: 'Bremsbelag',
    bremsscheibe: 'Bremsscheibe',
    batterie_spannung: 'Batteriespannung',
    batterie_kaltstart: 'Kaltstartstrom',
    frostschutz: 'Frostschutz',
    hu_bremswert: 'HU-Bremswert',
};

function dayTime(day) {
    return Date.parse(day.slice(0, 10) + 'T00:00:00Z');
}

function monthLabel(time) {
    const date = new Date(time);

    return String(date.getUTCMonth() + 1).padStart(2, '0') + '/' + date.getUTCFullYear();
}

function monthName(time) {
    return new Date(time).toLocaleDateString('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

function formatValue(value, unit) {
    return value.toLocaleString('de-DE', { maximumFractionDigits: 2 }) + ' ' + unit;
}

function seriesTitle(series) {
    let title = series.label;
    if (series.quantity !== 'sonstiges') {
        title = QUANTITY_LABELS[series.quantity] ?? series.quantity;
    }
    const position = POSITION_WORDS[series.position];
    if (position === undefined) {
        return title;
    }

    return title + ' ' + position;
}

function infoList(rows) {
    const list = element('dl');
    for (const [term, value] of rows) {
        list.appendChild(element('dt', term));
        list.appendChild(element('dd', value));
    }

    return list;
}

// --- Kilometerverlauf ------------------------------------------------------------------------------------------

/** Prognose vom letzten Stand über heute bis heute + 4 Monate; ohne `per_day` gibt es keine Linie. */
function buildForecast(readings, estimate) {
    if (estimate === null || estimate.per_day === null || readings.length === 0) {
        return [];
    }
    const lastTime = dayTime(readings[readings.length - 1].read_on);
    const today = dayTime(new Date().toISOString());
    const start = Math.max(today, lastTime);
    const endDate = new Date(start);
    endDate.setUTCMonth(endDate.getUTCMonth() + FORECAST_MONTHS);
    const endTime = endDate.getTime();
    const forecast = [];
    if (estimate.estimated && start > lastTime) {
        forecast.push({ time: start, value: estimate.km_estimate, label: monthLabel(start) });
    }
    const endValue = Math.round(estimate.km_estimate + estimate.per_day * ((endTime - start) / DAY_MS));
    forecast.push({ time: endTime, value: endValue, label: monthLabel(endTime) });

    return forecast;
}

function kmAriaLabel(points, forecast) {
    const first = points[0];
    const last = points[points.length - 1];
    let text = 'Kilometerverlauf von ' + formatKm(first.value) + ' km (' + first.label + ') bis ' + formatKm(last.value) + ' km (' + last.label + ')';
    if (forecast.length > 0) {
        const end = forecast[forecast.length - 1];
        text += ', Prognose ' + formatKm(end.value) + ' km im ' + monthName(end.time);
    }

    return text;
}

function readingTip(reading) {
    return (tip) => {
        const rows = [['Datum', formatDay(reading.read_on)], ['Stand', formatKm(reading.km) + ' km']];
        if (reading.note !== null && reading.note !== '') {
            rows.push(['Notiz', reading.note]);
        }
        tip.appendChild(infoList(rows));
        fillProvenance(tip, reading.source, reading.confidence, { sourceUrl: reading.source_url });
    };
}

function legend(estimate, hasForecast) {
    const row = element('div', undefined, 'legend');
    const real = element('span');
    real.appendChild(element('i'));
    real.appendChild(document.createTextNode('belegte Stände'));
    row.appendChild(real);
    if (hasForecast) {
        const dashed = element('span');
        dashed.appendChild(element('i', undefined, 'dash'));
        dashed.appendChild(document.createTextNode('Prognose ≈ ' + estimate.per_day.toLocaleString('de-DE', { maximumFractionDigits: 1 }) + ' km/Tag'));
        row.appendChild(dashed);
    }

    return row;
}

function activatePoint(group, readings) {
    const reading = readings[Number(group.dataset.point)];
    tipContent(group, 'Kilometerstand', readingTip(reading));
}

/** `node` kommt in die Seite, danach zeichnet `draw()` das Diagramm — die Breite entscheidet über die Viewbox und ist erst dann messbar. */
function kmSection(readings, estimate) {
    const section = element('div', undefined, 'sec');
    const head = element('div', undefined, 'sec-h');
    head.appendChild(element('h2', 'Kilometerstand'));
    section.appendChild(head);
    const chart = element('div', undefined, 'chart');
    section.appendChild(chart);

    const forecast = buildForecast(readings, estimate);
    const points = readings.map((reading) => ({
        time: dayTime(reading.read_on),
        value: reading.km,
        label: monthLabel(dayTime(reading.read_on)),
        title: formatDay(reading.read_on) + ': ' + formatKm(reading.km) + ' km',
    }));
    const draw = () => {
        const svgMarkup = lineChart({ points, forecast, yLabel: 'km', ariaLabel: kmAriaLabel(points, forecast), containerWidth: chart.clientWidth });
        chart.insertAdjacentHTML('afterbegin', svgMarkup);
        chart.appendChild(legend(estimate, forecast.length > 0));
    };
    chart.addEventListener('click', (event) => {
        const group = event.target.closest('[data-point]');
        if (group !== null) {
            activatePoint(group, readings);
        }
    });
    chart.addEventListener('keydown', (event) => {
        const group = event.target.closest('[data-point]');
        if (group !== null && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            activatePoint(group, readings);
        }
    });

    return { node: section, draw };
}

// --- Messwerte -------------------------------------------------------------------------------------------------

function latestTwo(series) {
    const points = series.points;

    return { latest: points[points.length - 1], previous: points.length > 1 ? points[points.length - 2] : null };
}

/** Popover eines Messwerts: Wert, Datum, Herkunft; mit Schreibrecht der Knopf „Messung löschen“. */
function measurementTip(context, series, point) {
    return (tip) => {
        tip.appendChild(infoList([['Datum', formatDay(point.date)], ['Wert', formatValue(point.value, series.unit)]]));
        fillProvenance(tip, point.source, point.confidence, {});
        if (context.vehicle.permissions.write) {
            const actions = element('div', undefined, 'actions');
            actions.appendChild(actionButton('Messung löschen', 'btn sm danger', () => {
                closeTip();
                deleteRecord('measurement', point.id, 'Messung', context.reload);
            }));
            tip.appendChild(actions);
        }
    };
}

function valueButton(context, series, point, title) {
    const button = actionButton(formatValue(point.value, series.unit), 'stbtn', () => tipContent(button, title, measurementTip(context, series, point)));
    button.setAttribute('aria-label', title + ' ' + formatValue(point.value, series.unit) + ': Details anzeigen');

    return button;
}

function treadWord(value) {
    if (value < TREAD_LEGAL_MM) {
        return stateWord('st bad', 'unter gesetzlichem Minimum');
    }
    if (value < TREAD_RECOMMENDED_MM) {
        return stateWord('st warn', 'unter Empfehlung');
    }

    return null;
}

function wheelCell(context, position, series) {
    const cell = element('div', undefined, 'wheel');
    cell.appendChild(element('div', WHEEL_LABELS[position], 'p'));
    if (series === undefined) {
        cell.appendChild(element('div', '–', 'v'));

        return cell;
    }
    const { latest, previous } = latestTwo(series);
    if (latest.value < TREAD_LEGAL_MM) {
        cell.classList.add('bad');
    } else if (latest.value < TREAD_RECOMMENDED_MM) {
        cell.classList.add('warn');
    }
    const value = element('div', undefined, 'v');
    value.appendChild(valueButton(context, series, latest, 'Profiltiefe ' + WHEEL_LABELS[position]));
    cell.appendChild(value);
    const word = treadWord(latest.value);
    if (word !== null) {
        cell.appendChild(word);
    }
    if (previous !== null) {
        cell.appendChild(element('div', 'vorher ' + formatValue(previous.value, series.unit), 'muted small'));
    }
    cell.appendChild(badge(latest.source, latest.confidence, {}));

    return cell;
}

function treadSection(context, treadSeries) {
    const byPosition = new Map(treadSeries.map((series) => [series.position, series]));
    let positions = WHEEL_POSITIONS;
    if (!WHEEL_POSITIONS.some((position) => byPosition.has(position))) {
        positions = treadSeries.map((series) => series.position);
    }
    const newestDay = treadSeries.map((series) => latestTwo(series).latest.date).sort().pop();

    const section = element('div', undefined, 'sec');
    const head = element('div', undefined, 'sec-h');
    head.appendChild(element('h2', 'Profiltiefe'));
    head.appendChild(element('span', formatDay(newestDay), 'muted small'));
    section.appendChild(head);
    const wheels = element('div', undefined, 'wheels');
    for (const position of positions) {
        wheels.appendChild(wheelCell(context, position, byPosition.get(position)));
    }
    section.appendChild(wheels);
    section.appendChild(element('p', 'Gesetzlich 1,6 mm, empfohlen 3 mm.', 'muted small'));

    return section;
}

function otherValuesSection(context, otherSeries) {
    const section = element('div', undefined, 'sec');
    const head = element('div', undefined, 'sec-h');
    head.appendChild(element('h2', 'Weitere Werte'));
    section.appendChild(head);
    const list = element('div', undefined, 'list');
    for (const series of otherSeries) {
        const { latest } = latestTwo(series);
        const title = seriesTitle(series);
        const row = element('div', undefined, 'row');
        row.appendChild(element('span', title, 't'));
        const right = element('span', undefined, 'r');
        right.appendChild(valueButton(context, series, latest, title));
        row.appendChild(right);
        const sub = element('span', formatDay(latest.date) + ' ', 's');
        sub.appendChild(badge(latest.source, latest.confidence, {}));
        row.appendChild(sub);
        list.appendChild(row);
    }
    section.appendChild(list);

    return section;
}

function emptyMeasurements() {
    return emptyBlock(icons.doc, 'Noch keine Messwerte', 'Profiltiefe, Batterie und Drücke stehen meist im Werkstattbericht — gib ihn deinem Agenten.');
}

/** `context`: `{ vehicleId, vehicle, reload() }` wie beim Reiter Übersicht. */
export async function renderMeasure(container, context) {
    let measurements;
    let odometer;
    try {
        [measurements, odometer] = await Promise.all([
            request('GET', 'vehicles/' + context.vehicleId + '/measurements'),
            request('GET', 'vehicles/' + context.vehicleId + '/odometer'),
        ]);
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    clearChildren(container);
    const series = measurements.series;
    const readings = odometer.readings;
    if (series.length === 0 && readings.length === 0) {
        container.appendChild(emptyMeasurements());

        return;
    }

    const columns = element('div', undefined, 'cols');
    const left = element('div');
    const right = element('div');
    columns.append(left, right);
    container.appendChild(columns);

    let kmBlock = null;
    if (readings.length > 0) {
        kmBlock = kmSection(readings, context.vehicle.km_estimate);
        left.appendChild(kmBlock.node);
    }
    const treadSeries = series.filter((entry) => entry.quantity === 'profiltiefe');
    const otherSeries = series.filter((entry) => entry.quantity !== 'profiltiefe');
    if (otherSeries.length > 0) {
        left.appendChild(otherValuesSection(context, otherSeries));
    }
    if (treadSeries.length > 0) {
        right.appendChild(treadSection(context, treadSeries));
    }
    if (series.length === 0) {
        left.appendChild(emptyMeasurements());
    }
    if (kmBlock !== null) {
        kmBlock.draw();
    }
}
