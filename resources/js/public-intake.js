document.querySelectorAll('[data-public-intake]').forEach((form) => {
    const steps = [...form.querySelectorAll('[data-step]')];
    const actions = form.querySelector('.public-step-actions');
    const back = form.querySelector('[data-step-back]');
    const next = form.querySelector('[data-step-next]');
    const status = form.querySelector('[data-step-status]');
    let current = 0;
    const show = (index, focus = true) => {
        current = index;
        steps.forEach((step, i) => step.hidden = i !== index);
        form.querySelectorAll('[data-step-link]').forEach((link, i) => {
            link.classList.toggle('current', i === index);
            if (i === index) link.setAttribute('aria-current', 'step');
            else link.removeAttribute('aria-current');
        });
        back.hidden = index === 0;
        next.hidden = index === steps.length - 1;
        status.textContent = 'Step ' + (index + 1) + ' of ' + steps.length;
        if (index === 3) review();
        if (focus) steps[index].querySelector('h2').focus();
    };
    const validate = (section) => {
        const invalid = [...section.querySelectorAll('input,select,textarea')].find((input) => !input.checkValidity());
        if (!invalid) return true;
        const index = steps.findIndex((step) => step.contains(invalid));
        show(index, false);
        invalid.setAttribute('aria-invalid', 'true');
        const errorHost = invalid.id === 'privacy-acknowledged' ? document.getElementById('privacy-error') : invalid.parentElement;
        let error = errorHost.querySelector('[data-client-error]');
        if (!error) {
            error = document.createElement('p');
            error.className = 'field-error';
            error.dataset.clientError = '';
            error.id = invalid.id + '-client-error';
            errorHost.append(error);
            invalid.setAttribute('aria-describedby', (invalid.getAttribute('aria-describedby') || '') + ' ' + error.id);
        }
        error.textContent = invalid.validationMessage;
        invalid.focus();
        return false;
    };
    form.addEventListener('input', (event) => {
        if (event.target.checkValidity?.()) {
            event.target.removeAttribute('aria-invalid');
            const errorHost = event.target.id === 'privacy-acknowledged' ? document.getElementById('privacy-error') : event.target.parentElement;
            errorHost.querySelector('[data-client-error]')?.remove();
        }
    });
    form.querySelectorAll('[data-step-link]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault();
        const target = Number(link.dataset.stepLink);
        if (target <= current || validate(steps[current])) show(target);
    }));
    back.addEventListener('click', () => show(current - 1));
    next.addEventListener('click', () => { if (validate(steps[current])) show(current + 1); });
    const values = () => new FormData(form);
    const value = (data, name) => String(data.get(name) || '').trim();
    const review = () => {
        const data = values();
        const root = form.querySelector('[data-review]');
        root.replaceChildren();
        const block = (title, lines, step) => {
            const section = document.createElement('section');
            const header = document.createElement('div');
            header.className = 'flex items-center justify-between gap-3 mb-3';
            const heading = document.createElement('h3');
            heading.className = 'text-sm';
            heading.textContent = title;
            const edit = document.createElement('button');
            edit.type = 'button';
            edit.className = 'text-link text-xs';
            edit.textContent = 'Edit ' + title.toLowerCase();
            edit.onclick = () => show(step);
            header.append(heading, edit);
            section.append(header);
            lines.forEach((line) => {
                const p = document.createElement('p');
                p.className = 'text-xs leading-6 whitespace-pre-line text-slate-600';
                p.textContent = line;
                section.append(p);
            });
            root.append(section);
        };
        block('Contact', ['Name: ' + (value(data, 'contact[name]') || 'Not provided'), 'Email: ' + (value(data, 'contact[email]') || 'Not provided'), 'Company: ' + (value(data, 'contact[company]') || 'Not provided'), 'Phone: ' + (value(data, 'contact[phone]') || 'Not provided')], 0);
        const shipment = (key) => value(data, 'shipment[' + key + ']');
        const selected = (name) => data.getAll(name).map((key) => form.querySelector('input[value="' + key + '"]')?.closest('label')?.textContent.trim() || key).join(', ') || 'None specified';
        const mode = shipment('mode');
        const scope = form.elements.namedItem('shipment[scope]');
        block('Shipment', [
            'Route: ' + (shipment('origin_location') || 'Unknown') + ', ' + (shipment('origin_country') || 'Unknown') + ' → ' + (shipment('destination_location') || 'Unknown') + ', ' + (shipment('destination_country') || 'Unknown'),
            'Cargo: ' + (shipment('cargo_description') || 'Not provided'),
            'Mode: ' + (mode === 'unknown' ? 'Not sure yet' : mode),
            'Scope: ' + scope.selectedOptions[0].textContent,
            'Ready: ' + (shipment('cargo_ready_date') || 'Unknown') + ' · Preferred arrival: ' + (shipment('arrival_date') || 'Unknown'),
            'Timing flexibility: ' + (shipment('timing_flexibility') || 'Not specified'),
            'Services: ' + selected('shipment[services][]'),
            'Pickup address: ' + (shipment('pickup_address') || 'Unknown'),
            'Delivery address: ' + (shipment('delivery_address') || 'Unknown'),
            'Special requirements: ' + selected('shipment[special_flags][]'),
            'Special notes: ' + (shipment('special_notes') || 'None supplied'),
        ], 1);
        ['packages', 'containers'].forEach((kind) => {
            const lines = [];
            form.querySelectorAll('[data-public-rows="' + kind + '"] [data-public-row]').forEach((row, index) => {
                const inputs = [...row.querySelectorAll('input,select')];
                if (!inputs.some((input) => input.value)) return;
                const entries = inputs.map((input) => row.querySelector('label[for="' + input.id + '"]').textContent + ': ' + (input.value || 'Unknown'));
                lines.push((kind === 'packages' ? 'Group ' : 'Row ') + (index + 1) + '\n' + entries.join(' · '));
            });
            if (lines.length) block(kind === 'packages' ? 'LCL package details (retained)' : 'FCL container details (retained)', lines, 1);
        });
        const commercial = ['declared_volume','declared_volume_source','incoterm','named_place','goods_value','goods_currency','budget','budget_currency','reference_quote','reference_currency'];
        if (commercial.some((key) => shipment(key))) block('Optional context', commercial.map((key) => key.replaceAll('_',' ') + ': ' + (shipment(key) || 'Not specified')), 1);
        const files = [...form.querySelector('[data-upload]').files];
        block('Documents & notes', ['Files: ' + (files.map((file) => file.name).join(', ') || 'None selected'), 'Suggested classification: ' + form.elements.namedItem('classification').selectedOptions[0].textContent, 'Notes: ' + (value(data,'additional_notes') || 'None supplied')], 2);
    };
    const updateMode = () => {
        const mode = value(values(),'shipment[mode]');
        form.querySelectorAll('[data-public-mode]').forEach((section) => section.hidden = section.dataset.publicMode !== mode);
    };
    const updateAddresses = () => {
        const data = values();
        const scope = value(data,'shipment[scope]');
        const services = data.getAll('shipment[services][]');
        form.querySelector('[data-address="pickup"]').hidden = !['door_to_port','door_to_door'].includes(scope) && !services.includes('pickup') && !value(data,'shipment[pickup_address]');
        form.querySelector('[data-address="delivery"]').hidden = !['port_to_door','door_to_door'].includes(scope) && !services.includes('delivery') && !value(data,'shipment[delivery_address]');
    };
    form.addEventListener('change', () => {
        updateMode();
        updateAddresses();
        if (current === 3) review();
    });
    form.querySelectorAll('[data-public-rows]').forEach((container) => {
        const kind = container.dataset.publicRows;
        const add = form.querySelector('[data-public-add="' + kind + '"]');
        add.type = 'button';
        const reindex = () => {
            [...container.children].forEach((row,index) => {
                row.querySelector('[data-row-number]').textContent = String(index + 1);
                row.querySelectorAll('[name]').forEach((input) => input.name = input.name.replace(/\[(?:\d+|__INDEX__)\]/, '[' + index + ']'));
                row.querySelectorAll('[id],[for],[aria-describedby]').forEach((node) => ['id','for','aria-describedby'].forEach((attr) => {
                    if (node.hasAttribute(attr)) node.setAttribute(attr,node.getAttribute(attr).replace(new RegExp('shipment-' + kind + '-(?:\\d+|__INDEX__)-','g'),'shipment-' + kind + '-' + index + '-'));
                }));
                const remove = row.querySelector('[data-public-remove]');
                remove.hidden = false;
                remove.onclick = () => {
                    const entered = [...row.querySelectorAll('input,select')].some((input) => input.value);
                    if (entered && !window.confirm('Remove this entered row? Its details will be removed from this request.')) return;
                    row.remove();
                    reindex();
                    form.dispatchEvent(new Event('input', { bubbles: true }));
                };
            });
            add.disabled = container.children.length >= 20;
        };
        add.addEventListener('click', () => {
            if (container.children.length >= 20) return;
            const wrapper = document.createElement('div');
            wrapper.innerHTML = form.querySelector('[data-public-template="' + kind + '"]').innerHTML.replaceAll('__INDEX__',String(container.children.length));
            container.append(wrapper.firstElementChild);
            reindex();
            container.lastElementChild.querySelector('input,select').focus();
            form.dispatchEvent(new Event('input', { bubbles: true }));
        });
        reindex();
    });
    const fileInput = form.querySelector('[data-upload]');
    fileInput.addEventListener('change', () => {
        const files = [...fileInput.files];
        const total = files.reduce((sum,file) => sum + file.size,0);
        let error = '';
        if (files.length > Number(fileInput.dataset.count)) error = 'Choose no more than ' + fileInput.dataset.count + ' files.';
        else if (files.some((file) => file.size > Number(fileInput.dataset.maxKb) * 1024)) error = 'A file exceeds the per-file size limit.';
        else if (total > Number(fileInput.dataset.totalKb) * 1024) error = 'The files exceed the combined size limit.';
        fileInput.setCustomValidity(error);
        form.querySelector('[data-file-status]').textContent = error || (files.length ? files.length + ' files selected · ' + (total/1048576).toFixed(1) + ' MB. Uploaded only on submission.' : 'No files selected.');
    });
    form.addEventListener('submit', (event) => {
        if (!validate(form)) { event.preventDefault(); return; }
        const button = form.querySelector('[data-submit]');
        button.disabled = true;
        button.textContent = 'Submitting…';
    });
    actions.hidden = false;
    updateMode();
    updateAddresses();
    const firstError = form.querySelector('[aria-invalid="true"]');
    show(firstError ? steps.findIndex((step) => step.contains(firstError)) : 0, false);
    firstError?.focus();
    if (!firstError) { const summary=document.querySelector('[role="alert"]'); if(summary) { summary.tabIndex=-1; summary.focus(); } }
    if ([...form.querySelectorAll('input[type="text"],input[type="email"],textarea')].some((field) => field.value.trim())) form.dispatchEvent(new Event('input',{bubbles:true}));
});

document.querySelectorAll('[data-copy-inquiry-link]').forEach((button) => {
    button.hidden = false;
    button.addEventListener('click',async () => {
        const field = document.getElementById('customer-inquiry-link');
        const status = document.querySelector('[data-link-copy-status]');
        try {
            await navigator.clipboard.writeText(field.value);
            status.textContent = 'Inquiry link copied. ' + field.dataset.scope;
        } catch {
            field.focus(); field.select();
            status.textContent = 'Clipboard unavailable. Link selected; press Ctrl+C or Command+C. ' + field.dataset.scope;
        }
    });
});
document.querySelectorAll('[data-confirm-mailbox]').forEach((form) => {
    const token = location.hash.slice(1);
    if (/^[a-f0-9]{64}$/.test(token)) {
        form.elements.namedItem('confirmation_token').value = token;
        history.replaceState(null,'',location.pathname);
    }
});
document.querySelectorAll('[data-resolve-client]').forEach((form) => {
    const client = form.elements.namedItem('client_id');
    const contact = form.elements.namedItem('client_contact_id');
    const filter = () => {
        [...contact.options].forEach((option) => {
            if (!option.dataset.client) return;
            option.hidden = option.dataset.client !== client.value;
            option.disabled = option.hidden;
        });
        if (contact.selectedOptions[0]?.disabled) contact.value = '';
    };
    client.addEventListener('change',filter);
    filter();
});
