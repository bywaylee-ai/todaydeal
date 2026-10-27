/* 툴바 채팅 아이콘 갱신 + 새 거래 대화·메시지 브라우저 알림 (로그인 사용자의 모든 화면).
 * 내 대화방 목록을 주기적으로 조회해(화면이 보이면 VISIBLE_MS, 백그라운드면 HIDDEN_MS)
 * 새로고침 없이 배지·링크·대화 목록을 다시 그리고, 상대방이 보낸 새 메시지를 알린다. */
(function () {
	if (!window.tdChatNotify) { return; }

	var VISIBLE_MS = 5000, HIDDEN_MS = 15000, KEY = 'td_chat_notified_id';
	var canNotify = 'Notification' in window;
	var memory = null, timer = null, signature = '';

	/* ---------- 툴바 ---------- */

	var li = null, closeTimer = null;

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function dateText(iso) {
		var d = new Date(iso);
		return isNaN(d) ? '' : d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
	}

	function isOpen() { return li.classList.contains('hover'); }
	function setOpen(open) {
		clearTimeout(closeTimer);
		li.classList.toggle('hover', open);
		var top = li.firstElementChild;
		if (top && top.hasAttribute('aria-expanded')) { top.setAttribute('aria-expanded', open ? 'true' : 'false'); }
	}

	// 상대방이 보낸 가장 최근 메시지와 그 방의 안 읽은 수.
	function roomItem(r) {
		var m = r.last_received;
		var a = el('a', 'ab-item');
		a.href = r.listing_url;
		var wrap = el('span', 'td-ab-room');
		wrap.appendChild(el('span', 'td-ab-avatar', (r.other_user.nickname || '?').charAt(0).toUpperCase())).setAttribute('aria-hidden', 'true');
		var body = el('span', 'td-ab-body');
		var row = el('span', 'td-ab-title-row');
		row.appendChild(el('span', 'td-ab-title', r.listing_title));
		if (r.unread_count) {
			row.appendChild(el('span', 'td-ab-count', String(r.unread_count))).setAttribute('aria-hidden', 'true');
			row.appendChild(el('span', 'screen-reader-text', ', 안 읽은 메시지 ' + r.unread_count + '개'));
		}
		body.appendChild(row);
		// 받은 메시지가 없는데 안 읽은 수가 있으면 "대화 시작" 안내뿐인 새 대화다.
		body.appendChild(el('span', 'td-ab-msg', m ? m.content : (r.unread_count ? '새 거래 대화가 시작되었습니다.' : '받은 메시지가 없습니다.')));
		var meta = el('span', 'td-ab-meta', r.other_user.nickname + ' (' + r.other_user.label + ') · ');
		meta.appendChild(el('time', '', m ? dateText(m.created_at) : ''));
		body.appendChild(meta);
		wrap.appendChild(body);
		a.appendChild(wrap);
		return a;
	}

	// 배지: 안 읽은 메시지 합계(1 이상일 때). 클릭: 대화 0개 안내 목록 / 1개 그 대화방 링크 / 2개 이상 대화 목록.
	function renderToolbar(rooms) {
		if (!li) { return; }
		var sig = JSON.stringify(rooms.map(function (r) { return [r.room_id, r.last_message && r.last_message.message_id, r.unread_count, r.listing_title]; }));
		if (sig === signature) { return; }
		signature = sig;

		var count = rooms.length, wasOpen = isOpen(), hadFocus = li.contains(document.activeElement);
		var unread = rooms.reduce(function (sum, r) { return sum + (r.unread_count || 0); }, 0);
		var top = el('a', 'ab-item');
		top.title = '거래 대화 · Trò chuyện';
		top.appendChild(el('span', 'ab-icon')).setAttribute('aria-hidden', 'true');
		top.appendChild(el('span', 'screen-reader-text td-ab-sr', '거래 대화 ' + count + '개, 안 읽은 메시지 ' + unread + '개'));
		if (unread > 0) { top.appendChild(el('span', 'td-ab-count', String(unread))).setAttribute('aria-hidden', 'true'); }

		li.textContent = '';
		li.appendChild(top);
		if (count === 1) {
			top.href = rooms[0].listing_url;
			li.classList.remove('menupop', 'hover');
		} else {
			top.href = '#';
			top.setAttribute('role', 'button');
			top.setAttribute('aria-haspopup', 'true');
			top.setAttribute('aria-expanded', 'false');
			var sub = el('div', 'ab-sub-wrapper'), ul = el('ul', 'ab-submenu');
			ul.id = 'wp-admin-bar-td-chat-default';
			if (!count) {
				var empty = el('li'); empty.appendChild(el('div', 'ab-item ab-empty-item', '아직 대화가 없습니다. · Chưa có cuộc trò chuyện.'));
				ul.appendChild(empty);
			}
			rooms.forEach(function (r) {
				var item = el('li'); item.id = 'wp-admin-bar-td-chat-room-' + r.room_id;
				item.appendChild(roomItem(r));
				ul.appendChild(item);
			});
			sub.appendChild(ul);
			li.appendChild(sub);
			li.classList.add('menupop');
			setOpen(wasOpen);
		}
		if (hadFocus) { top.focus(); }
	}

	function bindToolbar() {
		li = document.getElementById('wp-admin-bar-td-chat');
		if (!li) { return; }
		// 코어 admin-bar.js 초기화 이후에 그려야 코어 처리와 겹치지 않는다.
		renderToolbar(tdChatNotify.rooms || []);

		// 마우스만 hover로 연다(터치는 pointerenter 직후 click이 와서 바로 닫혀 버린다).
		li.addEventListener('pointerenter', function (e) { if (e.pointerType === 'mouse' && li.classList.contains('menupop')) { setOpen(true); } });
		li.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') { closeTimer = setTimeout(function () { setOpen(false); }, 200); } });
		li.addEventListener('click', function (e) {
			var top = li.firstElementChild;
			if (li.classList.contains('menupop') && top && top.contains(e.target)) { e.preventDefault(); setOpen(!isOpen()); }
		});
		li.addEventListener('keydown', function (e) {
			var top = li.firstElementChild;
			if (e.key === 'Escape' && isOpen()) { setOpen(false); top.focus(); }
			else if (e.key === ' ' && e.target === top && li.classList.contains('menupop')) { e.preventDefault(); setOpen(!isOpen()); }
		});
		document.addEventListener('click', function (e) { if (isOpen() && !li.contains(e.target)) { setOpen(false); } });
		li.addEventListener('focusout', function (e) { if (!li.contains(e.relatedTarget)) { setOpen(false); } });
	}

	/* ---------- 알림 ---------- */

	function getSeen() {
		try { var v = localStorage.getItem(KEY); return v === null ? memory : Number(v); } catch (e) { return memory; }
	}
	function setSeen(id) {
		memory = id;
		try { localStorage.setItem(KEY, String(id)); } catch (e) {}
	}

	// 권한 요청은 사용자 동작(채팅 아이콘·거래하기 클릭)에서만 한다.
	document.addEventListener('click', function (e) {
		if (canNotify && Notification.permission === 'default' && e.target.closest && e.target.closest('#wp-admin-bar-td-chat, .td-deal-btn')) {
			Notification.requestPermission();
		}
	}, true);

	function notify(rooms) {
		var seen = getSeen(), max = seen || 0;
		rooms.forEach(function (r) {
			var m = r.last_message;
			if (!m) { return; }
			max = Math.max(max, m.message_id);
			// 첫 조회는 기준점만 잡는다(기존 메시지로 알림 폭주 방지).
			if (seen === null || m.message_id <= seen || m.is_mine || !r.listing_url) { return; }
			// "대화 시작" 시스템 메시지는 거래하기를 누른 쪽이 아닌 작성자에게만 알린다.
			if (m.type === 'system' && r.my_role !== 'owner') { return; }
			// 지금 보고 있는 대화방이면 알리지 않는다.
			if (document.visibilityState === 'visible' && window.tdChatOpenRoom === r.room_id) { return; }
			if (!canNotify || Notification.permission !== 'granted') { return; }
			var n = new Notification(r.listing_title, {
				body: r.other_user.nickname + ' (' + r.other_user.label + '): ' + (m.type === 'system' ? '거래 대화를 시작했습니다.' : m.content),
				tag: 'td-room-' + r.room_id
			});
			n.onclick = function () { window.focus(); location.href = r.listing_url; n.close(); };
		});
		setSeen(max);
	}

	/* ---------- 조회 ---------- */

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(poll, document.visibilityState === 'visible' ? VISIBLE_MS : HIDDEN_MS);
	}

	function poll() {
		fetch(tdChatNotify.root + 'chat-rooms', { credentials: 'same-origin', headers: { 'X-WP-Nonce': tdChatNotify.nonce } })
			.then(function (res) { return res.ok ? res.json() : null; })
			.then(function (json) {
				if (!json) { return; }
				var rooms = json.data.items.filter(function (r) { return r.listing_url; });
				renderToolbar(rooms);
				notify(rooms);
			})
			.catch(function () {})
			.then(schedule);
	}

	// 다른 탭에서 돌아오면 바로 갱신한다.
	document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') { poll(); } });

	function start() {
		setTimeout(function () { bindToolbar(); poll(); }, 0);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
