import { sanitizeHtml } from './sanitize';

/** Generate stable local heading anchors after sanitizing the authored HTML. */
export function prepareReading(body: string, lessonId: number) {
    const document = new DOMParser().parseFromString(sanitizeHtml(body), 'text/html');
    const headings = Array.from(document.body.querySelectorAll('h2, h3')).map((heading, index) => {
        const id = `vy-reading-${lessonId}-${index}`;
        heading.id = id;
        return { id, title: heading.textContent ?? '', level: heading.tagName === 'H3' ? 3 : 2 };
    });
    return { html: document.body.innerHTML, headings };
}

/** Print just the sanitized lesson, without the CMS navigation or course controls. */
export function printReading(title: string, html: string) {
    const frame = document.createElement('iframe');
    frame.title = title;
    frame.style.cssText = 'position:fixed;width:1px;height:1px;left:-10000px;border:0';
    const documentToPrint = new DOMParser().parseFromString(
        '<!doctype html><html><head></head><body></body></html>',
        'text/html',
    );
    const heading = documentToPrint.createElement('h1');
    heading.textContent = title;
    documentToPrint.body.append(heading);
    const article = documentToPrint.createElement('article');
    article.innerHTML = sanitizeHtml(html);
    documentToPrint.body.append(article);
    const style = documentToPrint.createElement('style');
    style.textContent =
        'body{font:12pt/1.7 sans-serif;padding:24px}img,video{max-width:100%}a{color:inherit}';
    documentToPrint.head.append(style);
    frame.srcdoc = documentToPrint.documentElement.outerHTML;
    frame.onload = () => {
        frame.contentWindow?.addEventListener('afterprint', () => frame.remove(), { once: true });
        frame.contentWindow?.print();
        window.setTimeout(() => frame.remove(), 60_000);
    };
    document.body.append(frame);
}
