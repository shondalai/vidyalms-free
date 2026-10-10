/* VidyaLMS learner blocks. Copyright 2026 BulaSikku Technologies Private Limited. GPL-3.0-or-later. */
(function (wp) {
    'use strict';
    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    [
        ['catalogue', __('Course catalogue', 'vidyalms'), {
            perPage: { type: 'number', default: 0 }, category: { type: 'number', default: 0 },
            showSearch: { type: 'boolean', default: true }, heading: { type: 'string', default: '' }
        }],
        ['my-learning', __('My learning', 'vidyalms'), { showCertificates: { type: 'boolean', default: true } }],
        ['certificate-verify', __('Certificate verification', 'vidyalms'), {
            code: { type: 'string', default: '' }, showForm: { type: 'boolean', default: true }
        }]
    ].forEach(function (definition) {
        var name = 'vidyalms/' + definition[0];
        wp.blocks.registerBlockType(name, {
            apiVersion: 3, title: definition[1], category: 'widgets', icon: 'welcome-learn-more',
            attributes: definition[2], supports: { html: false, align: ['wide', 'full'] },
            edit: function (props) {
                return el('div', wp.blockEditor.useBlockProps(),
                    el(wp.serverSideRender, { block: name, attributes: props.attributes }));
            },
            save: function () { return null; }
        });
    });
})(window.wp);
