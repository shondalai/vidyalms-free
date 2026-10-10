import { z } from 'zod';
import { translate as uiText } from './i18n';

/** Host-owned destinations; the UI never guesses CMS or shop URLs. */
export const shopSetupSchema = z.object({
    name: z.string(),
    installed: z.boolean(),
    integrationEnabled: z.boolean(),
    productsUrl: z.string().nullable(),
    settingsUrl: z.string().nullable(),
    mappingUrl: z.string().nullable(),
    mappingApiUrl: z.string().nullable().optional(),
});

export function ShopSetup({ shop }: { shop?: z.infer<typeof shopSetupSchema> }) {
    return (
        <div className="grid gap-3 text-sm">
            <p>{uiText('vidyalms.ui.shopResponsibility', 'Set prices, payment methods, subscriptions, invoices and refunds in your shop. VidyaLMS manages course access and learning progress.')}</p>
            {shop ? (
                <>
                    <p role="status">
                        <strong>{shop.name}</strong>{' · '}
                        {!shop.installed
                            ? uiText('vidyalms.ui.shopMissing', 'Install and activate the shop to sell courses.')
                            : !shop.integrationEnabled
                              ? uiText('vidyalms.ui.shopIntegrationMissing', 'Shop available. Install or enable the VidyaLMS integration to connect courses.')
                              : uiText('vidyalms.ui.shopIntegrationAvailable', 'Integration enabled. Configure product mappings and verify a purchase before selling.')}
                    </p>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {[
                            [shop.productsUrl, uiText('vidyalms.ui.shopProductsPrices', 'Products & prices'), uiText('vidyalms.ui.shopProductsPricesHint', 'Create a product and set its price or recurring billing terms.')],
                            [shop.mappingUrl, uiText('vidyalms.ui.shopConnectCourses', 'Connect products to courses'), uiText('vidyalms.ui.shopConnectCoursesHint', 'Choose which paid courses each purchased product unlocks.')],
                            [shop.settingsUrl, uiText('vidyalms.ui.shopPaymentSettings', 'Shop payment settings'), uiText('vidyalms.ui.shopPaymentSettingsHint', 'Configure payment methods, tax, invoices and refunds in the shop.')],
                        ].map(([url, title, hint]) => (
                            <div key={title} className="rounded-lg border border-border p-4">
                                {url ? <a className="font-semibold text-accent underline underline-offset-4" href={url}>{title}</a> : <strong>{title}</strong>}
                                <p className="mt-2 text-xs text-fg-2">{hint}</p>
                            </div>
                        ))}
                    </div>
                </>
            ) : (
                <p>{uiText('vidyalms.ui.shopSetupAdminHelp', 'Ask your administrator to configure EasyCommerce on Joomla or WooCommerce on WordPress and link its products to your paid courses.')}</p>
            )}
        </div>
    );
}
