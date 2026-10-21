/**
 * Settings page (Auto_Alt_Text_Settings_Page): turns the server-rendered
 * sidebar of section links into vertical tabs (WAI-ARIA APG, automatic
 * activation), keeps each section's sidebar summary current while fields
 * change, opens the panel of a field that fails validation, and holds back
 * "Töm cache" and "Kontrollera nu" while the options form has unsaved changes.
 * Summaries are written with textContent only, so typed HTML stays text.
 * Without this script every section shows, stacked, and the links are plain
 * anchors.
 */
const cfg = window.aatSettings || {};
const labels = cfg.labels || {};
const i18n = cfg.i18n || {};

const PANEL_PREFIX = 'aat-section-';
// Buttons that submit their own forms and would drop unsaved edits.
const SIDE_FORMS = [ 'aat-clear-cache-form', 'aat-check-key-form' ];
// The server joins a summary's parts with this.
const SEP = ' · ';

const root = document.querySelector( '.aat-settings' );
const form = document.getElementById( 'aat-settings-form' );
const list = root ? root.querySelector( '.aat-settings-nav ul' ) : null;
const tabs = list ? Array.from( list.querySelectorAll( 'a[data-section]' ) ) : [];
const panels = new Map();
let dirty = false;
let revealing = false;

const field = ( id ) => document.getElementById( id );
const onOff = ( on ) => ( on ? labels.on : labels.off ) || null;
const slugOf = ( panel ) => panel.id.slice( PANEL_PREFIX.length );

/**
 * Each section's summary rebuilt from its fields, as the server builds it.
 * null keeps the current text. Anslutning depends on a stored key check, so
 * it is left as the server printed it.
 */
const summaries = {
	sprak() {
		const languages = labels.languages || {};
		return languages[ field( 'aat_language' )?.value ] || languages.en || null;
	},
	namn() {
		const naming = field( 'naming' )?.value;
		const text = ( labels.naming || {} )[ naming ];
		const brand = ( field( 'aat_brand_name' )?.value || '' ).trim();
		if ( ! text ) {
			return null;
		}
		return naming === 'product_brand' && brand !== '' ? text + SEP + brand : text;
	},
	automatik() {
		const policy = ( labels.policy || {} )[ field( 'overwrite_policy' )?.value ];
		const on = onOff( field( 'auto_generate' )?.checked );
		return policy && on ? on + SEP + policy : null;
	},
	cache( current ) {
		const input = field( 'cache_duration' );
		const days = Number( input?.value );
		const template = days === 1 ? labels.day : labels.days;
		if ( ! input || input.value === '' || ! input.validity.valid || ! Number.isInteger( days ) || ! template ) {
			return null;
		}
		// The saved-answers count after the days is known only to the server: keep it.
		const at = current.indexOf( SEP );
		return template.replace( '%d', String( days ) ) + ( at === -1 ? '' : current.slice( at ) );
	},
	feedback() {
		return onOff( field( 'enable_feedback' )?.checked );
	},
};

function refreshSummary( panel ) {
	const slug = slugOf( panel );
	const target = document.getElementById( 'aat-summary-' + slug );
	if ( ! target || ! summaries[ slug ] ) {
		return;
	}
	const text = summaries[ slug ]( target.textContent );
	if ( text !== null && text !== target.textContent ) {
		target.textContent = text;
	}
}

/** options.php returns to the form's referer after a save: carry the section there, and in the address bar. */
function rememberSection( slug, inAddressBar ) {
	const withSection = ( href ) => {
		const url = new URL( href, window.location.href );
		url.searchParams.set( 'section', slug );
		return url;
	};
	for ( const input of form.querySelectorAll( 'input[name="_wp_http_referer"]' ) ) {
		const url = withSection( input.value );
		input.value = url.pathname + url.search;
	}
	if ( inAddressBar ) {
		window.history.replaceState( window.history.state, '', withSection( window.location.href ) );
	}
}

function select( slug, { focus = false, inAddressBar = true } = {} ) {
	for ( const tab of tabs ) {
		const on = tab.dataset.section === slug;
		tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		tab.tabIndex = on ? 0 : -1;
		panels.get( tab.dataset.section ).hidden = ! on;
		if ( on && focus ) {
			tab.focus();
		}
	}
	rememberSection( slug, inAddressBar );
}

function onKeydown( event ) {
	const index = tabs.indexOf( event.target );
	if ( index === -1 || event.altKey || event.ctrlKey || event.metaKey ) {
		return;
	}
	const last = tabs.length - 1;
	const next = {
		ArrowDown: index === last ? 0 : index + 1,
		ArrowUp: index === 0 ? last : index - 1,
		Home: 0,
		End: last,
		// A link ignores Space, a tab is activated by it.
		' ': index,
	}[ event.key ];
	if ( next === undefined ) {
		return;
	}
	event.preventDefault();
	select( tabs[ next ].dataset.section, { focus: true } );
}

/** Sidebar tabs, the strip's "Till Anslutning" and any other #aat-section-… link open their section. */
function onLinkClick( event ) {
	const link = event.target.closest( 'a[href^="#' + PANEL_PREFIX + '"]' );
	if ( ! link || event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ) {
		return;
	}
	const slug = link.getAttribute( 'href' ).slice( PANEL_PREFIX.length + 1 );
	if ( panels.has( slug ) ) {
		event.preventDefault();
		select( slug, { focus: true } );
	}
}

/**
 * The browser fires invalid at every invalid field, then shows its message on
 * the first one, which must not sit in a hidden panel. All of that happens in
 * one task, so only the first event of a submit opens its panel.
 */
function onInvalid( event ) {
	if ( revealing ) {
		return;
	}
	revealing = true;
	window.setTimeout( () => {
		revealing = false;
	} );
	const panel = event.target.closest( '.aat-settings-section' );
	if ( panel && panel.hidden && panels.has( slugOf( panel ) ) ) {
		select( slugOf( panel ) );
	}
}

function setUpTabs() {
	if ( ! list || tabs.length === 0 ) {
		return;
	}
	for ( const tab of tabs ) {
		const panel = document.getElementById( PANEL_PREFIX + tab.dataset.section );
		if ( ! panel || ! tab.querySelector( '.aat-nav-summary' ) ) {
			// The markup is out of step with this script: leave the stacked page as it is.
			panels.clear();
			return;
		}
		panels.set( tab.dataset.section, panel );
	}

	list.setAttribute( 'role', 'tablist' );
	list.setAttribute( 'aria-orientation', 'vertical' );
	for ( const item of list.children ) {
		item.setAttribute( 'role', 'presentation' );
	}
	for ( const tab of tabs ) {
		const slug = tab.dataset.section;
		// The tab is named by the section name; the summary is its description.
		// Hiding the summary keeps it out of the name computed from the tab's
		// content (and so out of the panel's name), while aria-describedby still
		// reads it. aria-labelledby on the name instead would fail WCAG 2.5.3
		// (label in name): the visible text also holds the summary.
		tab.querySelector( '.aat-nav-summary' ).setAttribute( 'aria-hidden', 'true' );
		tab.setAttribute( 'role', 'tab' );
		tab.setAttribute( 'aria-controls', PANEL_PREFIX + slug );
		tab.setAttribute( 'aria-describedby', 'aat-summary-' + slug );
		const panel = panels.get( slug );
		panel.setAttribute( 'role', 'tabpanel' );
		panel.setAttribute( 'aria-labelledby', tab.id );
	}

	const initial = root.dataset.activeSection;
	select( panels.has( initial ) ? initial : tabs[ 0 ].dataset.section, { inAddressBar: false } );

	list.addEventListener( 'keydown', onKeydown );
	document.addEventListener( 'click', onLinkClick );
	form.addEventListener( 'invalid', onInvalid, true );
}

function onEdit( event ) {
	dirty = true;
	const panel = event.target.closest( '.aat-settings-section' );
	if ( panel ) {
		refreshSummary( panel );
	}
}

/** While the options form has unsaved edits, the side buttons say so instead of submitting. */
function onSideButton( event ) {
	const button = event.target.closest( 'button[form]' );
	if ( ! dirty || ! button || ! SIDE_FORMS.includes( button.getAttribute( 'form' ) ) ) {
		return;
	}
	event.preventDefault();
	const host = button.closest( 'p' ) || button;
	const previous = host.nextElementSibling;
	if ( previous && previous.classList.contains( 'aat-dirty-note' ) ) {
		// A fresh alert is announced again.
		previous.remove();
	}
	const note = document.createElement( 'p' );
	note.className = 'aat-dirty-note';
	note.setAttribute( 'role', 'alert' );
	note.textContent = i18n.dirty || '';
	host.after( note );
}

if ( root && form ) {
	setUpTabs();
	form.addEventListener( 'input', onEdit );
	form.addEventListener( 'change', onEdit );
	document.addEventListener( 'click', onSideButton );
}
