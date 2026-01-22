document.addEventListener('DOMContentLoaded', () => {
  const container = document.getElementById('cloudari-integrations');
  const template = document.getElementById('cloudari-integration-template');
  const addBtn = document.getElementById('cloudari-add-integration');

  function toggleSecret(btn) {
    const root = btn.closest('[data-integration]');
    if (!root) return;
    const input = root.querySelector('input[data-secret-input]');
    if (!input) return;

    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.textContent = isPassword ? 'Ocultar' : 'Mostrar';
    btn.setAttribute(
      'aria-label',
      isPassword ? 'Ocultar client secret' : 'Mostrar client secret'
    );
  }

  function ensureDefaultChecked() {
    if (!container) return;
    const checked = container.querySelector('input[name="default_integration"]:checked');
    if (!checked) {
      const first = container.querySelector('input[name="default_integration"]');
      if (first) first.checked = true;
    }
  }

  if (container) {
    container.addEventListener('click', (event) => {
      const toggleBtn = event.target.closest('[data-toggle-secret]');
      if (toggleBtn) {
        event.preventDefault();
        toggleSecret(toggleBtn);
        return;
      }

      const removeBtn = event.target.closest('[data-remove-integration]');
      if (removeBtn) {
        event.preventDefault();
        const blocks = container.querySelectorAll('[data-integration]');
        if (blocks.length <= 1) {
          alert('Debe haber al menos una integracion.');
          return;
        }
        const block = removeBtn.closest('[data-integration]');
        if (block) {
          block.remove();
          ensureDefaultChecked();
        }
      }
    });
  }

  if (addBtn && container && template) {
    addBtn.addEventListener('click', (event) => {
      event.preventDefault();
      const key = 'int_' + Date.now();
      const html = template.innerHTML.replace(/__KEY__/g, key);
      const wrapper = document.createElement('div');
      wrapper.innerHTML = html.trim();
      const node = wrapper.firstElementChild;
      if (node) {
        container.appendChild(node);
        ensureDefaultChecked();
      }
    });
  }

  ensureDefaultChecked();
});
