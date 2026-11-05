/*
 * Service worker AD'Hub (appli installée sur téléphone).
 * Volontairement sans cache : aucune donnée de l'application n'est gardée
 * sur le téléphone. Tout passe par le réseau ; sans connexion, une page
 * simple indique qu'il faut du réseau.
 */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

var PAGE_HORS_LIGNE =
  '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">' +
  '<meta name="viewport" content="width=device-width, initial-scale=1">' +
  '<title>Hors connexion · AD\'HUB</title>' +
  '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;' +
  'background:#0E1C2B;color:#fff;font-family:-apple-system,system-ui,sans-serif;text-align:center;padding:24px}' +
  'h1{font-size:1.3rem;margin:0 0 8px}p{color:rgba(255,255,255,.7);margin:0 0 20px}' +
  'button{background:#C8A84B;color:#0E1C2B;border:0;border-radius:8px;padding:10px 18px;font-weight:600;font-size:1rem}</style>' +
  '</head><body><div><h1>Pas de connexion internet</h1>' +
  '<p>AD\'Hub a besoin du réseau pour afficher vos données.</p>' +
  '<button onclick="location.reload()">Réessayer</button></div></body></html>';

self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate') return; // le navigateur gère le reste normalement
  e.respondWith(fetch(e.request).catch(function () {
    return new Response(PAGE_HORS_LIGNE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});
