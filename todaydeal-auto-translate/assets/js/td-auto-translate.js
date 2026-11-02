/* TodayDeal 자동번역 화면 연동.
 * todaydeal 의 chat.js(거래 패널)가 그린 상대방 메시지에 백엔드가 내려준 translated_content 를 표시한다.
 * - REST messages 응답을 가로채 message_id → {content, translated} 사전을 만든다.
 * - 주기 스캔으로 '.td-msg.is-other' (상대방이 보낸 메시지) 만 번역으로 교체한다 (내 메시지는 원문 유지).
 * - 번역 텍스트를 클릭하면 원문과 오가며 볼 수 있다.
 * (function(){ ... })()
 */
(function () {
	if (!window.fetch || !document.getElementById('td-chat')) { return; }

	var MSGS = window.__tdtMsgs = window.__tdtMsgs || {}; // message_id -> {content, translated}

	/* 1) todaydeal 의 fetch 응답을 가로채 채팅 메시지의 번역을 수집한다. */
	var origFetch = window.fetch.bind(window);
	window.fetch = function (input, init) {
		var url = typeof input === 'string' ? input : (input && input.url) || '';
		var promise = origFetch(input, init);
		if (url.indexOf('/chat-rooms/') !== -1) {
			promise.then(function (res) {
				try { var clone = res.clone(); clone.json().then(function (json) {
					var data = json && json.data;
					if (data && data.items && data.items.length) {
						data.items.forEach(function (m) { if (m && m.message_id) { MSGS[m.message_id] = { content: m.content, translated: m.translated_content }; } });
					} else if (data && data.message_id) {
						MSGS[data.message_id] = { content: data.content, translated: data.translated_content };
					}
				}).catch(function () {}); } catch (e) {}
			});
		}
		return promise;
	};

	/* 2) 주기 스캔: 상대방 메시지(원문)에 대응하는 번역이 수집되면 표시한다. */
	function patchOne(li) {
		var textEl = li.querySelector('.td-msg-text');
		if (!textEl) { return; }
		var original = textEl.getAttribute('data-original') || textEl.textContent;
		var found = null;
		for (var id in MSGS) {
			var m = MSGS[id];
			if (m && m.content === original && m.translated) { found = m.translated; }
		}
		if (!found) { return; }
		li.classList.add('tdt-done');
		textEl.setAttribute('data-original', original);
		textEl.textContent = found;
		textEl.classList.add('tdt-translated');
		textEl.title = '원문 보기 / Ẩn bản gốc'; /* 번역 표시 중 — 클릭하면 원문 */
		textEl.onclick = function () {
			var cur = textEl.textContent;
			textEl.textContent = (cur === original) ? found : original;
			textEl.title = (textEl.textContent === original) ? '번역 보기 / Xem bản dịch' : '원문 보기 / Ẩn bản gốc';
		};
	}
	function scan() {
		var list = document.querySelectorAll('#td-chat-log li.td-msg.is-other:not(.tdt-done)');
		for (var i = 0; i < list.length; i++) { patchOne(list[i]); }
	}
	scan();
	setInterval(scan, 600);
})();
