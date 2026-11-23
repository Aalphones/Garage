// Symbole der Schale und der Bauteile als SVG-Strings (feste Quelle, nie Nutzerdaten).
const OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';

export const garage = OPEN + '<path d="M3 10l9-6 9 6v10H3z"/><path d="M7 20v-6h10v6"/><path d="M7 17h10"/></svg>';
export const inbox = OPEN + '<path d="M4 13l2-8h12l2 8"/><path d="M4 13v6h16v-6h-5l-1 2h-4l-1-2z"/></svg>';
export const oi = OPEN + '<path d="M7 4h4v4H7zM13 16h4v4h-4z"/><path d="M9 8v4a4 4 0 0 0 4 4"/><path d="M15 4l2 2-2 2"/></svg>';
export const user = OPEN + '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></svg>';
export const logo = OPEN + '<path d="M3 11l9-6 9 6"/><path d="M5 10v9h14v-9"/><path d="M12 19c0-4 2-6 5-6-1 3-2 5-5 6z"/></svg>';
export const chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
export const back = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>';
export const more = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>';
export const cam = OPEN + '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';
export const car = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 15l2-6h14l2 6v3H3z"/><circle cx="7.5" cy="18" r="1.6"/><circle cx="16.5" cy="18" r="1.6"/><path d="M5 12h14"/></svg>';
export const doc = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/></svg>';
export const photo = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="M21 16l-5-5-8 8"/></svg>';
export const copy = OPEN + '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V4H4v12h4"/></svg>';
export const list = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg>';
export const timeline = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 3v18"/><circle cx="6" cy="7" r="2"/><circle cx="6" cy="17" r="2"/><path d="M10 7h10M10 17h10"/></svg>';
export const edit = OPEN + '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/></svg>';
export const trash = OPEN + '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/></svg>';
export const print = OPEN + '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/></svg>';

const SOURCE_OPEN = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">';

/** Herkunfts-Symbole für `.src`, nach Quelle. */
export const sources = {
    beleg: SOURCE_OPEN + '<path d="M6 3h8l4 4v14H6z"/></svg>',
    werkstatt: SOURCE_OPEN + '<path d="M14 6a4 4 0 0 0-5 5l-6 6 3 3 6-6a4 4 0 0 0 5-5l-3 3-3-3z"/></svg>',
    nutzer: SOURCE_OPEN + '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/></svg>',
    recherche: SOURCE_OPEN + '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/></svg>',
    agent: SOURCE_OPEN + '<rect x="5" y="8" width="14" height="11" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01"/></svg>',
};
