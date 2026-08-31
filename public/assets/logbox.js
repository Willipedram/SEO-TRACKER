document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-logbox-copy]');
    if (!button) return;
    const output = document.querySelector('.logbox-output');
    if (!output) return;

    const original = button.innerHTML;
    try {
        await navigator.clipboard.writeText(output.textContent || '');
        button.textContent = 'کپی شد';
    } catch {
        const selection = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(output);
        selection.removeAllRanges();
        selection.addRange(range);
        button.textContent = 'متن انتخاب شد';
    }
    window.setTimeout(() => { button.innerHTML = original; }, 1800);
});
