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
    const waitRemainingAtRender = Math.max(0, Number(form.dataset.waitRemainingMs) || 0);
    const elapsedAtRender = Math.max(0, Number(form.dataset.elapsedAtRenderMs) || 0);
    const monotonicStartedAt = window.performance.now();
    let enabled = false;

    const monotonicElapsed = () => Math.max(0, window.performance.now() - monotonicStartedAt);
    const waitRemaining = () => Math.max(0, waitRemainingAtRender - monotonicElapsed());

    const update = () => {
        const remaining = waitRemaining();
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
        if (!enabled || waitRemaining() > 0) {
            event.preventDefault();
            update();
            return;
        }

        if (!(event.submitter instanceof HTMLButtonElement)) {
            event.preventDefault();
            return;
        }

        selectedOptionInput.value = event.submitter.value;
        elapsedInput.value = String(Math.round(elapsedAtRender + monotonicElapsed()));
        buttons.forEach(button => { button.disabled = true; });
        status.textContent = 'Scelta in verifica…';
    });

    update();
})();
