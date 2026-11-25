// Reiter „Papiere“: Notfallkarte, Stichtage, Verträge mit Prüfzeile, Plaketten, Zubehör, Schlüssel und Papiere. Stichtage, Anteile und Prüfzeilen rechnet der Server.
import { request } from './api.js';
import { actionButton, clearChildren, element, formatDay } from './dom.js';
import * as icons from './icons.js';
import { badge, tipText } from './provenance.js';
import { deleteRecord } from './record-delete.js';
import { entryDetail, factActions, factRow } from './vehicle-overview.js';
import { openFactSheet } from './vehicle-inputs.js';
import { emptyBlock, errorBlock, formatEuro, formatKm, section, stateWord } from './widgets.js';

const DAY_MS = 86_400_000;
const WARN_DAYS = 30;
const DAYS_PER_WEEK = 7;
const DAYS_PER_MONTH = 30.44;
const WEEKS_FROM_DAYS = 31;
const MONTHS_FROM_DAYS = 70;
const FULL_YEAR_DAYS = [365, 366];
const KIND_INSURANCE = 'versicherung';
const KIND_TAX = 'steuer';
const LEVEL_GARAGE = 'garage';
const EVENT_DONE = 'erledigt';
const CO2_FIELD = 'co2_g_km';
const CO2_LABEL = 'CO₂ (g/km)';
const ALLOCATION_KM = 'km';

const CONTRACT_LABELS = {
    versicherung: 'Kfz-Versicherung',
    steuer: 'Kfz-Steuer',
    club: 'Automobilclub',
    stellplatz: 'Stellplatz',
    finanzierung: 'Finanzierung',
    sonstiges: 'Sonstiger Vertrag',
};
const EVENT_LABELS = {
    beitragsrechnung: 'Beitragsrechnung',
    aenderung: 'Änderung',
    schadenmeldung: 'Schadenmeldung',
    regulierung: 'Regulierung',
    rueckstufung: 'Rückstufung',
    kuendigung: 'Kündigung',
};
const EVENT_STATES = {
    offen: { className: 'st warn', label: 'offen' },
    in_bearbeitung: { className: 'st warn', label: 'in Bearbeitung' },
    erledigt: { className: 'st ok', label: 'erledigt' },
};
const CHECK_CLASSES = { ok: 'st ok', warn: 'st warn', unbekannt: 'st unk' };
const ALLOCATION_LABELS = { gleich: 'gleich auf alle aktiven Autos', km: 'nach gefahrenen Kilometern', fest: 'nach festen Anteilen' };
const PERMIT_LABELS = {
    umweltplakette: 'Umweltplakette',
    critair: 'Crit’Air',
    vignette: 'Vignette',
    parkausweis: 'Parkausweis',
    mautbox: 'Mautbox',
    sonstiges: 'Sonstiges',
};
const MOUNT_LABELS = { montiert: 'montiert', eingelagert: 'eingelagert' };
const KEY_FIELDS = [
    { field: 'schluesselanzahl', label: 'Schlüssel', inputKind: 'int' },
    { field: 'ablageort_papiere', label: 'Ablageort Papiere', inputKind: 'text' },
];

function euro(amount) {
    return formatEuro(Number(amount));
}

function todayIso() {
    return new Date().toLocaleDateString('sv-SE');
}

function daysUntil(date) {
    return Math.round((Date.parse(date) - Date.parse(todayIso())) / DAY_MS);
}

function remainingText(days) {
    if (days === 0) {
        return 'heute';
    }
    if (days === 1) {
        return 'morgen';
    }
    if (days < WEEKS_FROM_DAYS) {
        return 'in ' + days + ' Tagen';
    }
    if (days < MONTHS_FROM_DAYS) {
        return 'in ' + Math.round(days / DAYS_PER_WEEK) + ' Wochen';
    }

    return 'in ' + Math.round(days / DAYS_PER_MONTH) + ' Monaten';
}

/** `MM-TT` → `TT.MM.` */
function dayOfYear(value) {
    return value.slice(3, 5) + '.' + value.slice(0, 2) + '.';
}

function joined(parts) {
    return parts.filter((part) => part !== null && part !== undefined && part !== '').join(' · ');
}

function trashButton(context, type, id, noun, description) {
    const button = actionButton('', 'del', () => deleteRecord(type, id, noun, context.reload));
    button.insertAdjacentHTML('beforeend', icons.trash);
    button.setAttribute('aria-label', description + ' löschen');

    return button;
}

function whyButton(title, explanation) {
    const button = actionButton('Rechenweg', 'linkbtn', () => tipText(button, title, explanation));

    return button;
}

// --- Notfallkarte, Stichtage ----------------------------------------------------------------------------------------

function phoneLine(number) {
    const line = element('div');
    const anchor = element('a', number);
    anchor.href = 'tel:' + number.replace(/[^+\d]/g, '');
    line.appendChild(anchor);

    return line;
}

function emergencyEntry(list, term, text, phone) {
    list.appendChild(element('dt', term));
    const definition = element('dd');
    definition.appendChild(text === null ? element('span', 'nicht erfasst', 'muted') : document.createTextNode(text));
    if (phone !== null && phone !== undefined) {
        definition.appendChild(phoneLine(phone));
    }
    list.appendChild(definition);
}

function emergencySection(emergency) {
    const { node } = section('Notfallkarte');
    const card = element('div', undefined, 'ecard');
    const head = element('div', undefined, 'hd');
    head.appendChild(element('b', emergency.label));
    card.appendChild(head);

    const list = element('dl');
    emergencyEntry(list, 'Kennzeichen', emergency.plate, null);
    emergencyEntry(list, 'FIN', emergency.fin, null);
    const { insurer, breakdown } = emergency;
    if (insurer === null) {
        list.appendChild(element('dt', 'Versicherer'));
        const definition = element('dd');
        definition.appendChild(element('span', 'fehlt — Police im Chat an GARAGE geben', 'gap'));
        list.appendChild(definition);
    } else {
        emergencyEntry(list, 'Versicherer', joined([insurer.provider, insurer.contract_number]), insurer.hotline);
    }
    if (breakdown === null) {
        emergencyEntry(list, 'Pannenhilfe', null, null);
    } else {
        const member = breakdown.member_number === null ? null : 'Mitglied ' + breakdown.member_number;
        emergencyEntry(list, 'Pannenhilfe', joined([breakdown.provider, member]), breakdown.hotline);
    }
    card.append(list, element('div', 'Stand ' + formatDay(emergency.as_of), 'muted small'));
    node.appendChild(card);

    return node;
}

function deadlineRow(deadline) {
    const row = actionButton('', 'row', () => tipText(row, deadline.title, deadline.explanation));
    const right = element('span', undefined, 'r');
    right.appendChild(stateWord(deadline.status === 'warn' ? 'st warn' : 'st ok', remainingText(deadline.days)));
    row.append(element('span', deadline.title, 't'), right, element('span', formatDay(deadline.date), 's'));

    return row;
}

function deadlineSection(deadlines) {
    const { node } = section('Stichtage', 'auch im Kalender-Abo');
    if (deadlines.length === 0) {
        node.appendChild(element('p', 'Keine Stichtage in den nächsten 24 Monaten.', 'muted small'));

        return node;
    }
    const list = element('div', undefined, 'list');
    list.append(...deadlines.map(deadlineRow));
    node.appendChild(list);

    return node;
}

// --- Verträge -------------------------------------------------------------------------------------------------------

/** Die heute gültige Periode, sonst die jüngste — dieselbe Wahl wie beim Anteil des Servers. `terms` ist absteigend sortiert. */
function currentTerm(terms) {
    const today = todayIso();

    return terms.find((term) => term.valid_from <= today && term.valid_to >= today) ?? terms[0] ?? null;
}

/** Beitrag aufs Jahr; Perioden mit anderer Länge als einem Jahr sind hochgerechnet und tragen „≈“. */
function yearlyText(term) {
    const days = Math.round((Date.parse(term.valid_to) - Date.parse(term.valid_from)) / DAY_MS) + 1;
    if (FULL_YEAR_DAYS.includes(days)) {
        return euro(term.amount_eur) + '/Jahr';
    }

    return '≈ ' + euro((Number(term.amount_eur) * FULL_YEAR_DAYS[0]) / days) + '/Jahr';
}

function headAmount(contract, term) {
    if (contract.level === LEVEL_GARAGE) {
        const share = contract.share.eur_per_year;
        if (share !== null) {
            return euro(share) + '/Jahr';
        }

        return contract.share.method === ALLOCATION_KM ? 'Anteil nach km' : 'Anteil offen';
    }

    return term === null ? 'Beitrag fehlt' : yearlyText(term);
}

function typeClasses(term) {
    return [['HP', term.type_class_liability], ['TK', term.type_class_partial], ['VK', term.type_class_full]]
        .filter(([, value]) => value !== null)
        .map(([name, value]) => name + ' ' + value)
        .join(' / ');
}

function classText(term) {
    if (term === null) {
        return '';
    }
    const noClaims = joined([term.sf_liability, term.sf_comprehensive === term.sf_liability ? null : term.sf_comprehensive]);

    return joined([noClaims, typeClasses(term), term.regional_class === null ? null : 'Regional ' + term.regional_class]);
}

function dueText(contract) {
    if (contract.main_due === null) {
        return '';
    }
    const notice = contract.notice_months === null ? null : 'Kündigungsfrist ' + contract.notice_months + (contract.notice_months === 1 ? ' Monat' : ' Monate');

    return joined([dayOfYear(contract.main_due), notice]);
}

function taxBasis(facts) {
    const displacement = facts.hubraum_ccm?.value;
    const emissions = facts.co2_g_km?.value;
    const registered = facts.erstzulassung?.value;

    return joined([
        displacement === undefined ? 'Hubraum fehlt' : Number(displacement).toLocaleString('de-DE') + ' cm³',
        emissions === undefined ? 'CO₂ fehlt' : emissions + ' g/km CO₂',
        registered === undefined ? null : 'EZ ' + String(registered).slice(5, 7) + '/' + String(registered).slice(0, 4),
    ]);
}

function premiumText(contract, term) {
    const today = todayIso();
    const upcoming = [...contract.terms].reverse().find((entry) => entry.valid_from > today);
    const next = upcoming === undefined || upcoming === term ? null : 'ab ' + formatDay(upcoming.valid_from) + ' ' + yearlyText(upcoming);

    return joined([term === null ? null : yearlyText(term), next]);
}

function allocationText(share) {
    const percent = share.percent === null ? null : 'Anteil ' + share.percent.toLocaleString('de-DE') + ' %';

    return joined([ALLOCATION_LABELS[share.method] ?? share.method, percent]);
}

/** Angaben je Vertragsart; leere Zeilen fallen weg. */
function contractDetails(context, contract, term) {
    const number = contract.contract_number ?? (contract.member_number === null ? null : 'Mitglied ' + contract.member_number);
    const rows = [['Vertrag', joined([number, term?.coverage])]];
    if (contract.kind === KIND_INSURANCE) {
        const declared = term?.declared_km_per_year ?? null;
        rows.push(
            ['Hauptfälligkeit', dueText(contract)],
            ['SF / Typklasse', classText(term)],
            ['Fahrleistung', declared === null ? '' : formatKm(declared) + ' km angegeben'],
        );
    } else {
        rows.push(['Fälligkeit', contract.main_due === null ? '' : 'jährlich zum ' + dayOfYear(contract.main_due)]);
    }
    if (contract.kind === KIND_TAX) {
        rows.push(['Bemessung', taxBasis(context.vehicle.facts)]);
    }
    if (contract.level === LEVEL_GARAGE) {
        rows.push(['Beitrag', premiumText(contract, term)], ['Umlage', allocationText(contract.share)]);
    }
    rows.push(['Läuft bis', contract.ends_on === null ? '' : formatDay(contract.ends_on)]);

    const list = element('dl');
    for (const [name, value] of rows) {
        if (value !== '') {
            list.append(element('dt', name), element('dd', value));
        }
    }

    return list;
}

/** Nächster Kündigungsstichtag dieses Vertrags innerhalb von 30 Tagen; Sonderkündigungen hängen an einem seiner Vorgänge. */
function noticeBanner(contract, deadlines) {
    const eventIds = contract.events.map((event) => event.id);
    // Die Liste kommt nach Datum sortiert: der erste Treffer ist der nächste.
    const next = deadlines.find((deadline) => deadline.days <= WARN_DAYS
        && ((deadline.kind === 'kuendigung' && deadline.ref.type === 'contract' && deadline.ref.id === contract.id)
            || (deadline.kind === 'sonderkuendigung' && eventIds.includes(deadline.ref.id))));
    if (next === undefined) {
        return null;
    }
    const banner = element('div', undefined, 'banner');
    const text = (next.kind === 'sonderkuendigung' ? 'Sonderkündigung bis ' : 'Kündigen bis ') + formatDay(next.date);
    banner.append(element('b', text), whyButton(text, next.explanation));

    return banner;
}

function canEnterCo2(context, contract) {
    const isDisputed = context.vehicle.disputed.some((entry) => entry.field === CO2_FIELD);

    return contract.kind === KIND_TAX && context.vehicle.facts[CO2_FIELD] === undefined && !isDisputed && context.vehicle.permissions.write;
}

function checkLine(context, contract) {
    const { check } = contract;
    if (check === null) {
        return null;
    }
    const line = element('div', undefined, 'chk');
    const isUnknown = check.status === 'unbekannt';
    if (isUnknown) {
        const mark = element('span', '?', 'vf unk');
        mark.setAttribute('aria-hidden', 'true');
        line.appendChild(mark);
    }
    const text = isUnknown ? 'nicht prüfbar: ' + check.text : check.text;
    line.append(stateWord(CHECK_CLASSES[check.status] ?? 'st unk', text), whyButton('Rechenweg', check.explanation));
    if (isUnknown && canEnterCo2(context, contract)) {
        line.appendChild(actionButton('CO₂ eintragen', 'btn sm', () => openFactSheet(
            context.vehicleId,
            { field: CO2_FIELD, label: CO2_LABEL, current: '', inputKind: 'int' },
            context.reloadVehicle,
        )));
    }

    return line;
}

function termHistory(terms) {
    if (terms.length === 0) {
        return null;
    }
    const years = new Set(terms.map((term) => term.valid_from.slice(0, 4))).size;
    const details = element('details');
    details.appendChild(element('summary', 'Beitragsverlauf · ' + years + (years === 1 ? ' Jahr' : ' Jahre')));
    const table = element('table', undefined, 'tbl');
    const headRow = element('tr');
    for (const [title, className] of [['Jahr'], ['Beitrag', 'num'], ['SF'], ['Typklasse']]) {
        headRow.appendChild(element('th', title, className));
    }
    const head = element('thead');
    head.appendChild(headRow);
    const body = element('tbody');
    for (const term of [...terms].reverse()) {
        const row = element('tr');
        row.append(
            element('td', term.valid_from.slice(0, 4)),
            element('td', euro(term.amount_eur), 'num'),
            element('td', term.sf_liability ?? '–'),
            element('td', typeClasses(term) || '–'),
        );
        body.appendChild(row);
    }
    table.append(head, body);
    details.appendChild(table);

    return details;
}

/** Vorgänge an Garagen-Verträgen löscht man nicht aus der Sicht eines einzelnen Fahrzeugs. */
function eventRow(context, contract, event, deadlines) {
    const kind = EVENT_LABELS[event.kind] ?? event.kind;
    const state = EVENT_STATES[event.event_status] ?? { className: 'st unk', label: event.event_status };
    const row = element('div', undefined, 'row');
    const right = element('span', undefined, 'r');
    right.appendChild(stateWord(state.className, state.label));
    row.append(element('span', kind, 't'), right);

    const special = deadlines.find((deadline) => deadline.ref.type === 'contract_event' && deadline.ref.id === event.id);
    const sub = element('span', undefined, 's');
    sub.appendChild(element('span', joined([
        formatDay(event.occurred_on),
        event.amount_eur === null ? null : euro(event.amount_eur),
        special === undefined ? null : 'Sonderkündigung bis ' + formatDay(special.date),
        event.claim_number,
        event.note,
    ])));
    if (context.vehicle.permissions.write && contract.level !== LEVEL_GARAGE) {
        sub.appendChild(trashButton(context, 'contract_event', event.id, 'Vorgang', kind + ' vom ' + formatDay(event.occurred_on)));
    }
    row.appendChild(sub);

    return row;
}

function eventList(context, contract, deadlines) {
    if (contract.events.length === 0) {
        return null;
    }
    const open = contract.events.filter((event) => event.event_status !== EVENT_DONE).length;
    const details = element('details');
    details.open = open > 0;
    details.appendChild(element('summary', 'Vorgänge · ' + open + ' offen'));
    const list = element('div', undefined, 'list');
    list.append(...contract.events.map((event) => eventRow(context, contract, event, deadlines)));
    details.appendChild(list);

    return details;
}

function contractCard(context, contract, deadlines) {
    const isGarage = contract.level === LEVEL_GARAGE;
    const label = CONTRACT_LABELS[contract.kind] ?? contract.kind;
    const term = currentTerm(contract.terms);
    const card = element('article', undefined, 'ctr');

    const head = element('div', undefined, 'hd');
    head.appendChild(element('b', label));
    if (isGarage) {
        head.appendChild(element('span', 'Garage', 'kindtag'));
    }
    head.appendChild(element('span', headAmount(contract, term), 'r'));

    const foot = element('div', undefined, 'ft');
    foot.appendChild(badge(contract.source, contract.confidence));
    if (context.vehicle.permissions.write && !isGarage) {
        const remove = actionButton('Vertrag löschen', 'btn sm danger', () => deleteRecord('contract', contract.id, 'Vertrag', context.reload));
        remove.setAttribute('aria-label', label + ' bei ' + contract.provider + ' löschen');
        foot.appendChild(remove);
    }

    const parts = [
        head,
        element('div', joined([contract.provider, contract.tariff]), 'muted small'),
        noticeBanner(contract, deadlines),
        contractDetails(context, contract, term),
        checkLine(context, contract),
        termHistory(contract.terms),
        eventList(context, contract, deadlines),
        foot,
    ];
    card.append(...parts.filter((part) => part !== null));

    return card;
}

function missingInsuranceCard() {
    const card = element('article', undefined, 'ctr missing');
    const head = element('div', undefined, 'hd');
    head.append(element('b', CONTRACT_LABELS[KIND_INSURANCE]), stateWord('st warn', 'fehlt'));
    card.append(head, element('p', 'Police oder Beitragsrechnung im Chat an GARAGE geben — der Agent legt Vertrag und Beitrag an.', 'muted small'));

    return card;
}

function contractSection(context, papers) {
    const count = papers.contracts.length;
    const { node } = section('Verträge', count + (count === 1 ? ' Vertrag' : ' Verträge'));
    const grid = element('div', undefined, 'contracts');
    if (papers.emergency.insurer === null) {
        grid.appendChild(missingInsuranceCard());
    }
    grid.append(...papers.contracts.map((contract) => contractCard(context, contract, papers.deadlines)));
    node.appendChild(grid);

    return node;
}

// --- Plaketten, Zubehör, Schlüssel und Papiere ----------------------------------------------------------------------

function permitState(validUntil) {
    if (validUntil === null) {
        return stateWord('st ok', 'unbegrenzt');
    }
    const days = daysUntil(validUntil);
    if (days < 0) {
        return stateWord('st bad', 'abgelaufen ' + formatDay(validUntil));
    }

    return stateWord(days <= WARN_DAYS ? 'st warn' : 'st ok', 'bis ' + formatDay(validUntil));
}

function recordRow(title, right, details, trash) {
    const row = element('div', undefined, 'row');
    const rightCell = element('span', undefined, 'r');
    if (right !== null) {
        rightCell.appendChild(right);
    }
    const sub = element('span', undefined, 's');
    sub.appendChild(element('span', details));
    if (trash !== null) {
        sub.appendChild(trash);
    }
    row.append(element('span', title, 't'), rightCell, sub);

    return row;
}

function listSection(title, rows, emptyText) {
    const { node } = section(title);
    if (rows.length === 0) {
        node.appendChild(element('p', emptyText, 'muted small'));

        return node;
    }
    const list = element('div', undefined, 'list');
    list.append(...rows);
    node.appendChild(list);

    return node;
}

function permitRow(context, permit) {
    const kind = PERMIT_LABELS[permit.kind] ?? permit.kind;
    const trash = context.vehicle.permissions.write ? trashButton(context, 'permit', permit.id, 'Plakette', permit.label) : null;

    return recordRow(kind, permitState(permit.valid_until), joined([permit.label, permit.number]), trash);
}

function accessoryRow(context, accessory) {
    let sale = null;
    if (accessory.goes_with_sale !== null) {
        sale = element('span', accessory.goes_with_sale ? 'geht mit' : 'bleibt', 'muted small');
    }
    const details = joined([
        MOUNT_LABELS[accessory.mount_state] ?? accessory.mount_state,
        accessory.storage_location,
        accessory.warranty_until === null ? null : 'Garantie bis ' + formatDay(accessory.warranty_until),
    ]);
    const trash = context.vehicle.permissions.write ? trashButton(context, 'accessory', accessory.id, 'Zubehör', accessory.name) : null;

    return recordRow(accessory.name, sale, details, trash);
}

/** Beide Angaben stehen immer da, damit sie sich auch erstmals eintragen lassen. */
function keyRow(context, { field, label, inputKind }) {
    const { vehicle, vehicleId } = context;
    const entry = vehicle.facts[field];
    const disputed = vehicle.disputed.find((item) => item.field === field);
    const shown = entry ?? disputed;
    const edit = () => openFactSheet(vehicleId, { field, label, current: entry?.value ?? '', inputKind }, context.reloadVehicle);
    const detail = entry === undefined ? { vehicleId } : entryDetail(entry, vehicleId);

    return factRow(label, shown === undefined ? 'nicht erfasst' : String(shown.value), shown?.source, shown?.confidence, detail, disputed?.open_item_id, factActions(context, edit));
}

function keySection(context) {
    const { node } = section('Schlüssel und Papiere');
    const facts = element('div', undefined, 'facts');
    facts.append(...KEY_FIELDS.map((definition) => keyRow(context, definition)));
    node.appendChild(facts);

    return node;
}

// --- Reiter ---------------------------------------------------------------------------------------------------------

function hasNoPapers(context, papers) {
    const hasKeyFact = KEY_FIELDS.some(({ field }) => context.vehicle.facts[field] !== undefined);

    return !hasKeyFact && papers.contracts.length === 0 && papers.permits.length === 0 && papers.accessories.length === 0;
}

/** `context`: `{ vehicleId, vehicle, reload(), reloadVehicle() }` wie beim Reiter Übersicht. */
export async function renderPapers(container, context) {
    let papers;
    try {
        papers = await request('GET', 'vehicles/' + context.vehicleId + '/papers');
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    clearChildren(container);
    if (hasNoPapers(context, papers)) {
        container.appendChild(emptyBlock(icons.car, 'Noch keine Papiere', 'Gib deinem Agenten Police, Steuerbescheid oder ADAC-Ausweis im Chat — er legt Verträge mit Stichtagen an.'));

        return;
    }

    const top = element('div', undefined, 'cols');
    top.append(emergencySection(papers.emergency), deadlineSection(papers.deadlines));

    const bottom = element('div', undefined, 'cols');
    bottom.append(
        listSection('Plaketten und Vignetten', papers.permits.map((permit) => permitRow(context, permit)), 'Keine Plaketten erfasst.'),
        listSection('Zubehör', papers.accessories.map((accessory) => accessoryRow(context, accessory)), 'Kein Zubehör erfasst. Dachträger, Kindersitz, Dashcam — Foto und Rechnung an den Agenten.'),
    );

    container.append(top, contractSection(context, papers), bottom, keySection(context));
}
