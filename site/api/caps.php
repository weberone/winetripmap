<?php
// Tetti dei servizi a consumo (regola dell'utente: limite gratuito del mese meno 100), letti da api/counter.php e da admin/consumi.php.
// Mappe: la prima della lista sotto il tetto è quella della visita; OpenFreeMap è senza costi e senza tetto.
const CAPS = ['google' => 9900, 'mapbox' => 4900];
// Altre chiamate a consumo, contate prima di farle (ok=false oltre il tetto).
const API_CAPS = ['gtext' => 4900, 'gphoto' => 900, 'static' => 4900];
// Per la pagina dei consumi: nome, fornitore, limite gratuito al mese, tetto giornaliero impostato nella console del fornitore (0 = nessuno).
const SERVICES = [
  'google' => ['Mappa Google', 'Google Maps · Dynamic Maps', 10000, 319],
  'gtext'  => ['Ricerca foto (Places)', 'Google Places · Text Search Pro', 5000, 158],
  'gphoto' => ['Foto della scheda', 'Google Places · Place Photos', 1000, 29],
  'mapbox' => ['Mappa Mapbox', 'Mapbox · Map Loads for Web (account senza carta)', 5000, 0],
  'static' => ['Satellite della scheda', 'Mapbox · Static Images (account senza carta)', 5000, 0],
  'free'   => ['Mappa OpenFreeMap', 'OpenFreeMap · senza costi, senza limiti', 0, 0],
];
