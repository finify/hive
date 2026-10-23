// Light and dark mode: follows the system setting until the person picks one.
const KEY = 'hive-theme';
const media = window.matchMedia('(prefers-color-scheme: dark)');

const stored = () => {
    try {
        return localStorage.getItem(KEY);
    } catch {
        return null;
    }
};

const apply = () => {
    const choice = stored() ?? 'system';
    const theme = choice === 'system' ? (media.matches ? 'dark' : 'light') : choice;
    document.documentElement.setAttribute('data-bs-theme', theme);
    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
        button.setAttribute('aria-pressed', String(button.dataset.themeChoice === choice));
    });
};

media.addEventListener('change', apply);
document.addEventListener('livewire:navigated', apply);
document.addEventListener('DOMContentLoaded', apply);

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-theme-choice]');
    if (!button) return;
    try {
        localStorage.setItem(KEY, button.dataset.themeChoice);
    } catch {
        // Private windows may refuse storage; the choice then lasts for this page only.
    }
    apply();
});

apply();
