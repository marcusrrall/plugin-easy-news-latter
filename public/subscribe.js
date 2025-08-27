(function () {
  document.addEventListener(
    'submit',
    function (e) {
      const form = e.target.closest('.newsletter-form');
      if (!form) return;
      e.preventDefault();

      const input = form.querySelector('input[type="email"]');
      const email = (input?.value || '').trim();
      if (!email) {
        alert(ENL.msgs.invalid);
        return;
      }

      const fd = new FormData();
      fd.append('action', 'enl_subscribe');
      fd.append('nonce', ENL.nonce);
      fd.append('email', email);

      form.classList.add('is-loading');
      fetch(ENL.ajax, { method: 'POST', body: fd })
        .then((r) => r.json())
        .then((res) => {
          form.classList.remove('is-loading');
          const code = res?.data?.message || (res?.success ? 'ok' : 'error');
          alert(ENL.msgs[code] || ENL.msgs.error);
          if (res.success) input.value = '';
        })
        .catch(() => {
          form.classList.remove('is-loading');
          alert(ENL.msgs.error);
        });
    },
    false,
  );
})();
