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
        const trigger = event.submitter ?? document.activeElement;
        dialog.querySelector('[data-confirm-message]').textContent = form.dataset.confirmForm;
        const accept = dialog.querySelector('[data-confirm-accept]');
        const handler = () => { form.dataset.confirmed = 'true'; dialog.close(); form.requestSubmit(trigger instanceof HTMLButtonElement && trigger.form===form ? trigger : undefined); };
        accept.addEventListener('click', handler, { once: true });
        dialog.addEventListener('close', () => {
            accept.removeEventListener('click', handler);
            trigger?.focus();
        }, { once: true });
        dialog.showModal();
    });
});
import './inquiries.js';
import './guided-forms.js';

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

document.querySelectorAll('[data-offer-form]').forEach((form) => {
 const list=form.querySelector('[data-charge-list]');
 form.querySelector('[data-add-charge]')?.addEventListener('click',()=>{
  const original=list.querySelector('[data-charge-row]');
  if(!original||list.children.length>=50)return;
  const row=original.cloneNode(true);
  row.querySelectorAll('.field-error').forEach(error=>error.remove());
  row.querySelectorAll('[aria-invalid]').forEach(input=>input.removeAttribute('aria-invalid'));
  row.querySelectorAll('details').forEach(details=>details.open=false);
  const chargeDetails=row.querySelector('[data-charge-detail]');
  if(chargeDetails)chargeDetails.open=true;
  const chargeSummary=row.querySelector('[data-charge-summary]');
  if(chargeSummary)chargeSummary.textContent='New charge · enter the quoted details';
  row.querySelectorAll('input,textarea').forEach(input=>{if(input.type==='checkbox')input.checked=false;else input.value='';});
  row.querySelectorAll('select').forEach(select=>select.selectedIndex=0);
  row.querySelector('input[type="hidden"]').value='line-'+crypto.randomUUID().split('-')[0];
  row.querySelector('select[name$="[currency]"]').value=form.querySelector('[name="currency"]').value;
  row.querySelector('select[name$="[state]"]').value='unpriced';
  row.querySelector('select[name$="[basis]"]').value='flat';
  list.append(row); renumber();
  row.querySelector('input:not([type="hidden"])')?.focus();
  form.dispatchEvent(new Event('input',{bubbles:true}));
 });
 const renumber=()=>list.querySelectorAll('[data-charge-row]').forEach((row,index)=>{
  row.querySelector('[data-charge-number]').textContent=String(index+1);
  row.querySelector('[data-charge-key]').textContent=row.querySelector('input[type="hidden"]').value;
  row.querySelectorAll('[name]').forEach(el=>el.name=el.name.replace(/^lines\[\d+\]/,'lines['+index+']'));
  row.querySelectorAll('[id]').forEach(el=>el.id=el.id.replace(/^lines-\d+-/,'lines-'+index+'-'));
  row.querySelectorAll('label[for]').forEach(el=>el.htmlFor=el.htmlFor.replace(/^lines-\d+-/,'lines-'+index+'-'));
  row.querySelectorAll('[aria-describedby]').forEach(el=>el.setAttribute('aria-describedby',el.getAttribute('aria-describedby').replace(/^lines-\d+-/,'lines-'+index+'-')));
 });
 list.addEventListener('click',event=>{const button=event.target.closest('[data-remove-charge]');if(button&&list.children.length>1){button.closest('[data-charge-row]').remove();renumber();form.dispatchEvent(new Event('input',{bubbles:true}));}});
});
document.querySelectorAll('[data-fx-form]').forEach(form=>{
 const currency=form.querySelector('[data-fx-currency]');
 const refreshFx=()=>{
  form.querySelectorAll('[data-fx-source]').forEach(section=>{
   const same=section.dataset.fxSource===currency.value;
   section.hidden=same;
   section.querySelectorAll('input,select').forEach(input=>input.disabled=same);
   section.querySelector('[data-fx-target]').value=currency.value;
   section.querySelector('[data-fx-target-label]').textContent=currency.value;
  });
 };
 if(currency){
  refreshFx();
  currency.addEventListener('change',()=>{
   form.querySelectorAll('input[name^="fx["][type="checkbox"]').forEach(input=>input.checked=false);
   refreshFx();
  });
 }
});

document.querySelectorAll('[data-inquiry-navigation]').forEach((navigation) => {
    const current = navigation.querySelector('[aria-current="page"]');
    if (current && matchMedia('(max-width:767px)').matches) {
        navigation.scrollLeft = Math.max(0, current.offsetLeft - navigation.offsetLeft - 12);
    }
});
