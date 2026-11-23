// Einstieg für fahrzeug.html: Adresse `#<id>/<reiter>`; Kopf, Reiter-Leiste und Kamera-Knopf bleiben beim Reiterwechsel stehen.
import { ApiError, request } from './api.js';
import { clearChildren, element, link } from './dom.js';
import * as icons from './icons.js';
import { mountCameraButton } from './capture-fab.js';
import { requireSession } from './session.js';
import { mountShell, scroller } from './shell.js';
import { renderBook } from './tab-book.js';
import { renderDocs } from './tab-docs.js';
import { renderMeasure } from './tab-measure.js';
import { renderParts } from './tab-parts.js';
import { renderPlan } from './tab-plan.js';
import { renderTires } from './tab-tires.js';
import { renderOverview } from './vehicle-overview.js';
import { emptyBlock, errorBlock, plate, skeletons, STATUS_LABELS, stateWord } from './widgets.js';

mountShell({ active: 'garage' });

const TABS = [
    { key: 'uebersicht', label: 'Übersicht' },
    { key: 'scheckheft', label: 'Scheckheft' },
    { key: 'wartung', label: 'Wartung' },
    { key: 'messwerte', label: 'Messwerte' },
    { key: 'reifen', label: 'Reifen' },
    { key: 'teile', label: 'Teile' },
    { key: 'belege', label: 'Belege' },
];
const ADDRESS = /^#(\d+)\/([a-z]+)$/;

const statusLine = document.getElementById('status');
const content = document.getElementById('content');

let loaded = null;
let tabsNav = null;
let panelHost = null;

function parseAddress() {
    const match = ADDRESS.exec(location.hash);
    if (match === null) {
        return null;
    }
    const tab = TABS.find((entry) => entry.key === match[2]);

    return { vehicleId: Number(match[1]), tab: tab === undefined ? TABS[0] : tab };
}

function openItemsOf(vehicleId, allOpenItems) {
    return allOpenItems.filter((item) => item.vehicle_id === vehicleId);
}

function updateOverviewCount() {
    const count = tabsNav.querySelector('[data-count]');
    const openCount = loaded.openItems.length;
    count.textContent = String(openCount);
    count.hidden = openCount === 0;
}

function tabLink(tab) {
    const anchor = link('', '#' + loaded.vehicleId + '/' + tab.key);
    anchor.setAttribute('role', 'tab');
    anchor.dataset.tab = tab.key;
    anchor.appendChild(document.createTextNode(tab.label));
    if (tab.key === 'uebersicht') {
        const count = element('span', undefined, 'count');
        count.dataset.count = '';
        anchor.appendChild(count);
    }

    return anchor;
}

function buildHead() {
    const { vehicle, plate: currentPlate } = loaded.data;
    const head = element('div', undefined, 'vhead');
    const back = link('', '/garage/garage.html', 'back');
    back.insertAdjacentHTML('beforeend', icons.back);
    back.appendChild(document.createTextNode('Garage'));
    head.appendChild(back);
    const sign = plate(currentPlate);
    if (sign !== null) {
        head.appendChild(sign);
    }
    head.appendChild(element('span', vehicle.label, 'nm'));
    if (vehicle.status !== 'aktiv') {
        head.appendChild(stateWord('st ok', STATUS_LABELS[vehicle.status] ?? vehicle.status));
    }
    const actions = element('div', undefined, 'vacts');
    actions.setAttribute('role', 'group');
    actions.setAttribute('aria-label', 'Aktionen zum Fahrzeug');
    head.appendChild(actions);

    return head;
}

function buildTabs() {
    const nav = element('nav', undefined, 'tabs');
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-label', 'Bereiche des Fahrzeugs');
    for (const tab of TABS) {
        nav.appendChild(tabLink(tab));
    }

    return nav;
}

/** Der aktive Reiter liegt immer im sichtbaren Bereich der seitlich rollbaren Leiste. */
function scrollActiveTabIntoView() {
    const active = tabsNav.querySelector('[aria-selected="true"]');
    if (active === null) {
        return;
    }
    tabsNav.scrollLeft = active.offsetLeft - (tabsNav.clientWidth - active.offsetWidth) / 2;
}

async function showPanel(tab) {
    for (const anchor of tabsNav.querySelectorAll('[role="tab"]')) {
        anchor.setAttribute('aria-selected', String(anchor.dataset.tab === tab.key));
    }
    scrollActiveTabIntoView();
    document.getElementById('seitentitel').textContent = loaded.data.vehicle.label + ' – ' + tab.label;

    // Neues Panel je Wechsel: so blendet der Inhalt jedes Mal ein.
    const panel = element('div', undefined, 'panel');
    panel.setAttribute('role', 'tabpanel');
    clearChildren(panelHost);
    panelHost.appendChild(panel);
    scroller().scrollTop = 0;

    panel.appendChild(skeletons());
    const vehicleId = loaded.vehicleId;
    const context = {
        vehicleId,
        vehicle: loaded.data,
        openItems: () => loaded.openItems,
        removeOpenItem: (itemId) => {
            loaded.openItems = loaded.openItems.filter((item) => item.id !== itemId);
        },
        onOpenItemsChanged: updateOverviewCount,
        reload: () => showPanel(tab),
    };
    const renderers = {
        uebersicht: renderOverview,
        scheckheft: renderBook,
        wartung: renderPlan,
        messwerte: renderMeasure,
        reifen: renderTires,
        teile: renderParts,
        belege: renderDocs,
    };
    await renderers[tab.key](panel, context);
}

function mountChrome() {
    clearChildren(content);
    content.appendChild(buildHead());
    tabsNav = buildTabs();
    content.appendChild(tabsNav);
    const uploadStatus = element('div');
    uploadStatus.setAttribute('aria-live', 'polite');
    content.appendChild(uploadStatus);
    panelHost = element('div');
    content.appendChild(panelHost);

    const { vehicle, permissions } = loaded.data;
    if (vehicle.status === 'aktiv' && permissions.write) {
        mountCameraButton({ buttonHost: content, statusHost: uploadStatus, vehicleId: vehicle.id, vehicleLabel: vehicle.label });
    }
    updateOverviewCount();
}

function showMissing(message) {
    clearChildren(content);
    content.appendChild(emptyBlock(icons.car, 'Fahrzeug nicht gefunden', message));
    const back = link('Zur Garage', '/garage/garage.html', 'btn');
    content.appendChild(back);
}

async function loadVehicle(vehicleId) {
    clearChildren(content);
    content.appendChild(skeletons());
    let data;
    let allOpenItems;
    try {
        [data, allOpenItems] = await Promise.all([request('GET', 'vehicles/' + vehicleId), request('GET', 'open-items')]);
    } catch (error) {
        if (error instanceof ApiError && error.status === 404) {
            showMissing('Dieses Fahrzeug gibt es in deinen Garagen nicht.');

            return false;
        }
        clearChildren(content);
        content.appendChild(errorBlock(error.message, route));

        return false;
    }
    loaded = { vehicleId, data, openItems: openItemsOf(vehicleId, allOpenItems.open_items) };
    mountChrome();

    return true;
}

async function route() {
    const address = parseAddress();
    if (address === null) {
        showMissing('Die Adresse ist unvollständig. Wähle ein Fahrzeug in der Garage.');

        return;
    }
    if (loaded === null || loaded.vehicleId !== address.vehicleId) {
        loaded = null;
        if (!(await loadVehicle(address.vehicleId))) {
            return;
        }
    }
    await showPanel(address.tab);
}

async function start() {
    const user = await requireSession(statusLine);
    if (user === null) {
        return;
    }
    content.hidden = false;
    window.addEventListener('hashchange', route);
    await route();
}

start();
