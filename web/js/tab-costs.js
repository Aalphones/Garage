// Reiter „Kosten“: Zeitraum, Kennzahlen, Säulen je Monat, Kosten pro km, Blöcke, Tankbuch und sonstige Ausgaben. Alles Gerechnete kommt vom Server.
import { request } from './api.js';
import { lineChart, onChartActivate, stackedBars } from './chart.js';
import { EXPENSE_CATEGORIES, openExpenseSheet, openFuelSheet } from './cost-inputs.js';
import { actionButton, clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { badge, tipText } from './provenance.js';
import { deleteRecord } from './record-delete.js';
import { isNarrow, readView, storeView, viewSwitch } from './view-switch.js';
import { emptyBlock, errorBlock, formatEuro, formatKm, section } from './widgets.js';

const PERIOD_KEY = 'garage.kosten.zeitraum';
const FUEL_VIEW_KEY = 'garage.ansicht.tankbuch';
const FUEL_VIEWS = ['tbl', 'cards'];
const DEFAULT_PERIOD = '12m';
const MONTHS_PER_YEAR = 12;
/** Kosten pro km liegen unter einem Euro; mit der üblichen Mindestspanne läge die Linie flach am Rand. */
const PER_KM_MIN_SPAN_EURO = 0.2;

/** Reihenfolge und Farben sind fest; `tab` ist der zuständige Reiter, den die aufgeklappte Zeile verlinkt. */
const BLOCKS = [
    { key: 'fix', label: 'Fixkosten', color: 'var(--s1)', swatch: 's1', tab: 'papiere', tabText: 'Verträge' },
    { key: 'run', label: 'Betrieb', color: 'var(--s2)', swatch: 's2' },
    { key: 'shop', label: 'Werkstatt', color: 'var(--s3)', swatch: 's3', tab: 'scheckheft', tabText: 'Ereignisse im Scheckheft' },
    { key: 'loss', label: 'Wertverlust', color: 'var(--s4)', swatch: 's4', tab: 'wert', tabText: 'Wert und Inserate' },
];

/** Fehlende Angaben, wie der Server sie nennt, in der Reihenfolge des dringendsten nächsten Schritts. */
const MISSING = [
    { name: 'Versicherung', block: 'fix', step: 'Police oder Beitragsrechnung im Chat an GARAGE geben.' },
    { name: 'Kraftstoff', block: 'run', step: 'Tankungen eintragen oder Tankbelege dem Agenten geben.' },
    { name: 'Steuer', block: 'fix', step: 'Steuerbescheid im Chat an GARAGE geben.' },
    { name: 'Wertverlust', block: 'loss', step: 'Kaufpreis und eine Bewertung oder Vergleichsinserate dem Agenten geben.' },
];
const MISSING_FUEL = 'Kraftstoff';

const BADGE_SOURCES = ['beleg', 'werkstatt', 'nutzer', 'recherche', 'agent'];

function euroFromCents(cents) {
    return formatEuro(cents / 100);
}

function formatDecimal(value, digits) {
    return value.toLocaleString('de-DE', { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function periodOptions() {
    const year = new Date().getFullYear();

    return [
        { value: DEFAULT_PERIOD, label: '12 Monate' },
        { value: 'seit_kauf', label: 'Seit Kauf' },
        { value: 'jahr:' + year, label: String(year) },
        { value: 'jahr:' + (year - 1), label: String(year - 1) },
    ];
}

/** Geschätzter oder gerechneter Wert; Tippen zeigt den Rechenweg. */
function estimateButton(text, title, explanation) {
    const button = actionButton(text, 'est', () => tipText(button, title, explanation));

    return button;
}

function reloadAfterSave(context) {
    // Ein neuer Klärfall ändert auch den Zähler am Reiter Übersicht, dafür muss das Fahrzeug neu geladen werden.
    return (hasNewOpenItem) => {
        if (hasNewOpenItem) {
            context.reloadVehicle();

            return;
        }
        context.reload();
    };
}

function fuelButton(context, className) {
    const { vehicle } = context.vehicle;

    return actionButton('Tankung eintragen', className, () => openFuelSheet(vehicle, context.vehicle.km_estimate, reloadAfterSave(context)));
}

function trashButton(context, type, id, noun, description) {
    const button = actionButton('', 'del', () => deleteRecord(type, id, noun, context.reload));
    button.insertAdjacentHTML('beforeend', icons.trash);
    button.setAttribute('aria-label', description + ' löschen');

    return button;
}

// --- Zeitraum, Hinweis, Kennzahlen -----------------------------------------------------------------------------

function periodBar(context, current) {
    const bar = element('div', undefined, 'tb');
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', 'Zeitraum');
    for (const option of periodOptions()) {
        const chip = actionButton(option.label, 'chip', () => {
            storeView(PERIOD_KEY, option.value);
            context.reload();
        });
        chip.setAttribute('aria-pressed', String(option.value === current));
        bar.appendChild(chip);
    }

    return bar;
}

function missingBanner(context, missing) {
    const entries = MISSING.filter((entry) => missing.includes(entry.name));
    const banner = element('div', undefined, 'banner');
    banner.appendChild(element('b', 'Unvollständig'));
    const names = missing.join(', ').replace(/, ([^,]*)$/, ' und $1');
    let text = names + (missing.length > 1 ? ' fehlen.' : ' fehlt.');
    if (entries.length > 0) {
        text += ' ' + entries[0].step;
    }
    banner.appendChild(element('span', text));
    if (missing.includes(MISSING_FUEL) && context.vehicle.permissions.write) {
        banner.appendChild(fuelButton(context, 'btn sm'));
    }

    return banner;
}

function kpiTile(value, labelText) {
    const tile = element('div', undefined, 'kpi');
    const number = element('div', undefined, 'v');
    number.appendChild(value);
    tile.appendChild(number);
    tile.appendChild(element('div', labelText, 'l'));

    return tile;
}

function kmExplanation(costs) {
    if (costs.km === null || costs.active === null) {
        return 'Ohne Kilometerstände im Zeitraum nicht rechenbar.';
    }

    return 'Stand am ' + formatDay(costs.active.to) + ' minus Stand am ' + formatDay(costs.active.from)
        + '; zwischen zwei Ständen linear gerechnet, nach dem letzten Stand geschätzt.';
}

function keyFigures(costs) {
    const parts = BLOCKS.filter((block) => block.key !== 'loss').map((block) => block.label + ' ' + euroFromCents(costs.blocks[block.key].cents));
    const runningText = parts.join(' + ') + ' = ' + euroFromCents(costs.running_cents) + '. ' + costs.explanation;
    const fullText = 'Laufend ' + euroFromCents(costs.running_cents) + ' + Wertverlust ' + euroFromCents(costs.blocks.loss.cents)
        + ' = ' + euroFromCents(costs.full_cents) + '. ' + costs.explanation;

    let perKm = '–';
    if (costs.per_km_full_cents !== null) {
        perKm = '≈ ' + formatEuro(Number(costs.per_km_full_cents));
    }
    let kilometers = '–';
    if (costs.km !== null) {
        kilometers = '≈ ' + formatKm(costs.km);
    }

    const bar = element('div', undefined, 'kpis');
    bar.append(
        kpiTile(estimateButton('≈ ' + euroFromCents(costs.month_running_cents), 'Pro Monat laufend', runningText), 'pro Monat laufend'),
        kpiTile(estimateButton('≈ ' + euroFromCents(costs.month_full_cents), 'Pro Monat mit Wertverlust', fullText), 'pro Monat mit Wertverlust'),
        kpiTile(estimateButton(perKm, 'Pro km mit Wertverlust', fullText), 'pro km mit Wertverlust'),
        kpiTile(estimateButton(kilometers, 'Kilometer im Zeitraum', kmExplanation(costs)), 'km im Zeitraum'),
    );

    return bar;
}

// --- Diagramme --------------------------------------------------------------------------------------------------

function monthDate(month) {
    return new Date(month + '-01T00:00:00Z');
}

/** Bis zu zwölf Monate reicht der Monatsname; darüber braucht die Beschriftung das Jahr. */
function monthLabel(month, count) {
    if (count > MONTHS_PER_YEAR + 1) {
        return month.slice(5, 7) + '/' + month.slice(2, 4);
    }

    return monthDate(month).toLocaleDateString('de-DE', { month: 'short', timeZone: 'UTC' }).replace('.', '');
}

function monthName(month) {
    return monthDate(month).toLocaleDateString('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' });
}

function legend() {
    const row = element('div', undefined, 'legend');
    for (const block of BLOCKS) {
        const entry = element('span');
        entry.appendChild(element('i', undefined, 'sw ' + block.swatch));
        entry.appendChild(document.createTextNode(block.label));
        row.appendChild(entry);
    }

    return row;
}

/** `node` kommt in die Seite, danach zeichnet `draw()` — die Breite entscheidet über die Viewbox und ist erst dann messbar. */
function monthSection(costs) {
    const { node } = section('Je Monat', 'gestapelt nach Block');
    const chart = element('div', undefined, 'chart');
    node.appendChild(chart);

    const draw = () => {
        const months = costs.months.map((row) => monthLabel(row.month, costs.months.length));
        const series = BLOCKS.map((block) => ({ key: block.key, label: block.label, color: block.color, values: costs.months.map((row) => row[block.key] / 100) }));
        chart.insertAdjacentHTML('afterbegin', stackedBars({ months, series, ariaLabel: 'Kosten je Monat, gestapelt nach Block; Werte in der Liste Blöcke', containerWidth: chart.clientWidth }));
        chart.appendChild(legend());
    };
    onChartActivate(chart, 'rect[data-series]', (segment) => {
        const row = costs.months[Number(segment.dataset.month)];
        const block = BLOCKS.find((entry) => entry.key === segment.dataset.series);
        tipText(segment, monthName(row.month), block.label + ': ' + euroFromCents(row[block.key]) + ' · Monat gesamt ' + euroFromCents(row.full_cents));
    });

    return { node, draw };
}

function perKmSection(series) {
    const { node } = section('Pro km', 'mit Wertverlust');
    const chart = element('div', undefined, 'chart');
    node.appendChild(chart);
    const caption = element('p', 'Ab 12 Monaten Haltedauer rollierend über die letzten 12 Monate.', 'cap');

    const draw = () => {
        if (series.length === 0) {
            chart.append(element('p', 'Noch keine Linie: es braucht Kilometerstände über mindestens zwei Monate Haltedauer.', 'muted small'), caption);

            return;
        }
        const points = series.map((entry) => ({
            time: monthDate(entry.month).getTime(),
            value: Number(entry.per_km_full_cents),
            label: monthLabel(entry.month, series.length),
            title: monthName(entry.month) + ': ' + formatEuro(Number(entry.per_km_full_cents)) + ' je km',
        }));
        chart.insertAdjacentHTML('afterbegin', lineChart({ points, yLabel: '€', ariaLabel: 'Kosten pro km im Verlauf', containerWidth: chart.clientWidth, minSpan: PER_KM_MIN_SPAN_EURO }));
        chart.appendChild(caption);
    };
    onChartActivate(chart, 'g[data-point]', (point) => {
        const entry = series[Number(point.dataset.point)];
        tipText(point, monthName(entry.month), formatEuro(Number(entry.per_km_full_cents)) + ' je km: ' + euroFromCents(entry.full_cents) + ' ÷ ' + formatKm(entry.km)
            + ' km (' + formatDay(entry.from) + '–' + formatDay(entry.to) + ')');
    });

    return { node, draw };
}

// --- Blöcke -----------------------------------------------------------------------------------------------------

function factRow(term, value, mark) {
    const row = element('div', undefined, 'fact');
    row.appendChild(element('span', term, 'k'));
    const middle = element('span', undefined, 'v');
    middle.appendChild(value);
    row.appendChild(middle);
    const right = element('span', undefined, 'b');
    if (mark !== null) {
        right.appendChild(mark);
    }
    row.appendChild(right);

    return row;
}

function postRow(post) {
    let mark = null;
    if (BADGE_SOURCES.includes(post.source) && post.confidence !== null) {
        mark = badge(post.source, post.confidence, {});
    }

    return factRow(post.title, estimateButton(euroFromCents(post.cents), post.title, post.explanation), mark);
}

function blockRow(context, costs, block) {
    const posts = costs.posts[block.key];
    const missing = MISSING.filter((entry) => entry.block === block.key && costs.missing.includes(entry.name));
    const { cents, month_cents: monthCents, explanation } = costs.blocks[block.key];

    const row = element('details', undefined, 'xrow');
    const summary = element('summary');
    const name = element('span', undefined, 't name');
    name.appendChild(element('i', undefined, 'sw ' + block.swatch));
    name.appendChild(document.createTextNode(block.label));
    summary.appendChild(name);
    const total = element('span', undefined, 'r');
    total.appendChild(element('b', posts.length === 0 ? '–' : euroFromCents(cents)));
    summary.appendChild(total);
    const detail = element('span', undefined, 'small muted detail');
    detail.appendChild(element('span', posts.length === 0 ? 'keine Kosten erfasst' : euroFromCents(monthCents) + ' je Monat'));
    if (missing.length > 0) {
        detail.appendChild(element('span', 'unvollständig', 'st warn'));
    }
    summary.appendChild(detail);
    row.appendChild(summary);

    const body = element('div', undefined, 'xbody');
    if (posts.length > 0 || missing.length > 0) {
        const facts = element('div', undefined, 'facts posts');
        for (const post of posts) {
            facts.appendChild(postRow(post));
        }
        for (const entry of missing) {
            facts.appendChild(factRow(entry.name, element('span', 'fehlt', 'missing'), null));
        }
        body.appendChild(facts);
    }
    if (posts.length === 0) {
        body.appendChild(element('p', explanation, 'muted small'));
    }
    // Verlinkt wird nur, was es als Reiter gibt.
    if (block.tab !== undefined && context.hasTab(block.tab)) {
        const paragraph = element('p', undefined, 'small');
        paragraph.appendChild(link(block.tabText, '#' + context.vehicleId + '/' + block.tab));
        body.appendChild(paragraph);
    }
    row.appendChild(body);

    return row;
}

function blockSection(context, costs) {
    const { node } = section('Blöcke', 'Summe im Zeitraum · je Monat');
    const list = element('div', undefined, 'list');
    for (const block of BLOCKS) {
        list.appendChild(blockRow(context, costs, block));
    }
    node.appendChild(list);

    return node;
}

// --- Tankbuch ---------------------------------------------------------------------------------------------------

/** Rechenweg zum Verbrauch der Volltankung `index`; `fuel` steht neueste zuerst. */
function consumptionExplanation(fuel, index) {
    const previousFull = fuel.findIndex((entry, position) => position > index && entry.is_full);
    const liters = fuel.slice(index, previousFull).map((entry) => formatDecimal(Number(entry.liters), 2)).reverse();

    return liters.join(' + ') + ' l seit der letzten Volltankung auf ' + formatKm(fuel[index].odometer_km - fuel[previousFull].odometer_km) + ' km.';
}

function consumptionText(entry) {
    return formatDecimal(entry.consumption_l_100km, 1) + ' l/100 km';
}

function pricePerLiter(entry) {
    return formatDecimal(Number(entry.price_per_liter), 3);
}

function fuelDeleteButton(context, entry) {
    return trashButton(context, 'fuel', entry.id, 'Tankung', 'Tankung vom ' + formatDay(entry.fueled_on));
}

function fuelTableRow(context, fuel, index) {
    const entry = fuel[index];
    const row = element('tr');
    row.appendChild(element('td', formatDay(entry.fueled_on)));
    row.appendChild(element('td', formatKm(entry.odometer_km), 'num'));
    row.appendChild(element('td', formatDecimal(Number(entry.liters), 2), 'num'));
    row.appendChild(element('td', formatEuro(Number(entry.amount_eur)), 'num'));
    row.appendChild(element('td', pricePerLiter(entry), 'num'));
    const consumption = element('td', undefined, 'num');
    if (entry.consumption_l_100km === null) {
        consumption.appendChild(element('span', '–', 'muted'));
    } else {
        consumption.appendChild(estimateButton(consumptionText(entry), 'Verbrauch', consumptionExplanation(fuel, index)));
    }
    row.appendChild(consumption);
    row.appendChild(element('td', entry.is_full ? 'ja' : 'nein', entry.is_full ? undefined : 'muted'));
    const actions = element('td', undefined, 'num');
    if (context.vehicle.permissions.write) {
        actions.appendChild(fuelDeleteButton(context, entry));
    }
    row.appendChild(actions);

    return row;
}

function fuelTable(context, fuel) {
    const scroll = element('div', undefined, 'tscroll');
    const table = element('table', undefined, 'tbl');
    const headRow = element('tr');
    for (const [title, className] of [['Datum'], ['km', 'num'], ['Liter', 'num'], ['Betrag', 'num'], ['€/l', 'num'], ['Verbrauch', 'num'], ['Voll'], ['']]) {
        headRow.appendChild(element('th', title, className));
    }
    const head = element('thead');
    head.appendChild(headRow);
    const body = element('tbody');
    fuel.forEach((entry, index) => body.appendChild(fuelTableRow(context, fuel, index)));
    table.append(head, body);
    scroll.appendChild(table);

    return scroll;
}

function fuelCard(context, fuel, index) {
    const entry = fuel[index];
    const row = element('div', undefined, 'row');
    row.appendChild(element('span', formatDay(entry.fueled_on) + ' · ' + formatEuro(Number(entry.amount_eur)), 't'));
    const right = element('span', undefined, 'r');
    if (entry.consumption_l_100km === null) {
        right.appendChild(element('span', entry.is_full ? 'ohne Vorwert' : 'nicht voll', 'muted small'));
    } else {
        right.appendChild(estimateButton(consumptionText(entry), 'Verbrauch', consumptionExplanation(fuel, index)));
    }
    row.appendChild(right);
    const sub = element('span', undefined, 's');
    sub.appendChild(element('span', formatKm(entry.odometer_km) + ' km · ' + formatDecimal(Number(entry.liters), 2) + ' l · ' + pricePerLiter(entry) + ' €/l'));
    if (context.vehicle.permissions.write) {
        sub.appendChild(fuelDeleteButton(context, entry));
    }
    row.appendChild(sub);

    return row;
}

function emptyFuel(context) {
    const block = emptyBlock(icons.car, 'Noch kein Tankbuch', 'Ohne Tankungen fehlt der Kraftstoff im Unterhalt. Trag die nächste Tankung direkt an der Zapfsäule ein oder gib den Beleg deinem Agenten.');
    if (context.vehicle.permissions.write) {
        block.appendChild(fuelButton(context, 'btn sm primary'));
    }

    return block;
}

function fuelSection(context, fuel) {
    const { node, head } = section('Tankbuch', fuel.length + (fuel.length === 1 ? ' Tankung' : ' Tankungen'));
    if (fuel.length === 0) {
        node.appendChild(emptyFuel(context));

        return node;
    }
    if (context.vehicle.permissions.write) {
        head.appendChild(fuelButton(context, 'btn primary sm more'));
    }

    const content = element('div');
    node.appendChild(content);
    const paint = (view) => {
        clearChildren(content);
        const bar = element('div', undefined, 'tb');
        bar.appendChild(element('span', 'Verbrauch zwischen zwei Volltankungen', 'muted small'));
        const options = [{ value: 'tbl', label: 'Tabelle', icon: icons.list }, { value: 'cards', label: 'Karten', icon: icons.timeline }];
        bar.appendChild(viewSwitch(FUEL_VIEW_KEY, options, view, paint));
        content.appendChild(bar);
        if (view === 'tbl') {
            content.appendChild(fuelTable(context, fuel));

            return;
        }
        const list = element('div', undefined, 'list');
        fuel.forEach((entry, index) => list.appendChild(fuelCard(context, fuel, index)));
        content.appendChild(list);
    };
    paint(readView(FUEL_VIEW_KEY, FUEL_VIEWS, isNarrow() ? 'cards' : 'tbl'));

    return node;
}

// --- Sonstige Ausgaben ------------------------------------------------------------------------------------------

function expenseRow(context, expense) {
    const category = EXPENSE_CATEGORIES.find((entry) => entry.value === expense.category)?.label ?? expense.category;
    const row = element('div', undefined, 'row');
    row.appendChild(element('span', category, 't'));
    const right = element('span', undefined, 'r');
    right.appendChild(element('b', formatEuro(Number(expense.amount_eur))));
    row.appendChild(right);
    const sub = element('span', undefined, 's');
    sub.appendChild(element('span', formatDay(expense.spent_on)));
    if (expense.spread_months > 1) {
        sub.appendChild(element('span', 'verteilt auf ' + expense.spread_months + ' Monate'));
    }
    if (expense.note !== null) {
        sub.appendChild(element('span', expense.note));
    }
    sub.appendChild(badge(expense.source, expense.confidence, {}));
    if (context.vehicle.permissions.write) {
        sub.appendChild(trashButton(context, 'expense', expense.id, 'Ausgabe', 'Ausgabe ' + category + ' vom ' + formatDay(expense.spent_on)));
    }
    row.appendChild(sub);

    return row;
}

function expenseSection(context, expenses) {
    const { node, head } = section('Sonstige Ausgaben');
    if (context.vehicle.permissions.write) {
        head.appendChild(actionButton('Ausgabe eintragen', 'btn quiet sm more', () => openExpenseSheet(context.vehicleId, reloadAfterSave(context))));
    }
    if (expenses.length === 0) {
        node.appendChild(element('p', 'Parken, Wäsche, Maut, Reifeneinlagerung — was nicht in der Werkstatt anfällt.', 'muted small'));

        return node;
    }
    const list = element('div', undefined, 'list');
    for (const expense of expenses) {
        list.appendChild(expenseRow(context, expense));
    }
    node.appendChild(list);

    return node;
}

// --- Reiter -----------------------------------------------------------------------------------------------------

function emptyCosts(context) {
    const block = emptyBlock(icons.car, 'Noch keine Kosten', 'Gib deinem Agenten den Steuerbescheid oder eine Beitragsrechnung — oder trag hier eine Tankung ein.');
    if (context.vehicle.permissions.write) {
        block.appendChild(fuelButton(context, 'btn sm primary'));
        block.appendChild(actionButton('Ausgabe eintragen', 'btn quiet sm', () => openExpenseSheet(context.vehicleId, reloadAfterSave(context))));
    }

    return block;
}

/** `context`: `{ vehicleId, vehicle, reload(), reloadVehicle(), hasTab(key) }` wie beim Reiter Übersicht. */
export async function renderCosts(container, context) {
    const period = readView(PERIOD_KEY, periodOptions().map((option) => option.value), DEFAULT_PERIOD);
    const base = 'vehicles/' + context.vehicleId + '/';
    let costs;
    let fuel;
    let expenses;
    try {
        [costs, { fuel }, { expenses }] = await Promise.all([
            request('GET', base + 'costs?period=' + encodeURIComponent(period)),
            request('GET', base + 'fuel'),
            request('GET', base + 'expenses'),
        ]);
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    clearChildren(container);

    const hasPosts = BLOCKS.some((block) => costs.posts[block.key].length > 0);
    const hasNothing = !hasPosts && fuel.length === 0 && expenses.length === 0;
    if (hasNothing && period === DEFAULT_PERIOD) {
        container.appendChild(emptyCosts(context));

        return;
    }
    container.appendChild(periodBar(context, period));
    if (costs.period.note !== null) {
        container.appendChild(element('p', costs.period.note, 'muted small'));
    }
    if (hasNothing) {
        container.appendChild(emptyCosts(context));

        return;
    }
    if (costs.missing.length > 0) {
        container.appendChild(missingBanner(context, costs.missing));
    }
    container.appendChild(keyFigures(costs));
    const byMonth = monthSection(costs);
    const perKm = perKmSection(costs.per_km);
    container.append(byMonth.node, perKm.node, blockSection(context, costs), fuelSection(context, fuel), expenseSection(context, expenses));
    byMonth.draw();
    perKm.draw();
}
