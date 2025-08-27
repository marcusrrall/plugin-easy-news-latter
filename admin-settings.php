<?php

/** admin-settings.php */
if (!defined('ABSPATH')) exit;

$opts  = get_option(ENL_Plugin::OPT, []);
$nonce = wp_create_nonce('enl_save_settings');

// Tags permitidas quando o usuário não tem unfiltered_html
function enl_allowed_form_tags()
{
    return [
        'form' => [
            'action' => true,
            'method' => true,
            'class' => true,
            'id' => true,
            'name' => true,
            'autocomplete' => true,
            'novalidate' => true,
        ],
        'input' => [
            'type' => true,
            'name' => true,
            'id' => true,
            'class' => true,
            'placeholder' => true,
            'value' => true,
            'required' => true,
            'autocomplete' => true,
            'min' => true,
            'max' => true,
            'step' => true,
            'pattern' => true,
        ],
        'button' => [
            'type' => true,
            'name' => true,
            'id' => true,
            'class' => true,
            'value' => true,
            'aria-label' => true,
        ],
        'label' => [
            'for' => true,
            'class' => true,
            'id' => true,
        ],
        'i' => [
            'class' => true,
            'aria-hidden' => true,
        ],
        'span' => [
            'class' => true,
            'id' => true,
        ],
        'div' => [
            'class' => true,
            'id' => true,
            'role' => true,
        ],
        'p' => ['class' => true],
        'small' => ['class' => true],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enl_save']) && current_user_can('manage_options')) {
    check_admin_referer('enl_save_settings');

    // SMTP
    $opts['smtp_debug'] = isset($_POST['smtp_debug']) ? (int) $_POST['smtp_debug'] : 0;
    $opts['smtp_host']  = sanitize_text_field($_POST['smtp_host'] ?? '');
    $opts['smtp_port']  = (int) ($_POST['smtp_port'] ?? 587);
    $opts['smtp_auth']  = isset($_POST['smtp_auth']) ? 1 : 0;
    $opts['smtp_user']  = sanitize_text_field($_POST['smtp_user'] ?? '');
    $opts['smtp_pass']  = sanitize_text_field($_POST['smtp_pass'] ?? '');
    $opts['from_email'] = sanitize_email($_POST['from_email'] ?? '');
    $opts['from_name']  = sanitize_text_field($_POST['from_name'] ?? get_bloginfo('name'));

    // Formulário HTML personalizado
    if (isset($_POST['form_html'])) {
        // REMOVE as barras automáticas do WP
        $raw = wp_unslash($_POST['form_html']);

        if (current_user_can('unfiltered_html')) {
            // Admin: salva cru
            $opts['form_html'] = $raw;
        } else {
            // Sem unfiltered_html: whitelist de tags de formulário
            $opts['form_html'] = wp_kses($raw, enl_allowed_form_tags());
        }
    }

    update_option(ENL_Plugin::OPT, $opts);
    echo '<div class="updated notice is-dismissible"><p>Configurações salvas.</p></div>';
}
?>
<div class="wrap enl-wrap">
    <h1>Easy Newsletter — Configurações</h1>
    <p>Defina abaixo o SMTP (PHPMailer) e, se quiser, cole um <strong>formulário HTML personalizado</strong> para inscrição.</p>

    <form method="post">
        <?php wp_nonce_field('enl_save_settings'); ?>

        <h2 class="title">SMTP</h2>
        <table class="form-table">
            <tr>
                <th><label for="smtp_host">Host</label></th>
                <td><input type="text" class="regular-text" id="smtp_host" name="smtp_host" value="<?php echo esc_attr($opts['smtp_host'] ?? ''); ?>" placeholder="smtp.seuservidor.com"></td>
            </tr>
            <tr>
                <th><label for="smtp_port">Porta</label></th>
                <td><input type="number" id="smtp_port" name="smtp_port" value="<?php echo esc_attr($opts['smtp_port'] ?? 587); ?>" min="1"></td>
            </tr>
            <tr>
                <th scope="row">Autenticação</th>
                <td><label><input type="checkbox" name="smtp_auth" <?php checked(($opts['smtp_auth'] ?? 1), 1); ?>> Requer autenticação</label></td>
            </tr>
            <tr>
                <th><label for="smtp_user">Usuário</label></th>
                <td><input type="text" class="regular-text" id="smtp_user" name="smtp_user" value="<?php echo esc_attr($opts['smtp_user'] ?? ''); ?>"></td>
            </tr>
            <tr>
                <th><label for="smtp_pass">Senha</label></th>
                <td>
                    <div class="enl-pass-wrap">
                        <input type="password" class="regular-text" id="smtp_pass" name="smtp_pass"
                            value="<?php echo esc_attr($opts['smtp_pass'] ?? ''); ?>">
                        <button type="button" class="enl-toggle-pass dashicons dashicons-visibility"
                            aria-label="Mostrar/ocultar senha"></button>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="from_email">From (e-mail)</label></th>
                <td><input type="email" class="regular-text" id="from_email" name="from_email" value="<?php echo esc_attr($opts['from_email'] ?? ''); ?>" placeholder="no-reply@seu-dominio.com"></td>
            </tr>
            <tr>
                <th><label for="from_name">From (nome)</label></th>
                <td><input type="text" class="regular-text" id="from_name" name="from_name" value="<?php echo esc_attr($opts['from_name'] ?? get_bloginfo('name')); ?>"></td>
            </tr>
            <tr>
                <th><label for="smtp_debug">Debug</label></th>
                <td>
                    <select id="smtp_debug" name="smtp_debug">
                        <option value="0" <?php selected(($opts['smtp_debug'] ?? 0), 0); ?>>0 (off)</option>
                        <option value="1" <?php selected(($opts['smtp_debug'] ?? 0), 1); ?>>1</option>
                        <option value="2" <?php selected(($opts['smtp_debug'] ?? 0), 2); ?>>2</option>
                    </select>
                    <p class="description">Use somente para depuração.</p>
                </td>
            </tr>
        </table>

        <h2 class="title">Formulário HTML personalizado</h2>
        <p>
            Cole abaixo o HTML do seu formulário. Você pode usar Bootstrap, Tailwind ou estilos próprios.
            <br><strong>Requisitos para capturar via AJAX:</strong>
        </p>
        <ol>
            <li>O formulário deve ter a classe <code>.newsletter-form</code>.</li>
            <li>Deve conter um campo <code>&lt;input type="email"&gt;</code> (qualquer <code>id</code>/<code>name</code>).</li>
            <li>Inclua um botão de envio (<code>&lt;button type="submit"&gt;</code> ou <code>&lt;input type="submit"&gt;</code>).</li>
            <li>O atributo <code>action</code> pode ser <code>#</code>; o plugin intercepta e envia via AJAX.</li>
            <li>Opcional: um elemento <code>.nl-feedback</code> logo após o formulário para mensagens (sucesso/erro).</li>
        </ol>

        <p><em>Exemplo recomendado:</em></p>
        <pre style="background:#f8f9fa;padding:10px;border:1px solid #ddd;font-size:12px;overflow:auto;"><?php
                                                                                                            echo esc_html('<form class="newsletter-form d-flex gap-2 mt-3" action="#" method="post">
  <label for="nl-email" class="visually-hidden">Seu e-mail</label>
  <input id="nl-email" type="email" class="form-control" placeholder="Digite seu e-mail" required />
  <button type="submit" class="btn btn-warning">
    <i class="bi bi-send-fill"></i>
  </button>
</form>
<div class="nl-feedback small mt-2"></div>');
                                                                                                            ?></pre>

        <table class="form-table">
            <tr>
                <th><label for="form_html">Formulário HTML</label></th>
                <td>
                    <textarea id="form_html" name="form_html" rows="10" class="large-text code"><?php echo esc_textarea($opts['form_html'] ?? ''); ?></textarea>
                    <p class="description">Para inserir no site, use o shortcode <code>[easy_newsletter_form]</code> em páginas, posts ou widgets.</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary" name="enl_save" value="1">Salvar configurações</button>
        </p>
    </form>
</div>