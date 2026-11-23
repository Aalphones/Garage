// Reiter „Belege“: die Belege dieses Fahrzeugs, dieselbe Darstellung wie auf belege.html.
import { renderDocuments } from './document-list.js';
import * as icons from './icons.js';
import { emptyBlock } from './widgets.js';

const DETAIL_BASE = '/garage/belege.html';

function emptyDocuments() {
    return emptyBlock(icons.doc, 'Noch keine Belege', 'Tipp unten rechts auf „Beleg“ oder gib die Rechnung deinem Agenten im Chat.');
}

/** `context`: `{ vehicleId }`; Tippen auf einen Beleg öffnet sein Detail auf `belege.html`. */
export async function renderDocs(container, context) {
    await renderDocuments(container, { vehicleId: context.vehicleId, detailBase: DETAIL_BASE, defaultView: 'gal', renderEmpty: emptyDocuments });
}
