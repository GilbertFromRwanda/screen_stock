// Registers the GilStock service worker. Included on every page's <head>.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').catch(function (err) {
            console.warn('Service worker registration failed:', err);
        });
    });
}
