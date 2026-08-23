(function () {
	'use strict';

	const cfg = window.gamingHubPgoRaids || {};
	const root = document.querySelector('[data-pgo-raid-board]');
	if (!root || !cfg.rest) {
		return;
	}

	const t = cfg.i18n || {};
	const listEl = root.querySelector('[data-pgo-raid-list]');
	const emptyEl = root.querySelector('[data-pgo-raid-empty]');
	const form = root.querySelector('[data-pgo-raid-form]');
	const bossBox = root.querySelector('[data-pgo-raid-bosses]');
	const customBoss = root.querySelector('[data-pgo-raid-custom-boss]');
	const slotsInput = root.querySelector('[name="slots"]');
	const modal = root.querySelector('[data-pgo-raid-modal]');
	const joinForm = root.querySelector('[data-pgo-raid-join-form]');
	const joinMsg = root.querySelector('[data-pgo-raid-join-msg]');
	const hostBox = root.querySelector('[data-pgo-raid-host]');
	const profileName = root.querySelectorAll('[data-pgo-profile-name]');
	const profileCode = root.querySelectorAll('[data-pgo-profile-code]');

	let raids = [];
	let selectedBoss = '';
	let joinTarget = '';
	let tickTimer = null;

	function store() {
		try {
			return JSON.parse(localStorage.getItem('pgoRaidProfile') || '{}');
		} catch (err) {
			return {};
		}
	}

	function saveStore(next) {
		localStorage.setItem('pgoRaidProfile', JSON.stringify(next));
	}

	function hostTokens() {
		const data = store();
		return data.hosts && typeof data.hosts === 'object' ? data.hosts : {};
	}

	function rememberHost(id, token) {
		const data = store();
		data.hosts = data.hosts || {};
		data.hosts[id] = token;
		saveStore(data);
	}

	function fillProfile() {
		const data = store();
		profileName.forEach(function (el) {
			if (!el.value && data.trainer_name) {
				el.value = data.trainer_name;
			}
		});
		profileCode.forEach(function (el) {
			if (!el.value && data.friend_code) {
				el.value = data.friend_code;
			}
		});
	}

	function persistProfile(name, code) {
		const data = store();
		data.trainer_name = name;
		data.friend_code = code;
		saveStore(data);
	}

	function remainLabel(expiresTs) {
		const left = Math.max(0, expiresTs - Math.floor(Date.now() / 1000));
		const m = Math.floor(left / 60);
		const s = left % 60;
		return t.left.replace('%s', m + ':' + String(s).padStart(2, '0'));
	}

	function statusLabel(status) {
		if (status === 'full') {
			return t.full;
		}
		if (status === 'started') {
			return t.started;
		}
		if (status === 'closed') {
			return t.closed;
		}
		return t.open;
	}

	function copyText(value) {
		if (!value) {
			return Promise.resolve();
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(value);
		}
		const box = document.createElement('textarea');
		box.value = value;
		document.body.appendChild(box);
		box.select();
		document.execCommand('copy');
		box.remove();
		return Promise.resolve();
	}

	function flash(btn, label) {
		if (!btn) {
			return;
		}
		const prev = btn.textContent;
		btn.textContent = label;
		setTimeout(function () {
			btn.textContent = prev;
		}, 1200);
	}

	function cardHtml(raid) {
		const isHost = !!hostTokens()[raid.id];
		const canJoin = raid.status === 'open' && raid.left > 0 && !isHost;
		const art = raid.art
			? '<img src="' + raid.art + '" alt="" width="88" height="88" loading="lazy">'
			: '<span class="pgo-raid-art-fallback">★</span>';
		const joiners = (raid.joiners || []).map(function (j) {
			return '<li>' + escapeHtml(j.trainer_name) + (isHost && j.friend_code ? ' · ' + escapeHtml(j.friend_code) : '') + '</li>';
		}).join('');

		return (
			'<article class="pgo-raid-card is-' + raid.status + (isHost ? ' is-host' : '') + '" data-raid-id="' + raid.id + '">' +
				'<div class="pgo-raid-art">' + art + '</div>' +
				'<div class="pgo-raid-body">' +
					'<div class="pgo-raid-meta">' +
						'<span class="pgo-badge pgo-status-' + (raid.status === 'open' ? 'live' : 'soon') + '">' + escapeHtml(statusLabel(raid.status)) + '</span>' +
						'<span class="pgo-raid-type">' + escapeHtml(raid.type_label) + '</span>' +
						'<span class="pgo-raid-clock" data-expires="' + raid.expires_ts + '">' + escapeHtml(remainLabel(raid.expires_ts)) + '</span>' +
					'</div>' +
					'<h3>' + escapeHtml(raid.boss_name) + '</h3>' +
					'<p class="pgo-raid-host">' + escapeHtml(raid.trainer_name) + ' · <button type="button" class="pgo-raid-copy" data-copy="' + escapeHtml(raid.friend_code) + '">' + escapeHtml(raid.friend_code) + '</button></p>' +
					'<p class="pgo-raid-seats">' + escapeHtml(t.seats.replace('%1$s', raid.taken).replace('%2$s', raid.slots)) + '</p>' +
					(raid.note ? '<p class="pgo-raid-note">' + escapeHtml(raid.note) + '</p>' : '') +
					(joiners ? '<ul class="pgo-raid-joiners">' + joiners + '</ul>' : '') +
					'<div class="pgo-raid-actions">' +
						(canJoin ? '<button type="button" class="btn btn-primary" data-join="' + raid.id + '">' + escapeHtml(t.join) + '</button>' : '') +
						(isHost ? '<button type="button" class="btn btn-outline" data-copy-names="' + raid.id + '">' + escapeHtml(t.copyNames) + '</button>' : '') +
						(isHost ? '<button type="button" class="btn btn-outline" data-copy-codes="' + raid.id + '">' + escapeHtml(t.copyCodes) + '</button>' : '') +
						(isHost && raid.status !== 'closed' ? '<button type="button" class="btn btn-outline" data-host-action="start" data-host-id="' + raid.id + '">' + escapeHtml(t.start) + '</button>' : '') +
						(isHost && raid.status !== 'closed' ? '<button type="button" class="btn btn-outline" data-host-action="close" data-host-id="' + raid.id + '">' + escapeHtml(t.close) + '</button>' : '') +
					'</div>' +
				'</div>' +
			'</article>'
		);
	}

	function escapeHtml(value) {
		return String(value == null ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function render() {
		if (!listEl) {
			return;
		}
		const open = raids.filter(function (raid) {
			return raid.status !== 'closed' && raid.expires_ts > Math.floor(Date.now() / 1000);
		});
		if (!open.length) {
			listEl.innerHTML = '';
			if (emptyEl) {
				emptyEl.hidden = false;
			}
			return;
		}
		if (emptyEl) {
			emptyEl.hidden = true;
		}
		listEl.innerHTML = open.map(cardHtml).join('');
	}

	function tickClocks() {
		root.querySelectorAll('[data-expires]').forEach(function (el) {
			el.textContent = remainLabel(Number(el.getAttribute('data-expires')) || 0);
		});
	}

	function api(path, body) {
		const options = {
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.wpNonce || '',
			},
		};
		if (body) {
			options.method = 'POST';
			options.body = JSON.stringify(body);
		}
		return fetch(cfg.rest + (path || ''), options).then(function (res) {
			return res.json().then(function (json) {
				if (!res.ok || !json.success) {
					throw new Error((json && json.message) || t.error);
				}
				return json;
			});
		});
	}

	function refresh() {
		const hostQs = Object.keys(hostTokens()).length ? '' : '';
		return api(hostQs).then(function (json) {
			raids = json.data || [];
			const tokens = hostTokens();
			const mine = Object.keys(tokens);
			if (!mine.length) {
				render();
				return;
			}
			return Promise.all(
				mine.map(function (id) {
					return fetch(cfg.rest + '/' + id + '?host_token=' + encodeURIComponent(tokens[id]), {
						headers: { 'X-WP-Nonce': cfg.wpNonce || '' },
					}).then(function (res) {
						return res.json();
					}).then(function (json) {
						if (json && json.success && json.data) {
							raids = raids.map(function (raid) {
								return raid.id === json.data.id ? json.data : raid;
							});
						}
					}).catch(function () {
						return null;
					});
				})
			).then(render);
		}).catch(function () {
			if (!raids.length && emptyEl) {
				emptyEl.hidden = false;
			}
		});
	}

	function renderBosses() {
		if (!bossBox) {
			return;
		}
		bossBox.innerHTML = (cfg.bosses || []).map(function (boss) {
			const art = boss.art
				? '<img src="' + boss.art + '" alt="" width="56" height="56" loading="lazy">'
				: '';
			return (
				'<button type="button" class="pgo-raid-boss' + (boss.featured ? ' is-featured' : '') + '" data-boss="' + boss.key + '" data-slots="' + boss.slots + '">' +
					art +
					'<strong>' + escapeHtml(boss.name) + '</strong>' +
					'<span>' + escapeHtml(String(boss.stars)) + '★</span>' +
				'</button>'
			);
		}).join('');
	}

	function setBoss(key, slots) {
		selectedBoss = key;
		if (form && form.boss_key) {
			form.boss_key.value = key;
		}
		if (customBoss) {
			customBoss.hidden = key !== 'other';
		}
		if (slotsInput && slots) {
			slotsInput.value = slots;
		}
		bossBox.querySelectorAll('.pgo-raid-boss').forEach(function (btn) {
			btn.classList.toggle('is-selected', btn.getAttribute('data-boss') === key);
		});
	}

	function openJoin(id) {
		joinTarget = id;
		if (joinMsg) {
			joinMsg.textContent = '';
		}
		fillProfile();
		if (modal) {
			modal.hidden = false;
		}
	}

	function closeJoin() {
		joinTarget = '';
		if (modal) {
			modal.hidden = true;
		}
	}

	root.addEventListener('click', function (event) {
		const bossBtn = event.target.closest('[data-boss]');
		if (bossBtn) {
			setBoss(bossBtn.getAttribute('data-boss'), Number(bossBtn.getAttribute('data-slots')) || 5);
			return;
		}

		const copyBtn = event.target.closest('[data-copy]');
		if (copyBtn) {
			copyText(copyBtn.getAttribute('data-copy')).then(function () {
				flash(copyBtn, t.copied);
			});
			return;
		}

		const namesBtn = event.target.closest('[data-copy-names]');
		if (namesBtn) {
			const raid = raids.find(function (item) { return item.id === namesBtn.getAttribute('data-copy-names'); });
			const names = ((raid && raid.joiners) || []).map(function (j) { return j.trainer_name; }).join(' ');
			copyText(names).then(function () { flash(namesBtn, t.copied); });
			return;
		}

		const codesBtn = event.target.closest('[data-copy-codes]');
		if (codesBtn) {
			const raid = raids.find(function (item) { return item.id === codesBtn.getAttribute('data-copy-codes'); });
			const codes = ((raid && raid.joiners) || []).map(function (j) { return j.friend_code; }).filter(Boolean).join('\n');
			copyText(codes).then(function () { flash(codesBtn, t.copied); });
			return;
		}

		const joinBtn = event.target.closest('[data-join]');
		if (joinBtn) {
			openJoin(joinBtn.getAttribute('data-join'));
			return;
		}

		const hostBtn = event.target.closest('[data-host-action]');
		if (hostBtn) {
			const id = hostBtn.getAttribute('data-host-id');
			const token = hostTokens()[id];
			if (!token) {
				return;
			}
			hostBtn.disabled = true;
			api('/' + id + '/host', {
				nonce: cfg.nonce,
				host_token: token,
				action: hostBtn.getAttribute('data-host-action'),
			}).then(function (json) {
				if (json.data) {
					raids = raids.map(function (raid) {
						return raid.id === json.data.id ? json.data : raid;
					});
					render();
				}
			}).catch(function (err) {
				window.alert(err.message || t.error);
			}).finally(function () {
				hostBtn.disabled = false;
			});
			return;
		}

		if (event.target.closest('[data-pgo-raid-close]')) {
			closeJoin();
		}
	});

	if (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!selectedBoss) {
				window.alert(t.needBoss);
				return;
			}
			const body = {
				nonce: cfg.nonce,
				website: form.website ? form.website.value : '',
				trainer_name: form.trainer_name.value.trim(),
				friend_code: form.friend_code.value.trim(),
				boss_key: selectedBoss,
				boss_name: form.boss_name ? form.boss_name.value.trim() : '',
				minutes: Number(form.minutes.value) || 25,
				slots: Number(form.slots.value) || 5,
				note: form.note ? form.note.value.trim() : '',
			};
			persistProfile(body.trainer_name, body.friend_code);
			const submit = form.querySelector('[type="submit"]');
			if (submit) {
				submit.disabled = true;
			}
			api('', body).then(function (json) {
				if (json.data && json.host_token) {
					rememberHost(json.data.id, json.host_token);
					raids.unshift(json.data);
					render();
				}
				form.reset();
				selectedBoss = '';
				setBoss('', 5);
				fillProfile();
				if (hostBox) {
					hostBox.hidden = false;
				}
			}).catch(function (err) {
				window.alert(err.message || t.error);
			}).finally(function () {
				if (submit) {
					submit.disabled = false;
				}
			});
		});
	}

	if (joinForm) {
		joinForm.addEventListener('submit', function (event) {
			event.preventDefault();
			if (!joinTarget) {
				return;
			}
			const body = {
				nonce: cfg.nonce,
				website: joinForm.website ? joinForm.website.value : '',
				trainer_name: joinForm.trainer_name.value.trim(),
				friend_code: joinForm.friend_code.value.trim(),
			};
			persistProfile(body.trainer_name, body.friend_code);
			const submit = joinForm.querySelector('[type="submit"]');
			if (submit) {
				submit.disabled = true;
			}
			api('/' + joinTarget + '/join', body).then(function (json) {
				if (json.data) {
					raids = raids.map(function (raid) {
						return raid.id === json.data.id ? json.data : raid;
					});
					render();
				}
				if (joinMsg) {
					joinMsg.textContent = t.joined + (json.host_code ? ' ' + json.host_code : '');
				}
				if (json.host_code) {
					copyText(json.host_code);
				}
			}).catch(function (err) {
				if (joinMsg) {
					joinMsg.textContent = err.message || t.error;
				}
			}).finally(function () {
				if (submit) {
					submit.disabled = false;
				}
			});
		});
	}

	renderBosses();
	fillProfile();
	refresh();
	tickTimer = window.setInterval(function () {
		tickClocks();
	}, 1000);
	window.setInterval(refresh, 8000);
	document.addEventListener('visibilitychange', function () {
		if (!document.hidden) {
			refresh();
		}
	});
})();
