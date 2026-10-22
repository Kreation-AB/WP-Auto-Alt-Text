/**
 * Block editor: the Image block's "Alt-text med AI" panel. "Generera
 * alt-text" opens a preview of the AI text; nothing is written to the
 * attachment or the block before "Använd" (AI Act brief, option E2).
 *
 * A classic script in an IIFE: its editor.BlockEdit filter is registered
 * before the editor initialises, and nothing is put on window. Text from the
 * server or the API reaches the page only as React props. If a wp.* package
 * is missing, there is no panel and the editor works as without the plugin.
 */
( function () {
	'use strict';

	const wp = window.wp;
	const cfg = window.aatBlockEditor;
	if ( ! wp || ! cfg || ! cfg.ajaxUrl || ! wp.hooks || typeof wp.hooks.addFilter !== 'function' ) {
		return;
	}
	const element = wp.element || {};
	const compose = wp.compose || {};
	const blockEditor = wp.blockEditor || {};
	const components = wp.components || {};
	const { createElement: el, Fragment, useEffect, useRef, useState } = element;
	const { createHigherOrderComponent, useInstanceId } = compose;
	const { InspectorControls } = blockEditor;
	const { Button, Flex, Modal, Notice, PanelBody, Spinner, TextareaControl } = components;
	const required = [ el, Fragment, useEffect, useRef, useState, createHigherOrderComponent, useInstanceId, InspectorControls, Button, Flex, Modal, Notice, PanelBody, TextareaControl ];
	if ( required.some( ( part ) => ! part ) ) {
		return;
	}
	const t = cfg.i18n || {};

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

	/** The editor's snackbar. The text is saved whether or not it can be shown. */
	function notify( message ) {
		try {
			const notices = wp.data && typeof wp.data.dispatch === 'function' ? wp.data.dispatch( 'core/notices' ) : null;
			if ( notices && typeof notices.createNotice === 'function' ) {
				notices.createNotice( 'success', message, { type: 'snackbar', id: 'aat-alt-text-saved' } );
			}
		} catch ( e ) {
			// No notices store: the block shows the saved text anyway.
		}
	}

	/* ---- The panel and its preview ---- */

	/**
	 * The preview state is null while closed, else { id, phase, baseline,
	 * text, error, invalid }. phase: generating → ready ⇄ saving, or
	 * generating → failed.
	 */
	function AltPanel( { attachmentId, setAttributes } ) {
		const [ preview, setPreview ] = useState( null );
		// Bumped on close and unmount, so a late response leaves the preview alone.
		const request = useRef( 0 );
		const body = useRef( null );
		const focusText = useRef( false );
		const statusId = useInstanceId( AltPanel, 'aat-block-preview-status' );
		const errorId = useInstanceId( AltPanel, 'aat-block-preview-error' );
		const phase = preview ? preview.phase : '';

		useEffect( () => () => {
			request.current++;
		}, [] );

		function textarea() {
			return body.current ? body.current.querySelector( 'textarea' ) : null;
		}

		// The generated text arrived: put focus on it, selected, as in the media modal.
		useEffect( () => {
			if ( phase !== 'ready' || ! focusText.current ) {
				return;
			}
			focusText.current = false;
			const area = textarea();
			if ( area ) {
				area.focus();
				area.select();
			}
		}, [ phase ] );

		function generate() {
			if ( preview ) {
				return;
			}
			const mine = ++request.current;
			const id = attachmentId;
			setPreview( { id, phase: 'generating', baseline: '', text: '', error: '', invalid: false } );
			post( {
				action: 'generate_alt_text_for_attachment',
				attachment_id: String( id ),
				nonce: cfg.nonce || '',
				preview: 'true',
			} )
				.then( ( data ) => {
					const text = typeof data.alt_text === 'string' ? data.alt_text : '';
					if ( ! text ) {
						throw failure( '' );
					}
					if ( request.current !== mine ) {
						return;
					}
					focusText.current = true;
					setPreview( ( current ) => current && { ...current, phase: 'ready', baseline: text, text } );
				} )
				.catch( ( error ) => {
					if ( request.current !== mine ) {
						return;
					}
					setPreview( ( current ) => current && { ...current, phase: 'failed', error: errorText( t.generate_failed, error ) } );
				} );
		}

		function close() {
			// A running save finishes first: the block must get what the attachment got.
			if ( ! preview || preview.phase === 'saving' ) {
				return;
			}
			request.current++;
			setPreview( null );
		}

		function apply() {
			if ( ! preview || preview.phase !== 'ready' ) {
				return;
			}
			const { id, baseline, text } = preview;
			if ( ! text.trim() ) {
				setPreview( { ...preview, error: t.empty_text, invalid: true } );
				const area = textarea();
				if ( area ) {
					area.focus();
				}
				return;
			}
			const mine = request.current;
			setPreview( { ...preview, phase: 'saving', error: '', invalid: false } );
			post( {
				action: 'apply_alt_text',
				attachment_id: String( id ),
				alt_text: text,
				original_text: baseline,
				is_edited: text.trim() !== baseline.trim() ? '1' : '0',
				nonce: cfg.nonce || '',
			} )
				.then( ( data ) => {
					// The attachment has the text now, so the block gets it too, even if the panel is gone.
					setAttributes( { alt: typeof data.alt_text === 'string' && data.alt_text ? data.alt_text : text } );
					if ( request.current === mine ) {
						request.current++;
						setPreview( null );
					}
					notify( t.saved );
				} )
				.catch( ( error ) => {
					if ( request.current !== mine ) {
						return;
					}
					setPreview( ( current ) => current && { ...current, phase: 'ready', error: errorText( t.apply_failed, error ) } );
				} );
		}

		function edit( value ) {
			setPreview( ( current ) => {
				if ( ! current ) {
					return current;
				}
				const fixed = current.invalid && value.trim() !== '';
				return { ...current, text: value, invalid: fixed ? false : current.invalid, error: fixed ? '' : current.error };
			} );
		}

		const panel = el(
			InspectorControls,
			null,
			el(
				PanelBody,
				{ title: t.panel, initialOpen: true },
				el( Button, { variant: 'secondary', onClick: generate, 'aria-haspopup': 'dialog', __next40pxDefaultSize: true }, t.generate )
			)
		);

		if ( ! preview ) {
			return panel;
		}

		const saving = phase === 'saving';
		const hasText = phase === 'ready' || saving;
		let status = '';
		if ( phase === 'generating' ) {
			status = t.generating;
		} else if ( saving ) {
			status = t.saving;
		}

		const modal = el(
			Modal,
			{
				title: t.title,
				onRequestClose: close,
				className: 'aat-block-preview',
				size: 'medium',
				shouldCloseOnClickOutside: false,
				aria: { describedby: statusId },
			},
			el(
				'div',
				{ ref: body, className: 'aat-block-preview__body' },
				el(
					Flex,
					{ direction: 'column', align: 'stretch', gap: 4 },
					preview.error ? el( 'div', { id: errorId }, el( Notice, { status: 'error', isDismissible: false }, preview.error ) ) : null,
					hasText
						? el( TextareaControl, {
							label: t.text_label,
							value: preview.text,
							onChange: edit,
							disabled: saving,
							rows: 4,
							'aria-invalid': preview.invalid ? 'true' : undefined,
							'aria-describedby': preview.invalid ? errorId : undefined,
							__nextHasNoMarginBottom: true,
						} )
						: null,
					el(
						Flex,
						{ justify: 'flex-end', className: 'aat-block-preview__actions' },
						el(
							'span',
							{ id: statusId, role: 'status', className: 'aat-block-preview__status' },
							status && Spinner ? el( Spinner ) : null,
							status
						),
						el(
							Button,
							{
								variant: 'tertiary',
								onClick: close,
								// Not `disabled` while saving: the focused control would lose focus.
								'aria-disabled': saving ? 'true' : undefined,
								__next40pxDefaultSize: true,
							},
							t.cancel
						),
						hasText
							? el(
								Button,
								{
									variant: 'primary',
									onClick: apply,
									isBusy: saving,
									'aria-disabled': saving ? 'true' : undefined,
									__next40pxDefaultSize: true,
								},
								t.apply
							)
							: null
					)
				)
			)
		);

		return el( Fragment, null, panel, modal );
	}

	const withAltPanel = createHigherOrderComponent(
		( BlockEdit ) =>
			function AatImageAltEdit( props ) {
				const attributes = props.attributes || {};
				const id = Number( attributes.id );
				// An alt bound to another source (block bindings) is not the block's to set.
				const bound = !! ( attributes.metadata && attributes.metadata.bindings && attributes.metadata.bindings.alt );
				if ( props.name !== 'core/image' || ! props.isSelected || ! ( id > 0 ) || bound ) {
					return el( BlockEdit, props );
				}
				return el(
					Fragment,
					null,
					el( BlockEdit, props ),
					// A replaced image starts a fresh panel.
					el( AltPanel, { key: id, attachmentId: id, setAttributes: props.setAttributes } )
				);
			},
		'withAatImageAlt'
	);

	wp.hooks.addFilter( 'editor.BlockEdit', 'wp-auto-alt-text/image-alt', withAltPanel );
}() );
