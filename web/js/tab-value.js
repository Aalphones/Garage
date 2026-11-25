// Reiter „Wert“: Kennzahlen, Verlauf mit Band, Inserate-Streuung, Wertfaktoren, Ankerwerte, Inserate. Alles Gerechnete kommt vom Server.
import { request } from './api.js';
import { bandLine, onChartActivate, scatter } from './chart.js';
import { actionButton, clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { badge, tipText } from './provenance.js';
import { deleteRecord } from './record-delete.js';
import { openFactSheet } from './vehicle-inputs.js';
import { emptyBlock, errorBlock, formatKm, section, stateWord } from './widgets.js';

const DAY_MS = 86_400_000;
const HISTORY_CORRIDOR = 'korridor';
const METHOD_NONE = 'none';
const METHOD_REGRESSION = 'regression';
const ACCIDENT_FACTOR = 'unfallfrei';
const MATCH_RULE = 'gleicher Motor und Getriebe, EZ ±1 Jahr, km ±25 %, höchstens 60 Tage alt';

const KIND_LABELS = {
    kaufpreis: 'Kaufpreis',
    verkaufspreis: 'Verkaufspreis',
    gutachten: 'Gutachten',
    haendlerangebot: 'Händlerangebot',
    bewertung: 'Bewertung',
    schaetzung: 'Schätzung',
};
const VALUE_TYPE_LABELS = { haendlereinkauf: 'Händlereinkauf', privatverkauf: 'Privatverkauf', haendlerverkauf: 'Händlerverkauf' };
const REASON_LABELS = {
    drivetrain: 'anderer Motor oder Getriebe',
    year: 'EZ mehr als 1 Jahr entfernt',
    km: 'km ±25 % verfehlt',
    age: 'älter als 60 Tage',
};
/** Reihenfolge der Kennzahl „dafür · dagegen · offen“. */
const VERDICTS = {
    plus: { mark: '+', markClass: 'vf plus', word: 'spricht dafür', stateClass: 'st ok' },
    minus: { mark: '−', markClass: 'vf minus', word: 'spricht dagegen', stateClass: 'st bad' },
    unbekannt: { mark: '?', markClass: 'vf unk', word: 'unbekannt', stateClass: 'st unk' },
};

/** Werte stehen in ganzen Euro. */
function euro(cents) {
    return (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 });
}

function middleHalf(corridor) {
    return Math.round(corridor.low_cents / 100).toLocaleString('de-DE') + '–' + euro(corridor.high_cents);
}

/** `YYYY-MM…` → `MM/YYYY`. */
function monthYear(value) {
    return value.slice(5, 7) + '/' + value.slice(0, 4);
}

function ageText(fetchedOn) {
    const today = new Date().toLocaleDateString('sv-SE');
    const days = Math.round((Date.parse(today) - Date.parse(fetchedOn)) / DAY_MS);
    if (days <= 0) {
        return 'heute';
    }
    if (days === 1) {
        return 'gestern';
    }

    return 'vor ' + days + ' Tagen';
}

function trashButton(context, type, id, noun, description) {
    const button = actionButton('', 'del', () => deleteRecord(type, id, noun, context.reload));
    button.insertAdjacentHTML('beforeend', icons.trash);
    button.setAttribute('aria-label', description + ' löschen');

    return button;
}

// --- Kennzahlen, leerer Zustand -----------------------------------------------------------------------------------

function kpiTile(value, labelText, extraClass) {
    const tile = element('div', undefined, extraClass === undefined ? 'kpi' : 'kpi ' + extraClass);
    const number = element('div', undefined, 'v');
    number.append(value);
    tile.append(number, element('div', labelText, 'l'));

    return tile;
}

function keyFigures(value) {
    const { corridor } = value;
    const median = actionButton('≈ ' + euro(corridor.median_cents), 'est', () => tipText(median, 'Marktmitte', corridor.explanation + ' Passend heißt: ' + MATCH_RULE + '.'));
    // Die Liste kommt jüngste zuerst.
    const purchase = value.anchors.find((anchor) => anchor.kind === 'kaufpreis');
    const counts = Object.keys(VERDICTS).map((verdict) => value.factors.filter((factor) => factor.verdict === verdict).length);
    const hasMinus = value.factors.some((factor) => factor.verdict === 'minus');

    const bar = element('div', undefined, 'kpis');
    bar.append(
        kpiTile(median, 'Marktmitte'),
        kpiTile(middleHalf(corridor), 'mittlere Hälfte'),
        kpiTile(purchase === undefined ? '–' : euro(purchase.amount_cents), purchase === undefined ? 'Kaufpreis' : 'Kaufpreis ' + monthYear(purchase.valued_on)),
        kpiTile(counts.join(' · '), 'Faktoren dafür · dagegen · offen', hasMinus ? 'warn' : undefined),
    );

    return bar;
}

function corridorNote(corridor) {
    let text = 'Angebotspreise sind keine Verkaufspreise. ';
    const missing = corridor.needed_for_regression - corridor.counting;
    if (corridor.method === METHOD_REGRESSION) {
        text += 'Der Korridor ist auf deinen km-Stand und dein Alter umgerechnet aus ' + corridor.counting + ' Inseraten.';
    } else if (missing > 0) {
        text += 'Ab ' + corridor.needed_for_regression + ' passenden Inseraten rechnet GARAGE den Korridor auf deinen km-Stand und dein Alter um — noch ' + missing + '.';
    } else {
        // Genug Inserate, aber km und Alter streuen zu wenig: den Grund nennt der Server.
        text += corridor.explanation;
    }

    return element('p', text, 'muted small');
}

function noCorridor(context, corridor) {
    return emptyBlock(
        icons.car,
        'Noch kein Marktkorridor',
        corridor.counting + ' von ' + corridor.needed_for_corridor + ' passenden Inseraten. Sag deinem Agenten: „Such Vergleichsinserate für meinen ' + context.vehicle.vehicle.label + '.“',
    );
}

// --- Diagramme ----------------------------------------------------------------------------------------------------

function legendEntry(markClass, text) {
    const entry = element('span');
    if (markClass !== null) {
        entry.appendChild(element('i', undefined, markClass));
    }
    entry.appendChild(document.createTextNode(text));

    return entry;
}

function chartSection(title, hint, entries) {
    const { node } = section(title, hint);
    const chart = element('div', undefined, 'chart');
    const legend = element('div', undefined, 'legend');
    legend.append(...entries);
    chart.appendChild(legend);
    node.appendChild(chart);

    return { node, chart };
}

function historyPoint(entry) {
    const time = Date.parse(entry.on);
    const label = entry.month.slice(5, 7) + '/' + entry.month.slice(2, 4);
    if (entry.type === HISTORY_CORRIDOR) {
        return {
            time,
            label,
            isAnchor: false,
            value: entry.median_cents / 100,
            low: entry.low_cents / 100,
            high: entry.high_cents / 100,
            title: monthYear(entry.month),
            text: 'Marktmitte ' + euro(entry.median_cents) + ', mittlere Hälfte ' + middleHalf(entry),
        };
    }

    return {
        time,
        label,
        isAnchor: true,
        value: entry.amount_cents / 100,
        title: formatDay(entry.on),
        text: 'Ankerwert: ' + (KIND_LABELS[entry.kind] ?? entry.kind) + ' ' + euro(entry.amount_cents),
    };
}

/** Der Server liefert abgeschlossene Monate; der heutige Korridor ist der letzte Punkt der Linie. */
function historySection(value) {
    const today = new Date().toLocaleDateString('sv-SE');
    const current = { ...value.corridor, type: HISTORY_CORRIDOR, on: today, month: today.slice(0, 7) };
    const points = [...value.history, current]
        .map(historyPoint)
        .sort((left, right) => left.time - right.time)
        .map((point) => ({ ...point, name: point.title, title: point.title + ': ' + point.text }));
    const { node, chart } = chartSection('Verlauf', undefined, [legendEntry('', 'Marktmitte'), legendEntry('sw band', 'mittlere Hälfte'), legendEntry(null, '○ Ankerwert')]);
    onChartActivate(chart, 'g[data-point]', (target) => {
        const point = points[Number(target.dataset.point)];
        tipText(target, point.name, point.text);
    });
    const draw = () => chart.insertAdjacentHTML('afterbegin', bandLine({ points, ariaLabel: 'Wertverlauf mit Marktmitte, mittlerer Hälfte und Ankerwerten', containerWidth: chart.clientWidth }));

    return { node, draw };
}

function listingText(listing) {
    let text = euro(listing.price_cents) + ' · ' + formatKm(listing.odometer_km) + ' km · EZ ' + monthYear(listing.first_registration) + ' · abgerufen ' + ageText(listing.fetched_on);
    if (!listing.counts) {
        text += ' · zählt nicht';
    }

    return text;
}

function scatterSection(context, value) {
    const estimate = context.vehicle.km_estimate?.km_estimate;
    const ownKm = typeof estimate === 'number' ? estimate : null;
    const entries = [legendEntry('sw s1 dot', 'passt'), legendEntry(null, '○ zählt nicht')];
    if (ownKm !== null) {
        entries.push(legendEntry('dash', 'dein Stand ≈ ' + formatKm(ownKm) + ' km'));
    }
    const { node, chart } = chartSection('Inserate', 'Preis gegen km', entries);
    const points = value.listings.map((listing) => ({ km: listing.odometer_km, price: listing.price_cents / 100, counts: listing.counts, title: listingText(listing) }));
    onChartActivate(chart, 'g[data-point]', (target) => tipText(target, 'Inserat', points[Number(target.dataset.point)].title));
    const band = { low: value.corridor.low_cents / 100, high: value.corridor.high_cents / 100 };
    const draw = () => chart.insertAdjacentHTML('afterbegin', scatter({ points, ownKm, band, ariaLabel: 'Vergleichsinserate, Preis gegen Kilometer; Werte in der Tabelle darunter', containerWidth: chart.clientWidth }));

    return { node, draw };
}

// --- Wertfaktoren, Ankerwerte, Inserate ---------------------------------------------------------------------------

/** „Unfallfrei“ steht nur als Stammdaten-Wert in der Akte; mit Schreibrecht öffnet die Zeile das Blatt dazu. */
function accidentSheet(context) {
    const current = context.vehicle.facts[ACCIDENT_FACTOR]?.value ?? '';

    openFactSheet(
        context.vehicleId,
        { field: ACCIDENT_FACTOR, label: 'Unfallfrei', current, inputKind: 'choice', choices: [{ value: 'ja', label: 'ja' }, { value: 'nein', label: 'nein' }] },
        context.reloadVehicle,
    );
}

function factorRow(context, factor) {
    const verdict = VERDICTS[factor.verdict] ?? VERDICTS.unbekannt;
    const opensSheet = factor.key === ACCIDENT_FACTOR && context.vehicle.permissions.write;
    let row;
    if (opensSheet) {
        row = actionButton('', 'row', () => accidentSheet(context));
    } else {
        row = link('', '#' + context.vehicleId + '/' + factor.ref, 'row');
    }
    const name = element('span', undefined, 't vfrow');
    const mark = element('span', verdict.mark, verdict.markClass);
    mark.setAttribute('aria-hidden', 'true');
    name.append(mark, document.createTextNode(factor.label));
    const right = element('span', undefined, 'r');
    right.appendChild(stateWord(verdict.stateClass, verdict.word));
    row.append(name, right, element('span', opensSheet ? factor.detail + ' · Angabe machen' : factor.detail, 's'));

    return row;
}

function factorSection(context, factors) {
    const { node } = section('Wertfaktoren', 'aus deiner Akte berechnet');
    const list = element('div', undefined, 'list');
    for (const factor of factors) {
        list.appendChild(factorRow(context, factor));
    }
    node.appendChild(list);

    return node;
}

function anchorRow(context, anchor) {
    const kind = KIND_LABELS[anchor.kind] ?? anchor.kind;
    const row = element('div', undefined, 'row');
    row.appendChild(element('span', kind, 't'));
    const right = element('span', undefined, 'r');
    right.appendChild(element('b', euro(anchor.amount_cents)));
    row.appendChild(right);

    const details = [formatDay(anchor.valued_on)];
    if (anchor.odometer_km !== null) {
        details.push(formatKm(anchor.odometer_km) + ' km');
    }
    if (anchor.value_type !== null) {
        details.push(VALUE_TYPE_LABELS[anchor.value_type] ?? anchor.value_type);
    }
    if (anchor.range_low_cents !== null && anchor.range_high_cents !== null) {
        details.push('Spanne ' + euro(anchor.range_low_cents) + ' bis ' + euro(anchor.range_high_cents));
    }
    for (const text of [anchor.provider, anchor.note]) {
        if (text !== null) {
            details.push(text);
        }
    }
    const sub = element('span', undefined, 's');
    for (const text of details) {
        sub.appendChild(element('span', text));
    }
    sub.appendChild(badge(anchor.source, anchor.confidence, { sourceUrl: anchor.source_url }));
    if (context.vehicle.permissions.write) {
        sub.appendChild(trashButton(context, 'valuation', anchor.id, 'Ankerwert', kind + ' vom ' + formatDay(anchor.valued_on)));
    }
    row.appendChild(sub);

    return row;
}

function anchorSection(context, anchors) {
    const { node } = section('Ankerwerte');
    if (anchors.length === 0) {
        node.appendChild(element('p', 'Kaufpreis, Händlerangebot oder Gutachten — gib den Beleg deinem Agenten.', 'muted small'));

        return node;
    }
    const list = element('div', undefined, 'list');
    for (const anchor of anchors) {
        list.appendChild(anchorRow(context, anchor));
    }
    node.appendChild(list);

    return node;
}

function priceLink(listing) {
    if (!/^https?:\/\//.test(listing.url)) {
        return element('span', euro(listing.price_cents));
    }
    const anchor = link(euro(listing.price_cents), listing.url);
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';

    return anchor;
}

function listingRow(context, listing) {
    const row = element('tr', undefined, listing.counts ? undefined : 'old');
    const price = element('td');
    price.appendChild(priceLink(listing));
    row.append(
        price,
        element('td', formatKm(listing.odometer_km), 'num'),
        element('td', monthYear(listing.first_registration)),
        element('td', ageText(listing.fetched_on)),
    );
    const counts = element('td');
    const reason = REASON_LABELS[listing.reason_not_counting] ?? 'zählt nicht';
    counts.appendChild(stateWord('st ok', listing.counts ? 'passt' : reason));
    row.appendChild(counts);
    const actions = element('td', undefined, 'num');
    if (context.vehicle.permissions.write) {
        actions.appendChild(trashButton(context, 'listing', listing.id, 'Inserat', 'Inserat für ' + euro(listing.price_cents)));
    }
    row.appendChild(actions);

    return row;
}

/** Zählende zuerst, innerhalb jeder Gruppe nach Preis. */
function listingSection(context, listings) {
    const matching = listings.filter((listing) => listing.counts).length;
    const { node } = section('Vergleichsinserate', listings.length + ' gesammelt · ' + matching + (matching === 1 ? ' passt' : ' passen'));
    if (listings.length === 0) {
        node.appendChild(element('p', 'Noch keine Inserate. Dein Agent legt jedes mit Link und Abrufdatum ab.', 'muted small'));

        return node;
    }
    const scroll = element('div', undefined, 'tscroll');
    const table = element('table', undefined, 'tbl');
    const headRow = element('tr');
    for (const [title, className] of [['Preis'], ['km', 'num'], ['EZ'], ['Abruf'], ['Zählt'], ['']]) {
        headRow.appendChild(element('th', title, className));
    }
    const head = element('thead');
    head.appendChild(headRow);
    const body = element('tbody');
    const sorted = [...listings].sort((left, right) => Number(right.counts) - Number(left.counts) || left.price_cents - right.price_cents);
    for (const listing of sorted) {
        body.appendChild(listingRow(context, listing));
    }
    table.append(head, body);
    scroll.appendChild(table);
    node.appendChild(scroll);

    return node;
}

// --- Reiter -------------------------------------------------------------------------------------------------------

/** `context`: `{ vehicleId, vehicle, reload(), reloadVehicle() }` wie beim Reiter Übersicht. */
export async function renderValue(container, context) {
    let value;
    try {
        value = await request('GET', 'vehicles/' + context.vehicleId + '/value');
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    clearChildren(container);

    const charts = [];
    if (value.corridor.method === METHOD_NONE) {
        container.appendChild(noCorridor(context, value.corridor));
    } else {
        container.append(keyFigures(value), corridorNote(value.corridor));
        charts.push(historySection(value), scatterSection(context, value));
        const columns = element('div', undefined, 'cols');
        columns.append(...charts.map((chart) => chart.node));
        container.appendChild(columns);
    }
    container.append(factorSection(context, value.factors), anchorSection(context, value.anchors), listingSection(context, value.listings));
    // Erst zeichnen, wenn die Karten in der Seite stehen: die Breite bestimmt die Viewbox.
    for (const chart of charts) {
        chart.draw();
    }
}
