import { describe, expectTypeOf, it } from 'vitest';
import type { components, paths } from '../src/index.js';

describe('the external commerce contract', () => {
    it('exposes no native commerce paths or billing response types', () => {
        type NativePath = Extract<keyof paths,
            `/commerce/${string}` | `/manage/commerce/${string}` |
            '/manage/orders' | `/manage/orders/${string}` | `/manage/invoices/${string}`>;
        type BillingSchema = Extract<keyof components['schemas'],
            'OrderDetail' | 'InvoiceDocument' | 'Subscription' | 'CheckoutSession' | 'CommerceWebhookAck'>;
        expectTypeOf<NativePath>().toEqualTypeOf<never>();
        expectTypeOf<BillingSchema>().toEqualTypeOf<never>();
    });

    it('retains purchase links, progress and general notification feedback', () => {
        expectTypeOf<components['schemas']['CourseDetail']['purchase_url']>().toEqualTypeOf<string | null>();
        expectTypeOf<paths['/me/progress']['get']>().toBeObject();
        expectTypeOf<paths['/notifications/feedback/{channelKey}']['post']>().toBeObject();
        type NetworkSettings = components['schemas']['RuntimeConfigurationValues'];
        expectTypeOf<NetworkSettings['integrations_http_allowlist']>().toEqualTypeOf<string | undefined>();
        expectTypeOf<NetworkSettings['integrations_http_timeout']>().toEqualTypeOf<number | undefined>();
        expectTypeOf<Extract<keyof NetworkSettings, `commerce_${string}`>>().toEqualTypeOf<never>();
    });

    it('keeps access evidence to raw learning facts without provider formatting', () => {
        type Evidence = components['schemas']['AccessEvidence'];
        type Read = paths['/manage/learners/{user_id}/courses/{course_id}/access-evidence']['get'];
        expectTypeOf<Extract<keyof Evidence, 'packet'>>().toEqualTypeOf<never>();
        expectTypeOf<Read['parameters']['query']>().toEqualTypeOf<undefined>();
    });

    it('keeps financial snapshots out of seat-pool reads and writes', () => {
        type FinancialField = 'unit_amount_minor' | 'currency';
        type Pool = components['schemas']['OrganisationSeatPool'];
        type Create = components['schemas']['OrganisationSeatPoolRequest'];
        type Update = components['schemas']['OrganisationSeatPoolUpdateRequest'];
        expectTypeOf<Extract<keyof Pool, FinancialField>>().toEqualTypeOf<never>();
        expectTypeOf<Extract<keyof Create, FinancialField>>().toEqualTypeOf<never>();
        expectTypeOf<Extract<keyof Update, FinancialField>>().toEqualTypeOf<never>();
    });
});
