// Herkunfts-Abzeichen und Popover. Nichts nur per Überfahren: Tippen öffnet, Tippen daneben und Escape schließen.
import { element, formatMoment } from './dom.js';
import { sources } from './icons.js';

const SOURCE_LABELS = { beleg: 'Beleg', werkstatt: 'Werkstatt', nutzer: 'Nutzer', recherche: 'Recherche', agent: 'Agent' };
const CONFIDENCE_LABELS = { bestaetigt: 'bestätigt', wahrscheinlich: 'wahrscheinlich', unsicher: 'unsicher' };
const POPOVER_GAP_PX = 6;
const SCREEN_MARGIN_PX = 8;
const WEB_ADDRESS = /^https?:\/\//;

let currentTip = null;
let currentAnchor = null;

export function closeTip() {
    if (currentTip !== null) {
        currentTip.remove();
        currentTip = null;
        currentAnchor = null;
    }
}

function place(tip, anchor) {
    const anchorBox = anchor.getBoundingClientRect();
    const left = Math.max(SCREEN_MARGIN_PX, Math.min(window.innerWidth - tip.offsetWidth - SCREEN_MARGIN_PX, anchorBox.left));
    let top = anchorBox.bottom + POPOVER_GAP_PX;
    if (top + tip.offsetHeight > window.innerHeight - SCREEN_MARGIN_PX) {
        top = Math.max(SCREEN_MARGIN_PX, anchorBox.top - tip.offsetHeight - POPOVER_GAP_PX);
    }
    tip.style.left = left + 'px';
    tip.style.top = top + 'px';
}

/** Öffnet ein Popover am Anker; ein zweites Tippen auf denselben Anker schließt es. `fill(tip)` baut den Inhalt. */
function openTip(anchor, label, fill) {
    const isSameAnchor = currentAnchor === anchor;
    closeTip();
    if (isSameAnchor) {
        return;
    }
    const tip = element('div', undefined, 'tip');
    tip.setAttribute('role', 'dialog');
    tip.setAttribute('aria-label', label);
    fill(tip);
    document.body.appendChild(tip);
    place(tip, anchor);
    currentTip = tip;
    currentAnchor = anchor;
}

document.addEventListener('click', (event) => {
    if (currentTip === null || currentTip.contains(event.target) || currentAnchor.contains(event.target)) {
        return;
    }
    closeTip();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && currentTip !== null) {
        const anchor = currentAnchor;
        closeTip();
        anchor.focus();
    }
});

/** Popover mit frei gebautem Inhalt, etwa der Wert eines Diagrammpunkts samt Knopf; `fill(tip)` baut den Inhalt. */
export function tipContent(anchor, title, fill) {
    openTip(anchor, title, (tip) => {
        tip.appendChild(element('b', title));
        fill(tip);
    });
}

/** Rechenweg oder Erklärung als Text, etwa unter „≈ km“ oder einem Zustand. */
export function tipText(anchor, title, text) {
    openTip(anchor, title, (tip) => {
        tip.appendChild(element('b', title));
        const paragraph = element('p', text);
        paragraph.style.margin = '.3rem 0 0';
        tip.appendChild(paragraph);
    });
}

function addRow(list, term, value) {
    list.appendChild(element('dt', term));
    const definition = element('dd');
    if (value instanceof Node) {
        definition.appendChild(value);
    } else {
        definition.textContent = value;
    }
    list.appendChild(definition);
}

function sourceLink(address) {
    if (!WEB_ADDRESS.test(address)) {
        return address;
    }
    const anchor = element('a', address);
    anchor.href = address;
    anchor.target = '_blank';
    anchor.rel = 'noopener noreferrer';

    return anchor;
}

export function fillProvenance(tip, source, confidence, detail) {
    tip.appendChild(element('b', 'Herkunft'));
    const list = element('dl');
    addRow(list, 'Quelle', SOURCE_LABELS[source] ?? source);
    addRow(list, 'Sicherheit', CONFIDENCE_LABELS[confidence] ?? confidence);
    addRow(list, 'Beleg', detail.sourceUrl === undefined || detail.sourceUrl === null ? '–' : sourceLink(detail.sourceUrl));
    if (detail.actor !== undefined && detail.actor !== null) {
        addRow(list, 'Erfasst', detail.recordedAt === undefined || detail.recordedAt === null ? detail.actor : detail.actor + ', ' + formatMoment(detail.recordedAt));
    }
    tip.appendChild(list);
    if (source === 'beleg' && detail.documentId !== undefined && detail.documentId !== null) {
        const open = element('a', 'Beleg öffnen', 'btn sm');
        open.href = '/garage/belege.html#beleg/' + detail.documentId;
        tip.appendChild(open);
    }
}

/**
 * Abzeichen `.src` mit Quelle und Sicherheit; Tippen zeigt das Popover.
 * `detail`: `{ actor?, recordedAt?, sourceUrl?, documentId? }`, alles optional.
 */
export function badge(source, confidence, detail = {}) {
    const button = element('button', undefined, 'src ' + confidence);
    button.type = 'button';
    button.setAttribute('aria-label', 'Herkunft: ' + (SOURCE_LABELS[source] ?? source) + ', ' + (CONFIDENCE_LABELS[confidence] ?? confidence));
    if (sources[source] !== undefined) {
        button.insertAdjacentHTML('beforeend', sources[source]);
    }
    button.appendChild(document.createTextNode(SOURCE_LABELS[source] ?? source));
    const dot = element('span', undefined, 'c');
    dot.setAttribute('aria-hidden', 'true');
    button.appendChild(dot);
    button.addEventListener('click', () => openTip(button, 'Herkunft', (tip) => fillProvenance(tip, source, confidence, detail)));

    return button;
}
