// Live "new order" popups + topnav bell (badge + dropdown list) for staff.
// Long-polls notifications_poll.php (no websockets on shared hosting) — each
// response either carries this user's waiting notifications or arrives empty
// after ~25s, and either way we immediately poll again. A notification stays
// in the database (and counted in the badge / shown in the bell's dropdown)
// until the user explicitly marks it read — dismissing it from the popup
// card, the dropdown list, or following either one's "View Orders" link — at
// which point it's deleted server-side. Self-contained: injects its own
// styles/container so it works on any authenticated page that includes it
// via sidebar.php, regardless of that page's own CSS.
(function() {
    var style = document.createElement('style');
    style.textContent = [
        '#onStack{position:fixed;bottom:20px;right:20px;z-index:99999;display:flex;flex-direction:column;gap:10px;max-width:320px;}',
        '.onCard{background:#0f172a;color:#fff;border-radius:10px;padding:14px 16px;box-shadow:0 8px 24px rgba(0,0,0,.25);font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:13px;line-height:1.5;animation:onIn .2s ease-out;}',
        '@keyframes onIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}',
        '.onCard a{display:inline-block;margin-top:8px;color:#93c5fd;font-weight:700;text-decoration:none;font-size:12px;}',
        '.onCard a:hover{text-decoration:underline;}',
        '.onCard .onClose{float:right;cursor:pointer;color:#94a3b8;margin-left:10px;}',
    ].join('\n');
    document.head.appendChild(style);

    var stack = document.createElement('div');
    stack.id = 'onStack';
    document.body.appendChild(stack);

    var badgeCount = 0;
    function renderBadge() {
        var el = document.getElementById('tnNotifBadge');
        if (!el) return;
        el.textContent = badgeCount > 99 ? '99+' : badgeCount;
        el.style.display = badgeCount > 0 ? '' : 'none';
    }
    function bumpBadge(delta) {
        badgeCount = Math.max(0, badgeCount + delta);
        renderBadge();
    }

    function markRead(id) {
        bumpBadge(-1);
        var fd = new FormData();
        fd.append('mark_read', '1');
        fd.append('id', id);
        fetch('notifications_poll.php', {method: 'POST', body: fd});
    }

    function escH(s) { return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ── Bell dropdown: click to fetch and show the full notification list ──────
    var bell  = document.getElementById('tnNotifBell');
    var panel = document.getElementById('tnNotifPanel');
    var panelList = document.getElementById('tnNotifList');

    function renderList(rows) {
        if (!rows.length) { panelList.innerHTML = '<div class="tn-notif-empty">No notifications</div>'; return; }
        panelList.innerHTML = rows.map(function(n) {
            return '<div class="tn-notif-row" id="tnNotifRow' + n.id + '">'
                + '<div>' + escH(n.message || ('New order ' + (n.order_number || ''))) + '</div>'
                + '<div class="tn-notif-row-actions">'
                +   '<a href="orders.php" onclick="window.__onMarkRead(' + n.id + ')">View Orders</a>'
                +   '<span onclick="window.__onDismiss(' + n.id + ', this)">Dismiss</span>'
                + '</div></div>';
        }).join('');
    }

    window.__onMarkRead = markRead;
    window.__onDismiss = function(id, el) {
        markRead(id);
        var row = document.getElementById('tnNotifRow' + id);
        if (row) row.remove();
        if (!panelList.querySelector('.tn-notif-row')) panelList.innerHTML = '<div class="tn-notif-empty">No notifications</div>';
    };

    if (bell && panel) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            var opening = !panel.classList.contains('open');
            panel.classList.toggle('open', opening);
            if (opening) {
                panelList.innerHTML = '<div class="tn-notif-empty">Loading…</div>';
                fetch('notifications_poll.php?action=list')
                    .then(function(r) { return r.ok ? r.json() : []; })
                    .then(function(rows) { renderList(rows || []); })
                    .catch(function() { panelList.innerHTML = '<div class="tn-notif-empty">Failed to load.</div>'; });
            }
        });
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#tnNotifWrap')) panel.classList.remove('open');
        });
    }

    function showNotification(n) {
        var card = document.createElement('div');
        card.className = 'onCard';
        var closeBtn = document.createElement('span');
        closeBtn.className = 'onClose';
        closeBtn.title = 'Mark as read';
        closeBtn.textContent = '×';
        closeBtn.onclick = function() { markRead(n.id); card.remove(); };
        card.appendChild(closeBtn);
        card.appendChild(document.createTextNode(n.message || ('New order ' + (n.order_number || ''))));
        var link = document.createElement('a');
        link.href = 'orders.php';
        link.textContent = 'View Orders →';
        link.onclick = function() { markRead(n.id); };
        card.appendChild(document.createElement('br'));
        card.appendChild(link);
        stack.appendChild(card);
    }

    function poll() {
        fetch('notifications_poll.php')
            .then(function(r) { return r.ok ? r.json() : []; })
            .then(function(list) {
                list = list || [];
                if (list.length) bumpBadge(list.length);
                list.forEach(showNotification);
                poll();
            })
            .catch(function() { setTimeout(poll, 3000); });
    }

    // Seed the badge with whatever's already unread (including notifications
    // delivered on an earlier visit that were never marked read) before the
    // long-poll starts picking up brand-new ones.
    fetch('notifications_poll.php?action=count')
        .then(function(r) { return r.ok ? r.json() : {count: 0}; })
        .then(function(res) { badgeCount = res.count || 0; renderBadge(); })
        .catch(function() {});

    poll();
})();
