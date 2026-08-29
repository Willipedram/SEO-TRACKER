document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.term-trigger');
    document.querySelectorAll('.term-trigger[aria-expanded="true"]').forEach((item) => {
        if (item !== trigger) item.setAttribute('aria-expanded', 'false');
    });
    if (trigger) trigger.setAttribute('aria-expanded', trigger.getAttribute('aria-expanded') === 'true' ? 'false' : 'true');
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') document.querySelectorAll('.term-trigger[aria-expanded="true"]').forEach((item) => item.setAttribute('aria-expanded', 'false'));
});
