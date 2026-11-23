// Gemeinsame Schale: Seitenleiste ab 992 px, untere Leiste darunter. Baut sich um das vorhandene <main> der Seite.
import { request, restore } from './api.js';
import { element } from './dom.js';
import * as icons from './icons.js';

const DESKTOP_MIN_PX = 992;
const HOME = '/garage/garage.html';

const TARGETS = [
    { key: 'garage', label: 'Garage', href: HOME, icon: icons.garage },
    { key: 'eingang', label: 'Eingang', href: '/garage/eingang.html', icon: icons.inbox, counter: 'inbox' },
    { key: 'klaerfaelle', label: 'Klärfälle', href: '/garage/klaerfaelle.html', icon: icons.oi, counter: 'openItems' },
    { key: 'konto', label: 'Konto', href: '/garage/index.html', icon: icons.user },
];

/** Ab 992 px scrollt nur der Inhaltsbereich, darunter das Dokument. */
export function scroller() {
    if (window.innerWidth >= DESKTOP_MIN_PX) {
        return document.querySelector('.content');
    }

    return document.scrollingElement;
}

function logoLink(className) {
    const anchor = element('a', undefined, className);
    anchor.href = HOME;
    anchor.insertAdjacentHTML('beforeend', icons.logo);
    anchor.appendChild(document.createTextNode('GARAGE'));

    return anchor;
}

function targetLink(target, active, className) {
    const anchor = element('a', undefined, className);
    anchor.href = target.href;
    if (target.key === active) {
        anchor.setAttribute('aria-current', 'page');
    }
    anchor.insertAdjacentHTML('beforeend', target.icon);
    anchor.appendChild(element('span', target.label));
    if (target.counter !== undefined) {
        const count = element('span', undefined, 'count');
        count.dataset.counter = target.counter;
        count.hidden = true;
        anchor.appendChild(count);
    }

    return anchor;
}

function buildSide(active) {
    const side = element('nav', undefined, 'side');
    side.setAttribute('aria-label', 'Hauptnavigation');
    side.appendChild(logoLink('logo'));
    for (const target of TARGETS) {
        side.appendChild(targetLink(target, active, 'nav'));
    }

    return side;
}

function buildBottom(active) {
    const bottom = element('nav', undefined, 'bottom');
    bottom.setAttribute('aria-label', 'Hauptnavigation');
    for (const target of TARGETS) {
        bottom.appendChild(targetLink(target, active, ''));
    }

    return bottom;
}

function setCounter(name, value) {
    for (const node of document.querySelectorAll('[data-counter="' + name + '"]')) {
        node.textContent = String(value);
        node.hidden = value === 0;
    }
}

/** Zähler für „Eingang“ (Status neu) und „Klärfälle“; ohne Anmeldung oder bei einem Fehler bleiben sie aus. */
export async function refreshCounters() {
    try {
        const user = await restore();
        if (user === null) {
            return;
        }
        const [inbox, openItems] = await Promise.all([request('GET', 'inbox?status=neu'), request('GET', 'open-items')]);
        setCounter('inbox', inbox.items.filter((item) => item.status === 'neu').length);
        setCounter('openItems', openItems.open_items.length);
    } catch {
        // Die Zähler sind Zugabe: die Seite arbeitet ohne sie weiter.
    }
}

/** `active`: `garage` | `eingang` | `klaerfaelle` | `konto`. */
export function mountShell({ active }) {
    const main = document.querySelector('main');
    const shell = element('div', undefined, 'shell');
    const content = element('div', undefined, 'content');
    const brandbar = element('div', undefined, 'brandbar');
    brandbar.appendChild(logoLink('logo'));

    main.before(shell);
    content.appendChild(brandbar);
    content.appendChild(main);
    shell.appendChild(buildSide(active));
    shell.appendChild(content);
    shell.appendChild(buildBottom(active));
    refreshCounters();
}
