/**
 * Attachment details (media modals, the Media Library, the attachment edit
 * screen): the "Generera alt-text" button, its preview dialog and the
 * "Alt-textkontext" line.
 *
 * Self-contained ES module: no imports, nothing on window. Text from the
 * server or the API goes through textContent and .value only.
 */
const cfg = window.aatMedia || {};
const t = cfg.i18n || {};
const ALT_FIELDS = '#attachment-details-two-column-alt-text, #attachment-details-alt-text, #attachment_alt';
const FEEDBACK_TYPES = [ 'more_descriptive', 'more_concise', 'more_accessible', 'better_seo', 'technical_accuracy' ];
const ERROR_ID = 'aat-preview-error';

/** The open preview: only one exists at a time. */
let current = null;
/** A generate request is running. */
let pending = false;
const announceTimers = new WeakMap();
/** Live regions inserted this moment: text set in the same task may not be announced. */
const freshRegions = new WeakSet();

function sprintf( template, ...args ) {
	let next = 0;
	return String( template ).replace( /%(?:(\d+)\$)?s/g, ( match, pos ) => {
		const value = args[ pos ? Number( pos ) - 1 : next++ ];
		return value === undefined ? '' : String( value );
	} );
}

function el( tag, attrs = {}, text = '' ) {
	const node = document.createElement( tag );
	Object.keys( attrs ).forEach( ( name ) => node.setAttribute( name, attrs[ name ] ) );
	if ( text ) {
		node.textContent = text;
	}
	return node;
}

function button( className, label ) {
	return el( 'button', { type: 'button', class: 'button ' + className }, label );
}

/* ---- Session cache: the unapplied AI text of an attachment ---- */

function cacheKey( id ) {
	return 'aat_alt_' + id;
}

function cacheGet( id ) {
	try {
		return window.sessionStorage.getItem( cacheKey( id ) ) || '';
	} catch ( e ) {
		return '';
	}
}

function cacheSet( id, text ) {
	try {
		window.sessionStorage.setItem( cacheKey( id ), text );
	} catch ( e ) {
		// Storage full or blocked: the next click asks the server again.
	}
}

function cacheDrop( id ) {
	try {
		window.sessionStorage.removeItem( cacheKey( id ) );
	} catch ( e ) {
		// Nothing stored.
	}
}

/* ---- admin-ajax ---- */

function failure( detail, session = false ) {
	const error = new Error( detail || '' );
	error.session = session;
	return error;
}

/**
 * POSTs to admin-ajax and returns the `data` of a successful response. A
 * nonce failure answers -1 with HTTP 403 (and a logged-out user 0), not
 * JSON; both reject with `session` set.
 */
async function post( params ) {
	if ( ! cfg.ajaxUrl ) {
		throw failure( '' );
	}
	let response;
	try {
		response = await fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: new URLSearchParams( params ),
		} );
	} catch ( e ) {
		throw failure( '' );
	}
	let raw = '';
	try {
		raw = await response.text();
	} catch ( e ) {
		raw = '';
	}
	if ( response.status === 403 || raw.trim() === '-1' || raw.trim() === '0' ) {
		throw failure( '', true );
	}
	let data = null;
	try {
		data = JSON.parse( raw );
	} catch ( e ) {
		data = null;
	}
	if ( ! data || data.success !== true ) {
		const message = data && data.data && typeof data.data.message === 'string' ? data.data.message : '';
		throw failure( message );
	}
	return data.data && typeof data.data === 'object' ? data.data : {};
}

function errorText( lead, error ) {
	if ( error && error.session ) {
		return t.session_expired || '';
	}
	return [ lead, error && error.message ].filter( Boolean ).join( ' ' );
}

/* ---- Live regions ---- */

/**
 * Sets a live region's text. A repeated message, or one for a region that was
 * just inserted, is set a moment later so it is announced.
 */
function announce( region, text ) {
	if ( ! region ) {
		return;
	}
	clearTimeout( announceTimers.get( region ) );
	const fresh = freshRegions.has( region );
	freshRegions.delete( region );
	if ( ! text || ( ! fresh && region.textContent !== text ) ) {
		region.textContent = text || '';
		return;
	}
	region.textContent = '';
	announceTimers.set( region, setTimeout( () => {
		region.textContent = text;
	}, 100 ) );
}

/** The status and alert regions after a Generate button, created on first use. */
function regions( trigger ) {
	const parent = trigger && trigger.parentNode;
	if ( ! parent ) {
		return { status: null, error: null };
	}
	const find = ( cls ) => Array.prototype.find.call( parent.children, ( child ) => child.classList.contains( cls ) );
	let status = find( 'aat-generate-status' );
	let error = find( 'aat-generate-error' );
	if ( ! status || ! error ) {
		const spinner = trigger.nextElementSibling && trigger.nextElementSibling.classList.contains( 'aat-generate-spinner' ) ? trigger.nextElementSibling : trigger;
		if ( ! status ) {
			status = el( 'span', { class: 'aat-generate-status', role: 'status' } );
			spinner.after( status );
			freshRegions.add( status );
		}
		if ( ! error ) {
			error = el( 'span', { class: 'aat-generate-error', role: 'alert' } );
			status.after( error );
			freshRegions.add( error );
		}
	}
	return { status, error };
}

/** The Generate button for an attachment: the one clicked, or the one that replaced it. */
function triggerFor( state ) {
	if ( state.trigger && state.trigger.isConnected ) {
		return state.trigger;
	}
	return document.querySelector( '.aat-generate[data-attachment-id="' + state.attachmentId + '"]' );
}

function setBusy( trigger, busy ) {
	trigger.disabled = busy;
	if ( busy ) {
		trigger.setAttribute( 'aria-busy', 'true' );
	} else {
		trigger.removeAttribute( 'aria-busy' );
	}
	const spinner = trigger.nextElementSibling;
	if ( spinner && spinner.classList.contains( 'aat-generate-spinner' ) ) {
		spinner.classList.toggle( 'is-active', busy );
	}
}

function focusLost() {
	return ! document.activeElement || document.activeElement === document.body;
}

/* ---- Generate ---- */

/** The "Alt-textkontext" line already shown next to the button, for a cached preview. */
function contextFor( trigger ) {
	const scope = trigger.closest( '.compat-attachment-fields, form, .media-modal' ) || document;
	const line = scope.querySelector( '.aat-alt-context' );
	return line ? line.textContent.trim() : '';
}

async function generate( trigger ) {
	const attachmentId = Number( trigger.dataset.attachmentId );
	if ( ! ( attachmentId > 0 ) || pending || current || ! cfg.ajaxUrl ) {
		return;
	}
	const live = regions( trigger );
	announce( live.error, '' );

	const cached = cacheGet( attachmentId );
	if ( cached ) {
		announce( live.status, '' );
		openPreview( { attachmentId, text: cached, context: contextFor( trigger ), cached: true, trigger } );
		return;
	}

	pending = true;
	const hadFocus = document.activeElement === trigger;
	setBusy( trigger, true );
	announce( live.status, t.generating );
	try {
		const data = await post( {
			action: 'generate_alt_text_for_attachment',
			attachment_id: String( attachmentId ),
			nonce: cfg.nonce || '',
			preview: 'true',
		} );
		const text = typeof data.alt_text === 'string' ? data.alt_text : '';
		if ( ! text ) {
			throw failure( '' );
		}
		cacheSet( attachmentId, text );
		setBusy( trigger, false );
		announce( live.status, '' );
		openPreview( {
			attachmentId,
			text,
			context: typeof data.context === 'string' ? data.context : '',
			cached: false,
			trigger,
		} );
	} catch ( error ) {
		setBusy( trigger, false );
		announce( live.status, '' );
		announce( live.error, errorText( t.generate_failed, error ) );
		// Disabling the focused button dropped focus to the page.
		if ( hadFocus && trigger.isConnected && focusLost() ) {
			trigger.focus();
		}
	} finally {
		pending = false;
	}
}

/* ---- The preview dialog ---- */

function buildFeedback( state ) {
	const fieldset = el( 'fieldset', { class: 'aat-feedback', 'aria-describedby': 'aat-feedback-note' } );
	fieldset.append(
		el( 'legend', {}, t.feedback_legend ),
		el( 'p', { id: 'aat-feedback-note', class: 'aat-feedback-note' }, t.feedback_note )
	);
	const labels = t.feedback_types || {};
	const types = FEEDBACK_TYPES.concat( cfg.brandTonalityEnabled === true ? [ 'brand_voice' ] : [] );
	const options = el( 'div', { class: 'aat-feedback-options' } );
	types.filter( ( type ) => labels[ type ] ).forEach( ( type ) => {
		const option = button( 'aat-feedback-option', labels[ type ] );
		option.dataset.type = type;
		option.addEventListener( 'click', () => improve( state, type, '', option ) );
		options.append( option );
	} );
	state.custom = el( 'textarea', { id: 'aat-feedback-custom', class: 'aat-feedback-custom', rows: '2' } );
	state.custom.addEventListener( 'input', () => clearFieldError( state, state.custom ) );
	const submit = button( 'aat-feedback-submit', t.custom_submit );
	submit.addEventListener( 'click', () => improve( state, 'custom', state.custom.value, submit ) );
	fieldset.append( options, el( 'label', { for: 'aat-feedback-custom' }, t.custom_label ), state.custom, submit );
	return fieldset;
}

/**
 * Opens the preview. It is created here and removed on close: the media
 * modal hides every body child that exists when it opens, so a dialog made
 * earlier would be hidden too.
 */
function openPreview( { attachmentId, text, context, cached, trigger } ) {
	if ( current || ! document.body ) {
		return null;
	}
	const dialog = el( 'dialog', { class: 'aat-preview-dialog', 'aria-labelledby': 'aat-preview-title' } );
	if ( typeof dialog.showModal !== 'function' ) {
		return null;
	}
	const state = { dialog, attachmentId, trigger, baseline: text, busy: false, custom: null };

	const title = el( 'h2', { id: 'aat-preview-title', class: 'aat-preview-title' }, t.title );
	if ( cached ) {
		title.append( ' ', el( 'span', { class: 'aat-preview-cached' }, t.cached ) );
	}
	dialog.append( title );

	if ( context ) {
		dialog.append( el( 'p', { id: 'aat-preview-context', class: 'aat-preview-context' }, sprintf( t.context, context ) ) );
		dialog.setAttribute( 'aria-describedby', 'aat-preview-context' );
	}

	state.textarea = el( 'textarea', { id: 'aat-preview-text', class: 'aat-preview-text', rows: '4' } );
	state.textarea.addEventListener( 'input', () => clearFieldError( state, state.textarea ) );
	dialog.append( el( 'label', { for: 'aat-preview-text', class: 'aat-preview-label' }, t.text_label ), state.textarea );

	if ( cfg.feedbackEnabled === true ) {
		dialog.append( buildFeedback( state ) );
	}

	state.status = el( 'p', { class: 'aat-preview-status', role: 'status' } );
	state.error = el( 'p', { id: ERROR_ID, class: 'aat-preview-error', role: 'alert' } );
	dialog.append( state.status, state.error );

	const actions = el( 'div', { class: 'aat-preview-actions' } );
	const applyButton = button( 'button-primary aat-preview-apply', t.apply );
	applyButton.addEventListener( 'click', () => apply( state, applyButton ) );
	actions.append( applyButton );
	if ( cached ) {
		const regenerate = button( 'aat-preview-regenerate', t.regenerate );
		regenerate.addEventListener( 'click', () => {
			cacheDrop( attachmentId );
			finish( state );
			const next = triggerFor( state );
			if ( next ) {
				generate( next );
			}
		} );
		actions.append( regenerate );
	}
	const cancel = button( 'aat-preview-cancel', t.cancel );
	cancel.addEventListener( 'click', () => finish( state ) );
	actions.append( cancel );
	dialog.append( actions );

	// Keys stay in the dialog: the media modal behind it closes on Escape and navigates on Alt+arrows.
	dialog.addEventListener( 'keydown', ( event ) => {
		event.stopPropagation();
		if ( event.key === 'Tab' ) {
			wrapTab( dialog, event );
		}
	} );
	// Escape fires cancel, whose default closes the dialog.
	dialog.addEventListener( 'close', () => finish( state ) );

	current = state;
	document.body.append( dialog );
	dialog.showModal();
	// AI output is text: assign it.
	state.textarea.value = text;
	state.textarea.focus();
	state.textarea.select();
	return state;
}

/**
 * Tab from the last control goes to the first, and Shift+Tab the other way:
 * a native modal dialog would otherwise let focus leave the page.
 */
function wrapTab( dialog, event ) {
	const controls = Array.prototype.filter.call(
		dialog.querySelectorAll( 'button, textarea, input, select, a[href], [tabindex]:not([tabindex="-1"])' ),
		( node ) => ! node.disabled && node.getClientRects().length > 0
	);
	if ( ! controls.length ) {
		return;
	}
	const first = controls[ 0 ];
	const last = controls[ controls.length - 1 ];
	if ( event.shiftKey && document.activeElement === first ) {
		event.preventDefault();
		last.focus();
	} else if ( ! event.shiftKey && document.activeElement === last ) {
		event.preventDefault();
		first.focus();
	}
}

/** Closes and removes the dialog, then returns focus to the button or the alt field. */
function finish( state ) {
	if ( current !== state ) {
		return;
	}
	current = null;
	if ( state.dialog.open ) {
		state.dialog.close();
	}
	state.dialog.remove();
	const trigger = triggerFor( state );
	if ( trigger && ! trigger.disabled ) {
		trigger.focus();
		return;
	}
	const field = Array.prototype.find.call( document.querySelectorAll( ALT_FIELDS ), ( node ) => node.getClientRects().length > 0 );
	if ( field ) {
		field.focus();
	}
}

/** Disables the dialog's controls (not "Avbryt") while a request runs. */
function setDialogBusy( state, busy, statusText ) {
	state.busy = busy;
	state.dialog.querySelectorAll( 'button, textarea' ).forEach( ( control ) => {
		if ( ! control.classList.contains( 'aat-preview-cancel' ) ) {
			control.disabled = busy;
		}
	} );
	announce( state.status, statusText );
	if ( busy ) {
		announce( state.error, '' );
	}
}

/** Puts focus back into the dialog when disabling a control dropped it. */
function refocus( state, control ) {
	if ( current === state && control && ! state.dialog.contains( document.activeElement ) ) {
		control.focus();
	}
}

function showError( state, message ) {
	announce( state.status, '' );
	announce( state.error, message );
}

function fieldError( state, field, message ) {
	field.setAttribute( 'aria-invalid', 'true' );
	field.setAttribute( 'aria-describedby', ERROR_ID );
	showError( state, message );
	field.focus();
}

function clearFieldError( state, field ) {
	if ( field && field.hasAttribute( 'aria-invalid' ) ) {
		field.removeAttribute( 'aria-invalid' );
		field.removeAttribute( 'aria-describedby' );
		announce( state.error, '' );
	}
}

/** Writes the saved text into the attachment's alt fields; `change` makes wp.media's model take it. */
function writeAltFields( text ) {
	document.querySelectorAll( ALT_FIELDS ).forEach( ( field ) => {
		field.value = text;
		field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
}

async function apply( state, origin ) {
	if ( state.busy ) {
		return;
	}
	const value = state.textarea.value;
	if ( ! value.trim() ) {
		fieldError( state, state.textarea, t.empty_text );
		return;
	}
	clearFieldError( state, state.textarea );
	setDialogBusy( state, true, t.saving );
	try {
		const data = await post( {
			action: 'apply_alt_text',
			attachment_id: String( state.attachmentId ),
			alt_text: value,
			original_text: state.baseline,
			is_edited: value.trim() !== state.baseline.trim() ? '1' : '0',
			nonce: cfg.nonce || '',
		} );
		// The applied text is no longer an unapplied AI suggestion.
		cacheDrop( state.attachmentId );
		writeAltFields( typeof data.alt_text === 'string' && data.alt_text ? data.alt_text : value );
		finish( state );
		const trigger = triggerFor( state );
		announce( trigger ? regions( trigger ).status : null, t.saved );
	} catch ( error ) {
		const message = errorText( t.apply_failed, error );
		if ( current !== state ) {
			const trigger = triggerFor( state );
			announce( trigger ? regions( trigger ).error : null, message );
			return;
		}
		setDialogBusy( state, false, '' );
		showError( state, message );
		refocus( state, origin );
	}
}

async function improve( state, type, custom, origin ) {
	if ( state.busy ) {
		return;
	}
	if ( type === 'custom' && ! custom.trim() ) {
		fieldError( state, state.custom, t.custom_empty );
		return;
	}
	clearFieldError( state, state.custom );
	clearFieldError( state, state.textarea );
	setDialogBusy( state, true, t.improving );
	try {
		const data = await post( {
			action: 'regenerate_alt_text_with_feedback',
			attachment_id: String( state.attachmentId ),
			nonce: cfg.nonce || '',
			improvement_type: type,
			custom_feedback: custom,
			original_alt_text: state.baseline,
		} );
		const text = typeof data.alt_text === 'string' ? data.alt_text : '';
		if ( ! text ) {
			throw failure( '' );
		}
		// Regenerated text is AI output too: it becomes the baseline Apply compares against.
		state.baseline = text;
		cacheSet( state.attachmentId, text );
		if ( current !== state ) {
			return;
		}
		state.textarea.value = text;
		setDialogBusy( state, false, t.improved );
		refocus( state, state.textarea );
	} catch ( error ) {
		if ( current !== state ) {
			return;
		}
		setDialogBusy( state, false, '' );
		showError( state, errorText( t.improve_failed, error ) );
		refocus( state, origin );
	}
}

/* ---- "Alt-textkontext": placeholders in media frames are filled once ---- */

function fillContext( root ) {
	if ( ! root || typeof root.querySelectorAll !== 'function' ) {
		return;
	}
	root.querySelectorAll( '.aat-alt-context[data-attachment-id]:not([data-loaded])' ).forEach( ( line ) => {
		line.dataset.loaded = '1';
		post( {
			action: 'aat_get_context',
			attachment_id: line.dataset.attachmentId,
			nonce: line.dataset.nonce || cfg.nonce || '',
		} )
			.then( ( data ) => {
				line.textContent = typeof data.text === 'string' ? data.text : '';
			} )
			.catch( () => {
				line.textContent = '';
			} );
	} );
}

const watched = new WeakSet();

/** Watches one media modal's own subtree (never document.body) for re-rendered attachment fields. */
function watch( container ) {
	if ( ! container || typeof container.querySelectorAll !== 'function' ) {
		return;
	}
	fillContext( container );
	if ( watched.has( container ) ) {
		return;
	}
	watched.add( container );
	let timer = 0;
	new MutationObserver( () => {
		clearTimeout( timer );
		timer = setTimeout( () => fillContext( container ), 100 );
	} ).observe( container, { childList: true, subtree: true } );
}

/**
 * Every surface that shows attachment fields in a media frame (the Media
 * Library's details modal, the editors' and the customizer's media modals)
 * opens through wp.media.view.Modal#open, so wrapping it reaches each modal
 * when it opens, including the details modal that the Media Library grid
 * creates and opens in one go.
 */
function hookMediaModals() {
	const media = window.wp && window.wp.media;
	const Modal = media && media.view && media.view.Modal;
	if ( ! Modal || ! Modal.prototype || typeof Modal.prototype.open !== 'function' ) {
		return;
	}
	const original = Modal.prototype.open;
	if ( original.aatWatched ) {
		return;
	}
	const open = function ( ...args ) {
		const result = original.apply( this, args );
		try {
			watch( this.el );
		} catch ( e ) {
			// The context line is optional; the modal must open regardless.
		}
		return result;
	};
	open.aatWatched = true;
	Modal.prototype.open = open;

	// A modal that opened before this module ran.
	const frame = media.frame;
	if ( frame && frame.modal && frame.modal.el && frame.modal.el.isConnected && frame.modal.el.getClientRects().length > 0 ) {
		watch( frame.modal.el );
	}
}

document.addEventListener( 'click', ( event ) => {
	const trigger = event.target instanceof Element ? event.target.closest( '.aat-generate' ) : null;
	if ( ! trigger || trigger.disabled ) {
		return;
	}
	event.preventDefault();
	generate( trigger );
} );

// Server-rendered placeholders (the attachment edit screen renders its line filled).
fillContext( document );
hookMediaModals();
