jQuery(document).ready(function ($) {
  $(document).on('click', '.enl-toggle-pass', function () {
    const input = $('#smtp_pass');
    const type = input.attr('type') === 'password' ? 'text' : 'password';
    input.attr('type', type);

    // alterna ícone dashicon
    if (type === 'password') {
      $(this).removeClass('dashicons-hidden').addClass('dashicons-visibility');
    } else {
      $(this).removeClass('dashicons-visibility').addClass('dashicons-hidden');
    }
  });
});
