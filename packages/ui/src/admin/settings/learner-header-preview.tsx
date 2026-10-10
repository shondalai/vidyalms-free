import { translate as uiText } from '@/shared/i18n';
import { useState, type CSSProperties } from 'react';
import { Monitor, Smartphone } from 'lucide-react';
import type { ConfigurationValues } from './fields';

const navigation = ['Explore courses', 'My learning', 'Achievements', 'Your organisations', 'Notification preferences'];

function LogoPreview({ url }: { url: string }) {
    const [failed, setFailed] = useState(false);
    if (failed) return <span className="vy-header-preview-image-error" role="status">{uiText("vidyalms.ui.a6569783e1e9cf5d", "Image unavailable. Check the logo URL.")}</span>;
    return <img src={url} alt={uiText("vidyalms.ui.19583801872ce3e7", "Header logo preview")} referrerPolicy="no-referrer" onError={() => setFailed(true)} />;
}

/** Read-only illustration of the draft; no settings or learner state are written. */
export function LearnerHeaderPreview({ values }: { values: ConfigurationValues }) {
    const [mobile, setMobile] = useState(false);
    const title = values.learner_header_title?.trim() || values.academy_name?.trim() || 'Learning space';
    const tagline = values.learner_header_tagline?.trim() || 'Your learning, your pace.';
    const display = values.learner_header_display ?? 'full';
    const rawLogo = values.learner_header_logo_url?.trim() ?? '';
    let logo = '';
    try {
        const parsed = new URL(rawLogo);
        if (['https:', 'http:'].includes(parsed.protocol) && !parsed.username && !parsed.password) logo = parsed.href;
    } catch { /* An unfinished URL should not start an image request. */ }
    const size = Math.min(80, Math.max(24, Number(values.learner_header_logo_size) || 42));
    return (
        <section className="vy-header-preview" aria-label={uiText("vidyalms.ui.08e8b9a775e8b353", "Learner header preview")}>
            <div className="vy-header-preview-toolbar">
                <div><strong>{uiText("vidyalms.ui.17f546d4e3528f25", "Header preview")}</strong><small>{uiText("vidyalms.ui.f02a794a46ef6585", "Updates as you edit · Save to apply")}</small></div>
                <div role="group" aria-label={uiText("vidyalms.ui.0644dda59d887ed5", "Preview width")}>
                    <button type="button" aria-label={uiText("vidyalms.ui.144adf27f7f36dc3", "Desktop preview")} aria-pressed={!mobile} onClick={() => setMobile(false)}><Monitor size={16} /></button>
                    <button type="button" aria-label={uiText("vidyalms.ui.f4185502ae839151", "Mobile preview")} aria-pressed={mobile} onClick={() => setMobile(true)}><Smartphone size={16} /></button>
                </div>
            </div>
            <div className="vy-header-preview-canvas" data-mobile={mobile}>
                {display === 'hidden' ? <p className="vy-header-preview-hidden">{uiText("vidyalms.ui.f4683a6b4add7237", "The learner header is hidden. Your site template provides navigation.")}</p> : (
                    <div className="vy-header-preview-masthead" data-layout={values.learner_navigation ?? 'top'} data-density={values.learner_header_density ?? 'comfortable'} data-divider={values.learner_header_divider ?? true} style={{ '--preview-logo-size': `${size}px` } as CSSProperties}>
                        {display === 'full' && <div className="vy-header-preview-brand">
                            {(values.learner_header_show_logo ?? true) && (logo ? <LogoPreview key={logo} url={logo} /> : <span className="vy-header-preview-mark" aria-hidden="true">{uiText("vidyalms.ui.de5a6f78116eca62", "V")}</span>)}
                            <div><strong>{title}</strong>{(values.learner_header_show_tagline ?? true) && <small>{tagline}</small>}</div>
                        </div>}
                        <div className="vy-header-preview-navigation" aria-label={uiText("vidyalms.ui.20dd7d4c5cda470c", "Preview navigation labels")}>
                            {navigation.map((label, index) => <span key={label} data-active={index === 2}>{label}</span>)}
                        </div>
                        {values.learner_navigation === 'sidebar' && <div className="vy-header-preview-content" aria-hidden="true"><span /><span /><span /></div>}
                    </div>
                )}
            </div>
            <p className="vy-header-preview-note">{uiText("vidyalms.ui.928f053c3b73189b", "The header adapts to your template’s width and colour mode. Logos and titles link to My learning.")}</p>
        </section>
    );
}
