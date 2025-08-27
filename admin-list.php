<?php if (!defined('ABSPATH')) exit;
global $wpdb;
$table = $wpdb->prefix . ENL_Plugin::TABLE;

// paginação
$per_page = 20;
$page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($page - 1) * $per_page;

$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
$subs  = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));

$total_pages = max(1, ceil($total / $per_page));
$base_url = admin_url('admin.php?page=easy-newsletter');
?>

<div class="wrap enl-wrap">
    <h1 class="wp-heading-inline">Easy Newsletter — Inscritos</h1>
    <?php
    // Avisos simples
    if (!empty($_GET['enl_msg'])) {
        $msgs = [
            'no_posts' => ['type' => 'error', 'text' => 'Não há posts publicados para enviar.'],
            'sent_test' => ['type' => 'updated', 'text' => 'Envio de teste disparado. Verifique sua caixa de entrada.'],
            'send_fail' => ['type' => 'error', 'text' => 'Envio de teste falhou. Confira as configurações de SMTP.'],
        ];
        $m = $msgs[$_GET['enl_msg']] ?? null;
        if ($m) {
            printf('<div class="%s notice is-dismissible">
        <p>%s</p>
    </div>', esc_attr($m['type']), esc_html($m['text']));
        }
    }
    ?>

    <!-- Form: Enviar último post (teste) -->
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="enl-send-test" style="margin:10px 0 20px;">
        <?php wp_nonce_field('enl_send_test'); ?>
        <input type="hidden" name="action" value="enl_send_test" />
        <label for="enl_test_email"><strong>Enviar último post para (teste):</strong></label>
        <input type="email" id="enl_test_email" name="test_email" class="regular-text" placeholder="email@exemplo.com" value="<?php echo esc_attr(get_option('admin_email')); ?>" required />
        <button type="submit" class="button button-secondary">Enviar último post (teste)</button>
    </form>
    <a class="page-title-action" href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=enl_export_csv'), 'enl_export_csv'); ?>">Exportar CSV</a>
    <hr class="wp-header-end">

    <p class="enl-total"><strong>Total de inscritos ativos:</strong> <?php echo number_format_i18n($total); ?></p>

    <table class="wp-list-table widefat fixed striped table-view-list enl-table">
        <thead>
            <tr>
                <th width="40">#</th>
                <th>E-mail</th>
                <th>Status</th>
                <th>Cadastro</th>
                <th>Descadastro</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($subs): $i = $offset + 1;
                foreach ($subs as $s): ?>
                    <tr>
                        <td><?php echo esc_html($i++); ?></td>
                        <td><?php echo esc_html($s->email); ?></td>
                        <td><?php echo esc_html($s->status); ?></td>
                        <td><?php echo esc_html(mysql2date('d/m/Y H:i', $s->created_at)); ?></td>
                        <td><?php echo $s->unsub_at ? esc_html(mysql2date('d/m/Y H:i', $s->unsub_at)) : '—'; ?></td>
                    </tr>
                <?php endforeach;
            else: ?>
                <tr>
                    <td colspan="5">Nenhum inscrito encontrado.</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>


    <?php if ($total_pages > 1): ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base'      => add_query_arg('paged', '%#%', $base_url),
                    'format'    => '',
                    'current'   => $page,
                    'total'     => $total_pages,
                    'prev_text' => '«',
                    'next_text' => '»',
                ]);
                ?>
            </div>
        </div>

    <?php endif; ?>
    <?php
    $last_log = get_option('enl_last_log', []);
    if (!empty($last_log)):
    ?>
        <h2 style="margin-top:30px;">📬 Último envio (log)</h2>
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
                        <td>
                            <code style="display:block;white-space:pre-wrap;word-break:break-word;">
                                <?php
                                foreach ($last_log['fail_list'] as $em => $err) {
                                    echo esc_html($em . ' → ' . $err) . "\n";
                                }
                                ?>
                            </code>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>