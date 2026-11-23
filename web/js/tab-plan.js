// Reiter „Wartung“: Aufgaben mit Regel, letzter Ausführung, nächstem Termin, Fortschritt und Status.
import { request } from './api.js';
import { clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { badge, tipText } from './provenance.js';
import { isNarrow, readView, viewSwitch } from './view-switch.js';
import { dueWord, emptyBlock, errorBlock, formatKm } from './widgets.js';

const STORAGE_KEY = 'garage.view.wartung';
const VIEWS = ['tbl', 'cards'];
const FULL_PERCENT = 100;

const BAR_TONES = { ueberfaellig: 'bad', bald: 'warn' };

function plural(count, one, many) {
    return count + ' ' + (count === 1 ? one : many);
}

function monthsText(months) {
    return months === 1 ? '1 Monat' : months + ' Monate';
}

/** Regel als Satz, etwa „alle 30.000 km oder 24 Monate, erstmals nach 60.000 km“. */
function ruleText(rule) {
    if (rule === null) {
        return 'Keine Regel hinterlegt';
    }
    const intervals = [];
    if (rule.interval_km !== null) {
        intervals.push(formatKm(rule.interval_km) + ' km');
    }
    if (rule.interval_months !== null) {
        intervals.push(monthsText(rule.interval_months));
    }
    const first = [];
    if (rule.first_km !== null) {
        first.push(formatKm(rule.first_km) + ' km');
    }
    if (rule.first_months !== null) {
        first.push(monthsText(rule.first_months));
    }
    let text = intervals.length === 0 ? 'ohne Intervall' : 'alle ' + intervals.join(' oder ');
    if (first.length > 0) {
        text += ', erstmals nach ' + first.join(' oder ');
    }
    if (rule.mode === 'flexibel') {
        text += ' (flexibel nach Bordanzeige)';
    }

    return text;
}

function lastText(lastDone) {
    if (lastDone === null) {
        return 'nie belegt';
    }

    return formatDay(lastDone.date) + (lastDone.km === null ? '' : ' · ' + formatKm(lastDone.km) + ' km');
}

function nextText(item) {
    const parts = [];
    if (item.due_date !== null) {
        parts.push(formatDay(item.due_date));
    }
    if (item.due_km !== null) {
        parts.push(formatKm(item.due_km) + ' km');
    }

    return parts.length === 0 ? '–' : parts.join(' · ');
}

/** Anteil der verstrichenen Zeit von der letzten Ausführung bis zum wirksamen Termin, 0–100; `null` ohne Basis. */
function progressPercent(item, today) {
    if (item.last_done === null || item.effective_date === null) {
        return null;
    }
    const start = Date.parse(item.last_done.date.slice(0, 10) + 'T00:00:00Z');
    const end = Date.parse(item.effective_date.slice(0, 10) + 'T00:00:00Z');
    const now = Date.parse(today.slice(0, 10) + 'T00:00:00Z');
    if (end <= start) {
        return FULL_PERCENT;
    }
    const share = ((now - start) / (end - start)) * FULL_PERCENT;

    return Math.min(FULL_PERCENT, Math.max(0, Math.round(share)));
}

function progressBar(item, today) {
    const percent = progressPercent(item, today);
    const tone = BAR_TONES[item.status];
    const bar = element('div', undefined, tone === undefined ? 'bar' : 'bar ' + tone);
    bar.setAttribute('role', 'img');
    const fill = element('i');
    if (percent === null) {
        bar.setAttribute('aria-label', 'Kein Fortschritt berechenbar');
    } else {
        bar.setAttribute('aria-label', percent + ' % der Zeit bis zur Fälligkeit verstrichen');
        fill.style.width = percent + '%';
    }
    bar.appendChild(fill);

    return bar;
}

/** Status als Knopf: Tippen zeigt den Rechenweg (kein `title`, am Handy nicht erreichbar). */
function statusButton(item) {
    const button = element('button', undefined, 'stbtn');
    button.type = 'button';
    button.appendChild(dueWord(item.status));
    button.setAttribute('aria-label', 'Status ' + item.title + ': Rechenweg anzeigen');
    button.addEventListener('click', () => tipText(button, item.title, item.explanation));

    return button;
}

function ruleBadge(item) {
    if (item.rule === null) {
        return null;
    }

    return badge(item.rule.source, item.rule.confidence, { sourceUrl: item.rule.source_url });
}

function uncertainBanner(vehicleId, items) {
    const uncertain = items.filter((item) => item.rule_uncertain);
    if (uncertain.length === 0) {
        return null;
    }
    const banner = element('div', undefined, 'banner');
    banner.appendChild(element('b', plural(uncertain.length, 'Regel unsicher', 'Regeln unsicher')));
    banner.appendChild(element('span', uncertain[0].title + ' stammt aus einer Quelle mit Sicherheit unsicher.'));
    banner.appendChild(link('Klärfälle ansehen', '/garage/klaerfaelle.html#fz=' + vehicleId));

    return banner;
}

function toolbar(data, view, onChange) {
    const bar = element('div', undefined, 'tb');
    const { months, km } = data.thresholds;
    bar.appendChild(element('span', plural(data.items.length, 'Aufgabe', 'Aufgaben') + ' · Gelb ab ' + months + ' Monaten oder ' + formatKm(km) + ' km', 'muted small'));
    bar.appendChild(viewSwitch(STORAGE_KEY, [
        { value: 'tbl', label: 'Tabelle', icon: icons.list },
        { value: 'cards', label: 'Karten', icon: icons.timeline },
    ], view, onChange));

    return bar;
}

function table(data) {
    const scroll = element('div', undefined, 'tscroll');
    const tableNode = element('table', undefined, 'tbl');
    const head = element('thead');
    const headRow = element('tr');
    for (const label of ['Aufgabe', 'Regel', 'Zuletzt', 'Nächstes', 'Fortschritt', 'Status']) {
        headRow.appendChild(element('th', label));
    }
    head.appendChild(headRow);
    tableNode.appendChild(head);
    const body = element('tbody');
    for (const item of data.items) {
        const row = element('tr');
        row.appendChild(element('td', item.title, 'strong'));
        const rule = element('td');
        rule.appendChild(element('div', ruleText(item.rule), 'small'));
        const ruleSource = ruleBadge(item);
        if (ruleSource !== null) {
            rule.appendChild(ruleSource);
        }
        row.appendChild(rule);
        row.appendChild(element('td', lastText(item.last_done), 'small'));
        row.appendChild(element('td', nextText(item), 'small'));
        const progress = element('td', undefined, 'prog');
        progress.appendChild(progressBar(item, data.today));
        row.appendChild(progress);
        const status = element('td');
        status.appendChild(statusButton(item));
        row.appendChild(status);
        body.appendChild(row);
    }
    tableNode.appendChild(body);
    scroll.appendChild(tableNode);

    return scroll;
}

function cards(data) {
    const list = element('div', undefined, 'list');
    for (const item of data.items) {
        const row = element('div', undefined, 'row');
        row.appendChild(element('span', item.title, 't'));
        const right = element('span', undefined, 'r');
        right.appendChild(statusButton(item));
        row.appendChild(right);
        const sub = element('span', undefined, 's stack');
        sub.appendChild(element('span', ruleText(item.rule) + ' · zuletzt ' + lastText(item.last_done)));
        sub.appendChild(progressBar(item, data.today));
        const next = element('span', 'nächstes ' + nextText(item));
        const ruleSource = ruleBadge(item);
        if (ruleSource !== null) {
            next.appendChild(document.createTextNode(' '));
            next.appendChild(ruleSource);
        }
        sub.appendChild(next);
        row.appendChild(sub);
        list.appendChild(row);
    }

    return list;
}

/** `context`: `{ vehicleId, reload() }` wie beim Reiter Übersicht. */
export async function renderPlan(container, context) {
    let data;
    try {
        data = await request('GET', 'vehicles/' + context.vehicleId + '/due');
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }

    function draw() {
        clearChildren(container);
        if (data.items.length === 0) {
            container.appendChild(emptyBlock(icons.doc, 'Noch kein Wartungsplan', 'Frag deinen Agenten: „Welche Wartungsintervalle hat mein Auto?“'));

            return;
        }
        // Handy: Karten als Standard; eine gespeicherte Wahl gilt überall.
        const view = readView(STORAGE_KEY, VIEWS, isNarrow() ? 'cards' : 'tbl');
        container.appendChild(toolbar(data, view, draw));
        const banner = uncertainBanner(context.vehicleId, data.items);
        if (banner !== null) {
            container.appendChild(banner);
        }
        container.appendChild(view === 'cards' ? cards(data) : table(data));
    }

    draw();
}
