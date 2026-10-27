/* 거래 대화 슬라이드 패널 (single-todaydeal-deal.php).
 * WebSocket 대신 패널이 열려 있는 동안 POLL_MS 간격으로 새 메시지를 조회한다. */
(function () {
	var panel = document.getElementById('td-chat');
	var openBtn = document.getElementById('td-deal-btn');
	if (!panel || !openBtn || !window.tdChat) { return; }

	var POLL_MS = 3000;
	var $ = function (id) { return document.getElementById(id); };
	var backdrop = $('td-chat-backdrop'), backBtn = $('td-chat-back'), closeBtn = $('td-chat-close');
	var sub = $('td-chat-sub'), roomsEl = $('td-chat-rooms'), log = $('td-chat-log'), statusEl = $('td-chat-status');
	var form = $('td-chat-form'), input = $('td-chat-input'), sendBtn = form.querySelector('button');
	var listingId = Number(panel.dataset.listing), isOwner = panel.dataset.mode === 'owner';
	var defaultSub = sub.textContent, room = null, lastId = 0, seen = {}, timer = null, sending = false;

	function api(path, method, body) {
		return fetch(tdChat.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': tdChat.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify(body) : undefined
		}).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok) { throw new Error((json.error && json.error.message) || json.message || '요청에 실패했습니다.'); }
				return json.data;
			});
		});
	}

	function setStatus(text) { statusEl.textContent = text || ''; }

	function timeText(iso) {
		var d = new Date(iso);
		return isNaN(d) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
	}

	function el(tag, cls, text) {
		var node = document.createElement(tag);
		if (cls) { node.className = cls; }
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function appendMessages(items) {
		var added = false;
		items.forEach(function (m) {
			if (seen[m.message_id]) { return; }
			seen[m.message_id] = true;
			lastId = Math.max(lastId, m.message_id);
			added = true;
			var li = el('li', 'td-msg ' + (m.type === 'system' ? 'is-system' : (m.is_mine ? 'is-mine' : 'is-other')));
			if (m.type !== 'system') {
				li.appendChild(el('span', 'td-sr-only', m.is_mine ? '나: ' : room.other_user.nickname + ': '));
			}
			li.appendChild(el('span', 'td-msg-text', m.content));
			if (m.type !== 'system') { li.appendChild(el('time', 'td-msg-time', timeText(m.created_at))); }
			log.appendChild(li);
		});
		if (added) {
			log.scrollTop = log.scrollHeight;
			api('chat-rooms/' + room.room_id + '/read', 'POST', { message_id: lastId }).catch(function () {});
		}
	}

	function poll() {
		if (!room) { return; }
		api('chat-rooms/' + room.room_id + '/messages?after=' + lastId).then(function (d) { appendMessages(d.items); }).catch(function () {});
	}

	function stopPolling() { clearInterval(timer); timer = null; }

	function enterRoom(r) {
		stopPolling();
		room = r; lastId = 0; seen = {};
		window.tdChatOpenRoom = r.room_id; // chat-notify.js: 보고 있는 방은 알리지 않는다.
		log.textContent = '';
		roomsEl.hidden = true; log.hidden = false;
		backBtn.hidden = !isOwner;
		sub.textContent = r.other_user.nickname + ' (' + r.other_user.label + ') · ' + r.listing_title;
		form.hidden = false;
		input.disabled = sendBtn.disabled = r.is_closed;
		setStatus(r.is_closed ? '거래가 종료되어 메시지를 보낼 수 없습니다. · Giao dịch đã kết thúc.' : '');
		api('chat-rooms/' + r.room_id + '/messages').then(function (d) {
			appendMessages(d.items);
			if (!r.is_closed) { input.focus(); }
			timer = setInterval(poll, POLL_MS);
		}).catch(function (e) { setStatus(e.message); });
	}

	function showRooms() {
		stopPolling();
		room = null; window.tdChatOpenRoom = null;
		sub.textContent = defaultSub;
		backBtn.hidden = true; form.hidden = true; log.hidden = true;
		roomsEl.hidden = false; roomsEl.textContent = '';
		setStatus('불러오는 중… · Đang tải…');
		api('chat-rooms?listing_id=' + listingId).then(function (d) {
			setStatus(d.items.length ? '' : '아직 대화가 없습니다. · Chưa có cuộc trò chuyện.');
			d.items.forEach(function (r) {
				var b = el('button', 'td-room');
				b.type = 'button';
				var head = el('span', 'td-room-head');
				head.appendChild(el('strong', '', r.other_user.nickname + ' (' + r.other_user.label + ')'));
				if (r.unread_count) { head.appendChild(el('span', 'td-room-badge', r.unread_count + '개 안 읽음')); }
				b.appendChild(head);
				b.appendChild(el('span', 'td-room-last', r.last_message ? r.last_message.content : ''));
				b.addEventListener('click', function () { enterRoom(r); });
				var li = el('li'); li.appendChild(b); roomsEl.appendChild(li);
			});
			var first = roomsEl.querySelector('button');
			(first || closeBtn).focus();
		}).catch(function (e) { setStatus(e.message); });
	}

	// roomId: 툴바 대화 목록·알림에서 온 경우(?td_room=) 그 방을 바로 연다.
	function open(roomId) {
		panel.classList.add('is-open'); backdrop.classList.add('is-open');
		document.documentElement.classList.add('td-chat-lock');
		closeBtn.focus();
		if (isOwner) {
			if (roomId) { api('chat-rooms/' + roomId).then(enterRoom).catch(function () { showRooms(); }); } else { showRooms(); }
			return;
		}
		setStatus('대화방을 여는 중… · Đang mở…');
		api('chat-rooms', 'POST', { listing_id: listingId }).then(enterRoom).catch(function (e) { setStatus(e.message); });
	}

	function close() {
		stopPolling();
		window.tdChatOpenRoom = null;
		panel.classList.remove('is-open'); backdrop.classList.remove('is-open');
		document.documentElement.classList.remove('td-chat-lock');
		openBtn.focus();
	}

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		var text = input.value.trim();
		if (!text || !room || sending) { return; }
		sending = true; sendBtn.disabled = true;
		var clientId = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
		api('chat-rooms/' + room.room_id + '/messages', 'POST', { content: text, client_message_id: clientId }).then(function (m) {
			input.value = '';
			setStatus('');
			appendMessages([m]);
		}).catch(function (err) { setStatus(err.message); }).then(function () {
			sending = false; sendBtn.disabled = false; input.focus();
		});
	});

	// Enter 전송, Shift+Enter 줄바꿈 (한글 조합 중에는 전송하지 않음).
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
	});

	panel.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { close(); return; }
		if (e.key !== 'Tab') { return; }
		var f = Array.prototype.filter.call(panel.querySelectorAll('button, textarea'), function (n) { return !n.disabled && n.offsetParent !== null; });
		if (!f.length) { return; }
		if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
		else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
	});

	openBtn.addEventListener('click', function () { open(); });
	closeBtn.addEventListener('click', close);
	backdrop.addEventListener('click', close);
	backBtn.addEventListener('click', showRooms);

	// 로그인 후 복귀·툴바 대화 목록·알림(?td_deal={ID}[&td_room={방}]): 파라미터를 지우고 대화 패널을 바로 연다.
	var params = new URLSearchParams(location.search);
	if (params.get('td_deal') === String(listingId)) {
		var roomId = Number(params.get('td_room')) || 0;
		params.delete('td_deal'); params.delete('td_room');
		history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params : '') + location.hash);
		open(roomId);
	}
})();
