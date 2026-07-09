// Live status updates for the public order_track.php page. Long-polls
// order_status_poll.php (same pattern as js/order-notify.js uses for staff)
// so a customer who leaves the tracking page open sees a toast — and, if
// they granted permission, an OS notification — the moment staff change the
// order's status or delivery stage in orders.php. On change, reloads the
// page so the status badge/stepper/cancel-note re-render from real data.
window.OrderStatusWatch = (function() {
    function showToast(text) {
        var toast = document.createElement('div');
        toast.className = 'status-toast';
        toast.textContent = text;
        var hint = document.createElement('small');
        hint.textContent = '↻';
        toast.appendChild(hint);
        toast.addEventListener('click', function() { location.reload(); });
        document.body.appendChild(toast);
        setTimeout(function() { location.reload(); }, 4000);
    }

    function start(opts) {
        var since = opts.updatedAt;

        if (window.Notification && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        function poll() {
            var url = 'order_status_poll.php'
                + '?order_number=' + encodeURIComponent(opts.orderNumber)
                + '&phone=' + encodeURIComponent(opts.phone)
                + '&since=' + encodeURIComponent(since || '');

            fetch(url)
                .then(function(r) { return r.ok ? r.json() : null; })
                .then(function(res) {
                    if (!res || res.error) return; // order gone/unauthorized — stop polling silently
                    if (res.changed) {
                        var label = (opts.statusLabels && opts.statusLabels[res.status]) || res.status;
                        var text = opts.message.replace('%s', label);
                        showToast(text);
                        if (window.Notification && Notification.permission === 'granted') {
                            new Notification(text);
                        }
                        return; // stop polling; the toast reloads the page shortly
                    }
                    since = res.updated_at || since;
                    poll();
                })
                .catch(function() { setTimeout(poll, 5000); });
        }

        poll();
    }

    return { start: start };
})();
