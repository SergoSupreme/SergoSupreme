// ===================================================================
//  Service worker: сповіщення в браузері.
//  Нічого не кешує — лише показує сповіщення й відкриває потрібне місце гри.
// ===================================================================
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { try { d = { body: e.data.text() }; } catch (e2) {} }
  const title = d.title || 'Мій Хмарочос';
  e.waitUntil(self.registration.showNotification(title, {
    body: d.body || '',
    icon: 'images/icons/crane.png',
    badge: 'images/icons/crane.png',
    tag: d.tag || d.kind || 'mx',
    renotify: true,
    data: { open: d.open || '', kind: d.kind || '' },
  }));
});

// Клік по сповіщенню: якщо гра вже відкрита — переходимо в ній, інакше відкриваємо нову вкладку
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const open = (e.notification.data && e.notification.data.open) || '';
  e.waitUntil((async () => {
    const all = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const base = new URL('./', self.registration.scope).href;
    for (const c of all) {
      if (!c.url.startsWith(base)) continue;
      await c.focus();
      c.postMessage({ mxOpen: open });
      return;
    }
    await self.clients.openWindow(base + (open ? 'index.html?open=' + encodeURIComponent(open) : ''));
  })());
});
