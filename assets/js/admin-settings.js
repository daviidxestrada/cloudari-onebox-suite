document.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('cloudari_client_secret');
  const btn = document.getElementById('cloudari-toggle-client-secret');

  if (!input || !btn) return;

  btn.addEventListener('click', () => {
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.textContent = isPassword ? 'Ocultar' : 'Mostrar';
    btn.setAttribute('aria-label', isPassword ? 'Ocultar client secret' : 'Mostrar client secret');
  });
});
