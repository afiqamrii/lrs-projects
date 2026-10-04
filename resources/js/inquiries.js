document.querySelectorAll('[data-inquiry-form]').forEach((form) => {
    const mode = form.querySelector('[data-shipment-mode]');
    const updateMode = () => form.querySelectorAll('[data-mode-section]').forEach((section) => {
        const active = section.dataset.modeSection === mode.value;
        section.hidden = !active;
        section.querySelectorAll('input, select, textarea, button').forEach((input) => input.disabled = !active);
        section.querySelector('[data-add-row]').disabled = !active || section.querySelector('[data-rows]').children.length >= 20;
    });
    mode.addEventListener('change', updateMode);
    updateMode();

    const client = form.querySelector('[data-client-select]');
    const contact = form.querySelector('[data-contact-select]');
    const filterContacts = () => {
        [...contact.options].forEach((option) => {
            if (!option.dataset.client) return;
            option.hidden = option.dataset.client !== client.value;
            option.disabled = option.hidden;
        });
        if (contact.selectedOptions[0]?.disabled) contact.value = '';
    };
    client.addEventListener('change', filterContacts);
    filterContacts();

    form.querySelectorAll('[data-rows]').forEach((container) => {
        const kind = container.dataset.rows;
        const add = form.querySelector('[data-add-row="'+kind+'"]');
        const section = container.closest('section');
        const reindex = () => {
            [...container.children].forEach((row, index) => {
                row.querySelector('[data-row-number]').textContent = String(index + 1);
                row.querySelectorAll('[name]').forEach((input) => {
                    input.name = input.name.replace(/\[(?:\d+|__INDEX__)\]/, '['+index+']');
                });
                row.querySelectorAll('[id], [for], [aria-describedby]').forEach((node) => {
                    ['id', 'for', 'aria-describedby'].forEach((attribute) => {
                        if (node.hasAttribute(attribute)) {
                            node.setAttribute(attribute, node.getAttribute(attribute).replace(
                                new RegExp('shipment-'+kind+'-(?:\\d+|__INDEX__)-', 'g'),
                                'shipment-'+kind+'-'+index+'-'
                            ));
                        }
                    });
                });
                const remove = row.querySelector('[data-remove-row]');
                remove.hidden = false;
                remove.onclick = () => {
                    row.remove();
                    reindex();
                    form.dispatchEvent(new Event('input', { bubbles: true }));
                };
            });
            add.disabled = mode.value !== section.dataset.modeSection || container.children.length >= 20;
            section.querySelector('[data-row-status]').textContent = container.children.length + ' of 20 rows';
        };
        add.hidden = false;
        add.addEventListener('click', () => {
            if (container.children.length >= 20) return;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = form.querySelector('[data-row-template="'+kind+'"]').innerHTML
                .replaceAll('__INDEX__', String(container.children.length));
            container.append(wrapper.firstElementChild);
            reindex();
            container.lastElementChild.querySelector('input, select')?.focus();
            form.dispatchEvent(new Event('input', { bubbles: true }));
        });
        reindex();
    });
});

document.querySelectorAll('[data-unsaved]').forEach((form) => {
    let dirty = false;
    form.addEventListener('input', () => dirty = true);
    form.addEventListener('change', () => dirty = true);
    form.addEventListener('submit', (event) => queueMicrotask(() => { if (!event.defaultPrevented) dirty = false; }));
    window.addEventListener('beforeunload', (event) => {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
});

document.querySelectorAll('[data-copy-message]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click', async () => {
        const message = document.getElementById(button.dataset.copyMessage);
        const status = button.parentElement.querySelector('[data-copy-status]');
        try {
            await navigator.clipboard.writeText(message.value);
            status.textContent = 'Message copied. Communication has not been recorded.';
        } catch {
            message.focus();
            message.select();
            status.textContent = 'Clipboard unavailable. The message is selected; press Ctrl+C (or Command+C) to copy. Communication has not been recorded.';
        }
    });
});
