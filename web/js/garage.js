// Einstieg für garage.html: Kennzahlen, Status-Filter und je Garage eine Gruppe Fahrzeugkarten.
import { ApiError, request } from './api.js';
import { actionButton, clearChildren, element, formatDay, link } from './dom.js';
import { storeEmergency } from './emergency.js';
import * as icons from './icons.js';
import { requireSession } from './session.js';
import { mountShell } from './shell.js';
import { registerWorker } from './share.js';
import { toast } from './toast.js';
import { dueWord, emptyBlock, errorBlock, formatKm, plate, skeletons, stateWord } from './widgets.js';

registerWorker();
mountShell({ active: 'garage' });

const FILTERS = [
    { key: 'aktiv', label: 'Aktiv' },
    { key: 'verkauft', label: 'Verkauft' },
    { key: 'stillgelegt', label: 'Stillgelegt' },
    { key: 'alle', label: 'Alle' },
];
const DELETED_FILTER = { key: 'geloescht', label: 'Gelöscht' };
const OWNER_ROLE = 'inhaber';
const ACTIVE_STATUS = 'aktiv';
const DAY_MS = 86_400_000;
const EMPTY_HINT = 'Sag deinem Agenten im Chat: „Leg meinen Golf in GARAGE an“ und gib ihm ein Foto vom Fahrzeugschein.';

const statusLine = document.getElementById('status');
const content = document.getElementById('content');

let garages = [];
let totals = null;
let activeFilter = 'aktiv';
let deletedVehicles = null;

function allVehicles() {
    return garages.flatMap((garage) => garage.vehicles);
}

function kpi(value, label, { href, tone }) {
    const cell = element('div', undefined, tone === undefined ? 'kpi' : 'kpi ' + tone);
    cell.setAttribute('role', 'listitem');
    const inner = href === undefined ? element('div') : link('', href);
    inner.appendChild(element('div', String(value), 'v'));
    inner.appendChild(element('div', label, 'l'));
    cell.appendChild(inner);

    return cell;
}

function kpiBar() {
    const bar = element('div', undefined, 'kpis');
    bar.setAttribute('role', 'list');
    const activeCount = allVehicles().filter((vehicle) => vehicle.status === 'aktiv').length;
    const overdue = totals.overdue;
    bar.appendChild(kpi(activeCount, 'Fahrzeuge aktiv', { href: '/garage/garage.html' }));
    bar.appendChild(kpi(overdue.count, 'überfällig', {
        href: overdue.count > 0 ? '/garage/fahrzeug.html#' + overdue.firstVehicleId + '/wartung' : undefined,
        tone: overdue.count > 0 ? 'bad' : undefined,
    }));
    bar.appendChild(kpi(totals.openItems, 'Klärfälle offen', { href: '/garage/klaerfaelle.html', tone: totals.openItems > 0 ? 'warn' : undefined }));
    bar.appendChild(kpi(totals.newInbox, 'neu im Eingang', { href: '/garage/eingang.html' }));

    return bar;
}

function isOwnerSomewhere() {
    return garages.some((garage) => garage.role === OWNER_ROLE);
}

function failureText(error, fallback) {
    return error instanceof ApiError ? error.message : fallback;
}

async function selectFilter(key) {
    activeFilter = key;
    if (key === DELETED_FILTER.key && deletedVehicles === null) {
        try {
            deletedVehicles = (await request('GET', 'vehicles?deleted=1')).vehicles;
        } catch (error) {
            activeFilter = 'aktiv';
            toast(failureText(error, 'Die gelöschten Fahrzeuge konnten nicht geladen werden.'));
        }
    }
    render();
}

function filterBar() {
    const bar = element('div', undefined, 'tb');
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', 'Status filtern');
    const filters = isOwnerSomewhere() ? [...FILTERS, DELETED_FILTER] : FILTERS;
    for (const filter of filters) {
        const button = actionButton(filter.label, 'chip', () => selectFilter(filter.key));
        button.setAttribute('aria-pressed', String(activeFilter === filter.key));
        bar.appendChild(button);
    }

    return bar;
}

function nextDueLine(vehicle) {
    const wrapper = element('dd');
    if (vehicle.next_due === null) {
        wrapper.appendChild(element('span', 'nichts berechenbar', 'muted'));

        return wrapper;
    }
    wrapper.appendChild(dueWord(vehicle.next_due.status));
    const title = vehicle.next_due.effective_date === null ? vehicle.next_due.title : vehicle.next_due.title + ' · ' + formatDay(vehicle.next_due.effective_date);
    wrapper.appendChild(element('div', title, 'muted small'));

    return wrapper;
}

function thumb(vehicle) {
    const box = element('div', undefined, 'thumb');
    if (vehicle.cover_url === null) {
        box.insertAdjacentHTML('beforeend', icons.car);

        return box;
    }
    box.style.backgroundImage = 'url("' + vehicle.cover_url + '")';
    box.setAttribute('role', 'img');
    box.setAttribute('aria-label', 'Titelbild ' + vehicle.label);

    return box;
}

function metaBlock(term, definition) {
    const block = element('div');
    block.appendChild(element('dt', term));
    block.appendChild(definition);

    return block;
}

function kmLine(vehicle) {
    const definition = element('dd');
    definition.textContent = vehicle.km_estimate === null ? 'kein Stand' : '≈ ' + formatKm(vehicle.km_estimate);

    return definition;
}

function carCard(vehicle) {
    const isRetired = vehicle.status !== 'aktiv';
    const card = element('a', undefined, isRetired ? 'car retired' : 'car');
    card.href = '/garage/fahrzeug.html#' + vehicle.id + '/uebersicht';
    if (vehicle.cover_url !== null) {
        const backdrop = element('div', undefined, 'bg');
        backdrop.style.backgroundImage = 'url("' + vehicle.cover_url + '")';
        card.appendChild(backdrop);
    }
    const inner = element('div', undefined, 'in');
    const top = element('div', undefined, 'top');
    top.appendChild(thumb(vehicle));
    const title = element('div');
    title.style.minWidth = '0';
    title.style.display = 'grid';
    title.style.gap = '.35rem';
    const sign = plate(vehicle.plate);
    if (sign !== null) {
        title.appendChild(sign);
    }
    title.appendChild(element('div', vehicle.label, 'name'));
    top.appendChild(title);
    inner.appendChild(top);

    if (isRetired) {
        inner.appendChild(element('div', vehicle.status, 'muted small'));
        card.appendChild(inner);

        return card;
    }
    const meta = element('dl', undefined, 'meta');
    meta.appendChild(metaBlock('km heute', kmLine(vehicle)));
    meta.appendChild(metaBlock('Nächste Fälligkeit', nextDueLine(vehicle)));
    inner.appendChild(meta);
    const foot = element('div', undefined, 'foot');
    if (vehicle.open_items > 0) {
        foot.appendChild(stateWord('st warn', vehicle.open_items === 1 ? '1 Klärfall' : vehicle.open_items + ' Klärfälle'));
    } else {
        foot.appendChild(stateWord('st ok', 'keine Klärfälle'));
    }
    foot.appendChild(element('span', vehicle.document_count + ' Belege', 'muted'));
    inner.appendChild(foot);
    card.appendChild(inner);

    return card;
}

function countsLine(vehicles) {
    const parts = [];
    for (const filter of FILTERS.slice(0, 3)) {
        const count = vehicles.filter((vehicle) => vehicle.status === filter.key).length;
        if (count > 0) {
            parts.push(count + ' ' + filter.key);
        }
    }

    return parts.join(', ');
}

function daysLeft(vehicle) {
    return Math.max(0, Math.ceil((new Date(vehicle.restorable_until).getTime() - Date.now()) / DAY_MS));
}

async function restoreVehicle(vehicle, { shouldReload }) {
    try {
        await request('POST', 'vehicles/' + vehicle.id + '/restore', {});
    } catch (error) {
        toast(failureText(error, vehicle.label + ' konnte nicht wiederhergestellt werden.'));

        return;
    }
    deletedVehicles = null;
    activeFilter = 'aktiv';
    toast(vehicle.label + ' wiederhergestellt');
    if (shouldReload) {
        await load();
    }
}

function deletedCard(vehicle) {
    const card = element('div', undefined, 'car retired');
    const inner = element('div', undefined, 'in');
    const top = element('div', undefined, 'top');
    const thumbBox = element('div', undefined, 'thumb');
    thumbBox.insertAdjacentHTML('beforeend', icons.car);
    top.appendChild(thumbBox);
    top.appendChild(element('div', vehicle.label, 'name'));
    inner.appendChild(top);
    const reason = vehicle.status_reason === null || vehicle.status_reason === '' ? '' : ' · ' + vehicle.status_reason;
    inner.appendChild(element('div', 'gelöscht am ' + formatDay(vehicle.deleted_at) + reason + ' · noch ' + daysLeft(vehicle) + ' Tage wiederherstellbar', 'muted small'));
    const foot = element('div');
    foot.appendChild(actionButton('Wiederherstellen', 'btn sm', () => restoreVehicle(vehicle, { shouldReload: true })));
    inner.appendChild(foot);
    card.appendChild(inner);

    return card;
}

function deletedGroup(garage) {
    const group = element('section', undefined, 'group');
    const head = element('div', undefined, 'group-h');
    head.appendChild(element('h2', garage.name));
    head.appendChild(element('span', roleLabel(garage.role), 'muted small'));
    group.appendChild(head);
    const cars = element('div', undefined, 'cars');
    const shown = deletedVehicles.filter((vehicle) => vehicle.garage_id === garage.id);
    for (const vehicle of shown) {
        cars.appendChild(deletedCard(vehicle));
    }
    if (shown.length === 0) {
        cars.appendChild(emptyBlock(icons.car, 'Keine gelöschten Fahrzeuge der letzten 30 Tage.', ''));
    }
    group.appendChild(cars);

    return group;
}

function roleLabel(role) {
    return role.charAt(0).toUpperCase() + role.slice(1);
}

function garageGroup(garage) {
    const shown = garage.vehicles.filter((vehicle) => activeFilter === 'alle' || vehicle.status === activeFilter);
    const group = element('section', undefined, 'group');
    const head = element('div', undefined, 'group-h');
    head.appendChild(element('h2', garage.name));
    head.appendChild(element('span', roleLabel(garage.role) + (garage.vehicles.length === 0 ? '' : ' · ' + countsLine(garage.vehicles)), 'muted small'));
    group.appendChild(head);
    const cars = element('div', undefined, 'cars');
    for (const vehicle of shown) {
        cars.appendChild(carCard(vehicle));
    }
    if (shown.length === 0) {
        cars.appendChild(emptyBlock(icons.car, 'Kein Fahrzeug mit diesem Status', 'Wähle „Alle“, um jedes Fahrzeug dieser Garage zu sehen.'));
    }
    group.appendChild(cars);

    return group;
}

function render() {
    clearChildren(content);
    if (allVehicles().length === 0) {
        content.appendChild(emptyBlock(icons.car, 'Noch kein Fahrzeug', EMPTY_HINT));
        // Inhaber brauchen den Filter „Gelöscht“ auch dann, wenn ihr einziges Fahrzeug gelöscht wurde.
        if (!isOwnerSomewhere()) {
            return;
        }
    }
    content.appendChild(kpiBar());
    const section = element('div', undefined, 'sec');
    section.appendChild(filterBar());
    if (activeFilter === DELETED_FILTER.key) {
        for (const garage of garages.filter((entry) => entry.role === OWNER_ROLE)) {
            section.appendChild(deletedGroup(garage));
        }
    } else {
        for (const garage of garages) {
            section.appendChild(garageGroup(garage));
        }
    }
    content.appendChild(section);
}

function summarizeOverdue(dueItems) {
    const overdue = dueItems.filter((item) => item.status === 'ueberfaellig');

    return { count: overdue.length, firstVehicleId: overdue.length === 0 ? null : overdue[0].vehicle_id };
}

async function load() {
    clearChildren(content);
    content.appendChild(skeletons());
    try {
        const [vehicleData, dueData, openItemData, inboxData] = await Promise.all([
            request('GET', 'vehicles'),
            request('GET', 'due'),
            request('GET', 'open-items'),
            request('GET', 'inbox?status=neu'),
        ]);
        garages = vehicleData.garages;
        deletedVehicles = null;
        totals = {
            overdue: summarizeOverdue(dueData.items),
            openItems: openItemData.open_items.length,
            newInbox: inboxData.items.filter((item) => item.status === 'neu').length,
        };
    } catch (error) {
        clearChildren(content);
        content.appendChild(errorBlock(error.message, load));

        return;
    }
    render();
    refreshEmergencies();
}

/** Hält die Notfallkarten auf dem Gerät aktuell (ADR-025); ein Fehler kostet nur die Aktualität, nicht die Seite. */
function refreshEmergencies() {
    const active = allVehicles().filter((vehicle) => vehicle.status === ACTIVE_STATUS);
    for (const vehicle of active) {
        request('GET', 'vehicles/' + vehicle.id + '/emergency').then(storeEmergency).catch(() => {});
    }
}

/** Nach „Fahrzeug löschen“ auf der Fahrzeugseite: Leiste mit Rückgängig (die Seite hat dafür gewechselt). */
function announceDeletion() {
    let note = null;
    try {
        note = JSON.parse(sessionStorage.getItem('garage.deleted') ?? 'null');
    } catch {
        note = null;
    }
    sessionStorage.removeItem('garage.deleted');
    if (note === null || typeof note.id !== 'number' || typeof note.label !== 'string') {
        return;
    }
    toast(note.label + ' gelöscht', { undo: () => restoreVehicle(note, { shouldReload: true }) });
}

async function start() {
    const user = await requireSession(statusLine);
    if (user === null) {
        return;
    }
    content.hidden = false;
    await load();
    announceDeletion();
}

start();
