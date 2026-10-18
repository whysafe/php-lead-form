(() => {
  'use strict';

  const form = document.getElementById('lead-form');
  if (!form) return;

  const button = form.querySelector('.submit');
  const status = form.querySelector('.status');
  const tokenInput = form.elements.csrf_token;
  const message = form.elements.message;
  const counter = document.getElementById('message-counter');

  const MESSAGES = {
    network: 'Нет связи с сервером. Проверьте интернет и попробуйте ещё раз.',
    server: 'Не удалось отправить заявку. Попробуйте позже.',
  };

  async function loadToken() {
    try {
      const res = await fetch('token.php', { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json();
      if (data.ok && data.token) tokenInput.value = data.token;
    } catch (e) {
      setStatus(MESSAGES.network, 'error');
    }
  }

  function setStatus(text, type) {
    status.textContent = text || '';
    status.className = 'status' + (type ? ' is-' + type : '');
  }

  function setFieldError(name, text) {
    const box = form.querySelector(`[data-error-for="${name}"]`);
    const field = form.elements[name];
    if (box) box.textContent = text || '';
    if (field && field.setAttribute) field.setAttribute('aria-invalid', text ? 'true' : 'false');
  }

  function clearErrors() {
    form.querySelectorAll('[data-error-for]').forEach((el) => setFieldError(el.dataset.errorFor, ''));
  }

  // Те же правила, что и на сервере, чтобы показать ошибку до отправки.
  function isValidContact(value) {
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return true;
    if (/^@[A-Za-z0-9_]{5,32}$/.test(value)) return true;
    if (/^\+?[\d\s()\-]{10,20}$/.test(value)) {
      const digits = value.replace(/\D/g, '').length;
      return digits >= 10 && digits <= 15;
    }
    return false;
  }

  function validate() {
    const errors = {};
    const name = form.elements.name.value.trim();
    const contact = form.elements.contact.value.trim();
    if (name.length < 2) errors.name = 'Укажите имя (от 2 символов).';
    if (!contact) errors.contact = 'Укажите телефон, email или Telegram.';
    else if (!isValidContact(contact)) errors.contact = 'Похоже на опечатку: нужен телефон, email или @username.';
    if (!form.elements.consent.checked) errors.consent = 'Без согласия на обработку персональных данных отправить заявку нельзя.';
    return errors;
  }

  function showErrors(errors) {
    Object.entries(errors).forEach(([name, text]) => setFieldError(name, text));
    const first = Object.keys(errors)[0];
    if (first && form.elements[first]) form.elements[first].focus();
  }

  function setBusy(busy) {
    button.disabled = busy;
    button.classList.toggle('is-busy', busy);
  }

  message.addEventListener('input', () => {
    counter.textContent = `${message.value.length} / 2000`;
  });

  form.addEventListener('input', (e) => {
    if (e.target.name) setFieldError(e.target.name, '');
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors();
    setStatus('');

    const errors = validate();
    if (Object.keys(errors).length) {
      showErrors(errors);
      return;
    }

    setBusy(true);
    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      let data = {};
      try { data = await res.json(); } catch (_) { /* пустой ответ */ }

      if (res.ok && data.ok) {
        form.reset();
        counter.textContent = '0 / 2000';
        setStatus(data.message || 'Заявка отправлена.', 'success');
      } else if (res.status === 422 && data.errors) {
        showErrors(data.errors);
      } else if (res.status === 403) {
        await loadToken();
        setStatus(data.message || 'Сессия устарела. Нажмите «Отправить» ещё раз.', 'error');
      } else {
        setStatus(data.message || MESSAGES.server, 'error');
      }
    } catch (_) {
      setStatus(MESSAGES.network, 'error');
    } finally {
      setBusy(false);
    }
  });

  loadToken();
})();
