import Alpine from 'alpinejs';
import { createIcons } from 'lucide';
import icons from './icons';
import optionSearch from './option-search';
import photoInput from './photo-input';

window.Alpine = Alpine;
Alpine.data('optionSearch', optionSearch);
Alpine.data('photoInput', photoInput);
Alpine.start();

const renderIcons = () => createIcons({ icons });
document.addEventListener('DOMContentLoaded', renderIcons);
window.renderIcons = renderIcons;
