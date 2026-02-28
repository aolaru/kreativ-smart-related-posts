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
                                props.setAttributes({ posts: parseInt(value, 10) || 1 });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Show excerpt (grid/list)', 'kreativ-smart-related-posts'),
                            checked: attributes.excerpt,
                            onChange: function (value) {
                                props.setAttributes({ excerpt: !!value });
                            }
                        })
                    ])
                )
                : null;

            return el('div', { className: 'srp-block-editor' }, [
                controls,
                el(
                    'p',
                    {},
                    __('Kreativ Smart Related Posts preview', 'kreativ-smart-related-posts') + ': ' +
                        attributes.layout +
                        ', ' + __('posts', 'kreativ-smart-related-posts') + ': ' +
                        attributes.posts +
                        (attributes.excerpt ? ' (' + __('with excerpts', 'kreativ-smart-related-posts') + ')' : '')
                )
            ]);
        },
        save: function () {
            return null;
        }
    });
}(window.wp));
