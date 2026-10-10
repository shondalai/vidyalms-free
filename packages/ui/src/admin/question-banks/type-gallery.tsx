import { useState } from 'react';
import {
    ArrowUpRight,
    CheckSquare2,
    Keyboard,
    Link2,
    Image,
    Hash,
    Upload,
    Layers,
    Play,
    CircleHelp,
    type LucideIcon,
} from 'lucide-react';
import { Icon } from '@/shared/components';
import { FilterSearch } from '../components/filter-toolbar';
import { pickerGroups, type PickerEntry } from '@/instructor/lib/question-picker';
import { instructorStrings } from '@/instructor/strings';
import type { useQuestionTypeCatalogue } from '@/question-types';
import { bankStrings as strings } from './strings';

const familyIcons: Record<string, LucideIcon> = {
    selection: CheckSquare2,
    text_entry: Keyboard,
    association: Link2,
    graphic: Image,
    numeric: Hash,
    artifact: Upload,
    composite: Layers,
    control: Play,
};

export function QuestionTypeGallery({
    catalogue,
    onChoose,
}: {
    catalogue: NonNullable<ReturnType<typeof useQuestionTypeCatalogue>['data']>;
    onChoose: (entry: PickerEntry) => void;
}) {
    const [search, setSearch] = useState('');
    const [family, setFamily] = useState('all');
    const groups = pickerGroups(catalogue.types, catalogue.presets);
    const label = (key: string) => instructorStrings.question.families[key] ?? key;
    const visible = groups
        .filter((group) => family === 'all' || family === group.family)
        .map((group) => ({
            ...group,
            entries: group.entries.filter((entry) =>
                `${entry.label} ${label(entry.family)}`.toLowerCase().includes(search.trim().toLowerCase()),
            ),
        }))
        .filter((group) => group.entries.length > 0);
    return (
        <section className="vy-bank-type-gallery" aria-label={strings.studio.typeGallery}>
            <header>
                <div>
                    <h2>{strings.studio.typeGallery}</h2>
                    <p>{strings.studio.typeHint}</p>
                </div>
                <FilterSearch
                    label={strings.studio.searchTypes}
                    placeholder={strings.studio.searchTypes}
                    value={search}
                    onValueChange={setSearch}
                />
            </header>
            <nav aria-label={strings.studio.typeFamilies}>
                <button type="button" aria-pressed={family === 'all'} onClick={() => setFamily('all')}>
                    {strings.studio.allFormats}
                    <span>{groups.reduce((sum, group) => sum + group.entries.length, 0)}</span>
                </button>
                {groups.map((group) => (
                    <button
                        key={group.family}
                        type="button"
                        aria-pressed={family === group.family}
                        onClick={() => setFamily(group.family)}
                    >
                        {label(group.family)}
                        <span>{group.entries.length}</span>
                    </button>
                ))}
            </nav>
            <div className="vy-bank-type-results">
                {visible.length === 0 && <p role="status">{strings.studio.noTypes}</p>}
                {visible.map((group) => (
                    <section key={group.family} aria-label={label(group.family)}>
                        <h3>{label(group.family)}</h3>
                        <div>
                            {group.entries.map((entry) => (
                                <button
                                    type="button"
                                    key={entry.key}
                                    aria-label={entry.label}
                                    onClick={() => onChoose(entry)}
                                >
                                    <span className="vy-bank-type-icon">
                                        <Icon icon={familyIcons[entry.family] ?? CircleHelp} size={21} />
                                    </span>
                                    <span>{entry.label}</span>
                                    <Icon icon={ArrowUpRight} size={16} />
                                </button>
                            ))}
                        </div>
                    </section>
                ))}
            </div>
        </section>
    );
}
