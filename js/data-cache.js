// Shared IndexedDB-backed cache for reference data (products, loan clients)
// that's identical across pages and rarely changes: catalog, prices, stock
// counts, and client balances. Falls back to an in-memory cache (no
// cross-page persistence, but still functional) if IndexedDB is unavailable.
//
// Freshness is change-driven, not time-driven: the server bumps a per-store
// `cache_meta.updated_at` (touchCacheStore() in config.php) whenever
// products/stock/retail_stock or loan_clients change. This module polls the
// cheap data_api.php?action=meta endpoint (a couple of indexed rows) and only
// refetches the full dataset when the server's timestamp is newer than what
// it last cached. META_CHECK_MIN_MS just debounces how often that cheap check
// runs; FALLBACK_TTL_MS is a safety net in case some write path forgets to
// call touchCacheStore().
var DataCache = (function() {
    var DB_NAME = 'screen_stock_cache';
    var DB_VERSION = 1;
    var META_CHECK_MIN_MS = 15 * 1000;
    var FALLBACK_TTL_MS = 30 * 60 * 1000;
    var STORES = ['products', 'clients'];

    var companyKey = (typeof window.APP_COMPANY_ID !== 'undefined' && window.APP_COMPANY_ID !== null)
        ? String(window.APP_COMPANY_ID) : 'all';

    var dbPromise = null;
    var memFallback = {}; // store -> record { key, data, serverTs, dataAt, checkedAt }
    var inflight = {};    // store -> Promise (de-dupe concurrent fetches)
    var metaPromise = null;
    var metaFetchedAt = 0;

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function(resolve) {
            if (!window.indexedDB) { resolve(null); return; }
            try {
                var req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = function() {
                    var db = req.result;
                    STORES.forEach(function(s) {
                        if (!db.objectStoreNames.contains(s)) db.createObjectStore(s, { keyPath: 'key' });
                    });
                };
                req.onsuccess = function() { resolve(req.result); };
                req.onerror = function() { resolve(null); };
            } catch (e) { resolve(null); }
        });
        return dbPromise;
    }

    function idbGet(store) {
        return openDb().then(function(db) {
            if (!db) return null;
            return new Promise(function(resolve) {
                try {
                    var tx = db.transaction(store, 'readonly');
                    var req = tx.objectStore(store).get(companyKey);
                    req.onsuccess = function() { resolve(req.result || null); };
                    req.onerror = function() { resolve(null); };
                } catch (e) { resolve(null); }
            });
        });
    }

    function idbSet(store, record) {
        memFallback[store] = record;
        return openDb().then(function(db) {
            if (!db) return;
            try {
                var tx = db.transaction(store, 'readwrite');
                tx.objectStore(store).put(record);
            } catch (e) { /* ignore, memory fallback already set */ }
        });
    }

    // Bumps just the last-checked time on an existing record, so the debounce
    // window restarts without re-fetching data (server confirmed no change).
    function idbTouch(store, ts) {
        return idbGet(store).then(function(record) {
            if (!record && memFallback[store]) record = memFallback[store];
            if (!record) return;
            record.checkedAt = ts;
            return idbSet(store, record);
        });
    }

    function idbClear(store) {
        delete memFallback[store];
        return openDb().then(function(db) {
            if (!db) return;
            try {
                var tx = db.transaction(store, 'readwrite');
                tx.objectStore(store).delete(companyKey);
            } catch (e) { /* ignore */ }
        });
    }

    function fetchFromServer(store) {
        var action = store === 'products' ? 'products' : 'clients';
        return fetch('data_api.php?action=' + action, { method: 'GET' })
            .then(function(r) { return r.json(); })
            .then(function(res) { return (res && res.success) ? (res.data || []) : []; });
    }

    // Returns a Promise<{products, clients}> of server-side last-changed
    // timestamps (ms). De-duped/debounced across both stores so calling
    // getProducts() and getClients() around the same time costs one request.
    function fetchMeta() {
        var now = Date.now();
        if (metaPromise && (now - metaFetchedAt) < META_CHECK_MIN_MS) return metaPromise;
        metaFetchedAt = now;
        metaPromise = fetch('data_api.php?action=meta', { method: 'GET' })
            .then(function(r) { return r.json(); })
            .then(function(res) { return (res && res.success) ? res.data : null; })
            .catch(function() { return null; });
        return metaPromise;
    }

    // Returns a Promise<Array> of rows for the given store, using the cache
    // when the server confirms nothing changed, and hitting the server (once,
    // de-duped) otherwise.
    function get(store, opts) {
        opts = opts || {};
        if (opts.force) return refresh(store);

        if (inflight[store]) return inflight[store];

        var p = idbGet(store).then(function(record) {
            if (!record && memFallback[store]) record = memFallback[store];
            if (!record) return refresh(store);

            var now = Date.now();
            if (now - record.dataAt > FALLBACK_TTL_MS) return refresh(store);
            if (now - record.checkedAt < META_CHECK_MIN_MS) return record.data;

            return fetchMeta().then(function(meta) {
                var serverTs = meta ? (meta[store] || 0) : 0;
                if (meta && serverTs > (record.serverTs || 0)) return refresh(store);
                idbTouch(store, now);
                return record.data;
            }).catch(function() { return record.data; });
        });
        inflight[store] = p.finally(function() { delete inflight[store]; });
        return inflight[store];
    }

    function refresh(store) {
        return Promise.all([fetchFromServer(store), fetchMeta()]).then(function(results) {
            var data = results[0];
            var meta = results[1];
            var now = Date.now();
            var record = {
                key: companyKey,
                data: data,
                serverTs: meta ? (meta[store] || 0) : 0,
                dataAt: now,
                checkedAt: now
            };
            idbSet(store, record);
            return data;
        });
    }

    function invalidate(store) {
        if (store) return idbClear(store);
        return Promise.all(STORES.map(idbClear));
    }

    function getProducts(opts) { return get('products', opts); }
    function getClients(opts) { return get('clients', opts); }

    // Distinct categories derived from the cached product list.
    // opts.withStock: 'wh' -> only categories with warehouse (bulk) stock > 0
    //                 'retail' -> only categories with retail stock > 0
    //                 omitted -> all categories regardless of stock
    function getCategories(opts) {
        opts = opts || {};
        return getProducts().then(function(list) {
            var seen = {};
            var out = [];
            list.forEach(function(p) {
                if (!p.category) return;
                if (opts.withStock === 'wh' && !(parseFloat(p.wh_qty) > 0)) return;
                if (opts.withStock === 'retail' && !(parseFloat(p.retail_qty) > 0)) return;
                if (!seen[p.category]) { seen[p.category] = true; out.push(p.category); }
            });
            out.sort();
            return out;
        });
    }

    return {
        getProducts: getProducts,
        getClients: getClients,
        getCategories: getCategories,
        invalidate: invalidate
    };
})();
