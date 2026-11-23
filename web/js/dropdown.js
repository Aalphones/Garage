// Gestaltete Auswahl statt Browser-Element: Knopf plus Liste (listbox), per Tastatur und Zeiger bedienbar.
import { chevron } from './icons.js';
import { element } from './dom.js';

const FLIP_SPACE_PX = 240;

/** `options`: `[{ value, label }]`. Gibt `{ setValue, destroy }` zurück. */
export function dropdown(container, { label, options, value, onChange }) {
    let selected = value;

    const root = element('div', undefined, 'dd');
    const trigger = element('button');
    trigger.type = 'button';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    const triggerText = element('span');
    trigger.appendChild(triggerText);
    trigger.insertAdjacentHTML('beforeend', chevron);

    const list = element('ul');
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', label);
    list.hidden = true;

    const optionNodes = options.map((option) => {
        const node = element('li', option.label);
        node.setAttribute('role', 'option');
        node.tabIndex = -1;
        node.dataset.value = option.value;
        list.appendChild(node);

        return node;
    });

    root.appendChild(trigger);
    root.appendChild(list);
    container.appendChild(root);

    function labelOf(optionValue) {
        return options.find((option) => option.value === optionValue)?.label ?? '';
    }

    function paint() {
        triggerText.textContent = labelOf(selected);
        trigger.setAttribute('aria-label', label + ': ' + labelOf(selected));
        for (const node of optionNodes) {
            node.setAttribute('aria-selected', String(node.dataset.value === selected));
        }
    }

    function close() {
        list.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }

    function open() {
        const hasNoRoomBelow = trigger.getBoundingClientRect().bottom > window.innerHeight - FLIP_SPACE_PX;
        root.classList.toggle('up', hasNoRoomBelow);
        list.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        const target = optionNodes.find((node) => node.dataset.value === selected) ?? optionNodes[0];
        target.focus();
    }

    function pick(node) {
        selected = node.dataset.value;
        paint();
        close();
        trigger.focus();
        onChange(selected);
    }

    function moveFocus(step) {
        const index = optionNodes.indexOf(document.activeElement);
        const next = Math.min(Math.max(index + step, 0), optionNodes.length - 1);
        optionNodes[next].focus();
    }

    function closeOnOutsideClick(event) {
        if (!root.contains(event.target)) {
            close();
        }
    }

    trigger.addEventListener('click', () => {
        if (list.hidden) {
            open();
        } else {
            close();
        }
    });
    trigger.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            open();
        }
    });
    list.addEventListener('click', (event) => {
        const node = event.target.closest('[role=option]');
        if (node !== null) {
            pick(node);
        }
    });
    list.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveFocus(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveFocus(-1);
        } else if ((event.key === 'Enter' || event.key === ' ') && optionNodes.includes(document.activeElement)) {
            event.preventDefault();
            pick(document.activeElement);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
            trigger.focus();
        } else if (event.key === 'Tab') {
            close();
        }
    });
    document.addEventListener('click', closeOnOutsideClick);

    paint();

    return {
        setValue(newValue) {
            selected = newValue;
            paint();
        },
        destroy() {
            document.removeEventListener('click', closeOnOutsideClick);
            root.remove();
        },
    };
}
