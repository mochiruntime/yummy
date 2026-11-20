<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Renders responsive, privacy-first, email-client-compatible HTML and plain-text emails.
 *
 * Uses table-based layout and 100% inline CSS with system fonts and zero external
 * images, fonts, or tracking pixels so emails render reliably across Gmail, Apple Mail,
 * Outlook, Thunderbird, and Proton Mail without leaking user IP addresses.
 */
final class EmailTemplateRenderer
{
    private const BRAND_ACCENT = '#E11D48';

    /**
     * Renders the multipart (HTML + plain-text) magic-link sign-in email.
     *
     * @return array{subject: string, text: string, html: string}
     */
    public function renderMagicLink(string $displayName, string $loginUrl): array
    {
        $safeName = $this->escape($displayName);
        $safeUrl = $this->escape($loginUrl);

        $subject = 'Your yummy sign-in link';
        $preheader = 'Use this one-time link to sign in to yummy (expires in 15 minutes).';

        $text = <<<TEXT
Hi {$displayName},

Sign in to yummy using this one-time link (valid for 15 minutes):

{$loginUrl}

If you didn't request this link, you can safely ignore this email.
TEXT;

        $contentHtml = <<<HTML
<h1 style="margin:0 0 12px 0;font-size:22px;line-height:1.3;font-weight:700;color:#111827;">
  Sign in to yummy
</h1>
<p style="margin:0 0 16px 0;font-size:15px;line-height:1.6;color:#374151;">
  Hi {$safeName},
</p>
<p style="margin:0 0 24px 0;font-size:15px;line-height:1.6;color:#374151;">
  Click the button below to finish signing in on your device. For your security, this one-time link expires in <strong>15 minutes</strong>.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px 0;">
  <tr>
    <td align="center" bgcolor="#E11D48" style="border-radius:10px;background-color:#E11D48;">
      <a href="{$safeUrl}" target="_blank" rel="noopener noreferrer"
         style="display:inline-block;padding:14px 28px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:10px;">
        Sign in to yummy
      </a>
    </td>
  </tr>
</table>
<p style="margin:0 0 8px 0;font-size:13px;line-height:1.5;color:#6B7280;">
  Or copy and paste this link into your browser:
</p>
<p style="margin:0 0 24px 0;padding:12px;background-color:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace;font-size:12px;line-height:1.5;color:#374151;word-break:break-all;">
  <a href="{$safeUrl}" target="_blank" rel="noopener noreferrer" style="color:#E11D48;text-decoration:underline;">{$safeUrl}</a>
</p>
<p style="margin:0;font-size:13px;line-height:1.5;color:#6B7280;">
  If you didn&#039;t request this sign-in link, you can safely ignore this email.
</p>
HTML;

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $this->wrapLayout($preheader, $contentHtml),
        ];
    }

    /**
     * Wraps body HTML in a centered, 560px table layout compatible with Outlook and mobile clients.
     */
    private function wrapLayout(string $preheader, string $contentHtml): string
    {
        $safePreheader = $this->escape($preheader);
        $accent = self::BRAND_ACCENT;

        return <<<HTML
<!doctype html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <title>yummy</title>
</head>
<body style="margin:0;padding:0;background-color:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;mso-hide:all;">
    {$safePreheader}
  </div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F3F4F6;">
    <tr>
      <td align="center" style="padding:32px 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background-color:#FFFFFF;border-radius:16px;border:1px solid #E5E7EB;overflow:hidden;">
          <tr>
            <td style="height:4px;background-color:{$accent};font-size:0;line-height:0;">&nbsp;</td>
          </tr>
          <tr>
            <td style="padding:24px 32px 0 32px;">
              <span style="display:inline-block;font-size:18px;font-weight:800;letter-spacing:-0.02em;color:#111827;">
                <span style="color:{$accent};">&#9679;</span> yummy
              </span>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 32px 32px 32px;">
              {$contentHtml}
            </td>
          </tr>
        </table>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
          <tr>
            <td align="center" style="padding:16px 16px 0 16px;font-size:12px;line-height:1.5;color:#9CA3AF;">
              yummy &mdash; privacy-first collaborative food map
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Escapes untrusted text for safe HTML interpolation.
     */
    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
