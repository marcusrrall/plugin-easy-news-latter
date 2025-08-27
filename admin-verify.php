<?php if (!defined('ABSPATH')) exit; ?>
<div class="wrap enl-wrap">
    <h1>Easy Newsletter — Verificar Envio</h1>

    <?php
    if (!empty($_GET['enl_msg'])) {
        $msgs = [
            'no_posts'   => ['type' => 'error', 'text' => 'Não há posts publicados para enviar.'],
            'sent_test'  => ['type' => 'updated', 'text' => 'Envio de teste disparado. Verifique sua caixa de entrada.'],
            'send_fail'  => ['type' => 'error', 'text' => 'Falha no envio. Confira o SMTP.'],
        ];
        $m = $msgs[$_GET['enl_msg']] ?? null;
        if ($m) printf(
            '<div class="%s notice is-dismissible"><p>%s</p></div>',
            esc_attr($m['type']),
            esc_html($m['text'])
        );
    }
    ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="enl-send-test" style="margin:10px 0 20px;">
        <?php wp_nonce_field('enl_send_test'); ?>
        <input type="hidden" name="action" value="enl_send_test" />
        <label for="enl_test_email"><strong>Enviar último post para (teste):</strong></label>
        <input type="email" id="enl_test_email" name="test_email" class="regular-text"
            value="<?php echo esc_attr(get_option('admin_email')); ?>" required />
        <button type="submit" class="button button-primary">Enviar último post (teste)</button>
    </form>

    <?php
    $last_log = get_option('enl_last_log', []);
    if (!empty($last_log)):
    ?>
        <h2>📬 Último envio (log)</h2>
        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <th style="width:220px;">Data</th>
                    <td><?php echo esc_html(mysql2date('d/m/Y H:i', $last_log['time'] ?? '')); ?></td>
                </tr>
                <tr>
                    <th>Alvo</th>
                    <td><?php echo ($last_log['target'] ?? 'all') === 'test' ? 'Teste (um e-mail)' : 'Todos os inscritos'; ?></td>
                </tr>
                <tr>
                    <th>Post ID</th>
                    <td><?php echo intval($last_log['post_id'] ?? 0); ?></td>
                </tr>
                <tr>
                    <th>Assunto</th>
                    <td><?php echo esc_html($last_log['subject'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Totais</th>
                    <td>
                        Total: <strong><?php echo intval($last_log['total'] ?? 0); ?></strong> ·
                        OK: <strong style="color:#127a12;"><?php echo intval($last_log['ok'] ?? 0); ?></strong> ·
                        Falhas: <strong style="color:#b00020;"><?php echo intval($last_log['fail'] ?? 0); ?></strong>
                    </td>
                </tr>
                <?php if (!empty($last_log['fail_list'])): ?>
                    <tr>
                        <th>Falhas (e-mail → erro)</th>
                        <td><code style="display:block;white-space:pre-wrap;word-break:break-word;"><?php
                                                                                                    foreach ($last_log['fail_list'] as $em => $err) echo esc_html("$em → $err") . "\n";
                                                                                                    ?></code></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php
    // Debug transitório do último envio (só aparece depois do POST)
    if ($dbg = get_transient('enl_last_debug')): ?>
        <h2 style="margin-top:24px;">🧪 Debug (temporário)</h2>
        <table class="widefat striped" style="max-width:900px;">
            <tbody>
                <tr>
                    <th style="width:220px;">Para</th>
                    <td><?php echo esc_html($dbg['to'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>Assunto</th>
                    <td><?php echo esc_html($dbg['subject'] ?? ''); ?></td>
                </tr>
                <tr>
                    <th>SMTP</th>
                    <td>
                        Host: <code><?php echo esc_html($dbg['host'] ?? ''); ?></code> ·
                        Porta: <code><?php echo esc_html($dbg['port'] ?? ''); ?></code> ·
                        Auth: <code><?php echo esc_html($dbg['auth'] ?? ''); ?></code> ·
                        User: <code><?php echo esc_html($dbg['username'] ?? ''); ?></code> ·
                        From: <code><?php echo esc_html($dbg['from'] ?? ''); ?></code>
                    </td>
                </tr>
                <tr>
                    <th>Resultado</th>
                    <td><strong><?php echo esc_html($dbg['result'] ?? ''); ?></strong></td>
                </tr>
                <?php if (!empty($dbg['last_error'])): ?>
                    <tr>
                        <th>WP_Error</th>
                        <td><code><?php echo esc_html($dbg['last_error']); ?></code></td>
                    </tr>
                <?php endif; ?>
                <?php if (!empty($dbg['lines'])): ?>
                    <tr>
                        <th>Transcript</th>
                        <td>
                            <pre style="white-space:pre-wrap;word-break:break-word;margin:0;"><?php
                                                                                                foreach ($dbg['lines'] as $ln) echo esc_html($ln) . "\n";
                                                                                                ?></pre>
                            <p class="description">Para transcript detalhado, ajuste “Debug” para 1 ou 2 nas Configurações SMTP.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php
        // apaga após exibir
        delete_transient('enl_last_debug');
    endif;
    ?>

</div>