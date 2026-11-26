// Mitglieder im Konto: je Garage Mitglieder und offene Einladungen; Inhaber laden ein, ändern Rollen, entfernen und
// erzeugen Passwort-Links (ADR-026). Ein Link steht nur in der einen Antwort und wird einmal angezeigt.
import { ApiError, request } from './api.js';
import { actionButton, clearChildren, element, formatDate, formatMoment, hideError, showError } from './dom.js';
import { dropdown } from './dropdown.js';
import { tipText } from './provenance.js';
import { openSheet } from './sheet.js';
import { toast } from './toast.js';

const ROLES = [
    { value: 'inhaber', label: 'Inhaber', help: 'Verwaltet Mitglieder und darf löschen.' },
    { value: 'mitglied', label: 'Mitglied', help: 'Liest und schreibt.' },
    { value: 'lesend', label: 'Lesend', help: 'Liest nur.' },
];
const DEFAULT_ROLE = 'mitglied';
const OWNER_ROLE = 'inhaber';
const DEFAULT_DAYS = 7;
const MAX_DAYS = 30;
const MAX_NOTE_LENGTH = 190;
const INVITES_REVOKED_TEXT = 'Offene Einladungen, die diese Person erzeugt hat, werden dabei widerrufen.';

function failureText(error) {
    if (error instanceof ApiError) {
        return error.message;
    }

    return 'Das hat nicht geklappt. Lade die Seite neu.';
}

function roleLabel(value) {
    return ROLES.find((role) => role.value === value)?.label ?? value;
}

/** Zeile „Rollen: Inhaber ? · …“; Tippen auf das Fragezeichen erklärt die Rolle. */
function roleHelp() {
    const line = element('p', 'Rollen:', 'hint role-help');
    for (const role of ROLES) {
        const entry = element('span', role.label);
        const info = actionButton('?', 'info', () => tipText(info, role.label, role.help));
        info.setAttribute('aria-label', 'Was darf die Rolle ' + role.label + '?');
        entry.appendChild(info);
        line.appendChild(entry);
    }

    return line;
}

/** Gestaltete Auswahl im Blatt; `onChange(value)` meldet die Wahl. */
function roleChoice(current, onChange) {
    const field = element('div', undefined, 'field choice');
    field.appendChild(element('span', 'Rolle', 'field-title'));
    dropdown(field, { label: 'Rolle', options: ROLES, value: current, onChange });

    return field;
}

function inputField(id, labelText, type) {
    const field = element('div', undefined, 'field');
    const label = element('label', labelText);
    label.htmlFor = id;
    const input = element('input');
    input.id = id;
    input.type = type;
    input.autocomplete = 'off';
    field.append(label, input);

    return { field, input };
}

function sheetError() {
    const error = element('p', undefined, 'error');
    error.setAttribute('role', 'alert');
    error.tabIndex = -1;
    error.hidden = true;

    return error;
}

/** `onLeft()` baut das Konto neu auf, nachdem der Nutzer selbst eine Garage verlassen hat. */
export function setupMembers(onLeft) {
    const groups = document.getElementById('member-groups');
    const empty = document.getElementById('member-empty');
    const sectionError = document.getElementById('member-error');
    const reveal = document.getElementById('member-reveal');
    const revealTitle = document.getElementById('member-reveal-title');
    const revealNote = document.getElementById('member-reveal-note');
    const revealLink = document.getElementById('member-link');
    const copyButton = document.getElementById('member-copy');
    const shareButton = document.getElementById('member-share');
    const copyStatus = document.getElementById('member-copy-status');
    let currentGarages = [];
    let loadRun = 0;
    let shareTitle = '';

    document.getElementById('member-roles').replaceChildren(roleHelp());

    function hideReveal() {
        reveal.hidden = true;
        revealLink.textContent = '';
        copyStatus.textContent = '';
    }

    function showReveal({ title, note, url, titleForShare }) {
        revealTitle.textContent = title;
        revealNote.textContent = note;
        revealLink.textContent = url;
        copyStatus.textContent = '';
        shareTitle = titleForShare;
        shareButton.hidden = typeof navigator.share !== 'function';
        reveal.hidden = false;
        reveal.focus();
    }

    function startAction() {
        hideError(sectionError);
        hideReveal();
    }

    // Das Neuladen ersetzt den gedrückten Knopf; ohne Ziel fiele der Fokus an den Seitenanfang.
    function focusGroup(garageId) {
        const heading = document.getElementById('member-garage-' + garageId) ?? document.getElementById('member-heading');
        heading.focus();
    }

    // Auch nach einem Fehler wird neu geladen: meist hat sich der Stand auf dem Server inzwischen geändert.
    async function change(garage, method, path, body, successText) {
        startAction();
        try {
            await request(method, 'garages/' + garage.id + '/' + path, body);
        } catch (error) {
            await load(currentGarages);
            showError(sectionError, failureText(error));
            return;
        }
        await load(currentGarages);
        toast(successText);
        focusGroup(garage.id);
    }

    function openRoleSheet(garage, member) {
        let role = member.role;
        const body = element('div');
        body.appendChild(element('p', member.name + ' in der Garage „' + garage.name + '“'));
        body.appendChild(roleChoice(role, (value) => {
            role = value;
        }));
        body.appendChild(roleHelp());
        if (member.role === OWNER_ROLE) {
            body.appendChild(element('p', 'Wenn du die Rolle Inhaber wegnimmst: ' + INVITES_REVOKED_TEXT, 'hint'));
        }
        openSheet({
            title: 'Rolle ändern',
            body,
            actions: [
                { label: 'Abbrechen', role: 'cancel' },
                {
                    label: 'Rolle speichern',
                    role: 'primary',
                    // Nicht abwarten: das Blatt schließt zuerst, sonst nähme es der Fehlermeldung den Fokus wieder weg.
                    onClick: () => {
                        if (role !== member.role) {
                            change(garage, 'PATCH', 'members/' + member.user_id, { role }, 'Rolle geändert');
                        }
                    },
                },
            ],
        });
    }

    function removeMember(garage, member) {
        const question = '„' + member.name + '“ aus der Garage „' + garage.name + '“ entfernen? Der Zugriff endet sofort. ' + INVITES_REVOKED_TEXT;
        if (window.confirm(question)) {
            change(garage, 'DELETE', 'members/' + member.user_id, undefined, 'Mitglied entfernt');
        }
    }

    async function leave(garage, member) {
        const question = 'Garage „' + garage.name + '“ verlassen? Du verlierst sofort den Zugriff. Offene Einladungen, die du erzeugt hast, werden widerrufen.';
        if (!window.confirm(question)) {
            return;
        }
        startAction();
        try {
            await request('DELETE', 'garages/' + garage.id + '/members/' + member.user_id);
        } catch (error) {
            await load(currentGarages);
            showError(sectionError, failureText(error));
            return;
        }
        toast('Garage verlassen');
        try {
            await onLeft();
        } catch (error) {
            showError(sectionError, failureText(error));
            return;
        }
        focusGroup(garage.id);
    }

    function revokeInvitation(garage, invitation) {
        const note = invitation.email === null ? '' : ' für „' + invitation.email + '“';
        if (window.confirm('Einladung' + note + ' widerrufen? Der Link funktioniert dann nicht mehr.')) {
            change(garage, 'DELETE', 'invitations/' + invitation.id, undefined, 'Einladung widerrufen');
        }
    }

    // Der Knopf bleibt bis zur Antwort gesperrt: jeder neue Link macht den vorigen ungültig.
    async function createResetLink(garage, member, button) {
        startAction();
        button.disabled = true;
        let created;
        try {
            created = await request('POST', 'garages/' + garage.id + '/members/' + member.user_id + '/password-reset', {});
        } catch (error) {
            button.disabled = false;
            showError(sectionError, failureText(error));
            return;
        }
        button.disabled = false;
        showReveal({
            title: 'Passwort-Link für ' + member.name + ' – wird nur jetzt angezeigt.',
            note: 'Gilt 24 Stunden. Schick ihn nur an diese Person. Läuft ab am ' + formatMoment(created.expires_at) + '.',
            url: created.link,
            titleForShare: 'Neues Passwort für GARAGE',
        });
    }

    function openInviteSheet(garage) {
        let role = DEFAULT_ROLE;
        let isSending = false;
        let sheet = null;
        const body = element('div');
        body.appendChild(element('p', 'In die Garage „' + garage.name + '“'));
        body.appendChild(roleChoice(role, (value) => {
            role = value;
        }));
        body.appendChild(roleHelp());

        const note = inputField('invite-note', 'Notiz oder E-Mail (optional)', 'text');
        note.input.maxLength = MAX_NOTE_LENGTH;
        const days = inputField('invite-days', 'Gültig in Tagen', 'number');
        days.input.min = '1';
        days.input.max = String(MAX_DAYS);
        days.input.inputMode = 'numeric';
        days.input.value = String(DEFAULT_DAYS);
        const daysHint = element('small', '1 bis ' + MAX_DAYS + '. Danach funktioniert der Link nicht mehr; er gilt nur für eine Person.');
        daysHint.id = 'invite-days-hint';
        days.input.setAttribute('aria-describedby', daysHint.id);
        days.field.appendChild(daysHint);
        const failure = sheetError();
        body.append(note.field, days.field, failure);

        async function send() {
            if (isSending) {
                return false;
            }
            hideError(failure);
            const dayCount = Number(days.input.value);
            if (!Number.isInteger(dayCount) || dayCount < 1 || dayCount > MAX_DAYS) {
                showError(failure, 'Gib eine ganze Zahl von 1 bis ' + MAX_DAYS + ' ein.');
                return false;
            }
            const payload = { role, days: dayCount };
            if (note.input.value.trim() !== '') {
                payload.email = note.input.value.trim();
            }
            startAction();
            isSending = true;
            let created;
            try {
                created = await request('POST', 'garages/' + garage.id + '/invitations', payload);
            } catch (error) {
                isSending = false;
                if (error instanceof ApiError && (error.code === 'invalid_value' || error.code === 'network_error')) {
                    showError(failure, error.message);
                    return false;
                }
                sheet.close();
                await load(currentGarages);
                showError(sectionError, failureText(error));
                return false;
            }
            await load(currentGarages);
            // Selbst schließen, bevor der Link den Fokus bekommt.
            sheet.close();
            showReveal({
                title: 'Einladungs-Link – wird nur jetzt angezeigt.',
                note: roleLabel(role) + ' in „' + garage.name + '“, gültig bis ' + formatMoment(created.expires_at) + '.',
                url: created.link,
                titleForShare: 'Einladung in die Garage ' + garage.name,
            });

            return false;
        }

        for (const input of [note.input, days.input]) {
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    send();
                }
            });
        }
        sheet = openSheet({
            title: 'Einladen',
            body,
            actions: [{ label: 'Abbrechen', role: 'cancel' }, { label: 'Link erzeugen', role: 'primary', onClick: send }],
        });
    }

    function memberButton(text, className, description, onClick) {
        const button = actionButton(text, className, () => onClick(button));
        button.setAttribute('aria-label', description);

        return button;
    }

    function memberItem(garage, member, canManage) {
        const item = element('li');
        item.appendChild(element('strong', member.is_self ? member.name + ' (du)' : member.name));
        item.appendChild(element('small', member.email));
        item.appendChild(element('small', roleLabel(member.role) + ' · seit ' + formatDate(member.since)));

        const actions = element('div', undefined, 'member-actions');
        const where = ' in der Garage ' + garage.name;
        if (member.is_self) {
            actions.appendChild(memberButton('Garage verlassen', 'btn sm', 'Garage ' + garage.name + ' verlassen', () => leave(garage, member)));
        } else if (canManage) {
            actions.append(
                memberButton('Rolle ändern', 'btn sm', 'Rolle von ' + member.name + where + ' ändern', () => openRoleSheet(garage, member)),
                memberButton('Passwort-Link', 'btn sm', 'Passwort-Link für ' + member.name + ' erzeugen', (button) => createResetLink(garage, member, button)),
                memberButton('Entfernen', 'btn sm', member.name + where + ' entfernen', () => removeMember(garage, member)),
            );
        }
        if (actions.childElementCount > 0) {
            item.appendChild(actions);
        }

        return item;
    }

    function invitationItem(garage, invitation) {
        const item = element('li');
        item.appendChild(element('strong', roleLabel(invitation.role)));
        if (invitation.email !== null) {
            item.appendChild(element('small', invitation.email));
        }
        item.appendChild(element('small', 'gültig bis ' + formatMoment(invitation.expires_at)));
        const actions = element('div', undefined, 'member-actions');
        const target = invitation.email === null ? 'als ' + roleLabel(invitation.role) : 'für ' + invitation.email;
        actions.appendChild(memberButton('Widerrufen', 'btn sm', 'Einladung ' + target + ' in die Garage ' + garage.name + ' widerrufen', () => revokeInvitation(garage, invitation)));
        item.appendChild(actions);

        return item;
    }

    function fillGroup(group, garage, data) {
        const members = element('ul', undefined, 'plain-list');
        members.append(...data.members.map((member) => memberItem(garage, member, data.can_manage)));
        group.appendChild(members);
        if (!data.can_manage) {
            return;
        }
        if (data.invitations.length > 0) {
            group.appendChild(element('h5', 'Offene Einladungen'));
            const invitations = element('ul', undefined, 'plain-list');
            invitations.append(...data.invitations.map((invitation) => invitationItem(garage, invitation)));
            group.appendChild(invitations);
        }
        group.appendChild(memberButton('Einladen', 'button', 'In die Garage ' + garage.name + ' einladen', () => openInviteSheet(garage)));
    }

    async function fetchGroup(garage) {
        const group = element('div', undefined, 'member-group');
        const heading = element('h4', garage.name);
        heading.id = 'member-garage-' + garage.id;
        heading.tabIndex = -1;
        group.appendChild(heading);
        try {
            fillGroup(group, garage, await request('GET', 'garages/' + garage.id + '/members'));
        } catch (error) {
            group.appendChild(element('p', 'Die Mitglieder konnten nicht geladen werden: ' + failureText(error) + ' Lade die Seite neu.', 'error'));
        }

        return group;
    }

    async function load(garages) {
        currentGarages = garages;
        loadRun += 1;
        const run = loadRun;
        hideError(sectionError);
        empty.hidden = garages.length > 0;
        const nodes = await Promise.all(garages.map(fetchGroup));
        if (run === loadRun) {
            groups.replaceChildren(...nodes);
        }
    }

    copyButton.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(revealLink.textContent);
            copyStatus.textContent = 'Kopiert.';
        } catch {
            copyStatus.textContent = 'Kopieren nicht möglich – den Link markieren und selbst kopieren.';
        }
    });

    shareButton.addEventListener('click', async () => {
        try {
            await navigator.share({ title: shareTitle, url: revealLink.textContent });
        } catch (error) {
            // AbortError heißt: der Teilen-Dialog wurde einfach geschlossen.
            if (error.name !== 'AbortError') {
                copyStatus.textContent = 'Teilen nicht möglich – nimm „Kopieren“.';
            }
        }
    });

    return {
        load,
        clear() {
            loadRun += 1;
            currentGarages = [];
            hideReveal();
            hideError(sectionError);
            clearChildren(groups);
        },
    };
}
