// Garage: Unterhalt der aktiven Autos (Kennzahlen, Säulen je Monat nach Auto) und die Verträge der Garage. Gerechnet wird auf dem Server.
import { request } from './api.js';
import { onChartActivate, stackedBars } from './chart.js';
import { actionButton, clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { badge, tipText } from './provenance.js';
import { deleteRecord } from './record-delete.js';
import { CONTRACT_LABELS, EVENT_LABELS, EVENT_STATES } from './tab-papers.js';
import { readView, viewSwitch } from './view-switch.js';
import { errorBlock, formatEuro, formatKm, formatWholeEuro, section, skeletons, stateWord } from './widgets.js';

const VIEW_KEY = 'garage.ansicht.unterhalt';
const VIEW_RUNNING = 'lauf';
const VIEW_FULL = 'voll';
const VIEW_OPTIONS = [
    { value: VIEW_RUNNING, label: 'laufend', icon: '' },
    { value: VIEW_FULL, label: 'mit Wertverlust', icon: '' },
];
const SERIES_CLASSES = ['s1', 's2', 's3', 's4'];
const REST_KEY = 'rest';
const ACTIVE_STATUS = 'aktiv';
const READONLY_ROLE = 'lesend';
const DEADLINE_DAYS = 30;
const MISSING_LOSS = 'Wertverlust';
const ALLOCATION_EQUAL = 'gleich';
const ALLOCATION_LABELS = { gleich: 'gleich', km: 'nach km', fest: 'nach festen Anteilen' };

const cache = new Map();

export function resetGarageCosts() {
    cache.clear();
}

/** Ergebnis eines Zusatzaufrufs oder `null`; die Kosten bleiben auch ohne ihn sichtbar. */
async function optional(promise) {
    try {
        return await promise;
    } catch {
        return null;
    }
}

function activeVehicles(garage) {
    return garage.vehicles.filter((vehicle) => vehicle.status === ACTIVE_STATUS);
}

/** Stichtage der nächsten 30 Tage über die aktiven Autos; ein Stichtag der Garage steht bei jedem Auto und zählt einmal. */
async function upcomingDeadlines(garage) {
    const lists = await Promise.all(activeVehicles(garage).map(async (vehicle) => {
        const { deadlines } = await request('GET', 'vehicles/' + vehicle.id + '/deadlines');

        return deadlines.map((deadline) => ({ ...deadline, vehicleId: vehicle.id }));
    }));
    const unique = new Map();
    for (const deadline of lists.flat()) {
        const key = [deadline.kind, deadline.ref.type, deadline.ref.id, deadline.date].join('|');
        if (deadline.days <= DEADLINE_DAYS && !unique.has(key)) {
            unique.set(key, deadline);
        }
    }

    return [...unique.values()];
}

/** Tarif und Vorgänge der Garagen-Verträge stehen nur in den Papieren eines Fahrzeugs der Garage. */
async function contractPapers(garage, costs) {
    if (costs.garage_contracts.length === 0 || garage.vehicles.length === 0) {
        return null;
    }

    return optional(request('GET', 'vehicles/' + garage.vehicles[0].id + '/papers'));
}

async function fetchGarage(garage) {
    const costs = await request('GET', 'garages/' + garage.id + '/costs');
    const [deadlines, papers] = await Promise.all([optional(upcomingDeadlines(garage)), contractPapers(garage, costs)]);

    return { costs, deadlines, papers };
}

/** Lädt einmal je Seitenstand; ein Filterwechsel zeichnet aus dem Speicher. */
export async function loadGarageCosts(garage) {
    if (!cache.has(garage.id)) {
        cache.set(garage.id, await fetchGarage(garage));
    }

    return cache.get(garage.id);
}

function euroFromCents(cents) {
    return formatEuro(cents / 100);
}

function estimateButton(text, title, explanation) {
    const button = actionButton(text, 'est', () => tipText(button, title, explanation));

    return button;
}

function sectionHint(garage, options, hint) {
    if (options.showName) {
        return garage.name + ' · ' + hint;
    }

    return hint;
}

// --- Kennzahlen -------------------------------------------------------------------------------------------------------

function kpiTile(value, labelText, { tone, href } = {}) {
    const tile = element('div', undefined, tone === undefined ? 'kpi' : 'kpi ' + tone);
    let host = tile;
    if (href !== undefined) {
        host = link('', href);
        tile.appendChild(host);
    }
    const number = element('div', undefined, 'v');
    number.append(value);
    host.append(number, element('div', labelText, 'l'));

    return tile;
}

function monthTile(costs, isRunning) {
    const total = isRunning ? costs.running_cents : costs.full_cents;
    const month = isRunning ? costs.month_running_cents : costs.month_full_cents;
    const explanation = euroFromCents(total) + (isRunning ? ' laufend' : ' mit Wertverlust') + ' in 12 Monaten. ' + costs.explanation;

    return kpiTile(estimateButton('≈ ' + formatWholeEuro(month / 100), 'Pro Monat', explanation), 'pro Monat');
}

function perKmTile(costs, isRunning) {
    if (costs.km === null || costs.km <= 0) {
        return kpiTile('–', 'pro km');
    }
    const total = isRunning ? costs.running_cents : costs.full_cents;
    const explanation = euroFromCents(total) + ' ÷ ' + formatKm(costs.km) + ' km (alle Autos zusammen, zwischen zwei Ständen linear gerechnet).';

    return kpiTile(estimateButton('≈ ' + formatEuro(total / 100 / costs.km), 'Pro km', explanation), 'pro km');
}

function missingTile(garage, costs) {
    const gap = activeVehicles(garage).find((vehicle) => costs.vehicles.some((entry) => entry.vehicle_id === vehicle.id && entry.missing.length > 0));
    const options = gap === undefined ? {} : { tone: 'warn', href: '/garage/fahrzeug.html#' + gap.id + '/kosten' };

    return kpiTile(String(costs.missing.length), costs.missing.length === 1 ? 'Block fehlt' : 'Blöcke fehlen', options);
}

function deadlineTile(deadlines) {
    const label = 'Stichtage in ' + DEADLINE_DAYS + ' Tagen';
    if (deadlines === null) {
        return kpiTile('–', label + ' · nicht geladen');
    }
    if (deadlines.length === 0) {
        return kpiTile('0', label);
    }
    const next = [...deadlines].sort((left, right) => left.date.localeCompare(right.date))[0];

    return kpiTile(String(deadlines.length), label, { href: '/garage/fahrzeug.html#' + next.vehicleId + '/papiere' });
}

// --- Säulen je Monat nach Auto ----------------------------------------------------------------------------------------

/** Reihen in der Reihenfolge der Fahrzeugliste; ab dem fünften Auto eine gemeinsame Reihe „Weitere“. */
function vehicleSeries(garage, costs, isRunning) {
    const listed = garage.vehicles.map((vehicle) => costs.vehicles.find((entry) => entry.vehicle_id === vehicle.id));
    const ordered = listed.filter((entry) => entry !== undefined);
    const valuesOf = (entry) => entry.months.map((month) => (isRunning ? month.running_cents : month.full_cents) / 100);
    const series = ordered.slice(0, SERIES_CLASSES.length).map((entry, index) => ({
        key: String(entry.vehicle_id),
        label: entry.label,
        swatch: SERIES_CLASSES[index],
        color: 'var(--' + SERIES_CLASSES[index] + ')',
        values: valuesOf(entry),
    }));
    const rest = ordered.slice(SERIES_CLASSES.length).map(valuesOf);
    if (rest.length > 0) {
        const values = rest[0].map((unused, month) => rest.reduce((total, entry) => total + entry[month], 0));
        series.push({ key: REST_KEY, label: 'Weitere', swatch: REST_KEY, color: 'var(--muted)', values });
    }

    return series;
}

function monthText(month, options) {
    return new Date(month + '-01T00:00:00Z').toLocaleDateString('de-DE', { ...options, timeZone: 'UTC' }).replace('.', '');
}

function lossNote(costs, isRunning) {
    if (isRunning) {
        return 'ohne Wertverlust';
    }
    const without = costs.vehicles.filter((entry) => entry.missing.includes(MISSING_LOSS)).map((entry) => entry.label);
    if (without.length === 0) {
        return 'mit Wertverlust';
    }

    return 'mit Wertverlust (fehlt bei ' + without.join(', ') + ')';
}

function legend(series, note) {
    const row = element('div', undefined, 'legend');
    for (const entry of series) {
        const item = element('span');
        item.append(element('i', undefined, 'sw ' + entry.swatch), document.createTextNode(entry.label));
        row.appendChild(item);
    }
    row.appendChild(element('span', note));

    return row;
}

function valueTable(months, series) {
    const details = element('details');
    details.appendChild(element('summary', 'Werte als Tabelle'));
    const scroll = element('div', undefined, 'tscroll');
    const table = element('table', undefined, 'tbl');
    const headRow = element('tr');
    headRow.appendChild(element('th', 'Monat'));
    for (const entry of series) {
        headRow.appendChild(element('th', entry.label, 'num'));
    }
    const head = element('thead');
    head.appendChild(headRow);
    const body = element('tbody');
    months.forEach((month, index) => {
        const row = element('tr');
        row.appendChild(element('td', monthText(month, { month: 'short', year: '2-digit' })));
        for (const entry of series) {
            row.appendChild(element('td', formatEuro(entry.values[index]), 'num'));
        }
        body.appendChild(row);
    });
    table.append(head, body);
    scroll.appendChild(table);
    details.appendChild(scroll);

    return details;
}

/** Die Breite der Karte bestimmt die Viewbox: `chart` muss schon in der Seite stehen. */
function drawChart(chart, costs, series, isRunning) {
    const months = costs.months.map((row) => row.month);
    const labels = months.map((month) => monthText(month, { month: 'short' }));
    chart.insertAdjacentHTML('afterbegin', stackedBars({ months: labels, series, ariaLabel: 'Unterhalt je Monat, gestapelt nach Auto; Werte in der Tabelle darunter', containerWidth: chart.clientWidth }));
    chart.append(legend(series, lossNote(costs, isRunning)), valueTable(months, series));
    onChartActivate(chart, 'rect[data-series]', (segment) => {
        const index = Number(segment.dataset.month);
        const entry = series.find((candidate) => candidate.key === segment.dataset.series);
        const total = series.reduce((sum, candidate) => sum + candidate.values[index], 0);
        tipText(segment, monthText(months[index], { month: 'long', year: 'numeric' }), entry.label + ': ' + formatEuro(entry.values[index]) + ' · Monat gesamt ' + formatEuro(total));
    });
}

function upkeepSection(garage, data, options, redraw) {
    const { costs, deadlines } = data;
    const view = readView(VIEW_KEY, [VIEW_RUNNING, VIEW_FULL], VIEW_RUNNING);
    const isRunning = view === VIEW_RUNNING;
    const { node } = section('Unterhalt', sectionHint(garage, options, 'aktive Autos · letzte 12 Monate'));

    const bar = element('div', undefined, 'tb');
    const toggle = viewSwitch(VIEW_KEY, VIEW_OPTIONS, view, redraw);
    toggle.setAttribute('aria-label', 'Rechnung');
    bar.append(element('span', 'Garage gesamt', 'muted small'), toggle);

    const figures = element('div', undefined, 'kpis');
    figures.append(monthTile(costs, isRunning), perKmTile(costs, isRunning), missingTile(garage, costs), deadlineTile(deadlines));

    const chart = element('div', undefined, 'chart');
    chart.style.marginTop = '.8rem';
    node.append(bar, figures, chart);

    return { node, draw: () => drawChart(chart, costs, vehicleSeries(garage, costs, isRunning), isRunning) };
}

// --- Verträge der Garage ----------------------------------------------------------------------------------------------

function allocationSummary(garage, contract) {
    const method = 'Umlage ' + (ALLOCATION_LABELS[contract.allocation] ?? contract.allocation);
    const shares = contract.per_vehicle.filter((share) => share.year_cents !== null);
    if (shares.length === 0) {
        return method;
    }
    if (contract.allocation === ALLOCATION_EQUAL) {
        return method + ' · ' + euroFromCents(shares[0].year_cents) + ' je Auto';
    }
    const parts = shares.map((share) => {
        const vehicle = garage.vehicles.find((candidate) => candidate.id === share.vehicle_id);

        return (vehicle === undefined ? 'Auto ' + share.vehicle_id : vehicle.label) + ' ' + euroFromCents(share.year_cents);
    });

    return method + ' · ' + parts.join(', ');
}

function allocationExplanation(contract) {
    if (contract.amount_year_cents === null) {
        return contract.explanation;
    }

    return euroFromCents(contract.amount_year_cents) + ' im Jahr. ' + contract.explanation + ' Kommt ein Auto dazu oder geht eins, verschiebt sich die Umlage ab diesem Tag.';
}

function trashButton(type, id, noun, description, onChanged) {
    const button = actionButton('', 'del', () => deleteRecord(type, id, noun, onChanged));
    button.insertAdjacentHTML('beforeend', icons.trash);
    button.setAttribute('aria-label', description + ' löschen');

    return button;
}

function eventRow(event, canWrite, onChanged) {
    const kind = EVENT_LABELS[event.kind] ?? event.kind;
    const state = EVENT_STATES[event.event_status] ?? { className: 'st unk', label: event.event_status };
    const row = element('div', undefined, 'row');
    const right = element('span', undefined, 'r');
    right.appendChild(stateWord(state.className, state.label));
    const parts = [formatDay(event.occurred_on), event.amount_eur === null ? null : formatEuro(Number(event.amount_eur)), event.claim_number, event.note];
    const sub = element('span', undefined, 's');
    sub.appendChild(element('span', parts.filter((part) => part !== null && part !== '').join(' · ')));
    if (canWrite) {
        sub.appendChild(trashButton('contract_event', event.id, 'Vorgang', kind + ' vom ' + formatDay(event.occurred_on), onChanged));
    }
    row.append(element('span', kind, 't'), right, sub);

    return row;
}

function eventDetails(events, canWrite, onChanged) {
    const details = element('details');
    details.appendChild(element('summary', 'Vorgänge · ' + events.length));
    const list = element('div', undefined, 'list');
    list.append(...events.map((event) => eventRow(event, canWrite, onChanged)));
    details.appendChild(list);

    return details;
}

function contractRow(garage, contract, paper, options) {
    const canWrite = garage.role !== READONLY_ROLE;
    const kind = CONTRACT_LABELS[contract.kind] ?? contract.kind;
    const name = [contract.provider, paper?.tariff].filter(Boolean).join(' ');
    const row = element('div', undefined, 'row');
    const amount = element('b', contract.amount_year_cents === null ? 'Beitrag fehlt' : euroFromCents(contract.amount_year_cents) + '/Jahr');
    const right = element('span', undefined, 'r');
    right.appendChild(amount);

    const sub = element('span', undefined, 's');
    const why = actionButton('Rechenweg', 'linkbtn', () => tipText(why, 'Umlage', allocationExplanation(contract)));
    sub.append(element('span', kind + ' · ' + allocationSummary(garage, contract)), why, badge(contract.source, contract.confidence));
    if (canWrite) {
        sub.appendChild(trashButton('contract', contract.contract_id, 'Vertrag', kind + ' bei ' + contract.provider, options.onChanged));
    }
    if (paper !== undefined && paper.events.length > 0) {
        sub.appendChild(eventDetails(paper.events, canWrite, options.onChanged));
    }
    row.append(element('span', name, 't'), right, sub);

    return row;
}

function contractSection(garage, data, options) {
    const { node } = section('Verträge der Garage', sectionHint(garage, options, 'umgelegt auf aktive Autos'));
    const list = element('div', undefined, 'list');
    for (const contract of data.costs.garage_contracts) {
        const paper = data.papers?.contracts.find((candidate) => candidate.id === contract.contract_id);
        list.appendChild(contractRow(garage, contract, paper, options));
    }
    node.appendChild(list);
    if (data.papers === null && garage.vehicles.length > 0) {
        node.appendChild(element('p', 'Tarif und Vorgänge konnten nicht geladen werden. Lade die Seite neu.', 'muted small'));
    }

    return node;
}

// --- Block je Garage --------------------------------------------------------------------------------------------------

function paint(host, garage, data, options) {
    clearChildren(host);
    let upkeep = null;
    if (data.costs.vehicles.length > 0 && activeVehicles(garage).length > 0) {
        upkeep = upkeepSection(garage, data, options, () => paint(host, garage, data, options));
        host.appendChild(upkeep.node);
    }
    if (data.costs.garage_contracts.length > 0) {
        host.appendChild(contractSection(garage, data, options));
    }
    upkeep?.draw();
}

async function fill(host, garage, options) {
    clearChildren(host);
    host.appendChild(skeletons(1));
    let data;
    try {
        data = await loadGarageCosts(garage);
    } catch (error) {
        clearChildren(host);
        const { node } = section('Unterhalt', sectionHint(garage, options, 'aktive Autos · letzte 12 Monate'));
        node.appendChild(errorBlock(error.message, () => fill(host, garage, options)));
        host.appendChild(node);

        return;
    }
    // Nach einem Filterwechsel steht der Block nicht mehr in der Seite.
    if (host.isConnected) {
        paint(host, garage, data, options);
    }
}

/**
 * `options`: `{ showName, onChanged() }`. `node` gehört in die Seite, danach zeichnet `mount()`.
 */
export function garageCostBlock(garage, options) {
    const node = element('div');
    const mount = () => {
        const data = cache.get(garage.id);
        if (data === undefined) {
            fill(node, garage, options);
        } else {
            paint(node, garage, data, options);
        }
    };

    return { node, mount };
}
