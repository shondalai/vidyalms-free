import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import {
    Bold,
    Italic,
    Underline,
    List,
    ListOrdered,
    Quote,
    Undo2,
    Redo2,
    Link2,
    ImagePlus,
} from 'lucide-react';
import { Button, Icon } from '@/shared/components';
import { sanitizeHtml } from '@/player/sanitize';
import { Field, TextArea, TextInput } from './field';

export const articleStrings = {
    visual: uiText("vidyalms.ui.5665dd290be10ec0", "Visual editor"),
    source: uiText("vidyalms.ui.8c8329c964bb3a7a", "HTML source"),
    formatting: uiText("vidyalms.ui.c85a8d1cf19c80ce", "Text formatting"),
    bold: uiText("vidyalms.ui.94fee62e68e257c0", "Bold"),
    italic: uiText("vidyalms.ui.9bf37cb58a694456", "Italic"),
    underline: uiText("vidyalms.ui.02f843261112c2d8", "Underline"),
    bullets: uiText("vidyalms.ui.a2b5baef0f36f6b9", "Bullet list"),
    numbers: uiText("vidyalms.ui.cc62f6c7b2a82fd1", "Numbered list"),
    quote: uiText("vidyalms.ui.cb19465f5fe69c2f", "Block quote"),
    undo: uiText("vidyalms.ui.a8283ade31856f71", "Undo"),
    redo: uiText("vidyalms.ui.74273989b0966e23", "Redo"),
    link: uiText("vidyalms.ui.421eaa9e095dc28c", "Insert link"),
    image: uiText("vidyalms.ui.cf3a92dadb38f288", "Insert image"),
    url: uiText("vidyalms.ui.45099c5588497157", "Web address"),
    alt: uiText("vidyalms.ui.42d964c12dfe09ef", "Image description"),
    insert: uiText("vidyalms.ui.8aa318eb7eaa233a", "Insert"),
    cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
    custom: uiText("vidyalms.ui.b3812a155a83c964", "This lesson contains custom HTML. Edit its source to preserve its complete layout and formatting."),
    heading: uiText("vidyalms.ui.b34f17f02ecf0307", "Heading"),
    paragraph: uiText("vidyalms.ui.c3b03dd28595bab2", "Paragraph"),
};

/** A source fallback protects custom HTML the visual editor does not model. */
export function visualCompatible(html: string): boolean {
    const document = new DOMParser().parseFromString(html, 'text/html');
    const tags = new Set(
        'P H1 H2 H3 H4 H5 H6 STRONG B EM I U S STRIKE DEL UL OL LI BLOCKQUOTE PRE CODE BR HR A IMG'.split(
            ' ',
        ),
    );
    const attrs = new Set(['href', 'target', 'rel', 'src', 'alt', 'title', 'width', 'height', 'start']);
    return Array.from(document.body.querySelectorAll('*')).every(
        (element) =>
            tags.has(element.tagName) &&
            Array.from(element.attributes).every((attribute) => attrs.has(attribute.name)),
    );
}

export function ArticleEditor({
    value,
    onChange,
    label,
    id,
    describedBy,
    invalid,
}: {
    value: string;
    onChange: (html: string) => void;
    label: string;
    id: string;
    describedBy?: string;
    invalid?: boolean;
}) {
    const [source, setSource] = useState(() => !visualCompatible(value));
    const [insert, setInsert] = useState<'link' | 'image' | null>(null);
    const [url, setUrl] = useState('');
    const [alt, setAlt] = useState('');
    const editor = useEditor({
        extensions: [StarterKit.configure({ link: { openOnClick: false } }), Image],
        content: sanitizeHtml(value),
        immediatelyRender: false,
        shouldRerenderOnTransaction: true,
        editorProps: {
            attributes: {
                id,
                role: 'textbox',
                'aria-label': label,
                'aria-multiline': 'true',
                'aria-describedby': describedBy ?? '',
                'aria-invalid': String(Boolean(invalid)),
            },
        },
        onUpdate: ({ editor: current }) => onChange(current.isEmpty ? '' : current.getHTML()),
    });
    useEffect(() => {
        if (editor && value !== editor.getHTML() && !(value === '' && editor.isEmpty)) {
            editor.commands.setContent(sanitizeHtml(value), { emitUpdate: false });
        }
    }, [editor, value]);
    const s = articleStrings;
    const compatible = visualCompatible(value);
    const actions = editor
        ? [
              {
                  label: s.bold,
                  icon: Bold,
                  active: editor.isActive('bold'),
                  run: () => editor.chain().focus().toggleBold().run(),
              },
              {
                  label: s.italic,
                  icon: Italic,
                  active: editor.isActive('italic'),
                  run: () => editor.chain().focus().toggleItalic().run(),
              },
              {
                  label: s.underline,
                  icon: Underline,
                  active: editor.isActive('underline'),
                  run: () => editor.chain().focus().toggleUnderline().run(),
              },
              {
                  label: s.bullets,
                  icon: List,
                  active: editor.isActive('bulletList'),
                  run: () => editor.chain().focus().toggleBulletList().run(),
              },
              {
                  label: s.numbers,
                  icon: ListOrdered,
                  active: editor.isActive('orderedList'),
                  run: () => editor.chain().focus().toggleOrderedList().run(),
              },
              {
                  label: s.quote,
                  icon: Quote,
                  active: editor.isActive('blockquote'),
                  run: () => editor.chain().focus().toggleBlockquote().run(),
              },
              { label: s.undo, icon: Undo2, active: false, run: () => editor.chain().focus().undo().run() },
              { label: s.redo, icon: Redo2, active: false, run: () => editor.chain().focus().redo().run() },
          ]
        : [];
    return (
        <div className="vy-article-editor">
            <div className="flex flex-wrap gap-2 border-b border-border p-2">
                <Button
                    variant="ghost"
                    size="sm"
                    aria-pressed={!source}
                    disabled={!compatible}
                    onClick={() => setSource(false)}
                >
                    {s.visual}
                </Button>
                <Button variant="ghost" size="sm" aria-pressed={source} onClick={() => setSource(true)}>
                    {s.source}
                </Button>
            </div>
            {source ? (
                <>
                    <TextArea
                        id={id}
                        aria-label={label}
                        aria-describedby={describedBy}
                        invalid={invalid}
                        rows={14}
                        className="border-0 font-mono text-xs"
                        value={value}
                        onChange={(event) => onChange(event.target.value)}
                    />
                    {!compatible && <p className="p-3 text-xs text-fg-2">{s.custom}</p>}
                </>
            ) : (
                <>
                    <div
                        role="group"
                        aria-label={s.formatting}
                        className="flex flex-wrap gap-1 border-b border-border p-2"
                    >
                        {actions.map((action) => (
                            <Button
                                key={action.label}
                                variant="ghost"
                                size="sm"
                                aria-label={action.label}
                                title={action.label}
                                aria-pressed={action.active}
                                onClick={action.run}
                            >
                                <Icon icon={action.icon} size={15} />
                            </Button>
                        ))}
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => editor?.chain().focus().toggleHeading({ level: 2 }).run()}
                        >
                            {s.heading}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => editor?.chain().focus().setParagraph().run()}
                        >
                            {s.paragraph}
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={s.link}
                            onClick={() => setInsert('link')}
                        >
                            <Icon icon={Link2} size={15} />
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            aria-label={s.image}
                            onClick={() => setInsert('image')}
                        >
                            <Icon icon={ImagePlus} size={15} />
                        </Button>
                    </div>
                    <EditorContent editor={editor} className="vy-prose" />
                </>
            )}
            {insert && (
                <div className="grid gap-3 border-t border-border p-3">
                    <Field label={s.url}>
                        {({ id: fieldId }) => (
                            <TextInput
                                id={fieldId}
                                value={url}
                                onChange={(event) => setUrl(event.target.value)}
                            />
                        )}
                    </Field>
                    {insert === 'image' && (
                        <Field label={s.alt}>
                            {({ id: fieldId }) => (
                                <TextInput
                                    id={fieldId}
                                    value={alt}
                                    onChange={(event) => setAlt(event.target.value)}
                                />
                            )}
                        </Field>
                    )}
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            disabled={!/^https?:\/\/\S+$/i.test(url)}
                            onClick={() => {
                                if (insert === 'image')
                                    editor?.chain().focus().setImage({ src: url, alt }).run();
                                else
                                    editor
                                        ?.chain()
                                        .focus()
                                        .extendMarkRange('link')
                                        .setLink({ href: url })
                                        .run();
                                setInsert(null);
                                setUrl('');
                                setAlt('');
                            }}
                        >
                            {s.insert}
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => setInsert(null)}>
                            {s.cancel}
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
