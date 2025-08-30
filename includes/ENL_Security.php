<?php
if (! defined('ABSPATH')) {
    exit; // no direct access
}

class ENL_Security
{

    /**
     * Inicializa apenas hooks não-invasivos (placeholder de captcha).
     * O handler AJAX fica no ENL_Plugin; aqui somos apenas "guard".
     */
    public static function init()
    {
        add_action('enl_render_captcha', [__CLASS__, 'render_default_captcha_placeholder']);
    }

    /* =========================
     * Internals (helpers)
     * ========================= */

    /**
     * Fingerprint simples do cliente (IP + User-Agent).
     * Pode ser customizado via filtro 'enl_client_fingerprint'.
     */
    private static function get_client_fingerprint()
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return apply_filters('enl_client_fingerprint', $ip . '|' . $ua);
    }

    /**
     * Rate-limit por janela, baseado em transient (servidor único).
     * Filtros:
     *  - enl_rate_window (segundos, default 10min)
     *  - enl_rate_max_hits (máx tentativas por janela, default 20)
     */
    private static function rate_limit_check_and_bump($context = 'subscribe')
    {
        $window  = (int) apply_filters('enl_rate_window', 10 * MINUTE_IN_SECONDS);
        $maxHits = (int) apply_filters('enl_rate_max_hits', 20);

        $fp  = self::get_client_fingerprint();
        $key = 'enl_rl_' . md5($context . '|' . $fp);

        $bucket = get_transient($key);
        $now    = time();

        if (!is_array($bucket) || empty($bucket['reset']) || $bucket['reset'] < $now) {
            $bucket = ['hits' => 0, 'reset' => $now + $window];
        }

        $bucket['hits']++;
        $ttl = max(1, $bucket['reset'] - $now);
        set_transient($key, $bucket, $ttl);

        return ($bucket['hits'] <= $maxHits);
    }

    /**
     * Honeypot simples: se preenchido, considera bot.
     */
    private static function honeypot_triggered($field = 'enl_hp')
    {
        $hp = isset($_POST[$field]) ? trim((string) $_POST[$field]) : '';
        return $hp !== '';
    }

    /* =========================
     * API pública (guard)
     * ========================= */

    /**
     * Guard do fluxo de subscribe: rate-limit, honeypot e captcha plugável.
     * @return true|WP_Error
     *   - true: passou
     *   - WP_Error(299): honeypot (retornar sucesso silencioso)
     *   - WP_Error(429): rate-limit
     *   - WP_Error(400): captcha inválido
     */
    public static function guard_subscribe()
    {
        // 1) Rate-limit
        if (! self::rate_limit_check_and_bump('subscribe')) {
            return new WP_Error(429, 'Muitas tentativas. Tente mais tarde.');
        }

        // 2) Honeypot (sucesso silencioso)
        if (self::honeypot_triggered('enl_hp')) {
            return new WP_Error(299, 'Honeypot acionado');
        }

        // 3) Captcha plugável (outro plugin deve validar)
        $captcha_ok = apply_filters('enl_captcha_is_valid', true, [
            'action'  => 'subscribe',
            'request' => $_POST,
            'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        if (! $captcha_ok) {
            return new WP_Error(400, 'Falha na verificação anti-bot.');
        }

        return true;
    }

    /**
     * Placeholder do render do captcha. Mantemos vazio por padrão.
     * Integrações (reCAPTCHA, hCaptcha, Turnstile) podem imprimir via hook.
     */
    public static function render_default_captcha_placeholder()
    {
        // Intencionalmente vazio. Outro plugin pode fazer echo aqui.
    }
}
