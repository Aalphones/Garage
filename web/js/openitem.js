// Einstieg für klaerfaelle.html: offene Klärfälle aller Fahrzeuge, nach Fahrzeug filterbar (`#fz=<id>`).
import { request } from './api.js';
import { actionButton, clearChildren, element, formatDate, link } from './dom.js';
import * as icons from './icons.js';
import { KIND_LABELS, openItemBody } from './openitem-view.js';
import { requireSession } from './session.js';
import { mountShell } from './shell.js';
import { emptyBlock, errorBlock, skeletons } from './widgets.js';

mountShell({ active: 'klaerfaelle' });

const statusLine = document.getElementById('status');
const content = document.getElementById('content');
const FILTER_HASH = /^#fz=(\d+)$/;

let openItems = [];
let filterVehicleId = null;

function filterFromHash() {
    const match = FILTER_HASH.exec(location.hash);

    return match === null ? null : Number(match[1]);
}

function vehiclesWithOpenItems() {
    const known = new Map();
    for (const item of openItems) {
        if (item.vehicle_id !== null && !known.has(item.vehicle_id)) {
            known.set(item.vehicle_id, item.vehicle_label);
        }
    }

    return known;
}

function setFilter(vehicleId) {
    filterVehicleId = vehicleId;
    history.replaceState(null, '', vehicleId === null ? location.pathname : '#fz=' + vehicleId);
    render();
}

function chip(label, isPressed, onClick) {
    const button = actionButton(label, 'chip', onClick);
    button.setAttribute('aria-pressed', String(isPressed));

    return button;
}

function filterBar() {
    const bar = element('div', undefined, 'tb');
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', 'Nach Fahrzeug filtern');
    bar.appendChild(chip('Alle Fahrzeuge', filterVehicleId === null, () => setFilter(null)));
    for (const [vehicleId, label] of vehiclesWithOpenItems()) {
        bar.appendChild(chip(label, filterVehicleId === vehicleId, () => setFilter(vehicleId)));
    }

    return bar;
}

function card(item) {
    const article = element('article', undefined, 'oi');
    const head = element('div', undefined, 'oi-h');
    if (item.vehicle_id === null) {
        head.appendChild(element('strong', 'Ohne Fahrzeug'));
    } else {
        head.appendChild(link(item.vehicle_label, '/garage/fahrzeug.html#' + item.vehicle_id + '/uebersicht', 'nm'));
    }
    head.appendChild(element('span', KIND_LABELS[item.kind] ?? item.kind, 'kindtag'));
    head.appendChild(element('span', formatDate(item.created_at) + ' · gemeldet von ' + item.created_by, 'muted small'));
    article.appendChild(head);
    article.appendChild(element('p', item.text, 'q'));
    article.appendChild(openItemBody(item, {
        hide: () => {
            article.hidden = true;
        },
        show: () => {
            article.hidden = false;
        },
        done: () => {
            openItems = openItems.filter((entry) => entry.id !== item.id);
            render();
        },
    }));

    return article;
}

function render() {
    clearChildren(content);
    if (openItems.length === 0) {
        content.appendChild(emptyBlock(icons.oi, 'Alles geklärt', 'Widersprüche, die Agenten finden, landen hier. Gerade gibt es nichts zu entscheiden.'));

        return;
    }
    const shown = filterVehicleId === null ? openItems : openItems.filter((item) => item.vehicle_id === filterVehicleId);
    content.appendChild(filterBar());
    content.appendChild(element('p', shown.length + ' offen · neueste zuerst', 'muted small'));
    for (const item of shown) {
        content.appendChild(card(item));
    }
    if (shown.length === 0) {
        content.appendChild(emptyBlock(icons.oi, 'Für dieses Fahrzeug ist alles geklärt', 'Wähle „Alle Fahrzeuge“, um die übrigen Klärfälle zu sehen.'));
    }
}

async function load() {
    clearChildren(content);
    content.appendChild(skeletons());
    try {
        openItems = (await request('GET', 'open-items')).open_items;
    } catch (error) {
        clearChildren(content);
        content.appendChild(errorBlock(error.message, load));

        return;
    }
    filterVehicleId = filterFromHash();
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
