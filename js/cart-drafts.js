// "Save Draft" storage for in-progress carts on sale_bulk/sale_retail/sale_external.
// IndexedDB is the primary store — saving/loading/deleting a draft never waits on
// the network. draft_api.php (table `cart_drafts`) is a backup sync so a draft
// started on one device/browser still shows up after a switch: init() pulls the
// server's list once per page load and merges it in, and every local save/delete
// is pushed to the server immediately with a background retry (mirrors
// js/sale-queue.js's outbox pattern) if that immediate push fails.
var CartDrafts = (function() {
    var DB_NAME = 'screen_stock_drafts';
    var DB_VERSION = 1;
    var STORE = 'drafts';
    var FLUSH_INTERVAL_MS = 20 * 1000;

    var companyKey = (typeof window.APP_COMPANY_ID !== 'undefined' && window.APP_COMPANY_ID !== null)
        ? String(window.APP_COMPANY_ID) : 'all';

    var dbPromise = null;
    var memFallback = {}; // draft_ref -> record, used when indexedDB is unavailable
    var pulled = {};      // sale_type -> true once server list has been merged in this page load
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
                        db.createObjectStore(STORE, { keyPath: 'draft_ref' });
                    }
                };
                req.onsuccess = function() { resolve(req.result); };
                req.onerror = function() { resolve(null); };
            } catch (e) { resolve(null); }
        });
        return dbPromise;
    }

    function putRecord(record) {
        memFallback[record.draft_ref] = record;
        return openDb().then(function(db) {
            if (!db) return;
            try {
                db.transaction(STORE, 'readwrite').objectStore(STORE).put(record);
            } catch (e) { /* ignore, memory fallback already set */ }
        });
    }

    function deleteRecord(draftRef) {
        delete memFallback[draftRef];
        return openDb().then(function(db) {
            if (!db) return;
            try {
                db.transaction(STORE, 'readwrite').objectStore(STORE).delete(draftRef);
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

    function genDraftRef() {
        return 'df_' + companyKey + '_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    function postForm(fields) {
        var fd = new FormData();
        for (var k in fields) if (fields.hasOwnProperty(k)) fd.append(k, fields[k]);
        return fetch('draft_api.php', { method: 'POST', body: fd }).then(function(r) { return r.json(); });
    }

    // Pulls this company's server-side drafts for one sale type and merges any
    // the browser doesn't have locally yet (a draft saved on another device).
    // Local records always win on conflict — they may hold newer edits than the
    // last successful sync. Runs at most once per sale_type per page load.
    function pullFromServer(saleType) {
        if (pulled[saleType]) return Promise.resolve();
        pulled[saleType] = true;
        return fetch('draft_api.php?action=list&type=' + encodeURIComponent(saleType))
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (!res || !res.success) return;
                return getAllRecords().then(function(existing) {
                    var known = {};
                    existing.forEach(function(r) { known[r.draft_ref] = true; });
                    var toAdd = (res.data || []).filter(function(r) { return !known[r.draft_ref]; });
                    return Promise.all(toAdd.map(function(r) {
                        var snapshot;
                        try { snapshot = JSON.parse(r.draft_json); } catch (e) { return Promise.resolve(); }
                        return putRecord({
                            draft_ref: r.draft_ref,
                            sale_type: r.sale_type,
                            customer_name: r.customer_name || '',
                            items_count: r.items_count,
                            total_amount: r.total_amount,
                            snapshot: snapshot,
                            updatedAt: new Date(r.updated_at.replace(' ', 'T')).getTime() || Date.now(),
                            syncStatus: 'synced'
                        });
                    }));
                });
            })
            .catch(function() { /* offline — local drafts still usable */ });
    }

    // Saves (or updates, if draftRef is passed back from a prior save/load) a
    // draft. Resolves with the draft_ref — never rejects, since the local write
    // always succeeds; a failed server push just gets retried by flush().
    function save(saleType, snapshot, draftRef) {
        var ref = draftRef || genDraftRef();
        var record = {
            draft_ref: ref,
            sale_type: saleType,
            customer_name: snapshot.customerName || '',
            items_count: snapshot.itemsCount,
            total_amount: snapshot.totalAmount,
            snapshot: snapshot,
            updatedAt: Date.now(),
            syncStatus: 'pending_save'
        };
        return putRecord(record).then(function() {
            return postForm({
                action: 'save',
                draft_ref: ref,
                sale_type: saleType,
                customer_name: record.customer_name,
                items_count: record.items_count,
                total_amount: record.total_amount,
                draft_json: JSON.stringify(snapshot)
            }).then(function(res) {
                if (res && res.success) {
                    record.syncStatus = 'synced';
                    return putRecord(record).then(function() { return ref; });
                }
                return ref;
            }, function() { return ref; });
        });
    }

    // Removes a draft (used once its sale is actually submitted, or on manual
    // delete). Local delete is immediate; the server delete is best-effort with
    // background retry via flush() if offline.
    function remove(draftRef) {
        return getAllRecords().then(function(records) {
            var existing = records.filter(function(r) { return r.draft_ref === draftRef; })[0];
            return deleteRecord(draftRef).then(function() {
                return postForm({ action: 'delete', draft_ref: draftRef }).catch(function() {
                    // Offline — leave a tombstone so flush() retries the server delete.
                    return putRecord({ draft_ref: draftRef, tombstone: true, sale_type: existing ? existing.sale_type : '', syncStatus: 'pending_delete', updatedAt: Date.now() });
                });
            });
        });
    }

    // Returns this sale type's drafts, most-recently-updated first. Pulls the
    // server's list in first (merged, doesn't block on network — falls back to
    // whatever is already local).
    function list(saleType) {
        return pullFromServer(saleType).then(function() {
            return getAllRecords();
        }).then(function(records) {
            return records
                .filter(function(r) { return r.sale_type === saleType && !r.tombstone; })
                .sort(function(a, b) { return b.updatedAt - a.updatedAt; });
        });
    }

    function flush() {
        if (flushing) return Promise.resolve();
        flushing = true;
        return getAllRecords().then(function(records) {
            var due = records.filter(function(r) { return r.syncStatus === 'pending_save' || r.syncStatus === 'pending_delete'; });
            return due.reduce(function(chain, record) {
                return chain.then(function() {
                    if (record.syncStatus === 'pending_delete') {
                        return postForm({ action: 'delete', draft_ref: record.draft_ref }).then(function() {
                            return deleteRecord(record.draft_ref);
                        }, function() { /* still offline, retry next flush */ });
                    }
                    return postForm({
                        action: 'save',
                        draft_ref: record.draft_ref,
                        sale_type: record.sale_type,
                        customer_name: record.customer_name,
                        items_count: record.items_count,
                        total_amount: record.total_amount,
                        draft_json: JSON.stringify(record.snapshot)
                    }).then(function(res) {
                        if (res && res.success) {
                            record.syncStatus = 'synced';
                            return putRecord(record);
                        }
                    }, function() { /* still offline, retry next flush */ });
                });
            }, Promise.resolve());
        }).then(function() { flushing = false; }, function() { flushing = false; });
    }

    function init() {
        flush();
        window.addEventListener('online', function() { flush(); });
        setInterval(flush, FLUSH_INTERVAL_MS);
    }

    return {
        init: init,
        save: save,
        remove: remove,
        list: list
    };
})();
