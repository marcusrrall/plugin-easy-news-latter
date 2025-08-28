<?php
if (!defined('ABSPATH')) exit;

/**
 * Camada de envio: monta conteúdo, gerencia imagem destacada (CID),
 * aplica SMTP no PHPMailer e expõe helpers de envio (um, muitos, todos).
 */
class ENL_Mailer
{
    /** @var string Chave (option) das configurações SMTP. */
    private $opt_key;

    /** @var string|null CID atual da imagem embutida. */
    private $embed_cid  = null;
    /** @var string|null Caminho do arquivo da imagem embutida. */
    private $embed_path = null;

    public function __construct(string $opt_key)
    {
        $this->opt_key = $opt_key;
    }

    /**
     * Envia para todos os inscritos ou apenas para um e-mail.
     *
     * @param int            $post_id   Post a ser enviado.
     * @param string|null    $only_email Se informado, envia só para ele.
     * @param callable       $on_log    Callback para registrar log (recebe payload).
     * @return bool          true se houve pelo menos 1 envio OK.
     */
    public function send_to_all_subscribers(int $post_id, ?string $only_email, callable $on_log): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . ENL_Plugin::TABLE;

        $recipients = $only_email ? [sanitize_email($only_email)]
            : $wpdb->get_col("SELECT email FROM $table WHERE status='active'");
        if (!$recipients) return false;

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return false;

        $site      = get_bloginfo('name');
        $subject   = '[' . $site . '] ' . $post->post_title;
        $excerpt   = has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : wp_trim_words(wp_strip_all_tags($post->post_content), 40);
        $permalink = get_permalink($post);
        $image     = $this->prepare_featured_image($post->ID);

        $body = ENL_Template::render([
            'site'        => $site,
            'title'       => $post->post_title,
            'excerpt'     => $excerpt,
            'permalink'   => $permalink,
            'image'       => $image,
            'unsubscribe' => null,
        ]);

        $ok = 0;
        $fail = 0;
        $fail_list = [];
        foreach ($recipients as $email) {
            if ($this->smtp_send($email, $subject, $body)) {
                $ok++;
            } else {
                $fail++;
                $fail_list[$email] = 'wp_mail retornou false';
            }
        }

        $on_log([
            'post_id'   => $post_id,
            'subject'   => $subject,
            'total'     => count($recipients),
            'ok'        => $ok,
            'fail'      => $fail,
            'fail_list' => $fail_list,
            'target'    => $only_email ? 'test' : 'all',
        ]);

        return ($ok > 0);
    }

    /**
     * Envia um post para uma lista específica de e-mails (usado no batch).
     *
     * @param string[]       $emails
     * @param int            $post_id
     * @param callable|null  $on_log
     * @return bool
     */
    public function send_post_to_many(array $emails, int $post_id, ?callable $on_log = null): bool
    {
        $emails = array_values(array_filter(array_map('sanitize_email', $emails)));
        if (!$emails) return false;

        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return false;

        $site      = get_bloginfo('name');
        $subject   = '[' . $site . '] ' . $post->post_title;
        $excerpt   = has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : wp_trim_words(wp_strip_all_tags($post->post_content), 40);
        $permalink = get_permalink($post);
        $image     = $this->prepare_featured_image($post->ID);

        $body = ENL_Template::render([
            'site'        => $site,
            'title'       => $post->post_title,
            'excerpt'     => $excerpt,
            'permalink'   => $permalink,
            'image'       => $image,
            'unsubscribe' => null,
        ]);

        $ok = 0;
        $fail = 0;
        $fail_list = [];
        foreach ($emails as $email) {
            if ($this->smtp_send($email, $subject, $body)) {
                $ok++;
            } else {
                $fail++;
                $fail_list[$email] = 'wp_mail retornou false';
            }
        }

        if ($on_log) {
            $on_log([
                'post_id'   => $post_id,
                'subject'   => $subject,
                'total'     => count($emails),
                'ok'        => $ok,
                'fail'      => $fail,
                'fail_list' => $fail_list,
                'target'    => 'cron',
            ]);
        }

        return ($ok > 0);
    }

    /**
     * Envia um post específico para um e-mail, com link de descadastro.
     */
    public function send_post_to(string $email, string $token, int $post_id): bool
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') return false;

        $title       = get_the_title($post);
        $permalink   = get_permalink($post);
        $excerpt     = has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : wp_trim_words(wp_strip_all_tags($post->post_content), 40);
        $site        = get_bloginfo('name');
        $unsubscribe = esc_url(add_query_arg(['enl_unsub' => $token, 'email' => rawurlencode($email)], home_url('/')));

        $image = $this->prepare_featured_image($post->ID);

        $body = ENL_Template::render([
            'site'        => $site,
            'title'       => $title,
            'excerpt'     => $excerpt,
            'permalink'   => $permalink,
            'image'       => $image,
            'unsubscribe' => $unsubscribe,
        ]);

        $subject = '[' . $site . '] ' . $title;
        return $this->smtp_send($email, $subject, $body);
    }

    /**
     * Envia o último post publicado (usado no ato do cadastro).
     *
     * @return true|string true ou 'no_posts'
     */
    public function send_latest_post_to(string $email, string $token)
    {
        $q = new WP_Query([
            'post_type'      => 'post',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC'
        ]);
        if (!$q->have_posts()) return 'no_posts';
        $q->the_post();
        $post_id = get_the_ID();
        wp_reset_postdata();
        return $this->send_post_to($email, $token, $post_id);
    }

    /**
     * Prepara a imagem destacada: tenta embutir (CID) e guarda path/CID;
     * se não der, cai para URL absoluta.
     *
     * @return string "cid:xxxxx" ou URL
     */
    private function prepare_featured_image(int $post_id): string
    {
        $this->embed_cid = $this->embed_path = null;
        if (!has_post_thumbnail($post_id)) return '';

        $url       = get_the_post_thumbnail_url($post_id, 'full');
        $attach_id = get_post_thumbnail_id($post_id);
        $file_path = $attach_id ? get_attached_file($attach_id) : '';

        if ($file_path && file_exists($file_path)) {
            $this->embed_cid  = 'enl_featured_' . md5($file_path);
            $this->embed_path = $file_path;
            return 'cid:' . $this->embed_cid;
        }
        return $url ?: '';
    }

    /**
     * Configura SMTP/PHPMailer, embute destacada (se houver) e envia.
     * Também captura transcript e último erro para a página "Verificar Envio".
     */
    private function smtp_send(string $email, string $subject, string $body): bool
    {
        $opts = get_option($this->opt_key, []);

        $debug_lines = [];
        $last_error  = '';

        // Garantir From/Name corretos em wp_mail()
        $from_filter = function ($from) use ($opts) {
            return !empty($opts['from_email']) ? $opts['from_email'] : $from;
        };
        $name_filter = function ($name) use ($opts) {
            return !empty($opts['from_name'])  ? $opts['from_name']  : get_bloginfo('name');
        };
        add_filter('wp_mail_from', $from_filter, 999);
        add_filter('wp_mail_from_name', $name_filter, 999);

        // Captura erros do wp_mail
        $failed_hook = function ($wp_error) use (&$last_error, &$debug_lines) {
            if (is_wp_error($wp_error)) {
                $last_error    = $wp_error->get_error_message();
                $debug_lines[] = 'wp_mail_failed → ' . $last_error;
                $data = $wp_error->get_error_data();
                if (is_array($data)) {
                    foreach ($data as $k => $v) if (is_scalar($v)) $debug_lines[] = "data[$k] → $v";
                }
            }
        };
        add_action('wp_mail_failed', $failed_hook, 10, 1);

        // Configura PHPMailer (SMTP, segurança, timeout, imagem embutida, debug)
        $phpmailer_hook = function ($phpmailer) use ($opts, &$debug_lines) {
            if (!empty($opts['smtp_host'])) {
                $phpmailer->isSMTP();
                $phpmailer->Host       = $opts['smtp_host'];
                $phpmailer->Port       = (int)($opts['smtp_port'] ?? 587);
                $phpmailer->SMTPAuth   = !empty($opts['smtp_auth']);
                $phpmailer->Username   = $opts['smtp_user'] ?? '';
                $phpmailer->Password   = $opts['smtp_pass'] ?? '';
                $phpmailer->SMTPSecure = ((int)($opts['smtp_port'] ?? 587) === 465) ? 'ssl' : 'tls';

                // performance/robustez
                $phpmailer->Timeout       = 10;     // segundos
                $phpmailer->SMTPKeepAlive = false;  // fecha após cada envio (evita timeouts em fila longa)
            }

            if (!empty($opts['from_email'])) {
                $phpmailer->setFrom($opts['from_email'], $opts['from_name'] ?? get_bloginfo('name'), false);
            }

            // Embute imagem destacada, se houver
            if (!empty($this->embed_path) && !empty($this->embed_cid) && file_exists($this->embed_path)) {
                $mime = function_exists('mime_content_type') ? mime_content_type($this->embed_path) : 'image/jpeg';
                try {
                    $phpmailer->AddEmbeddedImage($this->embed_path, $this->embed_cid, basename($this->embed_path), 'base64', $mime);
                } catch (\Exception $e) {
                    $debug_lines[] = 'embed_error → ' . $e->getMessage();
                }
            }

            // Transcript (0,1,2)
            $phpmailer->SMTPDebug   = (int)($opts['smtp_debug'] ?? 0);
            $phpmailer->Debugoutput = function ($str, $level) use (&$debug_lines) {
                $debug_lines[] = trim($str);
            };
        };
        add_action('phpmailer_init', $phpmailer_hook);

        // Envia como HTML
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $ok = wp_mail($email, $subject, $body, $headers);

        // Limpa hooks/estado
        remove_action('phpmailer_init', $phpmailer_hook);
        remove_action('wp_mail_failed', $failed_hook, 10);
        remove_filter('wp_mail_from', $from_filter, 999);
        remove_filter('wp_mail_from_name', $name_filter, 999);
        $this->embed_cid = $this->embed_path = null;

        // Guarda transcript/resumo para a UI de verificação
        $mask = function ($s) {
            if (!$s) return '';
            $l = strlen($s);
            return ($l <= 4) ? str_repeat('•', $l) : substr($s, 0, 2) . str_repeat('•', max(0, $l - 4)) . substr($s, -2);
        };
        set_transient('enl_last_debug', [
            'to'         => $email,
            'subject'    => $subject,
            'host'       => $opts['smtp_host'] ?? '',
            'port'       => (int)($opts['smtp_port'] ?? 0),
            'auth'       => !empty($opts['smtp_auth']) ? 'on' : 'off',
            'username'   => $mask($opts['smtp_user'] ?? ''),
            'from'       => $opts['from_email'] ?? '',
            'result'     => $ok ? 'OK' : 'FAIL',
            'last_error' => $last_error,
            'lines'      => $debug_lines,
        ], 5 * MINUTE_IN_SECONDS);

        return $ok;
    }
}
