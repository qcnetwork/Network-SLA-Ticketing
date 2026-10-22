import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import icons from './icons';
import mediaViewer from './media-viewer';
import notificationBell from './notification-bell';
import optionSearch from './option-search';
import photoInput from './photo-input';
import topScrollbar from './top-scrollbar';

window.Alpine = Alpine;
Alpine.data('mediaViewer', mediaViewer);
Alpine.data('notificationBell', notificationBell);
Alpine.data('optionSearch', optionSearch);
Alpine.data('photoInput', photoInput);
Alpine.data('topScrollbar', topScrollbar);
Alpine.start();

const renderIcons = () => createIcons({ icons });
document.addEventListener('DOMContentLoaded', renderIcons);
window.renderIcons = renderIcons;

// <form data-confirm="..."> asks before submitting. An inline onsubmit="return confirm()" would be
// blocked by the Content-Security-Policy, and the form would then submit without asking.
document.addEventListener('submit', (event) => {
    const message = event.target.dataset?.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}
