// Verbundene Apps im Konto: auflisten und trennen. Gezeigt wird nur, was der angemeldete Nutzer selbst bewilligt hat.
import { request } from './api.js';
import { clearChildren, element, formatDate, hideError, showError } from './dom.js';

function describeGrant(grant) {
    const item = element('li');
    item.appendChild(element('strong', grant.client_name));
    item.appendChild(element('small', 'ausgewiesen von ' + grant.client_host));
    item.appendChild(element('small', grant.scopes.join(', ')));

    const lastUsed = grant.last_used_at === null ? 'noch nie benutzt' : 'zuletzt benutzt am ' + formatDate(grant.last_used_at);
    item.appendChild(element('small', 'verbunden seit ' + formatDate(grant.connected_at) + ' · ' + lastUsed));

    return item;
}

export function setupGrants() {
    const list = document.getElementById('grant-list');
    const empty = document.getElementById('grant-empty');
    const listError = document.getElementById('grant-list-error');

    async function disconnect(grant) {
        if (!window.confirm('„' + grant.client_name + '“ trennen? Die App verliert sofort den Zugriff und muss neu verbunden werden.')) {
            return;
        }
        hideError(listError);
        try {
            await request('DELETE', 'oauth/grants/' + grant.family);
        } catch (error) {
            showError(listError, error.message);
            return;
        }
        await load();
    }

    function render(grants) {
        clearChildren(list);
        empty.hidden = grants.length > 0;
        for (const grant of grants) {
            const item = describeGrant(grant);
            const button = element('button', 'Trennen');
            button.type = 'button';
            button.setAttribute('aria-label', grant.client_name + ' trennen');
            button.addEventListener('click', () => disconnect(grant));
            item.appendChild(button);
            list.appendChild(item);
        }
    }

    async function load() {
        hideError(listError);
        try {
            render((await request('GET', 'oauth/grants')).grants);
        } catch (error) {
            showError(listError, error.message);
        }
    }

    return {
        load,
        clear() {
            hideError(listError);
            clearChildren(list);
        },
    };
}
