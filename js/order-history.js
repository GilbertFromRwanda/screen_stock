// IndexedDB-backed history of orders a customer has placed/tracked on this
// device — lets order_track.php show "your recent orders" without asking the
// customer to re-type their order number and phone each time. Falls back to
// an in-memory list (works for the current page load only) if IndexedDB is
// unavailable. Separate DB from js/data-cache.js on purpose: that one is for
// logged-in staff catalog data; this one is for the public, no-login customer
// pages and must never be gated behind isLoggedIn().
var OrderHistory = (function() {
    var DB_NAME = 'screen_stock_orders';
    var DB_VERSION = 1;
    var STORE = 'orders';

    var dbPromise = null;
    var memFallback = {}; // order_number -> record

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function(resolve) {
            if (!window.indexedDB) { resolve(null); return; }
            try {
                var req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = function() {
                    var db = req.result;
                    if (!db.objectStoreNames.contains(STORE)) {
                        db.createObjectStore(STORE, { keyPath: 'order_number' });
                    }
                };
                req.onsuccess = function() { resolve(req.result); };
                req.onerror = function() { resolve(null); };
            } catch (e) { resolve(null); }
        });
        return dbPromise;
    }

    // Adds/updates one order record (keyed by order_number), stamping when it
    // was saved so the list can be sorted most-recent-first.
    function saveOrder(order) {
        if (!order || !order.order_number) return Promise.resolve();
        var record = {};
        for (var k in order) record[k] = order[k];
        record.saved_at = Date.now();
        memFallback[record.order_number] = record;
        return openDb().then(function(db) {
            if (!db) return;
            try {
                var tx = db.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).put(record);
            } catch (e) { /* ignore, memory fallback already set */ }
        });
    }

    // Returns a Promise<Array> of saved orders, most recently saved first.
    function getOrders() {
        return openDb().then(function(db) {
            if (!db) {
                return Object.keys(memFallback).map(function(k) { return memFallback[k]; });
            }
            return new Promise(function(resolve) {
                try {
                    var tx = db.transaction(STORE, 'readonly');
                    var req = tx.objectStore(STORE).getAll();
                    req.onsuccess = function() { resolve(req.result || []); };
                    req.onerror = function() { resolve([]); };
                } catch (e) { resolve([]); }
            });
        }).then(function(rows) {
            return rows.sort(function(a, b) { return (b.saved_at || 0) - (a.saved_at || 0); });
        });
    }

    function removeOrder(order_number) {
        delete memFallback[order_number];
        return openDb().then(function(db) {
            if (!db) return;
            try {
                var tx = db.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).delete(order_number);
            } catch (e) { /* ignore */ }
        });
    }

    return {
        saveOrder: saveOrder,
        getOrders: getOrders,
        removeOrder: removeOrder
    };
})();
