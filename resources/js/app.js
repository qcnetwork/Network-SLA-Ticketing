import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import customerPicker from './customer-picker';
import dashboardLive from './dashboard-live';
import icons from './icons';
import mediaViewer from './media-viewer';
import notificationBell from './notification-bell';
import optionSearch from './option-search';
import personPicker from './person-picker';
import photoInput from './photo-input';
import repairMaterials from './repair-materials';
import saleForm from './sale-form';
import serialPicker from './serial-picker';
import serialScanner from './serial-scanner';
import snFinder from './sn-finder';
import stockForm from './stock-form';
import topScrollbar from './top-scrollbar';
import usageForm from './usage-form';

window.Alpine = Alpine;
Alpine.data('customerPicker', customerPicker);
Alpine.data('dashboardLive', dashboardLive);
Alpine.data('mediaViewer', mediaViewer);
Alpine.data('notificationBell', notificationBell);
Alpine.data('optionSearch', optionSearch);
Alpine.data('personPicker', personPicker);
Alpine.data('photoInput', photoInput);
Alpine.data('repairMaterials', repairMaterials);
Alpine.data('saleForm', saleForm);
Alpine.data('serialPicker', serialPicker);
Alpine.data('serialScanner', serialScanner);
Alpine.data('snFinder', snFinder);
Alpine.data('stockForm', stockForm);
Alpine.data('topScrollbar', topScrollbar);
Alpine.data('usageForm', usageForm);
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
