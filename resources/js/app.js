document.body.classList.add('js-ready');
document.querySelectorAll('[data-open-dialog]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', () => {
        const dialog = document.getElementById(button.dataset.openDialog);
        dialog.showModal();
        dialog.addEventListener('close', () => button.focus(), { once: true });
    });
});
document.querySelectorAll('[data-close-dialog]').forEach((button) => {
    button.addEventListener('click', () => button.closest('dialog').close());
});
document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
        }
    });
});
document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(reveal));
    });
});
document.querySelectorAll('[data-confirm-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.confirmed) return;
        event.preventDefault();
        const dialog = document.getElementById('confirmation');
        const trigger = document.activeElement;
        dialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirmForm;
        const accept = dialog.querySelector('[data-confirm-accept]');
        const handler = () => { form.dataset.confirmed = 'true'; dialog.close(); form.requestSubmit(); };
        accept.addEventListener('click', handler, { once: true });
        dialog.addEventListener('close', () => {
            accept.removeEventListener('click', handler);
            trigger?.focus();
        }, { once: true });
        dialog.showModal();
    });
});
import './inquiries.js';

import './public-intake';

document.querySelectorAll('[data-proposal-review]').forEach((form) => {
    form.querySelector('[data-select-supported]')?.addEventListener('click', () => {
        form.querySelectorAll('[data-candidate][data-supported="1"] [data-proposal-action]').forEach((select) => { select.value = 'accept'; select.dispatchEvent(new Event('change', {bubbles:true})); });
    });
    form.querySelectorAll('[data-proposal-action]').forEach((select) => select.addEventListener('change', () => {
        const correction = select.closest('[data-candidate]').querySelector('[data-correction]');
        if (correction && select.value === 'correct') correction.open = true;
    }));
});
document.querySelectorAll('[data-review-workspace]').forEach((workspace) => {
    workspace.classList.add('review-enhanced');
    const switches = document.querySelectorAll('[data-review-switch]');
    function showPane(name) {
        workspace.querySelectorAll('[data-review-pane]').forEach((pane) => pane.classList.toggle('review-mobile-hidden', pane.dataset.reviewPane !== name));
        switches.forEach((link) => {
            link.classList.toggle('active', link.dataset.reviewSwitch === name);
            if (link.dataset.reviewSwitch === name) link.setAttribute('aria-current','true'); else link.removeAttribute('aria-current');
        });
    }
    switches.forEach((link) => link.addEventListener('click', () => showPane(link.dataset.reviewSwitch)));
    showPane(location.hash === '#source-panel' ? 'source' : 'proposals');
});


document.querySelectorAll('[data-print-rfq]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', () => window.print());
});
if (document.querySelector('[role="alert"]')) {
    const invalid = document.querySelector('[aria-invalid="true"]');
    if (invalid) { invalid.focus(); invalid.scrollIntoView({block:'center'}); }
    else { const alert = document.querySelector('[role="alert"]'); alert.tabIndex = -1; alert.focus(); }
}
