import { translate as uiText } from '@/shared/i18n';
import { Award, Check, Palette } from 'lucide-react';
import { Button } from '@/shared/components';
import { certificateWorkspaceStrings as s } from './workspace-strings';

export function CertificateHero({ onCustomize }: { onCustomize: () => void }) {
    return (
        <section className="vy-cert-hero" aria-label={s.studio}>
            <div>
                <span className="vy-cert-eyebrow">
                    <Palette size={15} aria-hidden="true" />
                    {s.studio}
                </span>
                <h2>{s.heroTitle}</h2>
                <p>{s.heroBody}</p>
                <Button variant="primary" onClick={onCustomize}>
                    {s.customize}
                </Button>
                <ul>
                    {s.heroFeatures.map((feature) => (
                        <li key={feature}>
                            <Check size={14} aria-hidden="true" />
                            {feature}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="vy-cert-illustration" aria-hidden="true">
                <div className="vy-cert-paper">
                    <Award size={32} />
                    <span>{uiText("vidyalms.ui.82a24d3c9a87bc6f", "Certificate of achievement")}</span>
                    <strong>{uiText("vidyalms.ui.102e9b759d128b05", "A milestone worth celebrating.")}</strong>
                    <i />
                    <div className="vy-cert-signatures">
                        <i />
                        <i />
                    </div>
                </div>
            </div>
        </section>
    );
}

export function CredentialIntro({ title, description }: { title: string; description: string }) {
    return (
        <div className="vy-cert-intro">
            <h2>{title}</h2>
            <p>{description}</p>
        </div>
    );
}
