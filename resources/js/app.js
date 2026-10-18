import './bootstrap';

import Alpine from 'alpinejs';
import { mountVueIslands } from './vue/islands';

// 1) Vue primero: monta las islas (#app / [data-vue-island]) y las marca con x-ignore.
mountVueIslands();

// 2) Alpine después: procesa el resto del layout Blade (navegación, dropdowns...),
//    ignorando el interior de las islas Vue.
window.Alpine = Alpine;

Alpine.start();
