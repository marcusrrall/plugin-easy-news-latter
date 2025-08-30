<?php

/**
 * Plugin Name: Easy Newsletter
 * Description: Captura e-mails, envia automaticamente o post mais recente, gerencia inscritos, descadastro por link e configura SMTP (PHPMailer). Envio em lotes via WP-Cron e template com imagem destacada (inline CID).
 * Version: 1.2.2
 * Author: Web Rall
 * Text Domain: easy-newsletter
 */

if (!defined('ABSPATH')) exit;

define('ENL_VERSION', '1.2.2');
define('ENL_PATH', plugin_dir_path(__FILE__));
define('ENL_URL',  plugin_dir_url(__FILE__));

require_once ENL_PATH . 'includes/ENL_Template.php';
require_once ENL_PATH . 'includes/ENL_Mailer.php';
require_once ENL_PATH . 'includes/ENL_Security.php';

ENL_Security::init();

/**
 * Núcleo do plugin (admin, rotas, hooks, jobs)
 */
class ENL_Plugin
{
    /** Nome da tabela de inscritos (sem prefixo). */
    const TABLE = 'enl_subscribers';
    /** Option key das configurações SMTP e afins. */
    const OPT   = 'enl_smtp_options';
    /** Nonce base para ações AJAX. */
    const NONCE = 'enl_nonce';

    /** @var ENL_Mailer Responsável por montar e enviar e-mails. */
    private $mailer;

    public function __construct()
    {
        $this->mailer = new ENL_Mailer(self::OPT);

        register_activation_hook(__FILE__, [$this, 'activate']);

        add_action('admin_menu',            [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts',    [$this, 'public_assets']);

        // AJAX público/privado para inscrição
        add_action('wp_ajax_enl_subscribe',        [$this, 'ajax_subscribe']);
        add_action('wp_ajax_nopriv_enl_subscribe', [$this, 'ajax_subscribe']);

        // Descadastro por link
        add_action('init', [$this, 'handle_unsubscribe']);

        // Exportação CSV
        add_action('admin_post_enl_export_csv', [$this, 'export_csv']);

        // Shortcode do formulário
        add_shortcode('easy_newsletter_form', [$this, 'shortcode_form']);

        // Dispara quando um post vira "publish" (inclui agendados)
        add_action('transition_post_status', [$this, 'maybe_send_on_publish'], 10, 3);

        // Ações dos botões no admin
        add_action('admin_post_enl_send_test',   [$this, 'admin_send_test']);
        add_action('admin_post_enl_send_single', [$this, 'admin_send_single']);

        // Job de envio em lotes via WP-Cron
        add_action('enl_send_batch', [$this, 'cron_send_batch'], 10, 2);
    }

    /**
     * Ativação: cria tabela de inscritos e option de configurações.
     */
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
                'smtp_debug' => 0,    // 0,1,2
                'smtp_host'  => '',
                'smtp_port'  => 587,  // 465=ssl, 587=tls
                'smtp_auth'  => 1,
                'smtp_user'  => '',
                'smtp_pass'  => '',
                'from_email' => '',
                'from_name'  => get_bloginfo('name'),
                'form_html'  => '',
                // Você pode futuramente incluir: batch_size, batch_delay, timeout, etc.
            ]);
        }
    }

    /**
     * Registra páginas no Admin.
     */
    public function admin_menu()
    {
        $icon = 'dashicons-email-alt';
        add_menu_page('Easy Newsletter', 'Easy Newsletter', 'manage_options', 'easy-newsletter', [$this, 'admin_list_page'], $icon, 60);
        add_submenu_page('easy-newsletter', 'Inscritos',          'Inscritos',        'manage_options', 'easy-newsletter',           [$this, 'admin_list_page']);
        add_submenu_page('easy-newsletter', 'Configurações SMTP', 'Configurações',    'manage_options', 'easy-newsletter-settings',  [$this, 'admin_settings_page']);
        add_submenu_page('easy-newsletter', 'Verificar Envio',    'Verificar Envio',  'manage_options', 'easy-newsletter-verify',    [$this, 'admin_verify_page']);
    }

    /**
     * CSS/JS do admin.
     */
    public function admin_assets($hook)
    {
        if (strpos($hook, 'easy-newsletter') === false) return;
        wp_enqueue_style('enl-admin', ENL_URL . 'assets/admin.css', [], ENL_VERSION);
        wp_enqueue_script('enl-admin', ENL_URL . 'assets/admin.js', ['jquery'], ENL_VERSION, true);
    }

    /**
     * JS público (formulário AJAX).
     */
    public function public_assets()
    {
        wp_register_script('enl-public', ENL_URL . 'public/subscribe.js', [], ENL_VERSION, true);
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

    /** Carrega páginas do admin. */
    public function admin_list_page()
    {
        require ENL_PATH . 'admin-list.php';
    }
    public function admin_settings_page()
    {
        require ENL_PATH . 'admin-settings.php';
    }
    public function admin_verify_page()
    {
        require ENL_PATH . 'admin-verify.php';
    }

    /**
     * Shortcode [easy_newsletter_form] - renderiza o HTML salvo ou um fallback,
     * e injeta campos de segurança (nonce, action, honeypot e captcha hook).
     */
    public function shortcode_form()
    {
        $opts = get_option(self::OPT, []);
        $html = html_entity_decode(wp_unslash($opts['form_html'] ?? ''), ENT_QUOTES, 'UTF-8');

        // === 1) Monta o bloco de segurança que precisa existir dentro do <form> ===
        // Importante: alinhar com o handler (action=self::NONCE, field='nonce')
        $nonce = wp_nonce_field(self::NONCE, 'nonce', true, false);

        // captcha render (capturado por buffer)
        ob_start();
        do_action('enl_render_captcha'); // outro plugin pode imprimir aqui
        $captcha = ob_get_clean();

        $securityBlock = $nonce . "\n" .
            '<input type="hidden" name="action" value="enl_subscribe" />' . "\n" .
            // Honeypot
            '<div style="position:absolute; left:-9999px;" aria-hidden="true">' .
            '<label>Deixe este campo vazio</label>' .
            '<input type="text" name="enl_hp" tabindex="-1" autocomplete="off" />' .
            '</div>' . "\n" .
            // Captcha hook
            '<div class="enl-captcha">' . $captcha . '</div>';

        // === 2) Se não houver HTML salvo, usa fallback já correto ===
        if (!trim($html)) {
            $action = esc_url(admin_url('admin-ajax.php'));
            $html = '<form class="newsletter-form d-flex gap-2 mt-3" action="' . $action . '" method="post">'
                . $nonce
                . '<input type="hidden" name="action" value="enl_subscribe" />'
                . '<label for="nl-email" class="visually-hidden">Seu e-mail</label>'
                // IMPORTANTE: name="email" para o handler encontrar
                . '<input id="nl-email" type="email" name="email" class="form-control" placeholder="Digite seu e-mail" required />'
                // Honeypot
                . '<div style="position:absolute; left:-9999px;" aria-hidden="true"><label>Deixe este campo vazio</label><input type="text" name="enl_hp" tabindex="-1" autocomplete="off" /></div>'
                // Captcha hook
                . '<div class="enl-captcha">' . $captcha . '</div>'
                . '<button type="submit" class="btn btn-warning"><i class="bi bi-send-fill"></i></button>'
                . '</form>'
                . '<div class="nl-feedback small mt-2"></div>';

            return $html;
        }

        // === 3) Para HTML customizado salvo nas opções, garantimos:
        // - existe um <form>?
        // - action aponta para admin-ajax.php?
        // - method="post"?
        // - injetamos $securityBlock antes de </form>

        // Se não tem <form>, envolve o HTML num form válido
        if (stripos($html, '<form') === false) {
            $action = esc_url(admin_url('admin-ajax.php'));
            $html = '<form class="newsletter-form" action="' . $action . '" method="post">' . $html . '</form>';
        }

        // Garante action e method no <form ...>
        // action
        if (!preg_match('/<form[^>]*\baction\s*=\s*["\'].*?["\']/i', $html)) {
            $html = preg_replace(
                '/<form\b/i',
                '<form action="' . esc_url(admin_url('admin-ajax.php')) . '"',
                $html,
                1
            );
        }
        // method
        if (!preg_match('/<form[^>]*\bmethod\s*=\s*["\']post["\']/i', $html)) {
            if (preg_match('/(<form[^>]*)(>)/i', $html, $m)) {
                $withMethod = preg_replace('/<form\b/i', '<form method="post"', $m[0], 1);
                $html = str_replace($m[0], $withMethod, $html);
            }
        }

        // Injeta o bloco de segurança antes do </form> (se já não estiver presente)
        if (stripos($html, 'name="nonce"') === false && stripos($html, 'name="action" value="enl_subscribe"') === false) {
            if (stripos($html, '</form>') !== false) {
                $html = preg_replace('/<\/form>/i', $securityBlock . '</form>', $html, 1);
            } else {
                $html .= $securityBlock; // fallback paranoico
            }
        }

        // Aviso silencioso: se o HTML customizado não tiver name="email" type="email", o handler não acha.
        // (Opcional: logar no admin)

        return $html;
    }


    /**
     * AJAX: inscreve/reativa e envia o post mais recente para o novo e-mail.
     */
    public function ajax_subscribe()
    {
        // Nonce: precisa bater com o gerado no form (action=self::NONCE, field='nonce')
        check_ajax_referer(self::NONCE, 'nonce');

        // Guard: rate-limit + honeypot + captcha
        $guard = ENL_Security::guard_subscribe();
        if (is_wp_error($guard)) {
            $code = (int) $guard->get_error_code();
            $msg  = $guard->get_error_message();

            if ($code === 299) { // honeypot ⇒ sucesso silencioso
                wp_send_json_success(['message' => 'ok']);
            }
            wp_send_json_error(['message' => $msg], $code ?: 400);
        }

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'invalid']);
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE email=%s", $email));
        $token = wp_generate_password(32, false, false);

        if ($row) {
            if ($row->status === 'active') wp_send_json_error(['message' => 'exists']);
            $wpdb->update(
                $table,
                ['status' => 'active', 'token' => $token, 'created_at' => current_time('mysql')],
                ['id' => $row->id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $table,
                ['email' => $email, 'status' => 'active', 'token' => $token],
                ['%s', '%s', '%s']
            );
        }

        $sent = $this->send_latest_post_to($email, $token);
        $sent === true
            ? wp_send_json_success(['message' => 'ok'])
            : wp_send_json_error(['message' => 'error', 'error' => $sent]);
    }

    /**
     * Descadastro por link (?enl_unsub=TOKEN&email=...).
     */
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
     * Ao publicar um post, agenda o job inicial para envio em lotes.
     */
    public function maybe_send_on_publish($new_status, $old_status, $post)
    {
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        if ($post->post_type !== 'post') return;

        if (!wp_next_scheduled('enl_send_batch', ['post_id' => $post->ID, 'offset' => 0])) {
            wp_schedule_single_event(time() + 10, 'enl_send_batch', ['post_id' => $post->ID, 'offset' => 0]); // start em 10s
        }
    }

    /**
     * Job de lote: envia N e-mails por execução e re-agenda até terminar.
     *
     * @param int $post_id ID do post a enviar.
     * @param int $offset  Posição na lista de inscritos.
     */
    public function cron_send_batch($post_id, $offset = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Tamanho do lote — ajuste conforme servidor / limite do SMTP
        $limit = 200;

        $emails = $wpdb->get_col($wpdb->prepare(
            "SELECT email FROM $table WHERE status='active' ORDER BY id ASC LIMIT %d OFFSET %d",
            $limit,
            (int)$offset
        ));
        if (empty($emails)) {
            return; // acabou
        }

        $this->mailer->send_post_to_many($emails, (int)$post_id, function ($payload) {
            update_option('enl_last_log', [
                'time'       => current_time('mysql'),
                'post_id'    => (int)($payload['post_id'] ?? 0),
                'subject'    => (string)($payload['subject'] ?? ''),
                'total'      => (int)($payload['total'] ?? 0),
                'ok'         => (int)($payload['ok'] ?? 0),
                'fail'       => (int)($payload['fail'] ?? 0),
                'fail_list'  => (array)($payload['fail_list'] ?? []),
                'target'     => 'cron',
            ], false);
        });

        // agenda o próximo lote
        $next_offset = (int)$offset + $limit;
        wp_schedule_single_event(time() + 5, 'enl_send_batch', ['post_id' => (int)$post_id, 'offset' => $next_offset]);
    }

    /* ---------- Encaminhadores p/ Mailer ---------- */

    private function send_to_all_subscribers($post_id, $only_email = null)
    {
        return $this->mailer->send_to_all_subscribers($post_id, $only_email, function ($payload) {
            $this->log_send_result($payload);
        });
    }
    private function send_post_to($email, $token, $post_id)
    {
        return $this->mailer->send_post_to($email, $token, $post_id);
    }
    private function send_latest_post_to($email, $token)
    {
        return $this->mailer->send_latest_post_to($email, $token);
    }

    /**
     * Exporta CSV dos inscritos ativos.
     */
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

    /**
     * Botão "Enviar último post para" (uma linha da lista).
     */
    public function admin_send_single()
    {
        if (!current_user_can('manage_options')) wp_die('Sem permissão');
        check_admin_referer('enl_send_single');

        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        if (!$email || !is_email($email)) {
            wp_safe_redirect(add_query_arg('enl_msg', 'bad_email', admin_url('admin.php?page=easy-newsletter')));
            exit;
        }

        $q = new WP_Query(['post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
        if (!$q->have_posts()) {
            wp_safe_redirect(add_query_arg('enl_msg', 'no_posts', admin_url('admin.php?page=easy-newsletter')));
            exit;
        }
        $q->the_post();
        $post_id = get_the_ID();
        wp_reset_postdata();

        $ok  = $this->send_to_all_subscribers($post_id, $email);
        $msg = $ok ? 'sent_single' : 'send_fail';
        wp_safe_redirect(add_query_arg('enl_msg', $msg, admin_url('admin.php?page=easy-newsletter')));
        exit;
    }

    /**
     * Botão "Enviar último post (teste)" na tela Verificar Envio.
     */
    public function admin_send_test()
    {
        if (!current_user_can('manage_options')) wp_die('Sem permissão');
        check_admin_referer('enl_send_test');

        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
        if (!$test_email || !is_email($test_email)) $test_email = get_option('admin_email');

        $q = new WP_Query(['post_type' => 'post', 'posts_per_page' => 1, 'post_status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
        if (!$q->have_posts()) {
            wp_safe_redirect(add_query_arg('enl_msg', 'no_posts', admin_url('admin.php?page=easy-newsletter-verify')));
            exit;
        }
        $q->the_post();
        $post_id = get_the_ID();
        wp_reset_postdata();

        $result  = $this->send_to_all_subscribers($post_id, $test_email);
        $msg     = $result ? 'sent_test' : 'send_fail';
        $referer = wp_get_referer() ?: admin_url('admin.php?page=easy-newsletter-verify');
        wp_safe_redirect(add_query_arg('enl_msg', $msg, $referer));
        exit;
    }

    /**
     * Salva o último log de envio (para exibir na UI).
     */
    private function log_send_result($args)
    {
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
