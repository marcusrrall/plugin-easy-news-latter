<?php

/**
 * Plugin Name: Easy Newsletter
 * Description: Captura e-mails, envia automaticamente o post mais recente, gerencia inscritos, descadastro por link e configura SMTP (PHPMailer).
 * Version: 1.1.0
 * Author: Web Rall
 * Text Domain: easy-newsletter
 */
if (!defined('ABSPATH')) exit;

class ENL_Plugin
{
    const TABLE = 'enl_subscribers';
    const OPT   = 'enl_smtp_options';
    const NONCE = 'enl_nonce';

    public function __construct()
    {
        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu',            [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts',    [$this, 'public_assets']);

        add_action('wp_ajax_enl_subscribe',        [$this, 'ajax_subscribe']);
        add_action('wp_ajax_nopriv_enl_subscribe', [$this, 'ajax_subscribe']);
        add_action('init',                          [$this, 'handle_unsubscribe']);
        add_action('admin_post_enl_export_csv',     [$this, 'export_csv']);

        // Shortcode do formulário
        add_shortcode('easy_newsletter_form', [$this, 'shortcode_form']);

        // ⏩ Enviar automaticamente quando um post virar "publicado" (inclui agendados)
        add_action('transition_post_status', [$this, 'maybe_send_on_publish'], 10, 3);

        add_action('admin_post_enl_send_test', [$this, 'admin_send_test']);
    }

    /** Ativação: cria tabela e opções */
    public function activate()
    {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          email VARCHAR(190) NOT NULL UNIQUE,
          status ENUM('active','unsub') NOT NULL DEFAULT 'active',
          token VARCHAR(64) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          unsub_at DATETIME NULL,
          PRIMARY KEY (id),
          KEY status (status)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        if (!get_option(self::OPT)) {
            add_option(self::OPT, [
                'smtp_debug' => 0,
                'smtp_host'  => '',
                'smtp_port'  => 587,
                'smtp_auth'  => 1,
                'smtp_user'  => '',
                'smtp_pass'  => '',
                'from_email' => '',
                'from_name'  => get_bloginfo('name'),
                'form_html'  => '', // HTML do formulário
            ]);
        }
    }

    /** Menu admin */
    public function admin_menu()
    {
        $icon = 'dashicons-email-alt';
        add_menu_page('Easy Newsletter', 'Easy Newsletter', 'manage_options', 'easy-newsletter', [$this, 'admin_list_page'], $icon, 60);
        add_submenu_page('easy-newsletter', 'Inscritos', 'Inscritos', 'manage_options', 'easy-newsletter', [$this, 'admin_list_page']);
        add_submenu_page('easy-newsletter', 'Configurações SMTP', 'Configurações', 'manage_options', 'easy-newsletter-settings', [$this, 'admin_settings_page']);
    }

    /** Assets admin */
    public function admin_assets($hook)
    {
        if (strpos($hook, 'easy-newsletter') === false) return;
        wp_enqueue_style('enl-admin', plugins_url('assets/admin.css', __FILE__), [], '1.0.0');
        wp_enqueue_script('enl-admin', plugins_url('assets/admin.js', __FILE__), ['jquery'], '1.0.0', true);
    }

    /** Assets públicos (JS do form) */
    public function public_assets()
    {
        wp_register_script('enl-public', plugins_url('public/subscribe.js', __FILE__), [], '1.0.0', true);
        wp_localize_script('enl-public', 'ENL', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'msgs'  => [
                'ok'      => 'Inscrito! Enviamos o conteúdo mais recente para o seu e-mail.',
                'exists'  => 'Você já está inscrito.',
                'invalid' => 'E-mail inválido.',
                'error'   => 'Erro ao enviar. Tente novamente.'
            ]
        ]);
        wp_enqueue_script('enl-public');
    }

    /** Página: lista de inscritos */
    public function admin_list_page()
    {
        require __DIR__ . '/admin-list.php';
    }

    /** Página: configurações */
    public function admin_settings_page()
    {
        require __DIR__ . '/admin-settings.php';
    }

    /** Shortcode: renderiza o formulário salvo (ou fallback) */
    public function shortcode_form()
    {
        $opts = get_option(self::OPT, []);
        $html = isset($opts['form_html']) ? $opts['form_html'] : '';
        $html = wp_unslash($html);
        $html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        if (!trim($html)) {
            $html = '<form class="newsletter-form d-flex gap-2 mt-3" action="#" method="post">
  <label for="nl-email" class="visually-hidden">Seu e-mail</label>
  <input id="nl-email" type="email" class="form-control" placeholder="Digite seu e-mail" required />
  <button type="submit" class="btn btn-warning"><i class="bi bi-send-fill"></i></button>
</form>
<div class="nl-feedback small mt-2"></div>';
        }
        return $html;
    }

    /** AJAX: cadastro + envio do post mais recente */
    public function ajax_subscribe()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'invalid']);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email=%s", $email));
        if ($row && $row->status === 'active') {
            wp_send_json_error(['message' => 'exists']);
        }

        $token = wp_generate_password(32, false, false);

        if ($row) {
            $wpdb->update($table, ['status' => 'active', 'token' => $token, 'created_at' => current_time('mysql')], ['id' => $row->id], ['%s', '%s', '%s'], ['%d']);
        } else {
            $wpdb->insert($table, ['email' => $email, 'status' => 'active', 'token' => $token], ['%s', '%s', '%s']);
        }

        // Envia o último post disponível
        $sent = $this->send_latest_post_to($email, $token);

        if ($sent === true) {
            wp_send_json_success(['message' => 'ok']);
        } else {
            wp_send_json_error(['message' => 'error', 'error' => $sent]);
        }
    }

    /** Descadastro via link */
    public function handle_unsubscribe()
    {
        if (!isset($_GET['enl_unsub'], $_GET['email'])) return;
        $token = sanitize_text_field($_GET['enl_unsub']);
        $email = sanitize_email($_GET['email']);
        if (!$token || !is_email($email)) return;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email=%s AND token=%s AND status='active'", $email, $token));
        if ($row) {
            $wpdb->update($table, ['status' => 'unsub', 'unsub_at' => current_time('mysql')], ['id' => $row->id], ['%s', '%s'], ['%d']);
            wp_safe_redirect(home_url('/obrigado/?unsub=ok'));
            exit;
        }
    }

    /**
     * Dispara quando status do post muda para "publish".
     * Envia o post recém-publicado para todos inscritos ativos.
     */
    public function maybe_send_on_publish($new_status, $old_status, $post)
    {
        if ($new_status !== 'publish' || $old_status === 'publish') return; // só primeira publicação
        if ($post->post_type !== 'post') return;

        $this->send_to_all_subscribers($post->ID);
    }

    /** Dispara e-mail com o post informado.
     *  - Se $only_email for informado, envia só para ele (modo teste)
     *  - Registra log do envio (total/ok/falha)
     */
    private function send_to_all_subscribers($post_id, $only_email = null)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Monta lista de destino
        if ($only_email) {
            $recipients = [sanitize_email($only_email)];
        } else {
            $recipients = $wpdb->get_col("SELECT email FROM $table WHERE status='active'");
        }
        if (!$recipients) return false;

        // Post e conteúdo
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') return false;

        $site    = get_bloginfo('name');
        $subject = '[' . $site . '] ' . $post->post_title;

        // Texto do corpo: usa seu template padrão que você já tem
        // Reaproveite seu código existente de construção do body se preferir.
        $excerpt = has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : wp_trim_words(wp_strip_all_tags($post->post_content), 28);
        $permalink = get_permalink($post);
        $body = '<html><body style="font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5;">'
            . '<h2 style="margin:0 0 12px 0;">' . esc_html($post->post_title) . '</h2>'
            . '<p style="margin:0 0 12px 0;">' . esc_html($excerpt) . '</p>'
            . '<p style="margin:16px 0;"><a href="' . esc_url($permalink) . '" style="background:#111;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Ler no ' . esc_html($site) . '</a></p>'
            . '<hr style="border:0;border-top:1px solid #eee;margin:20px 0;">'
            . '<p style="font-size:12px;color:#6b7280;margin:0;">Se não quiser mais receber, acesse seu e-mail cadastrado na newsletter e clique em descadastrar.</p>'
            . '</body></html>';

        // Envia um a um com wp_mail e coleta resultados
        $ok = 0;
        $fail = 0;
        $fail_list = [];

        foreach ($recipients as $email) {
            $sent = $this->smtp_send($email, $subject, $body);
            if ($sent) {
                $ok++;
            } else {
                $fail++;
                $fail_list[$email] = 'wp_mail retornou false';
            }
        }

        // Log
        $this->log_send_result([
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

    /** Envia um post específico para um e-mail */
    private function send_post_to($email, $token, $post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || $post->post_type !== 'post') return false;

        $title     = get_the_title($post);
        $permalink = get_permalink($post);
        $excerpt   = has_excerpt($post) ? wp_strip_all_tags(get_the_excerpt($post)) : wp_trim_words(wp_strip_all_tags($post->post_content), 28);
        $site      = get_bloginfo('name');
        $unsubscribe = esc_url(add_query_arg(['enl_unsub' => $token, 'email' => rawurlencode($email)], home_url('/')));

        $body = '<html><body style="font-family:Arial,Helvetica,sans-serif;color:#111;line-height:1.5;">'
            . '<h2 style="margin:0 0 12px 0;">' . esc_html($title) . '</h2>'
            . '<p style="margin:0 0 12px 0;">' . esc_html($excerpt) . '</p>'
            . '<p style="margin:16px 0;"><a href="' . esc_url($permalink) . '" style="background:#111;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none">Ler no ' . esc_html($site) . '</a></p>'
            . '<hr style="border:0;border-top:1px solid #eee;margin:20px 0;">'
            . '<p style="font-size:12px;color:#6b7280;margin:0;">Se não quiser mais receber, <a href="' . $unsubscribe . '">clique para descadastrar</a>.</p>'
            . '</body></html>';

        $subject = '[' . $site . '] ' . $title;
        return $this->smtp_send($email, $subject, $body);
    }

    /** Envia o post mais recente (usado no ato do cadastro) */
    private function send_latest_post_to($email, $token)
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

    /** Envio SMTP usando PHPMailer */
    private function smtp_send($email, $subject, $body)
    {
        $opts = get_option(self::OPT, []);

        // Configura SMTP via phpmailer_init
        add_action('phpmailer_init', function ($phpmailer) use ($opts) {
            if (!empty($opts['smtp_host'])) {
                $phpmailer->isSMTP();
                $phpmailer->Host       = $opts['smtp_host'];
                $phpmailer->Port       = (int)($opts['smtp_port'] ?? 587);
                $phpmailer->SMTPAuth   = true;
                $phpmailer->Username   = $opts['smtp_user'];
                $phpmailer->Password   = $opts['smtp_pass'];
                $phpmailer->SMTPSecure = 'tls';
            }
            if (!empty($opts['from_email'])) {
                $phpmailer->setFrom(
                    $opts['from_email'],
                    $opts['from_name'] ?? get_bloginfo('name'),
                    false
                );
            }
        });

        // Headers HTML
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Usa wp_mail do WordPress
        $ok = wp_mail($email, $subject, $body, $headers);

        // Remove hook pra não interferir em outros envios
        remove_all_actions('phpmailer_init');

        return $ok;
    }

    /** Exporta CSV (apenas ativos) */
    public function export_csv()
    {
        if (!current_user_can('manage_options')) wp_die('Sem permissão');
        check_admin_referer('enl_export_csv');

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results("SELECT email,status,created_at,unsub_at FROM $table WHERE status='active' ORDER BY created_at DESC", ARRAY_A);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=enl-subscribers.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['email', 'status', 'created_at', 'unsub_at']);
        foreach ($rows as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }


    /** Trata o envio de teste a partir do admin */
    public function admin_send_test()
    {
        if (!current_user_can('manage_options')) wp_die('Sem permissão');
        check_admin_referer('enl_send_test');

        // E-mail de teste (se vazio, usa admin_email do site)
        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
        if (!$test_email || !is_email($test_email)) {
            $test_email = get_option('admin_email');
        }

        // Post mais recente publicado
        $q = new WP_Query([
            'post_type'      => 'post',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC'
        ]);
        if (!$q->have_posts()) {
            wp_safe_redirect(add_query_arg('enl_msg', 'no_posts', admin_url('admin.php?page=easy-newsletter')));
            exit;
        }
        $q->the_post();
        $post_id = get_the_ID();
        wp_reset_postdata();

        // Envia somente para o e-mail de teste
        $result = $this->send_to_all_subscribers($post_id, $test_email); // <-- ajustado para aceitar alvo único
        $msg = $result ? 'sent_test' : 'send_fail';
        wp_safe_redirect(add_query_arg('enl_msg', $msg, admin_url('admin.php?page=easy-newsletter')));
        exit;
    }

    /** Registra log do último envio (para teste e para envios reais) */
    private function log_send_result($args)
    {
        // $args: [
        //   'post_id' => int, 'subject' => string,
        //   'total' => int, 'ok' => int, 'fail' => int,
        //   'fail_list' => array(email => error_string),
        //   'target' => 'all'|'test',
        // ]
        $log = [
            'time'       => current_time('mysql'),
            'post_id'    => (int)($args['post_id'] ?? 0),
            'subject'    => (string)($args['subject'] ?? ''),
            'total'      => (int)($args['total'] ?? 0),
            'ok'         => (int)($args['ok'] ?? 0),
            'fail'       => (int)($args['fail'] ?? 0),
            'fail_list'  => (array)($args['fail_list'] ?? []),
            'target'     => (string)($args['target'] ?? 'all'),
        ];
        update_option('enl_last_log', $log, false);
    }
}
new ENL_Plugin();
