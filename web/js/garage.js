// Einstieg für garage.html: Kennzahlen, Status-Filter und je Garage eine Gruppe Fahrzeugkarten.
import { request } from './api.js';
import { actionButton, clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { requireSession } from './session.js';
import { mountShell } from './shell.js';
import { registerShareWorker } from './share.js';
import { dueWord, emptyBlock, errorBlock, formatKm, plate, skeletons, stateWord } from './widgets.js';

registerShareWorker();
mountShell({ active: 'garage' });

const FILTERS = [
    { key: 'aktiv', label: 'Aktiv' },
    { key: 'verkauft', label: 'Verkauft' },
    { key: 'stillgelegt', label: 'Stillgelegt' },
    { key: 'alle', label: 'Alle' },
];
const EMPTY_HINT = 'Sag deinem Agenten im Chat: „Leg meinen Golf in GARAGE an“ und gib ihm ein Foto vom Fahrzeugschein.';

const statusLine = document.getElementById('status');
const content = document.getElementById('content');

let garages = [];
let totals = null;
let activeFilter = 'aktiv';

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

function filterBar() {
    const bar = element('div', undefined, 'tb');
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', 'Status filtern');
    for (const filter of FILTERS) {
        const button = actionButton(filter.label, 'chip', () => {
            activeFilter = filter.key;
            render();
        });
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

        return;
    }
    content.appendChild(kpiBar());
    const section = element('div', undefined, 'sec');
    section.appendChild(filterBar());
    for (const garage of garages) {
        section.appendChild(garageGroup(garage));
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
}

async function start() {
    const user = await requireSession(statusLine);
    if (user === null) {
        return;
    }
    content.hidden = false;
    await load();
}

start();
