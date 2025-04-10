( function( blocks, editor, element, components, escapeHtml ) {
    const { createElement: el, useState } = element;
    const { SelectControl } = components;
    const { escapeAttribute } = escapeHtml;

    blocks.registerBlockType( 'url2cite/citation-block', {
        title: 'URL2Cite Citation',
        icon: 'book-alt',
        category: 'widgets',
        attributes: {
            url: { type: 'string' },
            citation: { type: 'string' },
            style: { type: 'string', default: url2citeSettings.defaultStyle }
        },

        edit: function( props ) {
            const [loading, setLoading] = useState(false);
            const styles = Object.entries(url2citeSettings.styles).map(([value, label]) => ({
                label,
                value
            }));

            const onChangeUrl = (newUrl) => {
                props.setAttributes({ url: newUrl, citation: '' });
                if (!newUrl) return;

                setLoading(true);
                wp.apiFetch({
                    path: `/wp-json/url2cite/v1/cite?url=${encodeURIComponent(newUrl)}&style=${encodeURIComponent(props.attributes.style)}`
                }).then(result => {
                    props.setAttributes({ citation: result.citation });
                    setLoading(false);
                }).catch(error => {
                    props.setAttributes({ citation: `Error: ${error.message || 'Failed to fetch citation'}` });
                    setLoading(false);
                });
            };

            const onChangeStyle = (newStyle) => {
                props.setAttributes({ style: newStyle });
                if (props.attributes.url) onChangeUrl(props.attributes.url);
            };

            return el('div', { className: props.className },
                el('div', { style: { marginBottom: '10px' } },
                    el('input', {
                        type: 'text',
                        placeholder: 'Enter URL for citation...',
                        'aria-label': 'Enter URL for citation',
                        value: props.attributes.url,
                        onChange: (e) => onChangeUrl(e.target.value),
                        style: { width: '100%' },
                        disabled: loading
                    })
                ),
                el('div', { style: { marginBottom: '10px' } },
                    el(SelectControl, {
                        label: 'Citation Style',
                        value: props.attributes.style,
                        options: styles,
                        onChange: onChangeStyle
                    })
                ),
                loading ? el('div', { 
                    style: { 
                        display: 'flex', 
                        alignItems: 'center', 
                        gap: '8px', 
                        color: '#757575' 
                    } 
                },
                    el('span', {}, 'Loading...'),
                    el('span', { className: 'components-spinner' })
                ) : el('div', { 
                    style: { 
                        fontStyle: 'italic',
                        padding: '10px',
                        backgroundColor: '#f5f5f5',
                        borderRadius: '4px'
                    } 
                }, props.attributes.citation)
            );
        },

        save: function( props ) {
            return props.attributes.url 
                ? el('span', {},
                    `[url2cite url="${escapeAttribute(props.attributes.url)}" style="${escapeAttribute(props.attributes.style)}"]`
                  )
                : null;
        }
    } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.element, window.wp.components, window.wp.escapeHtml );