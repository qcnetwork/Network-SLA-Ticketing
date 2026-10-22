import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import icons from './icons';
import notificationBell from './notification-bell';
import optionSearch from './option-search';
import photoInput from './photo-input';
import topScrollbar from './top-scrollbar';

window.Alpine = Alpine;
Alpine.data('notificationBell', notificationBell);
Alpine.data('optionSearch', optionSearch);
Alpine.data('photoInput', photoInput);
Alpine.data('topScrollbar', topScrollbar);
Alpine.start();

const renderIcons = () => createIcons({ icons });
document.addEventListener('DOMContentLoaded', renderIcons);
window.renderIcons = renderIcons;

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
}
