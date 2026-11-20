<?php
// Nomi leggibili degli eventi di api/track.php (clic nella mappa, GPS, cookie, pagine), per admin/visite.php e admin/percorso.php.
// Gli eventi non elencati si mostrano con il loro nome tecnico.
const EV_LABELS = [
  'consent:yes' => 'Cookie: Accetta', 'consent:no' => 'Cookie: Rifiuta',
  'gps:ok' => 'GPS: posizione trovata', 'gps:denied' => 'GPS: permesso negato', 'gps:timeout' => 'GPS: nessuna risposta in tempo',
  'gps:unavailable' => 'GPS: posizione non disponibile', 'gps:nobrowser' => 'GPS: il browser non lo permette',
  'app:open' => 'Apre la mappa', 'app:close' => 'Chiude la pagina', 'app:hidden' => 'Mappa in secondo piano', 'app:visible' => 'Torna sulla mappa',
  'view:winery' => 'Apre la scheda di una cantina', 'view:closed' => 'Scheda: Google la dà chiusa', 'list:winery' => 'Elenco: tocca una cantina',
  'search:place' => 'Ricerca: un luogo', 'search:town' => 'Ricerca: un comune', 'search:geo' => 'Ricerca: coordinate', 'search:winery' => 'Ricerca: una cantina', 'search:area' => 'Ricerca: un\'area', 'search:zone' => 'Ricerca: una zona del vino',
  'act:use_location' => 'Usa la posizione (riuscito)', 'act:open_browse' => 'Esplora per area', 'act:share' => 'Condivide', 'act:add_to_trip' => 'Aggiunge al trip',
  'act:open_street_view' => 'Apre Street View', 'act:get_directions' => 'Indicazioni stradali', 'act:call_winery' => 'Telefona alla cantina',
  'act:open_winery_site' => 'Apre il sito della cantina', 'act:open_google_maps' => 'Apre Google Maps', 'act:add_favorite' => 'Salva tra le preferite',
  'act:remove_favorite' => 'Toglie dalle preferite',
  'btn:locBtn' => 'In alto: posizione', 'btn:placeBtn' => 'In alto: luogo', 'btn:browseBtn' => 'Esplora per area', 'btn:tripBtn' => 'In alto: il tuo trip',
  'btn:acctBtn' => 'In alto: account', 'btn:worldAskGps' => 'Invito iniziale: usa la mia posizione', 'btn:worldAskBtn' => 'Invito iniziale: cerca un luogo',
  'btn:worldAskBrowse' => 'Invito iniziale: esplora per area', 'btn:searchHere' => 'Cerca in questa zona', 'btn:nearBtn' => 'Le più vicine',
  'btn:previewBtn' => 'Radar', 'btn:shareBtn' => 'Condividi (mappa)', 'btn:searchBtn' => 'Cerca', 'btn:mapThemeBtn' => 'Mappa chiara/scura',
  'btn:zoomIn' => 'Zoom +', 'btn:zoomOut' => 'Zoom −', 'btn:meBtn' => 'Cerca intorno a me', 'btn:kmBtn' => 'Raggio in km', 'btn:menuBtn' => 'Elenco (telefono)',
  'btn:browseClose' => 'Esplora: chiude', 'btn:svClose' => 'Street View: chiude', 'btn:lbClose' => 'Foto grande: chiude', 'btn:lbPrev' => 'Foto grande: indietro',
  'btn:lbNext' => 'Foto grande: avanti', 'btn:tripClose' => 'Trip: chiude', 'btn:cookieOpen' => 'Link Cookie in fondo',
  'btn:dClose' => 'Scheda: chiude', 'btn:dFav' => 'Scheda: Salva', 'btn:dShare' => 'Scheda: Condividi', 'btn:dSv' => 'Scheda: Street View',
  'btn:dTrip' => 'Scheda: Aggiungi al trip', 'btn:wMore' => 'Scheda: tutti i vini', 'btn:tripNew' => 'Trip: nuovo', 'btn:tripSave' => 'Trip: salva',
  'btn:tripShow' => 'Trip: vedi sulla mappa', 'btn:tripSort' => 'Trip: ordine più corto', 'btn:mimg' => 'Scheda: tocca una foto', 'btn:mnav' => 'Scheda: freccia delle foto',
  'page:seo' => 'Pagine delle cantine (stati, regioni, comuni, schede)', 'page:account' => 'Pagina account', 'page:privacy' => 'Pagina privacy',
  'err:js' => 'Errore nella mappa',
  'support:map' => 'Dona: tocca "🥂 Cin cin!" sulla mappa', 'support:header' => 'Dona: "🥂 Supporta" in alto', 'support:footer' => 'Dona: link in fondo al pannello',
  'donate:view' => 'Dona: apre la pagina', 'donate:back' => 'Dona: torna alla mappa', 'donate:onlyview' => 'Dona: esce senza andare su Ko-fi',
  'donate:return' => 'Dona: torna da Ko-fi', 'donate:kofi-2' => 'Dona: su Ko-fi con 2 €', 'donate:kofi-5' => 'Dona: su Ko-fi con 5 €',
  'donate:kofi-10' => 'Dona: su Ko-fi con 10 €', 'donate:kofi-free' => 'Dona: su Ko-fi, importo libero',
];
const EV_GROUPS = ['app' => 'Mappa aperta e chiusa', 'consent' => 'Cookie', 'gps' => 'GPS', 'view' => 'Schede delle cantine', 'list' => 'Elenco',
  'search' => 'Ricerche', 'act' => 'Azioni', 'btn' => 'Pulsanti', 'link' => 'Link', 'page' => 'Altre pagine del sito', 'support' => 'Bottone dona', 'donate' => 'Pagina Dona', 'err' => 'Errori'];
function ev_label(string $e): string {
  if (isset(EV_LABELS[$e])) return EV_LABELS[$e];
  [$g, $k] = explode(':', $e, 2) + ['', ''];
  if ($g === 'link') return str_starts_with($k, 'ext/') ? 'Link esterno: ' . substr($k, 4) : 'Link: ' . $k;
  if ($g === 'search') return 'Ricerca: ' . $k;
  if ($g === 'act') return 'Azione: ' . str_replace('_', ' ', $k);
  if ($g === 'donate' && str_starts_with($k, 'from-')) return 'Dona: arriva da ' . substr($k, 5);
  if ($g === 'donate' && str_starts_with($k, 't-kofi-')) return 'Dona: va su Ko-fi dopo ' . substr($k, 7);
  if ($g === 'donate' && str_starts_with($k, 't-')) return 'Dona: resta sulla pagina ' . substr($k, 2);
  if ($g === 'donate' && str_starts_with($k, 'away-')) return 'Dona: su Ko-fi per ' . substr($k, 5);
  return $e;
}
// pagina d'ingresso della mappa (campo land di app_opens) in parole
function land_kind(?string $l): string {
  if ($l === null || $l === '') return 'non registrata';
  $q = (string)parse_url($l, PHP_URL_QUERY);
  parse_str($q, $p);
  if (isset($p['c'])) return 'Link di una cantina';
  if (isset($p['trip'])) return $p['trip'] === 'draft' ? 'Ritorno dall\'accesso (trip)' : 'Link di un trip';
  $path = (string)parse_url($l, PHP_URL_PATH);
  if ($path === '/' || $path === '') return isset($p['utm_source']) ? 'Mappa (link con campagna)' : 'Mappa';
  return $path;
}
