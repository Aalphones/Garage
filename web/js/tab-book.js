// Reiter „Scheckheft“: Ereignisse als Zeitstrahl oder Tabelle, Lücken (ADR-020) rot, Details beim ersten Aufklappen.
import { ApiError, request } from './api.js';
import { actionButton, clearChildren, element, formatDay, link } from './dom.js';
import * as icons from './icons.js';
import { badge } from './provenance.js';
import { toast } from './toast.js';
import { readView, viewSwitch } from './view-switch.js';
import { emptyBlock, errorBlock, formatEuro, formatKm, skeletons, stateWord } from './widgets.js';

const STORAGE_KEY = 'garage.view.scheckheft';
const VIEWS = ['tl', 'tbl'];

const ITEM_TYPE_LABELS = { arbeit: 'Arbeit', teil: 'Teil', material: 'Material', pauschale: 'Pauschale' };

/** Ereignisse und Lücken in einer Reihe, neueste zuerst; eine Lücke steht an ihrem erwarteten Datum. */
function mergeEntries(events, gaps) {
    const entries = [
        ...events.map((event) => ({ type: 'event', date: event.performed_on, event })),
        ...gaps.map((gap) => ({ type: 'gap', date: gap.expected_by, gap })),
    ];
    entries.sort((left, right) => (left.date < right.date ? 1 : left.date > right.date ? -1 : 0));

    return entries;
}

function eventMeta(event) {
    const parts = [formatDay(event.performed_on)];
    if (event.odometer_km !== null) {
        parts.push(formatKm(event.odometer_km) + ' km');
    }
    if (event.workshop !== null) {
        parts.push(event.workshop.name);
    }

    return parts.join(' · ');
}

function gapTitle(gap) {
    return 'Lücke: ' + gap.title;
}

function gapCard(gap) {
    const card = element('div', undefined, 'gapcard');
    card.appendChild(element('b', gapTitle(gap)));
    const explanation = element('div', gap.explanation, 'small');
    card.appendChild(explanation);
    if (gap.rule_uncertain) {
        card.appendChild(stateWord('st unk', 'Regel unsicher'));
    }

    return card;
}

function positionsTable(items) {
    if (items.length === 0) {
        return element('p', 'Keine Positionen erfasst.', 'muted small');
    }
    const scroll = element('div', undefined, 'tscroll');
    const table = element('table', undefined, 'tbl');
    const body = element('tbody');
    for (const item of items) {
        const row = element('tr');
        row.appendChild(element('td', ITEM_TYPE_LABELS[item.type] ?? item.type, 'muted small'));
        row.appendChild(element('td', item.description));
        row.appendChild(element('td', item.net_eur === null ? '–' : formatEuro(item.net_eur), 'num'));
        body.appendChild(row);
    }
    table.appendChild(body);
    scroll.appendChild(table);

    return scroll;
}

function attachmentBar(documents) {
    if (documents.length === 0) {
        return null;
    }
    const bar = element('div', undefined, 'att');
    for (const document_ of documents) {
        const anchor = link('', '/garage/belege.html#beleg/' + document_.id);
        const label = document_.title === null || document_.title === '' ? 'Beleg ' + document_.id : document_.title;
        anchor.setAttribute('aria-label', label + ' öffnen');
        if (document_.preview_url === null || document_.preview_url === undefined) {
            anchor.insertAdjacentHTML('beforeend', icons.doc);
        } else {
            const image = element('img');
            image.src = document_.preview_url;
            image.alt = '';
            image.loading = 'lazy';
            anchor.appendChild(image);
        }
        anchor.appendChild(element('span', label));
        bar.appendChild(anchor);
    }

    return bar;
}

function openItemBanner(item) {
    const banner = element('div', undefined, 'banner');
    banner.appendChild(element('b', 'Klärfall'));
    banner.appendChild(element('span', item.text));
    banner.appendChild(link('Lösen', '/garage/klaerfaelle.html#fz=' + item.vehicle_id));

    return banner;
}

/** Inhalt eines aufgeklappten Ereignisses; lädt `events/{id}` einmal. */
async function fillBody(body, context, event, onDelete) {
    body.appendChild(skeletons(1));
    let detail;
    try {
        detail = await request('GET', 'events/' + event.id);
    } catch (error) {
        clearChildren(body);
        body.appendChild(errorBlock(error.message, () => {
            clearChildren(body);
            fillBody(body, context, event, onDelete);
        }));

        return;
    }
    clearChildren(body);
    for (const item of context.openItems()) {
        if (item.ref_table === 'events' && item.ref_id === event.id) {
            body.appendChild(openItemBanner(item));
        }
    }
    body.appendChild(positionsTable(detail.items));
    const attachments = attachmentBar(detail.documents ?? []);
    if (attachments !== null) {
        body.appendChild(attachments);
    }
    if (context.vehicle.permissions.write) {
        const actions = element('div', undefined, 'actions');
        actions.appendChild(actionButton('Ereignis löschen', 'btn sm danger', () => onDelete(event)));
        body.appendChild(actions);
    }
}

function eventCard(context, event, isOpen, onDelete) {
    const wrapper = element('div', undefined, 'ev');
    const card = element('div', undefined, 'card');
    const details = element('details');
    const summary = element('summary');
    const head = element('div');
    head.appendChild(element('div', event.kind, 'kind'));
    head.appendChild(element('div', event.title, 'ttl'));
    head.appendChild(element('div', eventMeta(event), 'd'));
    summary.appendChild(head);
    const sum = element('div', event.gross_eur === null ? '' : formatEuro(event.gross_eur), 'sum');
    const sumBadge = element('div');
    sumBadge.appendChild(badge(event.source, event.confidence, { sourceUrl: null }));
    sum.appendChild(sumBadge);
    summary.appendChild(sum);
    details.appendChild(summary);

    const body = element('div', undefined, 'body');
    details.appendChild(body);
    let isLoaded = false;
    const load = () => {
        if (!isLoaded) {
            isLoaded = true;
            fillBody(body, context, event, onDelete);
        }
    };
    details.addEventListener('toggle', () => {
        if (details.open) {
            load();
        }
    });
    if (isOpen) {
        details.open = true;
        load();
    }
    card.appendChild(details);
    wrapper.appendChild(card);

    return wrapper;
}

function timeline(context, entries, onDelete) {
    const strip = element('div', undefined, 'tl');
    let lastYear = null;
    let isFirstEvent = true;
    for (const entry of entries) {
        const year = entry.date.slice(0, 4);
        if (year !== lastYear) {
            strip.appendChild(element('div', year, 'year'));
            lastYear = year;
        }
        if (entry.type === 'gap') {
            const wrapper = element('div', undefined, 'ev gap');
            wrapper.appendChild(gapCard(entry.gap));
            strip.appendChild(wrapper);
            continue;
        }
        strip.appendChild(eventCard(context, entry.event, isFirstEvent, onDelete));
        isFirstEvent = false;
    }

    return strip;
}

function cell(text, className) {
    return element('td', text, className);
}

function table(entries) {
    const scroll = element('div', undefined, 'tscroll');
    const tableNode = element('table', undefined, 'tbl');
    const head = element('thead');
    const headRow = element('tr');
    for (const [label, className] of [['Datum'], ['Art'], ['Was'], ['km', 'num'], ['Betrag', 'num'], ['Herkunft']]) {
        headRow.appendChild(element('th', label, className));
    }
    head.appendChild(headRow);
    tableNode.appendChild(head);
    const body = element('tbody');
    for (const entry of entries) {
        const row = element('tr');
        if (entry.type === 'gap') {
            row.className = 'gaprow';
            const only = element('td');
            only.colSpan = 6;
            only.appendChild(element('b', gapTitle(entry.gap)));
            only.appendChild(element('span', ' · ' + entry.gap.explanation, 'small'));
            if (entry.gap.rule_uncertain) {
                only.appendChild(document.createTextNode(' '));
                only.appendChild(stateWord('st unk', 'Regel unsicher'));
            }
            row.appendChild(only);
            body.appendChild(row);
            continue;
        }
        const event = entry.event;
        row.appendChild(cell(formatDay(event.performed_on)));
        row.appendChild(cell(event.kind, 'kind'));
        row.appendChild(cell(event.title));
        row.appendChild(cell(event.odometer_km === null ? '–' : formatKm(event.odometer_km), 'num'));
        row.appendChild(cell(event.gross_eur === null ? '–' : formatEuro(event.gross_eur), 'num'));
        const source = element('td');
        source.appendChild(badge(event.source, event.confidence, { sourceUrl: null }));
        row.appendChild(source);
        body.appendChild(row);
    }
    tableNode.appendChild(body);
    scroll.appendChild(tableNode);

    return scroll;
}

function toolbar(eventCount, gapCount, view, onChange) {
    const bar = element('div', undefined, 'tb');
    bar.appendChild(element('span', eventCount + ' Ereignisse · ' + gapCount + (gapCount === 1 ? ' Lücke' : ' Lücken'), 'muted small'));
    bar.appendChild(viewSwitch(STORAGE_KEY, [
        { value: 'tl', label: 'Zeitstrahl', icon: icons.timeline },
        { value: 'tbl', label: 'Tabelle', icon: icons.list },
    ], view, onChange));

    return bar;
}

/** `context`: `{ vehicleId, vehicle, openItems(), reload() }` wie beim Reiter Übersicht. */
export async function renderBook(container, context) {
    let data;
    try {
        data = await request('GET', 'vehicles/' + context.vehicleId + '/events');
    } catch (error) {
        clearChildren(container);
        container.appendChild(errorBlock(error.message, context.reload));

        return;
    }
    let events = data.events;
    const gaps = data.gaps;

    function draw() {
        clearChildren(container);
        if (events.length === 0 && gaps.length === 0) {
            container.appendChild(emptyBlock(icons.doc, 'Noch keine Ereignisse', 'Gib deinem Agenten eine Werkstattrechnung — er legt das Ereignis mit allen Positionen an.'));

            return;
        }
        // Der Zeitstrahl ist auf allen Breiten Standard; eine gespeicherte Wahl gilt überall.
        const view = readView(STORAGE_KEY, VIEWS, 'tl');
        container.appendChild(toolbar(events.length, gaps.length, view, draw));
        const entries = mergeEntries(events, gaps);
        container.appendChild(view === 'tbl' ? table(entries) : timeline(context, entries, deleteEvent));
    }

    async function deleteEvent(event) {
        try {
            await request('POST', 'records/event/' + event.id + '/delete', {});
        } catch (error) {
            toast(error instanceof ApiError ? error.message : 'Das Ereignis konnte nicht gelöscht werden.');

            return;
        }
        events = events.filter((entry) => entry.id !== event.id);
        draw();
        toast('Ereignis gelöscht', {
            undo: async () => {
                try {
                    await request('POST', 'records/event/' + event.id + '/restore', {});
                } catch (error) {
                    toast(error instanceof ApiError ? error.message : 'Das Ereignis konnte nicht wiederhergestellt werden.');

                    return;
                }
                context.reload();
            },
        });
    }

    draw();
}
