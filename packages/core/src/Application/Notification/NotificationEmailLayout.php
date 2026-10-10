<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * Conservative email markup owned by the server, never by template authors.
 *
 * It deliberately uses nested presentation tables, inline critical styles
 * and a 620px body. The template source supplies words and safe addresses;
 * every byte of markup comes from this class.
 */
final class NotificationEmailLayout
{
    private const FONT_STACK = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    public function __construct(private readonly ?TranslatorPort $translator = null) {}

    private function text(string $key, string $fallback): string
    {
        $value = $this->translator?->translate($key);
        return $value === null || $value === $key ? $fallback : $value;
    }

    /**
     * @return array{body_text:string,body_html:?string}
     */
    public function render(
        TemplateSource $source,
        bool $supportsHtml,
        bool $includeCompliance,
        ?string $complianceUrl,
        string $locale = 'en-GB',
    ): array {
        return [
            'body_text' => $this->plainText($source, $includeCompliance, $complianceUrl),
            'body_html' => $supportsHtml
                ? $this->html($source, $includeCompliance, $complianceUrl, $locale)
                : null,
        ];
    }

    private function plainText(TemplateSource $source, bool $includeCompliance, ?string $complianceUrl): string
    {
        $parts = [$source->heading, ...$source->paragraphs];

        if ($source->actionLabel !== null && $source->actionUrl !== null) {
            $parts[] = $source->actionLabel . ': ' . $source->actionUrl;
        }

        $parts[] = $source->footer;

        if ($includeCompliance) {
            $parts[] = $this->complianceText($complianceUrl);
        }

        return implode("\n\n", array_values(array_filter(
            $parts,
            static fn (string $part): bool => trim($part) !== '',
        )));
    }

    private function html(
        TemplateSource $source,
        bool $includeCompliance,
        ?string $complianceUrl,
        string $locale,
    ): string {
        $font = self::FONT_STACK;
        $accent = $source->accentColor;
        $preheader = $this->escape($source->preheader);
        $heading = $this->escape($source->heading);
        $paragraphs = '';

        foreach ($source->paragraphs as $paragraph) {
            $paragraphs .= '<p style="Margin:0 0 18px;color:#344054;font-family:' . $font
                . ';font-size:16px;line-height:1.65;">'
                . nl2br($this->escape($paragraph), false)
                . '</p>';
        }

        $logo = '';

        if ($source->logoUrl !== null && $source->logoAlt !== null) {
            $logo = '<tr><td align="left" style="padding:0 0 28px;">'
                . '<img src="' . $this->escape($source->logoUrl) . '" alt="' . $this->escape($source->logoAlt)
                . '" width="160" style="display:block;width:auto;max-width:160px;height:auto;border:0;outline:none;text-decoration:none;">'
                . '</td></tr>';
        }

        $action = '';

        if ($source->actionLabel !== null && $source->actionUrl !== null) {
            // The coloured table cell keeps the action usable in clients
            // that ignore the anchor's background style.
            $action = '<tr><td style="padding:8px 0 28px;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td bgcolor="' . $accent . '" style="border-radius:8px;background:' . $accent . ';">'
                . '<a href="' . $this->escape($source->actionUrl) . '" style="display:inline-block;padding:13px 22px;'
                . 'border:1px solid ' . $accent . ';border-radius:8px;color:#FFFFFF;font-family:' . $font
                . ';font-size:16px;font-weight:700;line-height:1.2;text-decoration:none;">'
                . $this->escape($source->actionLabel) . '</a></td></tr></table></td></tr>';
        }

        $compliance = '';

        if ($includeCompliance) {
            $complianceText = $this->escape($this->complianceText(null));

            if ($complianceUrl !== null && $complianceUrl !== '') {
                $complianceText .= ' <a href="' . $this->escape($complianceUrl)
                    . '" style="color:#475467;text-decoration:underline;">'
                    . $this->escape($this->text('vidyalms.notification.footer.manage', 'Manage or unsubscribe from notifications')) . '</a>.';
            }

            $compliance = '<p style="Margin:10px 0 0;color:#667085;font-family:' . $font
                . ';font-size:12px;line-height:1.5;">' . $complianceText . '</p>';
        }

        return '<!doctype html><html lang="' . $this->escape($locale) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="Margin:0;padding:0;background:#F2F4F7;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">'
            . $preheader . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#F2F4F7"'
            . ' style="width:100%;background:#F2F4F7;"><tr><td align="center" style="padding:32px 12px;">'
            . '<table role="presentation" width="620" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF"'
            . ' style="width:100%;max-width:620px;background:#FFFFFF;border:1px solid #E4E7EC;border-radius:12px;">'
            . '<tr><td style="height:6px;background:' . $accent . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:38px 42px 34px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . $logo
            . '<tr><td><h1 style="Margin:0 0 20px;color:#101828;font-family:' . $font
            . ';font-size:30px;line-height:1.2;letter-spacing:-0.4px;">' . $heading . '</h1></td></tr>'
            . '<tr><td>' . $paragraphs . '</td></tr>'
            . $action
            . '<tr><td style="padding-top:20px;border-top:1px solid #EAECF0;">'
            . '<p style="Margin:0;color:#667085;font-family:' . $font . ';font-size:13px;line-height:1.55;">'
            . nl2br($this->escape($source->footer), false) . '</p>'
            . $compliance
            . '</td></tr></table></td></tr></table></td></tr></table></body></html>';
    }

    private function complianceText(?string $url): string
    {
        $text = $this->text('vidyalms.notification.footer.reason', 'You are receiving this learning notification based on your notification settings.');

        return $url === null || $url === '' ? $text : $text . ' '
            . $this->text('vidyalms.notification.footer.manage', 'Manage or unsubscribe from notifications') . ': ' . $url;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
