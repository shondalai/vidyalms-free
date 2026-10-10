import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import { CertificateDesignerRegistry } from './registry';
import { certificateDesignerRegistry, installCertificateDesignerBridge } from './bridge';
import {
    CERTIFICATE_DESIGNER_CONTRACT,
    CertificateDesignerRegistrationError,
    isCertificateDesignerSlot,
} from './contract';
import type {
    CertificatePageDesignerProps,
    CertificateTemplateDetail,
    CertificateTemplatePage,
} from './contract';

/*
 * The fifth published registry. A miss is a normal path here: the free
 * template editor draws the same aggregate as a form, so nothing below
 * asserts that an unregistered slot is an error.
 */

function designer(label: string) {
    return {
        version: CERTIFICATE_DESIGNER_CONTRACT,
        Designer: (props: CertificatePageDesignerProps) => (
            <p>
                {label}: {props.page.elements.length}
            </p>
        ),
    };
}

const page: CertificateTemplatePage = {
    id: 5,
    template_id: 4,
    sequence: 1,
    width: 297,
    height: 210,
    orientation: 'landscape',
    margin_top: 10,
    margin_right: 10,
    margin_bottom: 10,
    margin_left: 10,
    background: null,
    elements: [
        {
            id: 9,
            page_id: 5,
            sequence: 1,
            element: 'learner_name',
            name: null,
            data: {},
            data_version: 1,
            current_version: 1,
            available: true,
            posx: 20,
            posy: 40,
            width: null,
            height: null,
            refpoint: 'top_left',
            alignment: 'centre',
        },
    ],
};

const template: CertificateTemplateDetail = {
    id: 4,
    template_key: 'standard',
    name: 'Standard',
    renderer: 'elements',
    format: 'elements',
    achievement_type: 'CertificateOfCompletion',
    revision: 1,
    schema_version: 1,
    locale: null,
    state: 'published',
    is_default: true,
    expiry_mode: 'never',
    expiry_days: null,
    expiry_at: null,
    locked_at: null,
    page_count: 1,
    element_count: 1,
    body: null,
    pages: [page],
    findings: [],
};

describe('certificate designer registry', () => {
    it('registers a designer for the slot the admin offers', () => {
        const registry = new CertificateDesignerRegistry();
        registry.register('page_designer', designer('canvas'));

        expect(registry.has('page_designer')).toBe(true);
        expect(registry.slots()).toEqual(['page_designer']);
    });

    it('refuses a slot that is already taken, so two packages fail loudly rather than one winning', () => {
        const registry = new CertificateDesignerRegistry();
        registry.register('page_designer', designer('first'));

        expect(() => registry.register('page_designer', designer('second'))).toThrow(
            CertificateDesignerRegistrationError,
        );
    });

    it('hands the displaced module back from replace, so an override decorates', () => {
        const registry = new CertificateDesignerRegistry();
        const first = designer('first');
        registry.register('page_designer', first);

        expect(registry.replace('page_designer', designer('second'))).toBe(first);
    });

    it('refuses replace on a slot nothing registered', () => {
        const registry = new CertificateDesignerRegistry();

        expect(() => registry.replace('page_designer', designer('only'))).toThrow(
            CertificateDesignerRegistrationError,
        );
    });

    it('refuses a slot this bundle does not offer rather than storing it unreachable', () => {
        const registry = new CertificateDesignerRegistry();

        expect(() =>
            registry.register('x_acme_canvas' as 'page_designer', designer('contributed')),
        ).toThrow(CertificateDesignerRegistrationError);
    });

    it('refuses a designer from a future contract instead of letting it fail at render', () => {
        const registry = new CertificateDesignerRegistry();

        expect(() =>
            registry.register('page_designer', {
                ...designer('future'),
                version: CERTIFICATE_DESIGNER_CONTRACT + 1,
            }),
        ).toThrow(/newer than the VidyaLMS assets/);
    });

    it('refuses a designer built against another React major, because a second React breaks hooks silently', () => {
        const registry = new CertificateDesignerRegistry();

        expect(() =>
            registry.register('page_designer', { ...designer('other'), reactMajor: 18 }),
        ).toThrow(/React 18/);
    });

    it('tells a subscriber about a designer that registered late', () => {
        const registry = new CertificateDesignerRegistry();
        const seen: string[] = [];
        registry.subscribe((event) => seen.push(`${event.kind}:${event.slot}`));

        registry.register('page_designer', designer('late'));
        registry.replace('page_designer', designer('later'));

        expect(seen).toEqual(['register:page_designer', 'replace:page_designer']);
    });

    it('renders the registered designer with the props the screen hands it', () => {
        const registry = new CertificateDesignerRegistry();
        registry.register('page_designer', designer('canvas'));
        const module = registry.get('page_designer');
        if (module === null) {
            throw new Error('The registry answered null for a slot it was just given.');
        }
        const Designer = module.Designer;

        render(
            <Designer
                template={template}
                page={page}
                elementTypes={[]}
                tokens={[]}
                selectedElementId={null}
                onSelect={() => undefined}
                onPlace={() => undefined}
                onReorder={() => undefined}
                onAdd={() => undefined}
                onRemove={() => undefined}
                busy={false}
                readOnly={false}
                locale="en-GB"
                controlId={(local) => `t4-${local}`}
                announce={() => undefined}
            />,
        );

        expect(screen.getByText('canvas: 1')).toBeInTheDocument();
    });

    it('answers null for a slot nothing registered, which is what keeps the free build byte identical', () => {
        expect(new CertificateDesignerRegistry().get('page_designer')).toBeNull();
    });

    it('names the slot the admin offers', () => {
        expect(isCertificateDesignerSlot('page_designer')).toBe(true);
        expect(isCertificateDesignerSlot('x_acme_canvas')).toBe(false);
    });
});

describe('the published bridge', () => {
    it('installs one registry and hands the same one back', () => {
        const first = installCertificateDesignerBridge();
        const second = certificateDesignerRegistry();

        expect(second).toBe(first);
        expect(first.contract).toBe(CERTIFICATE_DESIGNER_CONTRACT);
    });

    it('lives beside the question type registry on the one published global', () => {
        installCertificateDesignerBridge();

        const global = window.vidyalms as Record<string, unknown> | undefined;
        expect(global?.questionTypes).toBeDefined();
        expect(global?.certificateDesigners).toBe(certificateDesignerRegistry());
    });
});
