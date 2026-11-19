<?php
// Informativa privacy e cookie (/privacy/), in italiano o inglese (?lang=it, altrimenti dalla lingua del browser).
// Titolare ed email per le richieste: Admin → Impostazioni → Privacy (settings.json nella cartella privata); finché mancano
// la pagina scrive "(da completare)". Il banner dei cookie (js/consent.js) porta qui (#cookie); il pulsante riapre la scelta.
require dirname(__DIR__) . '/api/lib.php';
require dirname(__DIR__) . '/api/page.php';
$q = (string)($_GET['lang'] ?? '');
$it = $q !== '' ? $q === 'it' : str_starts_with(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 'it');
$s = settings();
$who = trim((string)($s['privacy_controller'] ?? '')); $mail = trim((string)($s['privacy_email'] ?? ''));
$todo = $it ? '<b>(da completare)</b>' : '<b>(to be completed)</b>';
$contact = $mail ? '<a href="mailto:' . h($mail) . '">' . h($mail) . '</a>' : $todo;
header('Cache-Control: public, max-age=600');
page_start($it ? 'Privacy e cookie' : 'Privacy and cookies', '<a class="navlink" href="/">' . ($it ? 'Apri la mappa' : 'Open the map') . '</a>');
?>
<main class="w prose">
<p class="lead" style="margin-top:18px"><a href="?lang=it"<?= $it ? ' aria-current="true"' : '' ?>>Italiano</a> · <a href="?lang=en"<?= !$it ? ' aria-current="true"' : '' ?>>English</a></p>
<?php if ($it): ?>
<h1 class="ptitle">Informativa privacy e cookie</h1>
<p class="muted">Aggiornata il 18 novembre 2026.</p>
<h2>Chi tratta i dati</h2>
<p>Titolare del trattamento: <?= $who ? h($who) : $todo ?>. Per qualsiasi richiesta sui tuoi dati: <?= $contact ?>.</p>
<h2>Quali dati e perché</h2>
<ul>
  <li><b>Account</b> (solo se ti registri): email, nome facoltativo, password salvata solo in forma cifrata (hash) oppure l'identificativo del tuo account Google se entri con Google, lingua, data di iscrizione e dell'ultimo accesso. Servono per farti entrare e per i servizi dell'account. Base giuridica: esecuzione del servizio che chiedi.</li>
  <li><b>Preferiti e trip</b>: le cantine che salvi e gli itinerari che crei, collegati al tuo account. Senza account la bozza del trip resta solo nel tuo browser.</li>
  <li><b>Feedback</b>: i messaggi che ci mandi, con il tuo account, per risponderti e migliorare il sito.</li>
  <li><b>Sicurezza</b>: dell'indirizzo IP salviamo solo un'impronta non reversibile (hash), per limitare spam e tentativi di accesso.</li>
  <li><b>Aperture della mappa</b>: per le statistiche interne contiamo ogni apertura con un codice casuale del browser (cookie tecnico <code>wtm_vid</code>), da dove arrivi (sito o campagna di provenienza), tipo di dispositivo, lingua e fuso orario, quanto tempo resti sulla mappa, quante schede di cantine apri, se il GPS è stato attivato (sì o no, mai la posizione) e la tua scelta sui cookie. Contiamo anche quante volte vengono usati i pulsanti, in totali anonimi per giorno. Niente nome, email né posizione; se poi accedi, le aperture vengono collegate al tuo account.</li>
  <li><b>Percorso nella mappa</b> (solo se hai un account o premi "Accetta" sui cookie): le azioni che fai nella mappa con l'ora (per esempio quale cantina apri o cosa cerchi), per capire cosa migliorare. Restano 90 giorni (180 per gli account) e le vede solo l'amministratore del sito.</li>
  <li><b>Posizione</b>: se la permetti, il GPS viene usato solo nel tuo browser per mostrarti le cantine vicine; non la salviamo.</li>
</ul>
<h2 id="cookie">Cookie e memoria del browser</h2>
<ul>
  <li><b>Tecnici</b> (sempre, non chiedono consenso): <code>wtm_session</code> per restare collegato, <code>wtm_vid</code> per contare le aperture, e nella memoria del browser tema, lingua, bozza del trip e la tua scelta sui cookie.</li>
  <li><b>Statistiche</b> (solo se premi "Accetta"): Google Analytics 4 (Google Ireland Ltd) misura le visite in forma aggregata con i cookie <code>_ga</code> e <code>_ga_*</code>, con l'IP anonimizzato. Se rifiuti, Google Analytics non viene caricato. La scelta vale 12 mesi.</li>
</ul>
<p><button type="button" class="btn" onclick="window.wtmConsent && wtmConsent.open()">Cambia la scelta sui cookie</button></p>
<h2>Servizi esterni usati dalla mappa</h2>
<p>Per mostrare mappe e immagini il tuo browser si collega direttamente a questi servizi, che vedono il tuo indirizzo IP: Google Maps e Google Places (mappa e foto delle cantine), Mapbox e OpenFreeMap (mappe), Esri (foto dal satellite), Nominatim di OpenStreetMap tramite il nostro server (ricerca dei luoghi). Le foto delle bottiglie si caricano dai siti delle cantine. I dati delle cantine vengono da OpenStreetMap (© OpenStreetMap contributors, ODbL).</p>
<h2>Quanto li teniamo</h2>
<p>I dati dell'account finché hai l'account: puoi scaricarli in ogni momento dalla pagina <a href="/account/">Account</a> e chiedere di cancellare l'account scrivendo a <?= $contact ?>. Le statistiche di Google Analytics restano al massimo 14 mesi.</p>
<h2>I tuoi diritti</h2>
<p>Puoi chiedere accesso, rettifica, cancellazione, limitazione, portabilità e opporti al trattamento scrivendo a <?= $contact ?>. Puoi anche rivolgerti al Garante per la protezione dei dati personali (garanteprivacy.it).</p>
<?php else: ?>
<h1 class="ptitle">Privacy and cookie policy</h1>
<p class="muted">Updated on 18 November 2026.</p>
<h2>Who handles the data</h2>
<p>Data controller: <?= $who ? h($who) : $todo ?>. For any request about your data: <?= $contact ?>.</p>
<h2>What data and why</h2>
<ul>
  <li><b>Account</b> (only if you sign up): email, optional name, password stored only as a hash or your Google account identifier if you sign in with Google, language, sign-up and last sign-in dates. Used to let you in and for account features. Legal basis: providing the service you ask for.</li>
  <li><b>Favourites and trips</b>: the wineries you save and the itineraries you build, linked to your account. Without an account the trip draft stays only in your browser.</li>
  <li><b>Feedback</b>: the messages you send us, with your account, to reply and improve the site.</li>
  <li><b>Security</b>: we keep only a non-reversible fingerprint (hash) of your IP address, to limit spam and sign-in attempts.</li>
  <li><b>Map opens</b>: for internal statistics we count each opening with a random browser code (technical cookie <code>wtm_vid</code>), where you came from (referring site or campaign), device type, language and time zone, how long you stay on the map, how many winery cards you open, whether GPS was turned on (yes or no, never your location) and your cookie choice. We also count how often buttons are used, as anonymous daily totals. No name, email or location; if you sign in, your opens are linked to your account.</li>
  <li><b>Path in the map</b> (only if you have an account or press "Accept" on cookies): the actions you take in the map with the time (for example which winery you open or what you search for), to understand what to improve. Kept for 90 days (180 for accounts) and seen only by the site administrator.</li>
  <li><b>Location</b>: if you allow it, GPS is used only in your browser to show nearby wineries; we do not store it.</li>
</ul>
<h2 id="cookie">Cookies and browser storage</h2>
<ul>
  <li><b>Technical</b> (always, no consent needed): <code>wtm_session</code> to keep you signed in, <code>wtm_vid</code> to count map opens, and in browser storage your theme, language, trip draft and cookie choice.</li>
  <li><b>Statistics</b> (only if you press "Accept"): Google Analytics 4 (Google Ireland Ltd) measures visits in aggregate with the <code>_ga</code> and <code>_ga_*</code> cookies, with IP anonymisation. If you reject, Google Analytics is not loaded. Your choice lasts 12 months.</li>
</ul>
<p><button type="button" class="btn" onclick="window.wtmConsent && wtmConsent.open()">Change your cookie choice</button></p>
<h2>External services used by the map</h2>
<p>To show maps and images your browser connects directly to these services, which see your IP address: Google Maps and Google Places (map and winery photos), Mapbox and OpenFreeMap (maps), Esri (satellite images), OpenStreetMap Nominatim through our server (place search). Bottle photos load from the wineries' own websites. Winery data comes from OpenStreetMap (© OpenStreetMap contributors, ODbL).</p>
<h2>How long we keep it</h2>
<p>Account data for as long as you have an account: you can download it at any time from the <a href="/account/">Account</a> page and ask for your account to be deleted by writing to <?= $contact ?>. Google Analytics statistics are kept for at most 14 months.</p>
<h2>Your rights</h2>
<p>You can ask for access, correction, deletion, restriction and portability, and object to processing, by writing to <?= $contact ?>. You can also contact your data protection authority.</p>
<?php endif; ?>
</main>
</body></html>
