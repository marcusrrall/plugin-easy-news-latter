# Easy Newsletter

**Versão:** 1.0.0  
**Autor:** Web Rall  
**Descrição:** Plugin simples de newsletter para WordPress. Captura e-mails via formulário, envia automaticamente o último post publicado, permite descadastro por link e inclui configuração de SMTP (PHPMailer).

---

## ✨ Recursos

- Captura de e-mails por formulário (shortcode).
- Envio automático do **último post publicado** para todos os inscritos.
- Painel de administração com:
  - Lista de inscritos (com paginação).
  - Exportação de inscritos ativos em **CSV**.
  - Configuração de SMTP (host, porta, usuário, senha, remetente).
  - Editor para o HTML do formulário de inscrição.
  - Botão de teste para envio do último post.
  - Log do último envio (quantidade de enviados, falhas e e-mails com erro).
- Link automático de descadastro no rodapé de cada e-mail.

---

## 📥 Instalação

1. Baixe ou clone o repositório para `wp-content/plugins/easy-newsletter`.
2. Ative o plugin no painel do WordPress em **Plugins → Easy Newsletter**.
3. Configure o SMTP em **Easy Newsletter → Configurações**.
4. Insira o formulário de inscrição em qualquer página/post/widget usando o shortcode:

```php
[easy_newsletter_form]
```
