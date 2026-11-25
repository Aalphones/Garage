// Einstieg für notfall.html: zeigt die Notfallkarten nur aus dem Gerätecache — ohne Anmeldung, ohne API, auch ohne Netz.
// `notfall.html#<id>` zeigt eine Karte, ohne Hash alle gespeicherten.
import { element } from './dom.js';
import { emergencyCard, readEmergencies } from './emergency.js';
import { registerWorker } from './share.js';

const EMPTY_HINT = 'Noch keine Notfallkarte auf diesem Gerät — öffne einmal den Reiter Papiere mit Netz.';
const MISSING_HINT = 'Für dieses Fahrzeug liegt keine Notfallkarte auf diesem Gerät — öffne einmal den Reiter Papiere mit Netz.';

registerWorker();

const statusLine = document.getElementById('status');
const content = document.getElementById('content');

function requestedId() {
    const id = Number.parseInt(window.location.hash.slice(1), 10);

    return Number.isNaN(id) ? null : id;
}

/** Eine angefragte, aber fehlende Karte nennt den Hinweis und zeigt die übrigen. */
function chooseCards(cards, id) {
    if (id === null) {
        return { shown: cards, hint: cards.length === 0 ? EMPTY_HINT : null };
    }
    const match = cards.filter((card) => card.vehicle_id === id);
    if (match.length > 0) {
        return { shown: match, hint: null };
    }

    return { shown: cards, hint: cards.length === 0 ? EMPTY_HINT : MISSING_HINT };
}

async function render() {
    let cards = [];
    try {
        cards = await readEmergencies();
    } catch {
        cards = [];
    }
    const { shown, hint } = chooseCards(cards, requestedId());
    content.replaceChildren(...shown.map((card) => {
        const wrapper = element('div', undefined, 'sec');
        wrapper.appendChild(emergencyCard(card));

        return wrapper;
    }));
    statusLine.textContent = hint ?? '';
    statusLine.hidden = hint === null;
    if (shown.length === 1) {
        document.title = shown[0].label + ' – Notfallkarte – GARAGE';
    }
}

window.addEventListener('hashchange', render);
render();
