(function (wp) {
    if (!wp || !wp.blocks) {
        return;
    }

    const __ = wp.i18n.__;
    const registerBlockType = wp.blocks.registerBlockType;
    const el = wp.element.createElement;
    const InspectorControls = wp.blockEditor
        ? wp.blockEditor.InspectorControls
        : (wp.editor ? wp.editor.InspectorControls : null);
    const PanelBody = wp.components.PanelBody;
    const SelectControl = wp.components.SelectControl;
    const TextControl = wp.components.TextControl;
    const ToggleControl = wp.components.ToggleControl;

    const previewPosts = [
        __('How to plan a stronger content cluster', 'kreativ-smart-related-posts'),
        __('Internal linking checklist for publishers', 'kreativ-smart-related-posts'),
        __('Choosing topics that keep readers moving', 'kreativ-smart-related-posts')
    ];

    function previewMeta(attributes) {
        const parts = [];

        if (attributes.date) {
            parts.push(__('May 5, 2026', 'kreativ-smart-related-posts'));
        }

        if (attributes.category) {
            parts.push(__('Strategy', 'kreativ-smart-related-posts'));
        }

        return parts.length
            ? el('div', { className: 'srp-meta' }, parts.join(' / '))
            : null;
    }

    function previewImage(index) {
        return el('div', { className: 'srp-thumb srp-thumb-preview' }, el('span', {}, index + 1));
    }

    function previewCard(title, index, attributes) {
        return el(
            'li',
            { className: 'srp-card', key: title },
            el('a', { href: '#', onClick: function (event) { event.preventDefault(); } }, [
                attributes.thumbnail ? previewImage(index) : null,
                el('div', { className: 'srp-content' }, [
                    el('h4', { className: 'srp-title' }, title),
                    previewMeta(attributes),
                    attributes.excerpt ? el('p', { className: 'srp-excerpt' }, __('Short preview excerpt for this related article.', 'kreativ-smart-related-posts')) : null
                ])
            ])
        );
    }

    function preview(attributes) {
        if (attributes.layout === 'list') {
            return el(
                'ul',
                { className: 'srp-list' },
                previewPosts.map(function (title) {
                    return el('li', { key: title }, [
                        el('a', { href: '#', onClick: function (event) { event.preventDefault(); } }, el('strong', {}, title)),
                        previewMeta(attributes),
                        attributes.excerpt ? el('div', { className: 'srp-excerpt' }, __('Short preview excerpt for this related article.', 'kreativ-smart-related-posts')) : null
                    ]);
                })
            );
        }

        if (attributes.layout === 'minimal') {
            return el(
                'div',
                { className: 'srp-minimal' },
                previewPosts.map(function (title) {
                    return el('a', { className: 'srp-pill', href: '#', key: title, onClick: function (event) { event.preventDefault(); } }, title);
                })
            );
        }

        return el(
            'ul',
            { className: 'srp-grid' },
            previewPosts.map(function (title, index) {
                return previewCard(title, index, attributes);
            })
        );
    }

    registerBlockType('kreativ-smart-related-posts/related-posts', {
        title: __('Kreativ Smart Related Posts', 'kreativ-smart-related-posts'),
        icon: 'admin-post',
        category: 'widgets',
        attributes: {
            layout: {
                type: 'string',
                default: 'grid'
            },
            posts: {
                type: 'number',
                default: 6
            },
            excerpt: {
                type: 'boolean',
                default: false
            },
            thumbnail: {
                type: 'boolean',
                default: true
            },
            target: {
                type: 'string',
                default: 'same'
            },
            date: {
                type: 'boolean',
                default: false
            },
            category: {
                type: 'boolean',
                default: false
            },
            heading: {
                type: 'string',
                default: 'h3'
            }
        },
        edit: function (props) {
            const attributes = props.attributes;
            const controls = InspectorControls
                ? el(
                    InspectorControls,
                    {},
                    el(PanelBody, { title: __('Settings', 'kreativ-smart-related-posts') }, [
                        el(SelectControl, {
                            label: __('Layout', 'kreativ-smart-related-posts'),
                            value: attributes.layout,
                            options: [
                                { label: __('Grid', 'kreativ-smart-related-posts'), value: 'grid' },
                                { label: __('List', 'kreativ-smart-related-posts'), value: 'list' },
                                { label: __('Minimal', 'kreativ-smart-related-posts'), value: 'minimal' }
                            ],
                            onChange: function (value) {
                                props.setAttributes({ layout: value });
                            }
                        }),
                        el(TextControl, {
                            label: __('Number of posts', 'kreativ-smart-related-posts'),
                            type: 'number',
                            value: attributes.posts,
                            onChange: function (value) {
                                props.setAttributes({ posts: Math.max(1, parseInt(value, 10) || 1) });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show thumbnails', 'kreativ-smart-related-posts'),
                            checked: attributes.thumbnail,
                            onChange: function (value) {
                                props.setAttributes({ thumbnail: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show excerpt (grid/list)', 'kreativ-smart-related-posts'),
                            checked: attributes.excerpt,
                            onChange: function (value) {
                                props.setAttributes({ excerpt: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show date', 'kreativ-smart-related-posts'),
                            checked: attributes.date,
                            onChange: function (value) {
                                props.setAttributes({ date: !!value });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show category', 'kreativ-smart-related-posts'),
                            checked: attributes.category,
                            onChange: function (value) {
                                props.setAttributes({ category: !!value });
                            }
                        }),
                        el(SelectControl, {
                            label: __('Link target', 'kreativ-smart-related-posts'),
                            value: attributes.target,
                            options: [
                                { label: __('Same tab', 'kreativ-smart-related-posts'), value: 'same' },
                                { label: __('New tab', 'kreativ-smart-related-posts'), value: 'new' }
                            ],
                            onChange: function (value) {
                                props.setAttributes({ target: value });
                            }
                        }),
                        el(SelectControl, {
                            label: __('Heading tag', 'kreativ-smart-related-posts'),
                            value: attributes.heading,
                            options: [
                                { label: 'H2', value: 'h2' },
                                { label: 'H3', value: 'h3' },
                                { label: 'H4', value: 'h4' }
                            ],
                            onChange: function (value) {
                                props.setAttributes({ heading: value });
                            }
                        })
                    ])
                )
                : null;

            return el('div', { className: 'srp-block-editor srp-related' }, [
                controls,
                el('style', {}, '.srp-block-editor{--srp-accent:#00C2FF;--srp-radius:14px;--srp-cols:3;--srp-ratio:16 / 9}.srp-block-editor .srp-grid{display:grid;gap:12px;grid-template-columns:repeat(3,minmax(0,1fr));list-style:none;padding-left:0;margin:0}.srp-block-editor .srp-card{border:1px solid #eee;border-radius:14px;overflow:hidden;background:#fff}.srp-block-editor a{color:inherit;text-decoration:none}.srp-block-editor .srp-thumb{aspect-ratio:16 / 9;background:#eef6f8;display:flex;align-items:center;justify-content:center;color:#2271b1;font-weight:700}.srp-block-editor .srp-content{padding:10px}.srp-block-editor .srp-title{font-size:.95rem;font-weight:600;margin:0 0 .35rem;line-height:1.3}.srp-block-editor .srp-meta{color:#666;font-size:.78rem;margin:0 0 .35rem}.srp-block-editor .srp-excerpt{color:#666;font-size:.88em;line-height:1.4;margin:0}.srp-block-editor .srp-list{list-style:none;padding-left:0;margin:0}.srp-block-editor .srp-list li{margin:.5rem 0}.srp-block-editor .srp-minimal{display:flex;flex-wrap:wrap;gap:.4rem}.srp-block-editor .srp-pill{padding:.2rem .45rem;border-radius:999px;background:#f5f5f5}.srp-block-editor .srp-titlebar{display:flex;align-items:center;gap:.45rem;margin-bottom:.6rem}.srp-block-editor .srp-dot{width:.55rem;height:.55rem;border-radius:999px;background:#00C2FF;display:inline-block}.srp-block-editor .srp-titletext{font-size:1rem;font-weight:700;letter-spacing:0;margin:0}@media (max-width:782px){.srp-block-editor .srp-grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}}'),
                el('div', { className: 'srp-titlebar' }, [
                    el('span', { className: 'srp-dot' }),
                    el('div', { className: 'srp-titletext' }, __('Related Posts', 'kreativ-smart-related-posts'))
                ]),
                preview(attributes)
            ]);
        },
        save: function () {
            return null;
        }
    });
}(window.wp));
