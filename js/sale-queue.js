// Offline-first outbox for sale submissions (sale_bulk/sale_retail/sale_external).
// On a slow/flaky connection the direct POST to sales.php can fail or time out;
// rather than lose the cart, the sale is written to IndexedDB first (durable
// before any network attempt) and a single immediate send is tried. If that
// fails, the record stays queued and is retried in the background — on
// 'online', on a periodic timer, and on page load — until the server
// confirms it. Falls back to an in-memory queue (session-only) if IndexedDB
// is unavailable, mirroring js/data-cache.js's degrade path.
//
// A client-generated client_ref travels with every attempt so a retry that
// actually reached the server on a prior try (response merely lost) is
// recognized and skipped server-side instead of double-inserted.
var SaleQueue = (function() {
    var DB_NAME = 'screen_stock_outbox';
    var DB_VERSION = 1;
    var STORE = 'pending_sales';
    var FLUSH_INTERVAL_MS = 20 * 1000;
    var IMMEDIATE_TIMEOUT_MS = 8 * 1000;
    var AUTH_ERROR_RETRY_MS = 2 * 60 * 1000;

    var INVALIDATE_STORES = {
        bulk: ['products', 'clients', 'recent_sales_bulk'],
        retail: ['products', 'clients', 'recent_sales_retail'],
        external: ['clients', 'recent_sales_external']
    };

    var companyKey = (typeof window.APP_COMPANY_ID !== 'undefined' && window.APP_COMPANY_ID !== null)
        ? String(window.APP_COMPANY_ID) : 'all';

    // Localhost dev never has a flaky connection worth queuing for, and running
    // the queue there just makes the offline-retry race (duplicate client_ref
    // submissions) easier to hit while testing. Submit directly instead.
    var IS_LOCALHOST = ['localhost', '127.0.0.1', '::1', ''].indexOf(window.location.hostname) !== -1;

    var dbPromise = null;
    var memFallback = {}; // client_ref -> record, used when indexedDB is unavailable
    var flushing = false;

    function openDb() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise(function(resolve) {
            if (!window.indexedDB) { resolve(null); return; }
            try {
                var req = indexedDB.open(DB_NAME, DB_VERSION);
                req.onupgradeneeded = function() {
                    var db = req.result;
                    if (!db.objectStoreNames.contains(STORE)) {
                        var os = db.createObjectStore(STORE, { keyPath: 'client_ref' });
                        os.createIndex('by_status', 'status', { unique: false });
                    }
                };
                req.onsuccess = function() { resolve(req.result); };
                req.onerror = function() { resolve(null); };
            } catch (e) { resolve(null); }
        });
        return dbPromise;
    }

    function putRecord(record) {
        memFallback[record.client_ref] = record;
        return openDb().then(function(db) {
            if (!db) return;
            try {
                db.transaction(STORE, 'readwrite').objectStore(STORE).put(record);
            } catch (e) { /* ignore, memory fallback already set */ }
        });
    }

    function deleteRecord(clientRef) {
        delete memFallback[clientRef];
        return openDb().then(function(db) {
            if (!db) return;
            try {
                db.transaction(STORE, 'readwrite').objectStore(STORE).delete(clientRef);
            } catch (e) { /* ignore */ }
        });
    }

    function getAllRecords() {
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
        });
    }

    function genClientRef() {
        return 'cr_' + companyKey + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    function formToObject(formEl, extraFields) {
        var out = {};
        new FormData(formEl).forEach(function(value, key) { out[key] = value; });
        if (extraFields) {
            for (var k in extraFields) if (extraFields.hasOwnProperty(k)) out[k] = extraFields[k];
        }
        return out;
    }

    function objectToFormData(fields) {
        var fd = new FormData();
        for (var k in fields) if (fields.hasOwnProperty(k)) fd.append(k, fields[k]);
        return fd;
    }

    // Rejects with {timeout:true} if the request doesn't settle in time, so a
    // hung/slow connection queues the sale instead of leaving the cashier
    // staring at a disabled button.
    function postWithTimeout(fields) {
        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var opts = { method: 'POST', body: objectToFormData(fields) };
        if (controller) opts.signal = controller.signal;

        var timer;
        var timeoutPromise = new Promise(function(resolve, reject) {
            timer = setTimeout(function() {
                if (controller) controller.abort();
                reject({ timeout: true });
            }, IMMEDIATE_TIMEOUT_MS);
        });

        var fetchPromise = fetch('sales.php', opts).then(function(r) { return r.json(); });

        return Promise.race([fetchPromise, timeoutPromise]).then(function(res) {
            clearTimeout(timer);
            return res;
        }, function(err) {
            clearTimeout(timer);
            throw err;
        });
    }

    function refreshBadge() {
        getAllRecords().then(function(records) {
            var badge = document.getElementById('saleQueueBadge');
            var count = records.length;
            if (!badge) {
                if (count === 0) return;
                badge = document.createElement('div');
                badge.id = 'saleQueueBadge';
                document.body.appendChild(badge);
            }
            if (count === 0) {
                badge.className = '';
                return;
            }
            badge.textContent = count + (count === 1 ? ' sale pending sync' : ' sales pending sync');
            badge.className = 'show';
        });
    }

    // Writes the record first (durable), then makes one immediate attempt.
    // Resolves {ok, immediate, message} — never rejects, so callers don't
    // need a .catch: a network failure just means immediate:false.
    function enqueue(type, formEl, extraFields) {
        var clientRef = genClientRef();
        var fields = formToObject(formEl, extraFields);
        fields.client_ref = clientRef;

        if (IS_LOCALHOST) {
            return fetch('sales.php', { method: 'POST', body: objectToFormData(fields) })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    return { ok: !!(res && res.success), immediate: true, message: (res && res.message) || 'Unexpected response.' };
                }, function() {
                    return { ok: false, immediate: true, message: 'Request failed.' };
                });
        }

        var record = {
            client_ref: clientRef,
            type: type,
            fields: fields,
            createdAt: Date.now(),
            attempts: 0,
            lastError: null,
            status: 'pending'
        };

        return putRecord(record).then(function() {
            refreshBadge();
            return postWithTimeout(fields).then(function(res) {
                if (res && res.success === true) {
                    return deleteRecord(clientRef).then(function() {
                        refreshBadge();
                        return { ok: true, immediate: true, message: res.message };
                    });
                }
                if (res && res.success === false) {
                    return deleteRecord(clientRef).then(function() {
                        refreshBadge();
                        return { ok: false, immediate: true, message: res.message };
                    });
                }
                // Unexpected shape — treat like a network failure, stay queued.
                return { ok: true, immediate: false, message: 'Saved offline — will sync automatically.' };
            }, function() {
                // Network error/timeout/non-JSON response — leave record queued.
                return { ok: true, immediate: false, message: 'Saved offline — will sync automatically.' };
            });
        });
    }

    function classifyAndHandle(record) {
        return postWithTimeout(record.fields).then(function(res) {
            if (res && res.success === true) {
                return deleteRecord(record.client_ref).then(function() {
                    if (!res.duplicate) {
                        showSaleToast('Sale for ' + (record.fields.customer_name || record.fields.ext_customer_name || 'client') + ' synced.', true);
                        var stores = INVALIDATE_STORES[record.type] || [];
                        if (window.DataCache) Promise.all(stores.map(function(s) { return DataCache.invalidate(s); }));
                    }
                });
            }
            if (res && res.success === false) {
                return deleteRecord(record.client_ref).then(function() {
                    showSaleToast('Queued sale failed: ' + res.message, false);
                });
            }
            // Non-JSON body (e.g. redirected to login.php) — session likely expired.
            record.status = 'auth_error';
            record.lastError = 'auth';
            return putRecord(record).then(function() {
                showSaleToast('Session expired — log in again to sync pending sale(s).', 'warning');
            });
        }, function() {
            record.attempts += 1;
            record.lastError = 'network';
            record.status = 'pending';
            return putRecord(record);
        });
    }

    function flush() {
        if (flushing) return Promise.resolve();
        flushing = true;
        var now = Date.now();
        return getAllRecords().then(function(records) {
            var due = records.filter(function(r) {
                if (r.status === 'pending') return true;
                if (r.status === 'auth_error') return (now - (r.lastAuthRetryAt || 0)) > AUTH_ERROR_RETRY_MS;
                return false;
            });
            return due.reduce(function(chain, record) {
                return chain.then(function() {
                    record.status = 'syncing';
                    record.lastAuthRetryAt = now;
                    return putRecord(record).then(function() { return classifyAndHandle(record); });
                });
            }, Promise.resolve());
        }).then(function() {
            flushing = false;
            refreshBadge();
        }, function() {
            flushing = false;
            refreshBadge();
        });
    }

    function getPendingCount() {
        return getAllRecords().then(function(records) { return records.length; });
    }

    function init() {
        if (IS_LOCALHOST) return;
        flush();
        window.addEventListener('online', function() { flush(); });
        setInterval(flush, FLUSH_INTERVAL_MS);
        refreshBadge();
    }

    return {
        enqueue: enqueue,
        flush: flush,
        getPendingCount: getPendingCount,
        init: init
    };
})();
