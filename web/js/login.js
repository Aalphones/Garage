// Anmeldeformular, das index.html und zustimmung.html teilen: E-Mail und Passwort an die API, bei Erfolg `onLoggedIn(user)`.
import { login } from './api.js';
import { hideError, showError } from './dom.js';

export function setupLogin(form, errorNode, onLoggedIn) {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        hideError(errorNode);
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        try {
            const user = await login(form.elements.email.value.trim(), form.elements.password.value);
            form.reset();
            await onLoggedIn(user);
        } catch (error) {
            showError(errorNode, error.message);
        }
        submitButton.disabled = false;
    });
}
