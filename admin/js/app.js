initBlockEditorImageAltTextGenerator();

/**
 * Sends an AJAX request to the plugin endpoints and returns the payload
 * (the block editor panel below).
 *
 * @param {Object} params Request parameters.
 * @returns {Promise<Object>} The parsed payload.
 */
async function sendAutoAltTextRequest(params) {
    const response = await fetch(autoAltTextData.ajaxurl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(params),
    });

    const data = await response.json();

    if (!response.ok || !data.success) {
        throw new Error(data?.data?.message || data?.message || 'Unexpected error');
    }

    return data.data || data;
}

/**
 * Requests generated alt text for a single attachment without immediately applying it.
 *
 * @param {number|string} attachmentId The attachment ID.
 * @param {boolean} previewMode Whether to generate in preview mode.
 * @returns {Promise<Object>} The generated alt text payload.
 */
function requestGeneratedAltText(attachmentId, previewMode = true) {
    return sendAutoAltTextRequest({
        action: 'generate_alt_text_for_attachment',
        attachment_id: attachmentId,
        nonce: autoAltTextData.actionNonce,
        preview: previewMode ? 'true' : 'false',
    });
}

/**
 * Applies generated alt text to the attachment metadata.
 *
 * @param {number|string} attachmentId The attachment ID.
 * @param {string} altText The alt text to apply.
 * @returns {Promise<Object>} The updated attachment payload.
 */
function applyGeneratedAltText(attachmentId, altText) {
    return sendAutoAltTextRequest({
        action: 'apply_alt_text',
        attachment_id: attachmentId,
        alt_text: altText,
        original_text: altText,
        is_edited: '0',
        nonce: autoAltTextData.actionNonce,
    });
}

/**
 * Registers a Gutenberg inspector control for the core/image block.
 */
function initBlockEditorImageAltTextGenerator() {
    if (
        typeof window.wp === 'undefined' ||
        !window.wp.hooks ||
        !window.wp.element ||
        !window.wp.blockEditor ||
        !window.wp.components
    ) {
        return;
    }

    if (window.autoAltTextBlockEditorInitialized) {
        return;
    }

    window.autoAltTextBlockEditorInitialized = true;

    const { addFilter } = window.wp.hooks;
    const { createElement, Fragment, useState } = window.wp.element;
    const { InspectorControls } = window.wp.blockEditor;
    const { PanelBody, Button, Notice } = window.wp.components;

    const withImageAltTextControls = (BlockEdit) => {
        return function ImageAltTextControls(props) {
            const [isGenerating, setIsGenerating] = useState(false);
            const [notice, setNotice] = useState(null);

            if (props.name !== 'core/image' || !props.isSelected) {
                return createElement(BlockEdit, props);
            }

            const attachmentId = props.attributes?.id;

            const handleGenerate = async () => {
                if (!attachmentId || isGenerating) {
                    return;
                }

                setIsGenerating(true);
                setNotice(null);

                try {
                    const result = await requestGeneratedAltText(attachmentId, true);

                    if (!result.alt_text) {
                        throw new Error('No alt text was generated.');
                    }

                    await applyGeneratedAltText(attachmentId, result.alt_text);
                    props.setAttributes({ alt: result.alt_text });
                    sessionStorage.setItem(`alt_text_${attachmentId}`, result.alt_text);

                    setNotice({
                        status: 'success',
                        message: 'Alt text generated and inserted into the block. Review the Alt text field before saving.',
                    });
                } catch (error) {
                    setNotice({
                        status: 'error',
                        message: error.message || 'Failed to generate alt text.',
                    });
                } finally {
                    setIsGenerating(false);
                }
            };

            return createElement(
                Fragment,
                null,
                createElement(BlockEdit, props),
                createElement(
                    InspectorControls,
                    null,
                    createElement(
                        PanelBody,
                        {
                            title: 'Auto Alt Text',
                            initialOpen: true,
                        },
                        !attachmentId
                            ? createElement(
                                Notice,
                                {
                                    status: 'warning',
                                    isDismissible: false,
                                },
                                'Select an image from the Media Library to generate alt text.'
                            )
                            : createElement(
                                Fragment,
                                null,
                                createElement(
                                    'p',
                                    null,
                                    'Generate alt text for this image and insert it into the block.'
                                ),
                                notice
                                    ? createElement(
                                        Notice,
                                        {
                                            status: notice.status,
                                            isDismissible: false,
                                        },
                                        notice.message
                                    )
                                    : null,
                                createElement(
                                    Button,
                                    {
                                        variant: 'secondary',
                                        onClick: handleGenerate,
                                        isBusy: isGenerating,
                                        disabled: isGenerating,
                                    },
                                    isGenerating ? 'Generating alt text...' : 'Generate Alternative Text with AI'
                                )
                            )
                    )
                )
            );
        };
    };

    addFilter(
        'editor.BlockEdit',
        'wp-auto-alt-text/gutenberg-image-generator',
        withImageAltTextControls
    );
}
