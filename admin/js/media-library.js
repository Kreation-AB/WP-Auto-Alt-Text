/**
 * Media Library: keeps the bulk progress notice (#aat-bulk-notice) up to date.
 * PHP prints the notice first (it works without JS); this module polls the
 * REST route and re-renders the same structure. Nothing from the API is
 * inserted as HTML: text goes through textContent and links through href.
 * In grid mode it also adds the "generate for selected" toolbar button.
 */
const cfg = window.aatMediaLibrary || {};
const t = cfg.i18n || {};
const links = cfg.links || {};
const LIVE_MIN_INTERVAL_MS = 30000;
const NBSP = ' ';

let timer = null;
let busy = false;
let stopped = false;
let seq = 0;
let lastState = null;
let lastLiveText = '';
let lastLiveAt = 0;
let latest = null;
let failuresKey = '';
let lastStalled = false;

const $ = (id) => document.getElementById('aat-bulk-' + id);
const root = () => document.getElementById('aat-bulk-notice');
const part = (selector) => (root() ? root().querySelector(selector) : null);

function sprintf(template, ...args) {
	let next = 0;
	return String(template).replace(/%(?:(\d+)\$)?[sd]/g, (match, pos) => {
		const value = args[pos ? Number(pos) - 1 : next++];
		return value === undefined ? '' : String(value);
	});
}

function fmtInt(n) {
	return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);
}

function setText(el, text) {
	if (el && el.textContent !== text) {
		el.textContent = text;
	}
}

function url(path) {
	return cfg.restUrl + (path || '');
}

async function api(method, path, body) {
	const options = {
		method,
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
	};
	if (body !== undefined) {
		options.body = JSON.stringify(body);
	}
	const response = await fetch(url(path), options);
	if (!response.ok) {
		const error = new Error('HTTP ' + response.status);
		error.status = response.status;
		try {
			const data = await response.json();
			error.serverMessage = data && typeof data.message === 'string' ? data.message : '';
		} catch (ignore) {
			error.serverMessage = '';
		}
		throw error;
	}
	return response.json();
}

/* ---- Text ---- */

function summaryText(p) {
	if (p.state === 'finished') {
		const written = Number(p.written) || 0;
		const parts = [written === 0 ? t.finished_none : written === 1 ? t.finished_one : sprintf(t.finished_many, fmtInt(written))];
		['skipped', 'failed', 'cancelled'].forEach((key) => {
			if (Number(p[key]) > 0) {
				parts.push(sprintf(t[key], fmtInt(p[key])));
			}
		});
		if (Number(p.removed) > 0) {
			parts.push(Number(p.removed) === 1 ? t.removed_one : sprintf(t.removed_many, fmtInt(p.removed)));
		}
		return parts.join(' ');
	}
	if (String(p.state).indexOf('paused_') === 0) {
		return t[p.state] || t.paused_auth;
	}
	const text = sprintf(t.running, fmtInt(p.total - p.pending), fmtInt(p.total));
	return p.stalled ? text : text + ' ' + t.leave;
}

function noticeClass(p) {
	if (p.state === 'finished') {
		return Number(p.failed) > 0 ? 'notice-warning' : 'notice-success';
	}
	return String(p.state).indexOf('paused_') === 0 ? 'notice-warning' : 'notice-info';
}

/* ---- DOM ---- */

function el(tag, className, id) {
	const node = document.createElement(tag);
	if (className) {
		node.className = className;
	}
	if (id) {
		node.id = id;
	}
	return node;
}

function link(className, text, href) {
	const a = el('a', className);
	a.textContent = text;
	a.href = href || '#';
	return a;
}

/** The structure PHP prints (Auto_Alt_Text_Bulk_Notice::html), for notices created by JS. */
function buildNotice() {
	const notice = el('div', 'notice aat-bulk-notice', 'aat-bulk-notice');
	notice.appendChild(el('p', '', 'aat-bulk-summary'));
	const progress = el('progress', 'aat-bulk-progress', 'aat-bulk-progress');
	progress.setAttribute('aria-labelledby', 'aat-bulk-summary');
	notice.appendChild(progress);
	notice.appendChild(el('p', 'aat-bulk-retrying'));
	notice.appendChild(el('p', 'aat-bulk-stalled'));
	const pause = el('p', 'aat-bulk-pause-link');
	pause.appendChild(link('', '', ''));
	notice.appendChild(pause);
	const details = el('details', 'aat-bulk-failures');
	const summary = el('summary');
	summary.textContent = t.failures_toggle;
	details.appendChild(summary);
	details.appendChild(el('ul'));
	notice.appendChild(details);
	const controls = el('p', 'aat-bulk-controls');
	controls.appendChild(link('aat-bulk-refresh', t.refresh, window.location.href));
	controls.appendChild(document.createTextNode(' '));
	controls.appendChild(link('aat-bulk-cancel', t.cancel, links.cancel));
	controls.appendChild(document.createTextNode(' '));
	controls.appendChild(link('aat-bulk-dismiss', t.hide, links.dismiss));
	notice.appendChild(controls);
	// Created empty: the text is set on a later tick so the first announcement is not missed.
	const live = el('p', 'screen-reader-text', 'aat-bulk-live');
	live.setAttribute('aria-live', 'polite');
	live.setAttribute('aria-atomic', 'true');
	notice.appendChild(live);
	return notice;
}

function placeNotice(notice) {
	const wrap = document.querySelector('.wrap');
	const anchor = wrap && wrap.querySelector('.wp-header-end');
	if (anchor) {
		anchor.insertAdjacentElement('afterend', notice);
	} else if (wrap) {
		wrap.insertBefore(notice, wrap.firstChild);
	} else {
		document.body.insertBefore(notice, document.body.firstChild);
	}
}

function insertNotice(notice) {
	placeNotice(notice);
	bind(notice);
}

function renderFailures(p) {
	const details = part('.aat-bulk-failures');
	const list = details.querySelector('ul');
	const failures = p.state === 'finished' && Array.isArray(p.failures) ? p.failures : [];
	const key = JSON.stringify(failures);
	details.hidden = failures.length === 0;
	if (key === failuresKey) {
		return;
	}
	failuresKey = key;
	list.textContent = '';
	failures.forEach((failure) => {
		const li = el('li');
		const a = el('a');
		a.textContent = String(failure.title);
		a.href = failure.edit_url;
		const span = el('span');
		span.textContent = String(failure.message);
		li.appendChild(a);
		li.appendChild(document.createTextNode(' '));
		li.appendChild(span);
		list.appendChild(li);
	});
}

/** Creates the notice when it is missing, otherwise updates it in place. */
function renderNotice(p) {
	const created = !root();
	if (created) {
		insertNotice(buildNotice());
	}
	const notice = root();
	const finished = p.state === 'finished';
	const paused = String(p.state).indexOf('paused_') === 0;
	notice.classList.remove('notice-info', 'notice-warning', 'notice-success');
	notice.classList.add(noticeClass(p));
	notice.dataset.state = p.state;
	setText($('summary'), summaryText(p));

	const progress = $('progress');
	progress.max = Number(p.total) || 0;
	progress.value = Math.max(0, (Number(p.total) || 0) - (Number(p.pending) || 0));
	progress.hidden = finished;

	const retrying = part('.aat-bulk-retrying');
	const showRetry = !finished && !paused && Number(p.retrying) > 0;
	setText(retrying, showRetry ? sprintf(t.retrying, fmtInt(p.retrying)) : '');
	retrying.hidden = !showRetry;

	const stalled = part('.aat-bulk-stalled');
	const showStalled = !finished && !paused && Boolean(p.stalled);
	setText(stalled, showStalled ? t.stalled : '');
	stalled.hidden = !showStalled;

	const pause = part('.aat-bulk-pause-link');
	const pauseLink = pause.querySelector('a');
	if (p.state === 'paused_connector' && links.connector) {
		pauseLink.textContent = t.open_approval;
		pauseLink.href = links.connector;
		pause.hidden = false;
	} else if ((p.state === 'paused_auth' || p.state === 'paused_no_key') && links.connection) {
		pauseLink.textContent = t.open_connection;
		pauseLink.href = links.connection;
		pause.hidden = false;
	} else {
		pause.hidden = true;
	}

	renderFailures(p);

	// With JS the page updates itself, so the manual refresh link goes away.
	part('.aat-bulk-refresh').hidden = true;
	part('.aat-bulk-cancel').hidden = finished || Number(p.pending) < 1;
	setText(part('.aat-bulk-dismiss'), finished ? t.close : t.hide);
	announceFor(p, created);
}

/* ---- Live region ---- */

function announce(text, force, defer) {
	const now = Date.now();
	if (!text || text === lastLiveText) {
		return;
	}
	if (!force && now - lastLiveAt < LIVE_MIN_INTERVAL_MS) {
		return;
	}
	lastLiveText = text;
	lastLiveAt = now;
	if (defer) {
		window.setTimeout(() => setText($('live'), text), 100);
	} else {
		setText($('live'), text);
	}
}

/**
 * State changes (and a new notice) are announced at once, progress at most
 * every 30 s. When the run turns stalled, the instruction is announced too.
 */
function announceFor(p, created) {
	const changed = lastState !== null && lastState !== p.state;
	const stalled = p.state === 'running' && Boolean(p.stalled);
	const turnedStalled = stalled && !lastStalled;
	lastState = p.state;
	lastStalled = stalled;
	const summary = summaryText(p);
	announce(turnedStalled ? summary + ' ' + t.stalled : summary, changed || created || turnedStalled, created);
}

/* ---- Polling ---- */

function wantsPolling() {
	return !stopped && root() !== null && latest !== null && latest.state !== 'finished';
}

function schedule() {
	window.clearTimeout(timer);
	timer = null;
	if (wantsPolling() && document.visibilityState === 'visible') {
		timer = window.setTimeout(poll, (Number(cfg.pollSeconds) || 5) * 1000);
	}
}

function expire() {
	stopped = true;
	window.clearTimeout(timer);
	setText($('summary'), t.session_expired);
	announce(t.session_expired, true);
}

function apply(bulk) {
	latest = bulk;
	if (bulk === null) {
		const notice = root();
		if (notice) {
			notice.remove();
		}
		// A notice created later starts clean.
		failuresKey = '';
		lastState = null;
		lastLiveText = '';
		lastStalled = false;
		return;
	}
	renderNotice(bulk);
}

async function poll() {
	if (busy || stopped) {
		return;
	}
	busy = true;
	const mine = ++seq;
	try {
		const response = await api('GET', '');
		if (mine === seq) {
			apply(response.bulk);
		}
		// Only a stalled bulk is moved from this tab, once per poll, never in parallel.
		if (mine === seq && latest && latest.state === 'running' && latest.stalled) {
			try {
				const ticked = await api('POST', '/tick');
				if (mine === seq) {
					apply(ticked.bulk);
				}
			} catch (error) {
				if (error.status === 401 || error.status === 403) {
					throw error;
				}
				// 409: nothing left to run. Other errors: try again on the next poll.
			}
		}
	} catch (error) {
		if (error.status === 401 || error.status === 403) {
			expire();
		}
	} finally {
		busy = false;
		schedule();
	}
}

/* ---- Controls ---- */

function focusHeading() {
	const heading = document.querySelector('#wpbody-content h1');
	if (heading) {
		if (!heading.hasAttribute('tabindex')) {
			heading.setAttribute('tabindex', '-1');
		}
		heading.focus();
	}
}

async function control(path, event) {
	if (stopped || !cfg.restUrl) {
		// No working session: let the admin-post link do the job.
		return;
	}
	event.preventDefault();
	// An in-flight poll or tick must not delay the click: its response is discarded.
	const mine = ++seq;
	window.clearTimeout(timer);
	const href = event.currentTarget.href;
	try {
		const response = await api('POST', path);
		if (mine !== seq) {
			// A later click (Avbryt, then Dölj) has taken over: this response is stale.
			return;
		}
		if (path === '/dismiss') {
			apply(null);
			focusHeading();
		} else {
			apply(response.bulk);
			const dismiss = part('.aat-bulk-dismiss');
			if (dismiss) {
				dismiss.focus();
			}
		}
	} catch (error) {
		if (error.status === 401 || error.status === 403) {
			expire();
		} else if (mine !== seq) {
			// Stale: the later click decides what happens.
		} else if (href && href !== '#') {
			// Anything else: the nonce-protected admin-post link does the same job.
			window.location.href = href;
		}
	} finally {
		schedule();
	}
}

function bind(notice) {
	const cancel = notice.querySelector('.aat-bulk-cancel');
	const dismiss = notice.querySelector('.aat-bulk-dismiss');
	if (cancel) {
		cancel.addEventListener('click', (event) => control('/cancel', event));
	}
	if (dismiss) {
		dismiss.addEventListener('click', (event) => control('/dismiss', event));
	}
}

/* ---- Grid bulk button ---- */

const attachedToolbars = new WeakSet();

function gridImageIds(selection) {
	return selection
		.filter((model) => model.get('type') === 'image')
		.map((model) => model.get('id'));
}

function clearGridError() {
	const old = document.getElementById('aat-grid-error');
	if (old) {
		old.remove();
	}
}

function showGridError(message) {
	clearGridError();
	const notice = el('div', 'notice notice-error aat-grid-error', 'aat-grid-error');
	notice.setAttribute('role', 'alert');
	const paragraph = el('p');
	paragraph.textContent = message;
	notice.appendChild(paragraph);
	placeNotice(notice);
}

/** Adds the button to the grid's toolbar. Does nothing, and throws nothing, when a piece is missing. */
function attachGridButton(frame) {
	const media = window.wp && window.wp.media;
	const toolbar = frame && frame.browserView && frame.browserView.toolbar;
	if (!media || !media.view || !media.view.Button || !toolbar || typeof toolbar.set !== 'function' || attachedToolbars.has(toolbar)) {
		return;
	}
	const selection = typeof frame.state === 'function' && frame.state() ? frame.state().get('selection') : null;
	if (!selection || typeof selection.filter !== 'function') {
		return;
	}
	attachedToolbars.add(toolbar);

	let inFlight = false;
	let visible = false;
	// Button.render() rewrites the class list, so the hidden class is applied after it.
	const GridButton = media.view.Button.extend({
		render() {
			media.view.Button.prototype.render.apply(this, arguments);
			this.el.classList.toggle('hidden', !visible);
			return this;
		},
	});
	const button = new GridButton({
		text: t.grid_generate,
		style: 'primary',
		size: '',
		classes: ['aat-grid-generate'],
		disabled: true,
		priority: -60,
		click: generate,
	});

	function sync() {
		visible = typeof frame.isModeActive === 'function' && frame.isModeActive('select');
		button.model.set('disabled', inFlight || gridImageIds(selection).length === 0);
		button.render();
	}

	async function generate() {
		const ids = gridImageIds(selection);
		if (inFlight || ids.length === 0 || !cfg.restUrl) {
			return;
		}
		const hadFocus = document.activeElement === button.el;
		inFlight = true;
		sync();
		clearGridError();
		// An in-flight poll must not overwrite the answer to this click.
		const mine = ++seq;
		window.clearTimeout(timer);
		try {
			const response = await api('POST', '', { attachment_ids: ids });
			if (mine === seq) {
				if (response && response.bulk) {
					apply(response.bulk);
				} else {
					showGridError(t.err_generic);
				}
			}
		} catch (error) {
			if (error.status === 401 || error.status === 403) {
				expire();
				showGridError(t.session_expired);
			} else {
				showGridError(error.serverMessage || t.err_generic);
			}
		} finally {
			inFlight = false;
			sync();
			if (hadFocus && !button.model.get('disabled')) {
				button.el.focus();
			}
			schedule();
		}
	}

	toolbar.set('aatGenerate', button);
	selection.on('add remove reset', sync);
	if (typeof frame.on === 'function') {
		frame.on('select:activate select:deactivate', sync);
	}
	sync();
}

function initGrid() {
	const jq = window.jQuery;
	if (!cfg.restUrl || typeof jq !== 'function') {
		return;
	}
	jq('#wp-media-grid').on('wp-media-grid-ready', (event, frame) => {
		try {
			attachGridButton(frame);
			if (frame && typeof frame.on === 'function') {
				// The browser view is rebuilt when the grid leaves edit mode.
				frame.on('content:render:browse', () => attachGridButton(frame));
			}
		} catch (error) {
			// The grid keeps working without the button.
		}
	});
}

function init() {
	initGrid();
	if (!cfg.restUrl) {
		return;
	}
	const notice = root();
	if (notice) {
		// Baseline from the server-rendered notice: no announcement for what the page already says.
		lastState = notice.dataset.state || null;
		lastLiveAt = Date.now();
		latest = { state: lastState };
		bind(notice);
		const refresh = notice.querySelector('.aat-bulk-refresh');
		if (refresh) {
			refresh.hidden = true;
		}
		if (lastState !== 'finished') {
			poll();
		}
	}
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible' && wantsPolling() && !busy) {
			poll();
		}
	});
}

init();
