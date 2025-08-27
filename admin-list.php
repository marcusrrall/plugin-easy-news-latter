<?php if (!defined('ABSPATH')) exit;

global $wpdb;
$table = $wpdb->prefix . ENL_Plugin::TABLE;

// paginação
$per_page = 20;
$page = max(1, intval($_GET['paged'] ?? 1));
$offset = ($page - 1) * $per_page;

$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
$subs  = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
));

$total_pages = max(1, ceil($total / $per_page));
$base_url = admin_url('admin.php?page=easy-newsletter');
?>
<div class="wrap enl-wrap">
    <h1 class="wp-heading-inline">Easy Newsletter — Inscritos</h1>

    <?php
    // Avisos (inclui feedback do envio por linha)
    if (!empty($_GET['enl_msg'])) {
        $msgs = [
            'no_posts'    => ['type' => 'error',   'text' => 'Não há posts publicados para enviar.'],
            'sent_single' => ['type' => 'updated', 'text' => 'Último post enviado para o e-mail selecionado.'],
            'bad_email'   => ['type' => 'error',   'text' => 'E-mail inválido.'],
            'send_fail'   => ['type' => 'error',   'text' => 'Envio falhou. Confira as configurações de SMTP.'],
            'csv_done'    => ['type' => 'updated', 'text' => 'Exportação gerada.'],
        ];
        $m = $msgs[$_GET['enl_msg']] ?? null;
        if ($m) {
            printf(
                '<div class="%s notice is-dismissible"><p>%s</p></div>',
                esc_attr($m['type']),
                esc_html($m['text'])
            );
        }
    }
    ?>

    <a class="page-title-action" href="<?php echo wp_nonce_url(
                                            admin_url('admin-post.php?action=enl_export_csv'),
                                            'enl_export_csv'
                                        ); ?>">Exportar CSV</a>

    <hr class="wp-header-end">

    <p class="enl-total"><strong>Total de inscritos ativos:</strong> <?php echo number_format_i18n($total); ?></p>

    <table class="wp-list-table widefat fixed striped table-view-list enl-table">
        <thead>
            <tr>
                <th width="50">#</th>
                <th>E-mail</th>
                <th>Status</th>
                <th>Cadastro</th>
                <th>Descadastro</th>
                <th style="width:200px;">Ações</th>
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
                        <td>
                            <!-- Enviar último post para este e-mail -->
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php wp_nonce_field('enl_send_single'); ?>
                                <input type="hidden" name="action" value="enl_send_single" />
                                <input type="hidden" name="email" value="<?php echo esc_attr($s->email); ?>" />
                                <button type="submit" class="button button-small" title="Enviar o último post para este e-mail">
                                    Enviar último post
                                </button>
                            </form>
                            <?php if ($s->status === 'active'): ?>
                                <!-- (Opcional) Link de descadastro rápido/admin -->
                                <!--
                                <a class="button button-small" href="<?php echo esc_url(
                                                                            wp_nonce_url(add_query_arg([
                                                                                'action' => 'enl_admin_unsub',
                                                                                'email'  => rawurlencode($s->email),
                                                                            ], admin_url('admin-post.php')), 'enl_admin_unsub')
                                                                        ); ?>">Descadastrar</a>
                                -->
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach;
            else: ?>
                <tr>
                    <td colspan="6">Nenhum inscrito encontrado.</td>
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
</div>