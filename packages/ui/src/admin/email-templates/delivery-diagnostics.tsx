import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { Badge, Icon } from '@/shared/components';
import { cn } from '@/shared/cn';
import { emailTemplateStrings } from './strings';

export interface DeliveryWarning {
    code: string;
    message: string;
    blocking: boolean;
}

export interface DeliveryState {
    delivery_ready: boolean;
    delivery_warnings: DeliveryWarning[];
}

export interface BlueprintProvenance {
    current_blueprint_version: number | null;
    published_blueprint_version: number | null;
    update_available: boolean;
    unavailable_reason: string | null;
}

export function DeliveryDiagnostics({
    ready,
    warnings,
    compact = false,
}: {
    ready: boolean | undefined;
    warnings: readonly DeliveryWarning[];
    compact?: boolean;
}) {
    const strings = emailTemplateStrings.delivery;
    if (compact) {
        const first = warnings[0];
        return (
            <div className="grid min-w-0 gap-1.5">
                <Badge className="w-fit" variant={ready === true ? 'ok' : ready === false ? 'warn' : 'neutral'}>
                    {ready === true ? strings.compactReady : ready === false ? strings.setupNeeded : strings.compactUnknown}
                </Badge>
                {first !== undefined && (
                    <p className="line-clamp-2 text-[10.5px] text-fg-2" title={first.message}>{first.message}</p>
                )}
            </div>
        );
    }

    return (
        <section aria-label={strings.label} className={cn(
            'grid gap-2 rounded-lg border px-4 py-3 text-[12px]',
            ready === true
                ? 'border-ok bg-ok-soft text-ok-strong'
                : ready === false
                  ? 'border-warn bg-warn-soft text-warn-strong'
                  : 'border-border bg-surface-2 text-fg-2',
        )}>
            <div className="flex items-center gap-2 font-semibold">
                <Icon icon={ready === true ? CheckCircle2 : AlertTriangle} size={16} />
                <span>
                    {ready === true
                        ? strings.ready
                        : ready === false
                          ? strings.setupNeeded
                          : strings.unknown}
                </span>
            </div>
            {warnings.length > 0 && (
                <ul className="grid gap-1.5">
                    {warnings.map((warning) => (
                        <li key={`${warning.code}:${warning.message}`} className="flex items-start gap-2">
                            <span aria-hidden="true">{warning.blocking ? '!' : '•'}</span>
                            <span>{warning.message}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
