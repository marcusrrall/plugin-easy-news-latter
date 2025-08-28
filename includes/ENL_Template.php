<?php
if (!defined('ABSPATH')) exit;

class ENL_Template
{
    /** Monta o HTML do e-mail. $image pode ser URL ou "cid:xxxx" */
    public static function render(array $args): string
    {
        $site        = $args['site'] ?? get_bloginfo('name');
        $title       = $args['title'] ?? '';
        $excerpt     = $args['excerpt'] ?? '';
        $permalink   = $args['permalink'] ?? '#';
        $image       = $args['image'] ?? '';
        $unsubscribe = $args['unsubscribe'] ?? null;

        $image_tag = '';
        if (!empty($image)) {
            $image_tag = '<tr><td style="padding:0 24px 16px 24px">
                <img src="' . esc_attr($image) . '" width="552" alt="" style="display:block;border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;border-radius:10px;width:100%;max-width:552px;height:auto;">
            </td></tr>';
        }

        $footer = $unsubscribe
            ? 'Se não quiser mais receber, <a href="' . esc_url($unsubscribe) . '" style="color:#6b7280">clique para descadastrar</a>.'
            : 'Se não quiser mais receber estes e-mails, responda esta mensagem ou gerencie sua inscrição no site.';

        return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>img{border:0;outline:none;text-decoration:none;}@media (max-width:620px){.container{width:100%!important;}}</style>
</head><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f3f4f6;padding:24px 12px;">
<tr><td align="center">
  <table class="container" role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:#fff;border-radius:14px;overflow:hidden;">
    <tr><td style="background:#111;color:#fff;padding:14px 24px;font-size:16px;font-weight:700;">' . esc_html($site) . '</td></tr>
    ' . $image_tag . '
    <tr><td style="padding:0 24px 8px 24px">
      <h1 style="margin:0 0 8px 0;font-size:22px;line-height:1.25;">' . esc_html($title) . '</h1>
    </td></tr>
    <tr><td style="padding:0 24px 20px 24px;font-size:16px;color:#111;line-height:1.6;">' . esc_html($excerpt) . '</td></tr>
    <tr><td style="padding:0 24px 28px 24px">
      <a href="' . esc_url($permalink) . '" style="display:inline-block;background:#111;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:600;">Ler no ' . esc_html($site) . '</a>
    </td></tr>
    <tr><td style="padding:0 24px 24px 24px">
      <hr style="border:0;border-top:1px solid #e5e7eb;margin:0 0 16px 0;">
      <p style="margin:0;font-size:12px;color:#6b7280;">' . $footer . '</p>
    </td></tr>
  </table>
</td></tr>
</table>
</body></html>';
    }
}
