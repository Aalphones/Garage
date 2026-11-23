// Reiter „Übersicht“: Kennzahlen, Fälligkeiten, letztes Ereignis, Klärfälle, Notizen, Schäden und Rückrufe, Angaben mit Herkunft.
import { request } from './api.js';
import { actionButton, clearChildren, element, formatDay, formatMoment, link } from './dom.js';
import { KIND_LABELS, openItemBody } from './openitem-view.js';
import * as icons from './icons.js';
import { badge, tipText } from './provenance.js';
import { openFactSheet, openNoteSheet, openOdometerSheet } from './vehicle-inputs.js';
import { dueWord, errorBlock, formatEuro, formatKm, stateWord } from './widgets.js';

const DUE_ROWS = 4;
const INTEGER_FIELDS = ['leistung_kw', 'hubraum_ccm', 'co2_g_km', 'leergewicht_kg', 'zul_gesamtgewicht_kg', 'listenneupreis_eur', 'schluesselanzahl'];
const PRIMARY_FIELDS = ['marke', 'modell', 'variante', 'fin', 'erstzulassung', 'kraftstoff', 'leistung_kw', 'farbe'];

const FIELD_LABELS = {
    fin: 'FIN', hsn: 'HSN', tsn: 'TSN', marke: 'Marke', modell: 'Modell', variante: 'Variante', motorcode: 'Motorcode',
    getriebecode: 'Getriebecode', erstzulassung: 'Erstzulassung', farbe: 'Farbe', farbcode: 'Farbcode', kraftstoff: 'Kraftstoff',
    leistung_kw: 'Leistung', hubraum_ccm: 'Hubraum', schadstoffklasse: 'Schadstoffklasse', co2_g_km: 'CO₂',
    leergewicht_kg: 'Leergewicht', zul_gesamtgewicht_kg: 'Zul. Gesamtgewicht', listenneupreis_eur: 'Listenneupreis',
    schluesselanzahl: 'Schlüssel', ablageort_papiere: 'Ablageort Papiere',
};
const FIELD_UNITS = { leistung_kw: ' kW', hubraum_ccm: ' cm³', co2_g_km: ' g/km', leergewicht_kg: ' kg', zul_gesamtgewicht_kg: ' kg', listenneupreis_eur: ' €' };

function formatFact(field, value) {
    if (field === 'erstzulassung') {
        return formatDay(String(value));
    }
    if (typeof value === 'number') {
        return value.toLocaleString('de-DE') + (FIELD_UNITS[field] ?? '');
    }

    return String(value);
}

function sectionHead(title, moreLabel, moreHref) {
    const head = element('div', undefined, 'sec-h');
    head.appendChild(element('h2', title));
    if (moreLabel !== undefined) {
        head.appendChild(link(moreLabel, moreHref, 'more'));
    }

    return head;
}

function section(title, moreLabel, moreHref) {
    const wrapper = element('div', undefined, 'sec');
    wrapper.appendChild(sectionHead(title, moreLabel, moreHref));

    return wrapper;
}

/** Ergebnis eines Aufrufs, ohne dass ein Fehler die übrigen Bereiche mitnimmt. */
async function settle(promise) {
    try {
        return { value: await promise, error: null };
    } catch (error) {
        return { value: null, error };
    }
}

function estimateExplanation(estimate) {
    const to = estimate.basis.to;
    const from = estimate.basis.from;
    if (!estimate.estimated) {
        return 'Gemessen: ' + formatKm(to.km) + ' km am ' + formatDay(to.date) + '.';
    }
    if (from === null || estimate.per_day === null) {
        return 'Letzter Stand: ' + formatKm(to.km) + ' km am ' + formatDay(to.date) + '. Für eine Hochrechnung fehlt ein zweiter Stand.';
    }

    return 'Hochgerechnet aus ' + formatKm(from.km) + ' km am ' + formatDay(from.date) + ' und ' + formatKm(to.km) + ' km am ' + formatDay(to.date)
        + ': etwa ' + estimate.per_day.toLocaleString('de-DE') + ' km pro Tag.';
}

function kpi(tone) {
    return element('div', undefined, tone === undefined ? 'kpi' : 'kpi ' + tone);
}

function kpiLink(href, value, label) {
    const inner = link('', href);
    inner.appendChild(element('div', String(value), 'v'));
    inner.appendChild(element('div', label, 'l'));

    return inner;
}

/** Beschriftung unter dem Kilometerwert; mit Schreibrecht steht dahinter der Link „Stand eintragen“. */
function kmLabel(text, context) {
    const label = element('div', text, 'l');
    if (context.vehicle.permissions.write) {
        label.appendChild(document.createTextNode(' · '));
        label.appendChild(actionButton('Stand eintragen', 'linkbtn', () => openOdometerSheet(context.vehicleId, context.reloadVehicle)));
    }

    return label;
}

function kmCell(estimate, context) {
    const cell = kpi();
    const value = element('div', undefined, 'v');
    if (estimate === null) {
        value.textContent = '–';
        cell.appendChild(value);
        cell.appendChild(kmLabel('noch kein Kilometerstand', context));

        return cell;
    }
    const button = actionButton((estimate.estimated ? '≈ ' : '') + formatKm(estimate.km_estimate), 'est', () => tipText(button, 'Kilometerstand heute', estimateExplanation(estimate)));
    value.appendChild(button);
    cell.appendChild(value);
    cell.appendChild(kmLabel('km heute', context));

    return cell;
}

function kpiBar(context, dueItems) {
    const bar = element('div', undefined, 'kpis');
    const overdue = dueItems.filter((item) => item.status === 'ueberfaellig').length;
    const soon = dueItems.filter((item) => item.status === 'bald' || item.rule_uncertain).length;
    const openCount = context.openItems().length;
    const maintenance = '/garage/fahrzeug.html#' + context.vehicleId + '/wartung';

    bar.appendChild(kmCell(context.vehicle.km_estimate, context));
    const overdueCell = kpi(overdue > 0 ? 'bad' : undefined);
    overdueCell.appendChild(kpiLink(maintenance, overdue, 'überfällig'));
    const soonCell = kpi(soon > 0 ? 'warn' : undefined);
    soonCell.appendChild(kpiLink(maintenance, soon, 'bald oder unsicher'));
    const openCell = kpi(openCount > 0 ? 'warn' : undefined);
    openCell.appendChild(kpiLink('/garage/klaerfaelle.html#fz=' + context.vehicleId, openCount, 'Klärfälle'));
    bar.append(overdueCell, soonCell, openCell);

    return bar;
}

function row(href, title, right, sub) {
    const anchor = link('', href, 'row');
    anchor.appendChild(element('span', title, 't'));
    const rightCell = element('span', undefined, 'r');
    if (right instanceof Node) {
        rightCell.appendChild(right);
    } else if (right !== null) {
        rightCell.textContent = right;
    }
    anchor.appendChild(rightCell);
    const subLine = element('span', undefined, 's');
    subLine.append(...sub);
    anchor.appendChild(subLine);

    return anchor;
}

function dueSection(context, result) {
    const wrapper = section('Fälligkeiten', 'Wartungsplan', '#' + context.vehicleId + '/wartung');
    wrapper.querySelector('.sec-h').insertBefore(element('span', 'nächste 36 Monate', 'muted small'), wrapper.querySelector('.more'));
    if (result.error !== null) {
        wrapper.appendChild(errorBlock(result.error.message, context.reload));

        return wrapper;
    }
    const items = result.value.items.slice(0, DUE_ROWS);
    if (items.length === 0) {
        wrapper.appendChild(element('p', 'Noch keine Wartungsregeln. Ein Agent legt sie an, sobald er Belege oder das Serviceheft kennt.', 'muted'));

        return wrapper;
    }
    const list = element('div', undefined, 'list');
    for (const item of items) {
        const sub = [document.createTextNode(item.effective_date === null ? 'Termin nicht berechenbar' : 'nächstes ' + formatDay(item.effective_date) + (item.due_km === null ? '' : ' · ' + formatKm(item.due_km) + ' km'))];
        if (item.rule_uncertain) {
            sub.push(stateWord('st unk', 'Regel unsicher'));
        }
        list.appendChild(row('#' + context.vehicleId + '/wartung', item.title, dueWord(item.status), sub));
    }
    wrapper.appendChild(list);

    return wrapper;
}

function eventSection(context, result) {
    const wrapper = section('Letztes Ereignis', 'Scheckheft', '#' + context.vehicleId + '/scheckheft');
    if (result.error !== null) {
        wrapper.appendChild(errorBlock(result.error.message, context.reload));

        return wrapper;
    }
    const latest = result.value.events[0];
    if (latest === undefined) {
        wrapper.appendChild(element('p', 'Noch kein Ereignis. Leg einen Beleg über den Kamera-Knopf in den Eingang.', 'muted'));

        return wrapper;
    }
    const parts = [formatDay(latest.performed_on)];
    if (latest.odometer_km !== null) {
        parts.push(formatKm(latest.odometer_km) + ' km');
    }
    if (latest.workshop !== null) {
        parts.push(latest.workshop.name);
    }
    const list = element('div', undefined, 'list');
    list.appendChild(row('#' + context.vehicleId + '/scheckheft', latest.title, latest.gross_eur === null ? null : formatEuro(latest.gross_eur), [document.createTextNode(parts.join(' · '))]));
    wrapper.appendChild(list);

    return wrapper;
}

function openItemRow(context, item, list, refresh) {
    const details = element('details', undefined, 'xrow');
    details.dataset.itemId = String(item.id);
    const summary = element('summary');
    summary.appendChild(element('span', item.text, 't'));
    summary.appendChild(stateWord(item.kind === 'konflikt' ? 'st warn' : 'st ok', KIND_LABELS[item.kind] ?? item.kind));
    details.appendChild(summary);
    const body = element('div', undefined, 'xbody');
    body.appendChild(openItemBody(item, {
        hide: () => {
            details.hidden = true;
        },
        show: () => {
            details.hidden = false;
        },
        done: () => {
            details.remove();
            context.removeOpenItem(item.id);
            refresh();
        },
    }));
    details.appendChild(body);
    list.appendChild(details);
}

function openItemSection(context, refresh) {
    const wrapper = section('Klärfälle', 'Alle Fahrzeuge', '/garage/klaerfaelle.html');
    const items = context.openItems();
    if (items.length === 0) {
        wrapper.appendChild(element('p', 'Keine offenen Klärfälle.', 'muted'));

        return wrapper;
    }
    const list = element('div', undefined, 'list');
    for (const item of items) {
        openItemRow(context, item, list, refresh);
    }
    wrapper.appendChild(list);

    return wrapper;
}

function noteSection(context, result) {
    const wrapper = section('Notizen');
    const canWrite = context.vehicle.permissions.write;
    if (canWrite) {
        wrapper.querySelector('.sec-h').appendChild(actionButton('Notiz anlegen', 'btn quiet sm more', () => openNoteSheet(context.vehicleId, null, context.reload)));
    }
    if (result.error !== null) {
        wrapper.appendChild(errorBlock(result.error.message, context.reload));

        return wrapper;
    }
    const notes = result.value.notes;
    if (notes.length === 0) {
        wrapper.appendChild(element('p', 'Noch keine Notizen.', 'muted'));

        return wrapper;
    }
    const list = element('div', undefined, 'list');
    for (const note of notes) {
        const details = element('details', undefined, 'xrow');
        const summary = element('summary');
        const title = element('span', note.topic, 't');
        title.appendChild(element('span', ' · Revision ' + note.revision, 'muted small'));
        summary.appendChild(title);
        details.appendChild(summary);
        const body = element('div', undefined, 'xbody');
        body.appendChild(element('pre', note.body, 'text'));
        body.appendChild(element('p', note.actor + ' · ' + formatMoment(note.updated_at), 'muted small'));
        if (canWrite) {
            const actions = element('div', undefined, 'acts');
            actions.appendChild(actionButton('Bearbeiten', 'btn sm', () => openNoteSheet(context.vehicleId, note, context.reload)));
            body.appendChild(actions);
        }
        details.appendChild(body);
        list.appendChild(details);
    }
    wrapper.appendChild(list);

    return wrapper;
}

function damageSection(context, result) {
    const wrapper = section('Schäden und Rückrufe');
    if (result.error !== null) {
        wrapper.appendChild(errorBlock(result.error.message, context.reload));

        return wrapper;
    }
    const { damages, recalls } = result.value;
    if (damages.length === 0 && recalls.length === 0) {
        wrapper.appendChild(element('p', 'Keine Schäden und Rückrufe erfasst.', 'muted'));

        return wrapper;
    }
    const list = element('div', undefined, 'list');
    for (const damage of damages) {
        const state = damage.repaired === true ? stateWord('st ok', 'repariert') : stateWord('st warn', 'offen');
        list.appendChild(row('#' + context.vehicleId + '/scheckheft', damage.kind + ' · ' + formatDay(damage.occurred_on), state, [document.createTextNode(damage.description)]));
    }
    for (const recall of recalls) {
        const isDone = recall.status === 'erledigt';
        const state = stateWord(isDone ? 'st ok' : 'st warn', recall.status);
        list.appendChild(row('#' + context.vehicleId + '/scheckheft', 'Rückruf: ' + recall.title, state, [document.createTextNode(recall.manufacturer_code ?? recall.kba_reference ?? '')]));
    }
    wrapper.appendChild(list);

    return wrapper;
}

/** Springt vom Wort „strittig“ zum zugehörigen Klärfall in der Übersicht; fehlt er dort, öffnet sich die Klärfall-Seite. */
function revealOpenItem(vehicleId, itemId) {
    const target = document.querySelector('details[data-item-id="' + itemId + '"]');
    if (target === null) {
        location.href = '/garage/klaerfaelle.html#fz=' + vehicleId;

        return;
    }
    target.open = true;
    target.scrollIntoView({ block: 'center' });
    target.querySelector('summary').focus();
}

function pencilButton(label, onClick) {
    const button = actionButton('', 'iconsm', onClick);
    button.setAttribute('aria-label', label + ' korrigieren');
    button.insertAdjacentHTML('beforeend', icons.edit);

    return button;
}

/** `edit`: Funktion, die das Blatt zum Korrigieren öffnet, oder `null` für Zeilen ohne Korrektur (Spezifikation, Ausstattung, Zulassung). */
function factActions(context, edit) {
    return {
        canWrite: context.vehicle.permissions.write,
        edit,
        openDispute: (itemId) => revealOpenItem(context.vehicleId, itemId),
    };
}

function inputKindOf(field) {
    if (field === 'erstzulassung') {
        return 'date';
    }
    if (INTEGER_FIELDS.includes(field)) {
        return 'int';
    }

    return 'text';
}

function editAction(context, field, entry) {
    const inputKind = inputKindOf(field);

    return () => openFactSheet(context.vehicleId, { field, label: FIELD_LABELS[field], current: entry.value, inputKind }, context.reloadVehicle);
}

function factRow(label, valueText, source, confidence, detail, disputedItemId, actions) {
    const fact = element('div', undefined, disputedItemId === undefined ? 'fact' : 'fact disputed');
    fact.appendChild(element('span', label, 'k'));
    const value = element('span', undefined, 'v');
    value.appendChild(element('span', valueText));
    if (disputedItemId !== undefined) {
        value.appendChild(actionButton('strittig', 'linkbtn small', () => actions.openDispute(disputedItemId)));
    }
    fact.appendChild(value);
    const badgeCell = element('span', undefined, 'b');
    if (source !== null && source !== undefined && confidence !== null && confidence !== undefined) {
        badgeCell.appendChild(badge(source, confidence, detail));
    }
    fact.appendChild(badgeCell);
    if (actions.canWrite) {
        const hasOpenDispute = disputedItemId !== undefined && disputedItemId > 0;
        const editCell = element('span');
        if (actions.edit !== null && !hasOpenDispute) {
            editCell.appendChild(pencilButton(label, actions.edit));
        }
        fact.appendChild(editCell);
    }

    return fact;
}

function entryDetail(entry, vehicleId) {
    return { actor: entry.actor, recordedAt: entry.recorded_at, sourceUrl: entry.source_url, vehicleId };
}

function primaryFactRows(context) {
    const { vehicle } = context;
    const vehicleId = context.vehicleId;
    const disputedByField = new Map(vehicle.disputed.map((entry) => [entry.field, entry]));
    const rows = [];
    for (const field of PRIMARY_FIELDS) {
        const entry = vehicle.facts[field];
        const disputed = disputedByField.get(field);
        if (entry === undefined && disputed === undefined) {
            continue;
        }
        if (entry === undefined) {
            rows.push(factRow(FIELD_LABELS[field], formatFact(field, disputed.value), disputed.source, disputed.confidence, { vehicleId }, disputed.open_item_id, factActions(context, null)));
            continue;
        }
        const disputedId = disputed === undefined ? undefined : disputed.open_item_id;
        rows.push(factRow(FIELD_LABELS[field], formatFact(field, entry.value), entry.source, entry.confidence, entryDetail(entry, vehicleId), disputedId, factActions(context, editAction(context, field, entry))));
    }

    return rows;
}

function restFactRows(context) {
    const { vehicle } = context;
    const vehicleId = context.vehicleId;
    const readOnly = factActions(context, null);
    const rows = [];
    for (const [field, entry] of Object.entries(vehicle.facts)) {
        if (!PRIMARY_FIELDS.includes(field)) {
            const isCorrectable = field in FIELD_LABELS;
            const actions = isCorrectable ? factActions(context, editAction(context, field, entry)) : readOnly;
            rows.push(factRow(FIELD_LABELS[field] ?? field, formatFact(field, entry.value), entry.source, entry.confidence, entryDetail(entry, vehicleId), undefined, actions));
        }
    }
    for (const [area, keys] of Object.entries(vehicle.specs)) {
        for (const [key, entry] of Object.entries(keys)) {
            rows.push(factRow(area + ' · ' + key, formatFact(key, entry.value), entry.source, entry.confidence, entryDetail(entry, vehicleId), undefined, readOnly));
        }
    }
    for (const equipment of vehicle.equipment) {
        const code = equipment.code === null ? '' : ' (' + equipment.code + ')';
        rows.push(factRow('Ausstattung', equipment.name + code, equipment.source, equipment.confidence, { sourceUrl: equipment.source_url, vehicleId }, undefined, readOnly));
    }
    for (const registration of vehicle.registrations) {
        const period = formatDay(registration.valid_from) + ' – ' + (registration.valid_to === null ? 'heute' : formatDay(registration.valid_to));
        const holder = registration.holder === null ? '' : ' · ' + registration.holder;
        rows.push(factRow('Zulassung', registration.plate + ' · ' + period + holder, registration.source, registration.confidence, { sourceUrl: registration.source_url, vehicleId }, undefined, readOnly));
    }

    return rows;
}

function factSection(context) {
    const wrapper = section('Angaben');
    const primary = primaryFactRows(context);
    const rest = restFactRows(context);
    if (primary.length === 0 && rest.length === 0) {
        wrapper.appendChild(element('p', 'Noch keine Angaben. Ein Agent liest sie aus dem Fahrzeugschein, wenn du ihm ein Foto gibst.', 'muted'));

        return wrapper;
    }
    const facts = element('div', undefined, 'facts two');
    facts.append(...primary);
    wrapper.appendChild(facts);
    if (rest.length > 0) {
        const toggle = actionButton('Alle Angaben, Ausstattung, Zulassungen', 'btn quiet sm more', () => {
            const isOpen = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', String(!isOpen));
            toggle.textContent = isOpen ? 'Alle Angaben, Ausstattung, Zulassungen' : 'Weniger anzeigen';
            if (isOpen) {
                for (const extra of rest) {
                    extra.remove();
                }
            } else {
                facts.append(...rest);
            }
        });
        toggle.setAttribute('aria-expanded', 'false');
        wrapper.querySelector('.sec-h').appendChild(toggle);
    }

    return wrapper;
}

/**
 * `context`: `{ vehicleId, vehicle, openItems(), removeOpenItem(id), onOpenItemsChanged(), reload() }`.
 * Füllt `panel`; jeder Bereich zeigt seinen Fehler selbst, die übrigen bleiben nutzbar.
 */
export async function renderOverview(panel, context) {
    const base = 'vehicles/' + context.vehicleId;
    const [due, events, notes, damages] = await Promise.all([
        settle(request('GET', base + '/due')),
        settle(request('GET', base + '/events')),
        settle(request('GET', base + '/notes')),
        settle(request('GET', base + '/damages')),
    ]);
    clearChildren(panel);

    const kpiHost = element('div');
    const openHost = element('div');
    const dueItems = due.error === null ? due.value.items : [];
    function refreshOpenItems() {
        clearChildren(kpiHost);
        kpiHost.appendChild(kpiBar(context, dueItems));
        clearChildren(openHost);
        openHost.appendChild(openItemSection(context, refreshOpenItems));
        context.onOpenItemsChanged();
    }
    refreshOpenItems();

    const columns = element('div', undefined, 'cols');
    const left = element('div');
    left.appendChild(dueSection(context, due));
    left.appendChild(eventSection(context, events));
    const right = element('div');
    right.appendChild(openHost);
    right.appendChild(noteSection(context, notes));
    right.appendChild(damageSection(context, damages));
    columns.append(left, right);

    panel.appendChild(kpiHost);
    panel.appendChild(columns);
    panel.appendChild(factSection(context));
}
