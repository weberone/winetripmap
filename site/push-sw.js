// Service worker solo per le notifiche push degli avvisi all'amministratore (api/webpush.php, Admin → Impostazioni).
// Non intercetta le pagine (niente "fetch"): il sito funziona esattamente come senza.
self.addEventListener("push", (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : "" }; }
  e.waitUntil(self.registration.showNotification(d.title || "WineTripMap", {
    body: d.body || "", icon: "/apple-touch-icon.png", badge: "/apple-touch-icon.png", data: { url: d.url || "/admin/" }
  }));
});
// tocco sulla notifica: si apre (o si porta davanti) la pagina dell'avviso
self.addEventListener("notificationclick", (e) => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || "/admin/", self.location.origin).href;
  e.waitUntil(clients.matchAll({ type: "window", includeUncontrolled: true }).then((list) => {
    const w = list.find((c) => c.url === url) || list[0];
    if (w && w.navigate) return w.navigate(url).then((c) => (c || w).focus()).catch(() => clients.openWindow(url));
    return clients.openWindow(url);
  }));
});
