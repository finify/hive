import * as bootstrap from 'bootstrap';
import './vendor/charts.js';
import { mountCharts } from './charts';
import './theme';

window.bootstrap = bootstrap;

// The top bar gains a rule once content scrolls under it.
const onScroll = () => document.querySelector('.topbar')?.classList.toggle('is-scrolled', window.scrollY > 4);
window.addEventListener('scroll', onScroll, { passive: true });

// Toasts: Livewire components dispatch `toast` with a message.
window.addEventListener('toast', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    const stack = document.getElementById('toast-stack');
    if (!stack || !detail) return;

    const toast = document.createElement('div');
    toast.className = `hive-toast${detail.type === 'error' ? ' is-error' : ''}`;
    toast.setAttribute('role', 'status');
    const icon = document.createElement('i');
    icon.className = `bi ${detail.type === 'error' ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill'}`;
    icon.setAttribute('aria-hidden', 'true');
    const text = document.createElement('div');
    text.textContent = detail.message;
    toast.append(icon, text);
    stack.append(toast);
    setTimeout(() => toast.remove(), 5000);
});

// Bootstrap modals opened and closed from Livewire.
window.addEventListener('modal', (event) => {
    const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
    const el = document.getElementById(detail.id);
    if (!el) return;
    const modal = bootstrap.Modal.getOrCreateInstance(el);
    detail.show ? modal.show() : modal.hide();
});

// "/" focuses search, like most tools people already know.
document.addEventListener('keydown', (event) => {
    if (event.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) {
        event.preventDefault();
        document.getElementById('global-search')?.focus();
    }
});

const boot = () => {
    onScroll();
    mountCharts(document);
};

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
