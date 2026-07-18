(() => {
    'use strict';

    const form = document.querySelector('[data-choice-form]');
    if (!form) {
        return;
    }

    const buttons = Array.from(form.querySelectorAll('button[type="submit"]'));
    const status = form.querySelector('[data-choice-status]');
    const elapsedInput = form.querySelector('[data-client-elapsed]');
    const selectedOptionInput = form.querySelector('[data-selected-option]');
    const shownAt = Number(form.dataset.shownAt);
    const availableAt = Number(form.dataset.availableAt);
    let enabled = false;

    const update = () => {
        const remaining = Math.max(0, availableAt - Date.now());
        if (remaining === 0) {
            enabled = true;
            buttons.forEach(button => { button.disabled = false; });
            status.textContent = 'Scegli adesso.';
            return;
        }

        buttons.forEach(button => { button.disabled = true; });
        status.textContent = `Attendi ${(remaining / 1000).toFixed(1).replace('.', ',')} secondi prima di scegliere.`;
        window.setTimeout(update, Math.min(100, remaining));
    };

    form.addEventListener('submit', event => {
        if (!enabled || Date.now() < availableAt) {
            event.preventDefault();
            update();
            return;
        }

        if (!(event.submitter instanceof HTMLButtonElement)) {
            event.preventDefault();
            return;
        }

        selectedOptionInput.value = event.submitter.value;
        elapsedInput.value = String(Math.max(0, Date.now() - shownAt));
        buttons.forEach(button => { button.disabled = true; });
        status.textContent = 'Scelta in verifica…';
    });

    update();
})();
