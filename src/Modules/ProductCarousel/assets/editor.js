/*
 * Product Carousel — editor registration. No build step: plain JS against the wp.* globals
 * (createElement, not JSX). Settings live in the inspector sidebar; the canvas shows a live
 * ServerSideRender preview of the same PHP render_callback used on the front end.
 */
(function (blocks, element, blockEditor, components, serverSideRender, i18n) {
    var el = element.createElement;
    var Fragment = element.Fragment;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelColorSettings = blockEditor.PanelColorSettings;
    var useBlockProps = blockEditor.useBlockProps;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var RangeControl = components.RangeControl;
    var TextControl = components.TextControl;
    var ToggleControl = components.ToggleControl;
    var ServerSideRender = serverSideRender;

    var SOURCES = [
        { label: __('Newest', 'valolink-woocommerce'), value: 'newest' },
        { label: __('On sale', 'valolink-woocommerce'), value: 'sale' },
        { label: __('Featured', 'valolink-woocommerce'), value: 'featured' },
        { label: __('Best selling', 'valolink-woocommerce'), value: 'best_selling' },
        { label: __('Top rated', 'valolink-woocommerce'), value: 'top_rated' },
        { label: __('Category', 'valolink-woocommerce'), value: 'category' }
    ];

    blocks.registerBlockType('valolink-gp-woo/product-carousel', {
        apiVersion: 3,
        title: __('Product Carousel', 'valolink-woocommerce'),
        description: __('A scrollable row of product cards, using the shared product-card template.', 'valolink-woocommerce'),
        icon: 'slides',
        category: 'woocommerce',
        keywords: [__('products', 'valolink-woocommerce'), __('carousel', 'valolink-woocommerce'), __('slider', 'valolink-woocommerce')],
        supports: { align: ['wide', 'full'], html: false },
        attributes: {
            source: { type: 'string', default: 'newest' },
            category: { type: 'string', default: '' },
            count: { type: 'number', default: 10 },
            columns: { type: 'number', default: 4 },
            cardMinWidth: { type: 'number', default: 220 },
            showArrows: { type: 'boolean', default: true },
            arrowSize: { type: 'number', default: 40 },
            arrowColor: { type: 'string', default: '' },
            arrowBackground: { type: 'string', default: '' },
            arrowPadding: { type: 'number', default: 14 },
            arrowRadius: { type: 'number', default: 50 }
        },

        edit: function (props) {
            var a = props.attributes;
            var set = props.setAttributes;
            var blockProps = useBlockProps();

            var controls = [
                el(SelectControl, {
                    key: 'source',
                    label: __('Source', 'valolink-woocommerce'),
                    value: a.source,
                    options: SOURCES,
                    onChange: function (v) { set({ source: v }); }
                })
            ];

            if (a.source === 'category') {
                controls.push(el(TextControl, {
                    key: 'category',
                    label: __('Category slug', 'valolink-woocommerce'),
                    help: __('The product_cat slug, e.g. "sale-items".', 'valolink-woocommerce'),
                    value: a.category,
                    onChange: function (v) { set({ category: v }); }
                }));
            }

            controls.push(el(RangeControl, {
                key: 'count',
                label: __('Number of products', 'valolink-woocommerce'),
                min: 1, max: 24,
                value: a.count,
                onChange: function (v) { set({ count: v }); }
            }));

            controls.push(el(RangeControl, {
                key: 'columns',
                label: __('Visible columns', 'valolink-woocommerce'),
                help: __('How many cards fit across the track (desktop).', 'valolink-woocommerce'),
                min: 1, max: 6,
                value: a.columns,
                onChange: function (v) { set({ columns: v }); }
            }));

            controls.push(el(RangeControl, {
                key: 'cardMinWidth',
                label: __('Mobile card min width (px)', 'valolink-woocommerce'),
                help: __('Cards never shrink below this on phones — they scroll instead.', 'valolink-woocommerce'),
                min: 120, max: 400, step: 10,
                value: a.cardMinWidth,
                onChange: function (v) { set({ cardMinWidth: v }); }
            }));

            controls.push(el(ToggleControl, {
                key: 'arrows',
                label: __('Show arrows', 'valolink-woocommerce'),
                checked: a.showArrows,
                onChange: function (v) { set({ showArrows: v }); }
            }));

            var arrowPanel = (a.showArrows && PanelColorSettings)
                ? el(PanelColorSettings, {
                    title: __('Arrows', 'valolink-woocommerce'),
                    initialOpen: false,
                    colorSettings: [
                        {
                            value: a.arrowColor,
                            label: __('Arrow color', 'valolink-woocommerce'),
                            onChange: function (v) { set({ arrowColor: v || '' }); }
                        },
                        {
                            value: a.arrowBackground,
                            label: __('Background', 'valolink-woocommerce'),
                            onChange: function (v) { set({ arrowBackground: v || '' }); }
                        }
                    ]
                },
                    el(RangeControl, {
                        key: 'arrowSize',
                        label: __('Arrow size (px)', 'valolink-woocommerce'),
                        min: 16, max: 80,
                        value: a.arrowSize,
                        onChange: function (v) { set({ arrowSize: v }); }
                    }),
                    el(RangeControl, {
                        key: 'arrowPadding',
                        label: __('Padding (px)', 'valolink-woocommerce'),
                        help: __('Space around the arrow — also its click area.', 'valolink-woocommerce'),
                        min: 0, max: 40,
                        value: a.arrowPadding,
                        onChange: function (v) { set({ arrowPadding: v }); }
                    }),
                    el(RangeControl, {
                        key: 'arrowRadius',
                        label: __('Background radius (px)', 'valolink-woocommerce'),
                        min: 0, max: 100,
                        value: a.arrowRadius,
                        onChange: function (v) { set({ arrowRadius: v }); }
                    })
                )
                : null;

            return el(Fragment, {},
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Listing', 'valolink-woocommerce'), initialOpen: true }, controls),
                    arrowPanel
                ),
                el('div', blockProps,
                    el(ServerSideRender, {
                        block: 'valolink-gp-woo/product-carousel',
                        attributes: a
                    })
                )
            );
        },

        save: function () {
            return null; // dynamic block — rendered in PHP
        }
    });
})(
    window.wp.blocks,
    window.wp.element,
    window.wp.blockEditor,
    window.wp.components,
    window.wp.serverSideRender,
    window.wp.i18n
);
