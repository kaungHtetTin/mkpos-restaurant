const input = document.querySelector('#server');
const button = document.querySelector('button');
const message = document.querySelector('#message');
const native = window.Desktop || window.Capacitor?.Plugins?.RestaurantServer;
if (native) native.settings().then(settings => { input.value = settings.serverUrl || 'https://restaurant.mkposmyanmar.com'; document.querySelector('#local-mode').hidden = !settings.localSupported; }).catch(error => { message.textContent = error.message; });
document.querySelector('#local-mode').addEventListener('click', async event => {
  event.target.disabled = true; message.textContent = 'Preparing your local database. This may take a moment…';
  try { const result = await native.local(); if (!result.ok) throw new Error('Could not start local mode.'); }
  catch (error) { message.textContent = error.message; event.target.disabled = false; }
});
document.querySelector('form').addEventListener('submit', async event => {
  event.preventDefault(); button.disabled = true; message.textContent = 'Connecting…';
  try {
    if (!native) throw new Error('Open this screen from the Android or Windows app.');
    const result = window.Desktop ? await native.connect(input.value) : await native.connect({ url: input.value });
    if (!result.ok) throw new Error(result.message || 'Cannot connect to this server.');
    message.textContent = 'Connected. Opening your restaurant…';
  } catch (error) { message.textContent = error.message; }
  finally { button.disabled = false; }
});
