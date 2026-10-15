import areaMap from './area-map';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('areaMap', areaMap);
});
