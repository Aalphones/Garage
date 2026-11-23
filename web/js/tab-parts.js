// Reiter „Teile“: je Bauteil eine Gruppe; Artikelnummern lassen sich per Tippen kopieren.
import { request } from './api.js';
import { actionButton, clearChildren, element } from './dom.js';
import * as icons from './icons.js';
import { deleteRecord } from './record-delete.js';
import { toast } from './toast.js';
import { emptyBlock, errorBlock, stateWord } from './widgets.js';

const ROLE_INSTALLED = 'verbaut';

async function copyNumber(articleNumber) {
    try {
        await navigator.clipboard.writeText(articleNumber);
    } catch {
        toast('Kopieren nicht möglich. Die Nummer lautet ' + articleNumber + '.');

        return;
    }
    toast(articleNumber + ' kopiert');
}

function copyButton(articleNumber) {
    const button = actionButton(articleNumber, 'copy', () => copyNumber(articleNumber));
    button.insertAdjacentHTML('beforeend', icons.copy);
    button.setAttribute('aria-label', 'Artikelnummer ' + articleNumber + ' kopieren');

    return button;
}

function partRow(context, part) {
    const row = element('div', undefined, 'row');
    const title = element('span', undefined, 't');
    title.appendChild(document.createTextNode((part.brand ?? 'Ohne Marke') + ' '));
    if (part.role === ROLE_INSTALLED) {
        title.appendChild(stateWord('st ok', 'verbaut'));
    } else {
        title.appendChild(element('span', 'Alternative', 'muted small'));
    }
    row.appendChild(title);

    const right = element('span', undefined, 'r');
    if (part.article_number !== null) {
        right.appendChild(copyButton(part.article_number));
    }
    row.appendChild(right);

    const sub = element('span', undefined, 's');
    if (part.oem_number !== null) {
        sub.appendChild(element('span', 'OEM ' + part.oem_number));
    }
    if (part.verified === true) {
        sub.appendChild(element('span', 'geprüft'));
    } else {
        sub.appendChild(stateWord('st warn', 'ungeprüft'));
    }
    if (part.note !== null) {
        sub.appendChild(element('span', part.note));
    }
    if (context.vehicle.permissions.write) {
        sub.appendChild(actionButton('Löschen', 'btn sm danger', () => deleteRecord('part', part.id, 'Teil', context.reload)));
    }
    row.appendChild(sub);

    return row;
}

function componentGroup(context, component) {
    const section = element('div', undefined, 'sec');
    const head = element('div', undefined, 'sec-h');
    head.appendChild(element('h2', component.component));
    section.appendChild(head);
    const list = element('div', undefined, 'list');
    for (const part of component.parts) {
        list.appendChild(partRow(context, part));
    }
    section.appendChild(list);

    return section;
}

/** `context`: `{ vehicleId, vehicle, reload() }` wie beim Reiter Übersicht. */
export async function renderParts(container, context) {
    let data;
    try {
        data = await request('GET', 'vehicles/' + context.vehicleId + '/parts');
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    clearChildren(container);
    if (data.components.length === 0) {
        container.appendChild(emptyBlock(icons.doc, 'Noch keine Teile', 'Frag deinen Agenten nach den Teilenummern für Öl, Filter und Kerzen.'));

        return;
    }
    const bar = element('div', undefined, 'tb');
    const count = data.components.length;
    bar.appendChild(element('span', count + (count === 1 ? ' Bauteil' : ' Bauteile'), 'muted small'));
    container.appendChild(bar);
    for (const component of data.components) {
        container.appendChild(componentGroup(context, component));
    }
}
