/**
 * "Hela biblioteket": renders the REST payload into the server-printed page,
 * polls while a run is active, and drives the two confirmation dialogs.
 * Nothing from the API is ever inserted as HTML: text goes through
 * textContent and the report link through the href property.
 */
const cfg = window.aatLibrary || {};
const t = cfg.i18n || {};
const LIVE_MIN_INTERVAL_MS = 30000;
const ETA_WINDOW_MS = 5 * 60 * 1000;
const ETA_MIN_SPAN_MS = 30000;

const $ = (id) => document.getElementById('aat-library-' + id);

let data = null;
let timer = null;
let busy = false;
let failuresShown = 0;
let lastKey = null;
let lastLiveText = '';
let lastLiveAt = 0;
let samples = [];
let samplesRun = null;
let dialogTrigger = null;
let suppressRefocus = false;
let lastError = '';
let seq = 0;
let stopped = false;
let ready = false;
let failuresKey = '';
let recentKey = '';
let retryable = false;
const clockSkew = (Number(cfg.now) || Date.now() / 1000) - Date.now() / 1000;

function sprintf(template, ...args) {
	let next = 0;
	return String(template).replace(/%(?:(\d+)\$)?[sd]/g, (match, pos) => {
		const value = args[pos ? Number(pos) - 1 : next++];
		return value === undefined ? '' : String(value);
	});
}

function fmtInt(n) {
	return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function fmtUsd(usd) {
	const value = Number(usd) || 0;
	return value.toFixed(value < 1 ? 3 : 2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

function setText(el, text) {
	if (el && el.textContent !== text) {
		el.textContent = text;
	}
}

function show(el, visible) {
	if (el) {
		el.hidden = !visible;
	}
}

/** "nyss", then minutes up to 59, hours up to 23, then days. */
function ageText(timestamp) {
	const now = Date.now() / 1000 + clockSkew;
	const minutes = Math.max(0, Math.floor((now - timestamp) / 60));
	if (minutes < 1) {
		return t.age_now;
	}
	if (minutes < 60) {
		return sprintf(t.age_minutes, minutes);
	}
	if (minutes < 1440) {
		return sprintf(t.age_hours, Math.floor(minutes / 60));
	}
	return sprintf(t.age_days, Math.floor(minutes / 1440));
}

/** "YYYY-MM-DD HH:MM:SS" (UTC) as HH:MM in the site's time zone. */
function siteTime(utc) {
	const match = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/.exec(String(utc));
	if (!match) {
		return '';
	}
	const [, y, mo, d, h, mi, s] = match.map(Number);
	const shifted = new Date(Date.UTC(y, mo - 1, d, h, mi, s) + (Number(cfg.siteOffsetMinutes) || 0) * 60000);
	return String(shifted.getUTCHours()).padStart(2, '0') + ':' + String(shifted.getUTCMinutes()).padStart(2, '0');
}

function url(path, query) {
	let target = cfg.restUrl + (path || '');
	if (query) {
		target += (target.includes('?') ? '&' : '?') + query;
	}
	return target;
}

async function api(method, path, body, query) {
	const response = await fetch(url(path, query), {
		method,
		credentials: 'same-origin',
		headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
		body: body ? JSON.stringify(body) : undefined,
	});
	if (!response.ok) {
		const error = new Error('HTTP ' + response.status);
		error.status = response.status;
		try {
			const payload = await response.json();
			error.restMessage = payload && typeof payload.message === 'string' ? payload.message : '';
		} catch (ignore) {
			error.restMessage = '';
		}
		throw error;
	}
	return response.json();
}

function showError(message) {
	lastError = message;
	const el = $('error');
	setText(el, message);
	show(el, message !== '');
}

/* ---- Derived state ---- */

function runState(run) {
	if (!run) {
		return 'none';
	}
	if (run.status === 'finished' || run.status === 'cancelled') {
		return 'final';
	}
	return 'active';
}

function needsPolling(payload) {
	if (stopped) {
		return false;
	}
	if (!payload) {
		return true;
	}
	const run = payload.run;
	const active = runState(run) === 'active';
	return active || payload.counts.recounting || !payload.counts.complete;
}

function candidateCount(counts) {
	return counts.missing + ($('coverage').checked ? counts.ai : 0);
}

/* ---- Rendering ---- */

function renderCounts(counts) {
	document.querySelectorAll('#aat-library-counts [data-count]').forEach((el) => {
		setText(el, fmtInt(counts[el.dataset.count]));
	});
	let status;
	if (!counts.complete) {
		status = t.counts_first;
	} else if (counts.recounting) {
		status = sprintf(t.counts_recounting, ageText(counts.updated_at));
	} else {
		status = sprintf(t.counts_updated, ageText(counts.updated_at));
	}
	setText($('count-status'), status);
	setText($('coverage-label'), sprintf(t.coverage_label, fmtInt(counts.ai)));
	renderEstimate(counts);
}

function renderEstimate(counts) {
	const n = candidateCount(counts);
	const perImage = Number(data && data.estimate_per_image_usd !== undefined ? data.estimate_per_image_usd : cfg.estimatePerImage) || 0;
	const text = n > 0 ? sprintf(t.estimate, fmtInt(n), fmtUsd(n * perImage)) : t.estimate_none;
	setText($('estimate'), text);
	setText($('dialog-summary'), text);
	$('start').disabled = !ready || !counts.complete || n < 1;
}

function etaText(run, progress) {
	const now = Date.now();
	if (samplesRun !== run.id) {
		samples = [];
		samplesRun = run.id;
	}
	samples.push({ at: now, done: progress.done });
	samples = samples.filter((s) => now - s.at <= ETA_WINDOW_MS);
	const first = samples[0];
	const span = now - first.at;
	const gained = progress.done - first.done;
	if (span < ETA_MIN_SPAN_MS || gained <= 0) {
		return t.eta_calculating;
	}
	const minutes = Math.ceil(((progress.total - progress.done) / (gained / span)) / 60000);
	let text;
	if (minutes < 1) {
		text = t.eta_less_than_minute;
	} else if (minutes < 60) {
		text = sprintf(t.eta_minutes, minutes);
	} else {
		text = sprintf(t.eta_hours, Math.floor(minutes / 60), minutes % 60);
	}
	return sprintf(t.eta, text);
}

function outcomeLabel(outcome) {
	return t['outcome_' + outcome] || outcome;
}

function renderRecent(items) {
	const key = JSON.stringify(items);
	if (key === recentKey) {
		return;
	}
	recentKey = key;
	const list = $('recent');
	list.replaceChildren();
	if (!items.length) {
		const li = document.createElement('li');
		li.textContent = t.recent_none;
		list.appendChild(li);
		return;
	}
	items.forEach((item) => {
		const li = document.createElement('li');
		const label = document.createElement('strong');
		label.className = 'aat-lib-outcome';
		label.textContent = outcomeLabel(item.outcome);
		li.appendChild(label);
		let detail = ' ' + (item.file || '');
		if (item.excerpt) {
			detail += ' – ”' + item.excerpt + '”';
		} else if (item.reason) {
			detail += ' – ' + item.reason;
		}
		li.appendChild(document.createTextNode(detail));
		list.appendChild(li);
	});
}

function appendFailures(items) {
	const body = $('failures-body');
	items.forEach((item) => {
		const tr = document.createElement('tr');
		const file = document.createElement('td');
		if (item.attachment_id && cfg.editUrl) {
			const link = document.createElement('a');
			link.href = cfg.editUrl + encodeURIComponent(item.attachment_id);
			link.textContent = item.file || '#' + item.attachment_id;
			file.appendChild(link);
		} else {
			file.textContent = item.file || '';
		}
		const reason = document.createElement('td');
		reason.textContent = item.reason || t.failures_unknown;
		tr.append(file, reason);
		body.appendChild(tr);
	});
	failuresShown += items.length;
	items.forEach((item) => {
		if (!/stöds inte/i.test(item.reason || '')) {
			retryable = true;
		}
	});
}

function renderFailures(run) {
	const total = run.failures_total || 0;
	show($('failures'), total > 0);
	setText($('failures-title'), sprintf(t.failures_heading, fmtInt(total)));
	// Rebuild only when the list can have changed, so polling keeps paging, focus and reading position.
	const key = run.id + ':' + run.status + ':' + total;
	if (run.failures_offset === 0 && key !== failuresKey) {
		failuresKey = key;
		$('failures-body').replaceChildren();
		failuresShown = 0;
		retryable = false;
		appendFailures(run.failures_page || []);
	}
	show($('failures-more'), failuresShown < total);
	const canRetry = (run.progress.failed > 0) && (retryable || failuresShown < total);
	show($('retry'), canRetry);
	show($('retry-note'), run.progress.failed > 0 && !canRetry);
}

function renderWarning(payload, waiting) {
	const health = payload.health || {};
	const visible = Boolean(health.stalled) && !waiting;
	show($('warning'), visible);
	show($('warning-cron'), visible && Boolean(health.cron_disabled));
}

function stateSentence(key) {
	return t['live_' + key] || '';
}

function announce(text, force) {
	const now = Date.now();
	if (!text || text === lastLiveText) {
		return;
	}
	if (!force && now - lastLiveAt < LIVE_MIN_INTERVAL_MS) {
		return;
	}
	setText($('live'), text);
	lastLiveText = text;
	lastLiveAt = now;
}

function render(payload) {
	data = payload;
	ready = true;
	const { counts, run } = payload;
	const mode = runState(run);

	renderCounts(counts);

	show($('before'), mode !== 'active');
	show($('active'), mode === 'active');
	show($('final'), mode === 'final');
	show($('report-section'), Boolean(run));
	setText($('before-title'), mode === 'final' ? t.new_run_heading : t.counts_heading);

	let waiting = false;
	let liveKey = 'idle';
	if (run) {
		$('report').href = run.report_url;
		const progress = run.progress;
		const paused = run.display_state.startsWith('paused');
		waiting = Boolean(run.waiting_until);
		liveKey = run.id + ':' + run.display_state;

		if (mode === 'active') {
			setText($('active-title'), paused ? t.paused_heading : t.active_heading);
			const reason = t['paused_' + (run.reason || '')] || '';
			setText($('pause-reason'), paused ? reason : '');
			show($('pause-reason'), paused && reason !== '');
			setText($('progress-text'), sprintf(t.progress_text, fmtInt(progress.done), fmtInt(progress.total)));
			const bar = $('progress');
			bar.setAttribute('aria-valuemax', String(progress.total));
			bar.setAttribute('aria-valuenow', String(progress.done));
			bar.setAttribute('aria-valuetext', sprintf(t.progress_value, fmtInt(progress.done), fmtInt(progress.total)));
			$('progress-fill').style.width = (progress.total > 0 ? Math.min(100, (progress.done / progress.total) * 100) : 0) + '%';
			setText($('waiting'), waiting ? sprintf(t.waiting, siteTime(run.waiting_until)) : '');
			show($('waiting'), waiting);
			const showEta = !paused && !waiting;
			show($('eta'), showEta);
			if (showEta) {
				setText($('eta'), etaText(run, progress));
			}
			setText($('failed-count'), sprintf(t.failed_so_far, fmtInt(progress.failed)));
			setText($('cost'), sprintf(t.cost_so_far, fmtUsd(run.cost_so_far), fmtUsd(run.estimate_usd)));
			renderRecent(run.recent || []);
			show($('pause'), run.status === 'running');
			show($('resume'), run.status === 'paused');
		} else {
			setText($('final-title'), run.status === 'cancelled' ? t.final_cancelled : t.final_finished);
			setText($('sum-filled'), fmtInt(progress.filled));
			setText($('sum-failed'), fmtInt(progress.failed));
			setText($('sum-skipped'), fmtInt(progress.skipped));
			setText($('sum-cost'), fmtUsd(run.cost_so_far) + ' USD');
			renderFailures(run);
		}
	}
	renderWarning(payload, waiting);

	// Announce state changes at once and progress at most every 30 s.
	if (lastKey !== null && lastKey !== liveKey) {
		const key = !run ? 'idle' : mode === 'final' ? run.status : run.display_state.startsWith('paused') ? 'paused' : 'running';
		announce(stateSentence(key), true);
	} else if (run && mode === 'active') {
		announce(sprintf(t.live_progress, fmtInt(run.progress.done), fmtInt(run.progress.total)), false);
	}
	lastKey = liveKey;

	// A section swap can hide the control that had focus; keep it on the page.
	const focused = document.activeElement;
	if (focused && focused !== document.body && focused.closest('[hidden]')) {
		moveFocusToSection();
	}
}

/* ---- Data flow ---- */

function schedule() {
	clearTimeout(timer);
	if (needsPolling(data)) {
		timer = setTimeout(poll, (Number(cfg.pollSeconds) || 10) * 1000);
	}
}

function sessionExpired(error) {
	if (error.status === 401 || error.status === 403) {
		stopped = true;
		clearTimeout(timer);
		showError(t.error_session);
		return true;
	}
	return false;
}

function setBusy(flag) {
	busy = flag;
	['dialog-confirm', 'cancel-dialog-confirm', 'failures-more', 'retry', 'pause', 'resume', 'cancel'].forEach((id) => {
		$(id).disabled = flag;
	});
}

async function refresh() {
	if (busy || stopped) {
		return;
	}
	const mine = ++seq;
	try {
		const payload = await api('GET', '');
		if (mine !== seq || busy) {
			return;
		}
		render(payload);
		if (lastError === t.error_poll) {
			showError('');
		}
	} catch (error) {
		if (mine !== seq || sessionExpired(error)) {
			return;
		}
		showError(t.error_poll);
	}
	schedule();
}

function poll() {
	if (document.hidden) {
		return;
	}
	if (busy) {
		schedule();
		return;
	}
	refresh();
}

async function act(path, body) {
	if (busy || stopped) {
		return false;
	}
	seq++;
	setBusy(true);
	try {
		render(await api('POST', path, body));
		showError('');
		return true;
	} catch (error) {
		if (!sessionExpired(error)) {
			showError(error.status === 409 ? t.error_active : error.restMessage || t.error_generic);
		}
		return false;
	} finally {
		setBusy(false);
		if (!stopped) {
			refresh();
		}
	}
}

async function loadMoreFailures() {
	if (busy || stopped) {
		return;
	}
	setBusy(true);
	try {
		const payload = await api('GET', '', null, 'failures_offset=' + failuresShown);
		const run = payload.run;
		if (run) {
			appendFailures(run.failures_page || []);
			show($('failures-more'), failuresShown < (run.failures_total || 0));
		}
	} catch (error) {
		if (!sessionExpired(error)) {
			showError(error.restMessage || t.error_generic);
		}
	} finally {
		setBusy(false);
		show($('failures-more'), data && data.run ? failuresShown < (data.run.failures_total || 0) : false);
	}
}

/* ---- Dialogs ---- */

function openDialog(dialog, trigger) {
	dialogTrigger = trigger;
	suppressRefocus = false;
	if (typeof dialog.showModal === 'function') {
		dialog.showModal();
	}
}

function closeDialog(dialog, refocus) {
	suppressRefocus = !refocus;
	if (dialog.open) {
		dialog.close();
	}
}

function bindDialog(dialog) {
	dialog.addEventListener('close', () => {
		if (!suppressRefocus && dialogTrigger) {
			if (dialogTrigger.closest('[hidden]')) {
				moveFocusToSection();
			} else {
				dialogTrigger.focus();
			}
		}
		suppressRefocus = false;
	});
}

function moveFocusToSection() {
	const target = !$('active').hidden ? $('active-title') : !$('final').hidden ? $('final-title') : $('before-title');
	target.setAttribute('tabindex', '-1');
	target.focus();
}

function init() {
	const root = $('start');
	if (!root || !cfg.restUrl) {
		return;
	}
	const startDialog = $('dialog');
	const cancelDialog = $('cancel-dialog');
	bindDialog(startDialog);
	bindDialog(cancelDialog);

	$('coverage').addEventListener('change', () => data && renderEstimate(data.counts));

	$('start').addEventListener('click', () => {
		if (data) {
			renderEstimate(data.counts);
		}
		if (typeof startDialog.showModal === 'function') {
			openDialog(startDialog, $('start'));
		} else if (window.confirm($('dialog-summary').textContent + ' ' + t.dialog_background)) {
			$('dialog-confirm').click();
		}
	});
	$('dialog-dismiss').addEventListener('click', () => closeDialog(startDialog, true));
	$('dialog-confirm').addEventListener('click', async () => {
		const coverage = $('coverage').checked ? 'missing_ai' : 'missing';
		const started = await act('/start', { coverage });
		closeDialog(startDialog, !started);
		if (started) {
			announce(stateSentence('started'), true);
			lastKey = data && data.run ? data.run.id + ':' + data.run.display_state : lastKey;
			moveFocusToSection();
		}
	});

	$('pause').addEventListener('click', async () => {
		await act('/pause');
		if (!$('resume').hidden) {
			$('resume').focus();
		}
	});
	$('resume').addEventListener('click', async () => {
		await act('/resume');
		if (!$('pause').hidden) {
			$('pause').focus();
		}
	});
	$('cancel').addEventListener('click', () => openDialog(cancelDialog, $('cancel')));
	$('cancel-dialog-keep').addEventListener('click', () => closeDialog(cancelDialog, true));
	$('cancel-dialog-confirm').addEventListener('click', async () => {
		const cancelled = await act('/cancel');
		closeDialog(cancelDialog, !cancelled);
		if (cancelled) {
			moveFocusToSection();
		}
	});
	$('retry').addEventListener('click', async () => {
		if (await act('/retry-failed')) {
			moveFocusToSection();
		}
	});
	$('failures-more').addEventListener('click', loadMoreFailures);

	document.addEventListener('visibilitychange', () => {
		if (!document.hidden && needsPolling(data)) {
			refresh();
		}
	});

	$('start').disabled = true;
	refresh();
}

init();
