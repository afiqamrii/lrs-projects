function revealDetails(element) {
    for (let node = element?.parentElement; node; node = node.parentElement) {
        if (node instanceof HTMLDetailsElement) node.open = true;
    }
    if (element instanceof HTMLDetailsElement) element.open = true;
}

document.querySelectorAll('[data-guided-form]').forEach((form) => {
    const guide = form.querySelector('[data-form-guide]');
    if (!guide) return;
    const buttons = [...guide.querySelectorAll('[data-form-go]')];
    const panels = [...form.querySelectorAll('[data-form-step]')];
    if (!buttons.length || !panels.length) return;
    let current = 0;
    const footer = document.createElement('div');
    footer.className = 'form-guide-actions';
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'btn btn-secondary';
    back.textContent = 'Back';
    const position = document.createElement('span');
    position.className = 'text-xs text-slate-500';
    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'btn btn-primary';
    const draft = document.createElement('button');
    draft.type = 'submit';
    draft.className = 'btn btn-secondary';
    draft.textContent = form.dataset.guideSave || 'Save draft';
    if (form.dataset.guideIntent) {
        draft.name = 'intent';
        draft.value = form.dataset.guideIntent;
    }
    footer.append(back, position, draft, next);
    guide.parentElement.append(footer);
    guide.hidden = false;

    function show(index, focus = false) {
        current = Math.max(0, Math.min(buttons.length - 1, index));
        const key = buttons[current].dataset.formGo;
        panels.forEach(panel => panel.classList.toggle('form-step-hidden', panel.dataset.formStep !== key));
        buttons.forEach((button, i) => {
            button.classList.toggle('active', i === current);
            if (i === current) button.setAttribute('aria-current', 'step');
            else button.removeAttribute('aria-current');
        });
        guide.querySelector('[data-form-position]').textContent = 'Step ' + (current + 1) + ' of ' + buttons.length;
        position.textContent = 'Step ' + (current + 1) + ' of ' + buttons.length;
        back.hidden = current === 0;
        next.hidden = current === buttons.length - 1;
        draft.hidden = current === buttons.length - 1;
        next.textContent = current < buttons.length - 1 ? 'Next: ' + buttons[current + 1].textContent.replace(/^\s*\d+\s*/, '').trim() : 'Next';
        if (focus) {
            buttons[current].focus();
            guide.scrollIntoView({ block: 'start', behavior: 'auto' });
        }
    }
    function revealField(input) {
        revealDetails(input);
        const panel = input.closest('[data-form-step]');
        const index = buttons.findIndex(button => button.dataset.formGo === panel?.dataset.formStep);
        if (index >= 0) show(index);
    }
    function advance() {
        const activePanels = panels.filter(panel => !panel.classList.contains('form-step-hidden'));
        const invalid = activePanels.flatMap(panel => [...panel.querySelectorAll('input,select,textarea')])
            .find(input => !input.disabled && input.getClientRects().length && !input.checkValidity());
        if (invalid) {
            revealField(invalid);
            invalid.reportValidity();
            return;
        }
        show(current + 1, true);
    }
    buttons.forEach((button, index) => button.addEventListener('click', () => show(index, true)));
    back.addEventListener('click', () => show(current - 1, true));
    next.addEventListener('click', advance);
    let firstInvalid = null;
    form.addEventListener('invalid', (event) => {
        if (firstInvalid && firstInvalid !== event.target) {
            event.preventDefault();
            return;
        }
        firstInvalid = event.target;
        revealField(event.target);
        requestAnimationFrame(() => { firstInvalid = null; });
    }, true);
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && event.target instanceof HTMLInputElement && event.target.type !== 'file' && current < buttons.length - 1) {
            event.preventDefault();
            advance();
        }
    });
    show(0);
    let validationFields = [];
    try { validationFields = JSON.parse(document.querySelector('[data-validation-fields]')?.dataset.validationFields || '[]'); } catch { validationFields = []; }
    form.querySelectorAll('[name]').forEach(input => {
        const key = input.name.replace(/\[([^\]]*)\]/g, '.$1').replace(/\.$/, '');
        if (validationFields.includes(key)) input.setAttribute('aria-invalid', 'true');
    });
    const error = form.querySelector('[aria-invalid="true"]');
    if (error) {
        revealField(error);
        requestAnimationFrame(() => { error.focus(); error.scrollIntoView({ block: 'center' }); });
    }
});

document.querySelectorAll('[aria-invalid="true"]').forEach(revealDetails);
function revealHashTarget() {
    let target;
    try { target = document.getElementById(decodeURIComponent(location.hash.slice(1))); } catch { return; }
    if (target) {
        revealDetails(target);
        requestAnimationFrame(() => target.scrollIntoView({ block: 'start' }));
    }
}
window.addEventListener('hashchange', revealHashTarget);
if (location.hash) revealHashTarget();

document.querySelectorAll('[data-event-form]').forEach((form) => {
    const kind = form.querySelector('[data-event-kind]');
    function updateBookingFields() {
        const confirmed = kind.value === 'booking_confirmed';
        form.querySelectorAll('[data-booking-fields]').forEach(group => {
            group.hidden = !confirmed;
            group.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = !confirmed; });
        });
    }
    kind.addEventListener('change', updateBookingFields);
    updateBookingFields();
});
