<?php
// Revisione a mano delle cantine dubbie (poche o nessuna prova da produttore: vedi tools/produttori.mjs dubbi → admin/dati/dubbi.json).
// Per ognuna: vista dal satellite (Esri World Imagery), motivi, sito; "Tieni" o "Togli". Le scelte stanno nella tabella revisioni;
// il workflow revisioni.yml le legge ogni giorno da api/revisioni.php e toglie dalla mappa quelle da togliere (e valgono anche ai giri mensili).
require __DIR__ . '/layout.php';
$me = admin_me();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_ok()) json_out(['error' => 'csrf'], 403);
  $id = (string)($_POST['id'] ?? ''); $d = (string)($_POST['d'] ?? '');
  if (!preg_match('/^([nwrq][0-9]{1,15}|o[0-9a-f]{12})$/', $id) || !in_array($d, ['t', 'k', ''], true)) json_out(['error' => 'input'], 400);
  if ($d === '') db()->prepare('DELETE FROM revisioni WHERE id = ?')->execute([$id]);
  else db()->prepare('INSERT INTO revisioni (id, d, at, by_user) VALUES (?, ?, ?, ?) ON CONFLICT(id) DO UPDATE SET d = excluded.d, at = excluded.at, by_user = excluded.by_user')
    ->execute([$id, $d, now(), (int)$me['id']]);
  json_out(['ok' => true]);
}
$D = json_decode((string)@file_get_contents(__DIR__ . '/dati/dubbi.json'), true) ?: ['n' => '', 'items' => []];
$dec = []; foreach (db()->query('SELECT id, d FROM revisioni') as $r) $dec[$r['id']] = $r['d'];
$vista = ($_GET['v'] ?? '') === 'decise' ? 'decise' : 'da';
// paesi con quante cantine ancora da decidere
$per = []; foreach ($D['items'] as $x) { $cc = strtok($x[4], ' ') ?: '?'; $per[$cc] ??= [0, 0]; $per[$cc][isset($dec[$x[0]]) ? 1 : 0]++; }
uasort($per, fn($a, $b) => $b[0] <=> $a[0]);
$cc = (string)($_GET['cc'] ?? ''); if (!isset($per[$cc])) $cc = isset($per['IT']) && $per['IT'][0] ? 'IT' : (string)array_key_first($per);
$list = array_values(array_filter($D['items'], fn($x) => (strtok($x[4], ' ') ?: '?') === $cc && (isset($dec[$x[0]]) === ($vista === 'decise'))));
$pag = 24; $tot = count($list); $p = max(1, min((int)($_GET['p'] ?? 1), max(1, (int)ceil($tot / $pag))));
$list = array_slice($list, ($p - 1) * $pag, $pag);
$cn = fn($c) => function_exists('locale_get_display_region') ? (locale_get_display_region('-' . $c, 'it') ?: $c) : $c;
// vista dal satellite: 3×3 piastrelle z16 con la cantina al centro di un riquadro di 256 px
$sat = function (float $lat, float $lng): string {
  $z = 16; $n = 2 ** $z; $x = ($lng + 180) / 360 * $n; $r = deg2rad($lat); $y = (1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n;
  $tx = (int)floor($x); $ty = (int)floor($y); $ox = 128 - (256 + ($x - $tx) * 256); $oy = 128 - (256 + ($y - $ty) * 256);
  $o = '<div class="sat"><div style="transform:translate(' . round($ox) . 'px,' . round($oy) . 'px)">';
  for ($j = -1; $j <= 1; $j++) for ($i = -1; $i <= 1; $i++)
    $o .= '<img loading="lazy" alt="" src="https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/' . $z . '/' . ($ty + $j) . '/' . ((($tx + $i) % $n + $n) % $n) . '">';
  return $o . '</div><i></i></div>';
};
$q = fn($a) => 'revisione.php?' . http_build_query(array_merge(['cc' => $cc, 'v' => $vista === 'decise' ? 'decise' : null], $a));
// chiave pubblica di Google (la stessa della mappa, limitata a winetripmap.com): presa da js/app.js per non ripeterla
$gkey = preg_match('/googleKey:\s*"([^"]+)"/', (string)@file_get_contents(dirname(__DIR__) . '/js/app.js'), $m) ? $m[1] : '';
admin_header('Revisione delle cantine', 'rev');
$tdec = count($dec); $tt = count(array_filter($dec, fn($d) => $d === 't'));
?>
<section class="card">
  <p style="margin-top:0">Cantine ancora sulla mappa con poche prove da produttore (punteggio da −1 a 1: niente sito, niente vigneti segnati vicino, nome generico).
    Guarda il satellite e scegli: <b>Tieni</b> se è una cantina che fa vino, <b>Togli</b> se è un negozio, un locale o altro.
    Le cantine tolte spariscono dalla mappa entro un giorno e la scelta vale anche negli aggiornamenti mensili.</p>
  <p class="muted" style="margin-bottom:0">Da decidere: <?= num(count($D['items']) - count(array_intersect_key($dec, array_flip(array_column($D['items'], 0))))) ?> · decise finora: <?= num($tdec) ?> (<?= num($tt) ?> da togliere) · elenco del <?= h($D['n']) ?></p>
  <form method="get" class="rfilt">
    <select name="cc" onchange="this.form.submit()"><?php foreach ($per as $k => [$a, $b]): ?><option value="<?= h($k) ?>"<?= $k === $cc ? ' selected' : '' ?>><?= h($cn($k)) ?> — <?= $a ?> da decidere<?= $b ? ", $b decise" : '' ?></option><?php endforeach; ?></select>
    <select name="v" onchange="this.form.submit()"><option value="">Da decidere</option><option value="decise"<?= $vista === 'decise' ? ' selected' : '' ?>>Già decise</option></select>
  </form>
</section>
<?php if (!$list): ?><section class="card"><p class="muted">Niente qui.</p></section><?php endif; ?>
<div class="rgrid">
<?php foreach ($list as [$id, $lat, $lng, $name, $place, $score, $why, $web]): $d = $dec[$id] ?? ''; ?>
  <article class="card rc<?= $d ? ' is-' . $d : '' ?>" data-id="<?= h($id) ?>" data-lat="<?= h($lat) ?>" data-lng="<?= h($lng) ?>" data-q="<?= h(trim($name . ' ' . substr($place, 3))) ?>">
    <?= $sat((float)$lat, (float)$lng) ?>
    <h3><?= h($name ?: '(senza nome)') ?></h3>
    <p class="muted"><?= h(substr($place, 3) ?: $place) ?> · punteggio <?= (int)$score ?></p>
    <p class="why"><?= h($why ?: 'nessuna prova né a favore né contro') ?></p>
    <p class="lnk"><a href="/?c=<?= h($id) ?>" target="_blank" rel="noopener">Sulla mappa</a>
      · <a href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($lat . ',' . $lng) ?>" target="_blank" rel="noopener">Google Maps</a>
      · <a href="https://www.google.com/search?q=<?= rawurlencode(trim($name . ' ' . substr($place, 3))) ?>" target="_blank" rel="noopener">Cerca</a>
      <?= $web ? ' · <a href="' . h($web) . '" target="_blank" rel="noopener nofollow">Sito</a>' : '' ?>
      · <?php if ($id[0] === 'q'): ?><a href="https://www.wikidata.org/wiki/Q<?= h(substr($id, 1)) ?>" target="_blank" rel="noopener">Wikidata</a><?php else: ?><a href="https://www.openstreetmap.org/<?= ['n' => 'node', 'w' => 'way', 'r' => 'relation'][$id[0]] ?? 'node' ?>/<?= h(substr($id, 1)) ?>" target="_blank" rel="noopener">OSM</a><?php endif; ?></p>
    <div class="gbox" hidden></div>
    <div class="rbtn"><button class="btn small gbtn" data-g="1" type="button">Vedi su Google</button></div>
    <div class="rbtn"><button class="btn small" data-d="k">Tieni</button><button class="btn small danger" data-d="t">Togli</button><button class="btn small undo" data-d="">Annulla</button></div>
    <p class="rstate"></p>
  </article>
<?php endforeach; ?>
</div>
<?php if ($tot > $pag): ?><p class="pager"><?php if ($p > 1): ?><a class="btn small" href="<?= h($q(['p' => $p - 1])) ?>">← Precedenti</a><?php endif; ?>
  <span class="muted"><?= ($p - 1) * $pag + 1 ?>–<?= min($tot, $p * $pag) ?> di <?= $tot ?></span>
  <?php if ($p * $pag < $tot): ?><a class="btn small" href="<?= h($q(['p' => $p + 1])) ?>">Successive →</a><?php endif; ?></p><?php endif; ?>
<style>
.rfilt{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px} .rfilt select{max-width:100%}
.rgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,280px),1fr));gap:14px;margin-top:14px}
.rc{display:flex;flex-direction:column;gap:4px;margin:0} .rc h3{margin:6px 0 0;font-size:16px} .rc p{margin:0}
.sat{position:relative;width:100%;height:256px;overflow:hidden;border-radius:10px;background:#333}
.sat>div{position:absolute;left:calc(50% - 128px);top:0;width:768px;display:grid;grid-template-columns:repeat(3,256px)} .sat img{width:256px;height:256px;display:block}
.sat i{position:absolute;left:50%;top:50%;width:14px;height:14px;margin:-7px 0 0 -7px;border:3px solid #fff;border-radius:50%;background:#c0392b;box-shadow:0 0 0 2px rgb(0 0 0 / .4)}
.why{font-size:13px} .lnk{font-size:13.5px}
.rbtn{display:flex;gap:8px;padding-top:8px} .rbtn:last-of-type{margin-top:auto}
.gbox{font-size:13.5px;background:var(--soft,rgb(127 127 127 / .1));border-radius:8px;padding:8px 10px} .gbox p{margin:2px 0} .gbox .bad{color:var(--err,#b42318);font-weight:700} .rbtn .undo{display:none}
.btn.danger{background:var(--err,#b42318);border-color:var(--err,#b42318);color:#fff}
.rc.is-k,.rc.is-t{opacity:.6} .rc.is-k .rbtn [data-d=k],.rc.is-t .rbtn [data-d=t]{display:none} .rc.is-k .undo,.rc.is-t .undo{display:inline-block}
.rc.is-k .rstate::before{content:'Tenuta';color:#1e8c46;font-weight:700} .rc.is-t .rstate::before{content:'Da togliere';color:var(--err,#b42318);font-weight:700}
.pager{display:flex;gap:12px;align-items:center;justify-content:center;margin:18px 0}
</style>
<script>
// "Vedi su Google": una ricerca Places (New) solo quando la si chiede, mostrata qui e non salvata (condizioni di Google Maps Platform);
// conta nel tetto gtext di api/counter.php come le foto della scheda (Text Search Pro: tipo, stato, indirizzo, link a Google Maps)
const GKEY = <?= json_encode($gkey) ?>;
let gLib = null;
const esc = (v) => String(v ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
async function places() {
  if (gLib) return gLib;
  if (!window.google?.maps?.importLibrary) {
    await new Promise((ok, ko) => { const s = document.createElement('script'); s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(GKEY) + '&v=weekly&loading=async'; s.onload = ok; s.onerror = ko; document.head.appendChild(s); });
    for (let i = 0; i < 50 && !window.google?.maps?.importLibrary; i++) await new Promise((f) => setTimeout(f, 100));
  }
  return (gLib = await google.maps.importLibrary('places'));
}
const STATO = { CLOSED_PERMANENTLY: 'chiusa definitivamente', CLOSED_TEMPORARILY: 'chiusa temporaneamente', OPERATIONAL: 'aperta' };
async function vediGoogle(card, b) {
  const box = card.querySelector('.gbox'); box.hidden = false; box.textContent = 'Cerco su Google…'; b.disabled = true;
  try {
    const ok = await fetch('/api/counter.php?api=gtext&n=1', { cache: 'no-store' }).then((r) => r.json()).then((j) => j.ok === true).catch(() => false);
    if (!ok) { box.textContent = 'Quota del mese delle ricerche Google finita (vedi Consumi).'; return; }
    const lib = await places();
    const lat = +card.dataset.lat, lng = +card.dataset.lng;
    const r = await lib.Place.searchByText({ textQuery: card.dataset.q, fields: ['displayName', 'formattedAddress', 'location', 'primaryTypeDisplayName', 'types', 'businessStatus', 'googleMapsURI'],
      locationBias: { center: { lat, lng }, radius: 500 }, maxResultCount: 1 });
    const p = r.places?.[0];
    if (!p) { box.innerHTML = '<p>Google non trova niente con questo nome qui vicino.</p>'; return; }
    const R = 6371, dl = (p.location.lat() - lat) * Math.PI / 180, dn = (p.location.lng() - lng) * Math.PI / 180;
    const km = 2 * R * Math.asin(Math.sqrt(Math.sin(dl / 2) ** 2 + Math.cos(lat * Math.PI / 180) * Math.cos(p.location.lat() * Math.PI / 180) * Math.sin(dn / 2) ** 2));
    const tipi = (p.types || []).filter((t) => !['point_of_interest', 'establishment', 'food', 'store'].includes(t)).slice(0, 6).join(', ');
    const st = p.businessStatus || '';
    box.innerHTML = `<p><b>${esc(p.displayName)}</b> <small>(a ${km < 1 ? Math.round(km * 1000) + ' m' : km.toFixed(1) + ' km'} dal punto)</small></p>`
      + (p.primaryTypeDisplayName ? `<p>Tipo: <b>${esc(p.primaryTypeDisplayName)}</b>${tipi ? ' <small>· ' + esc(tipi) + '</small>' : ''}</p>` : (tipi ? `<p>Tipo: ${esc(tipi)}</p>` : ''))
      + (st ? `<p${/CLOSED/.test(st) ? ' class="bad"' : ''}>Stato: ${esc(STATO[st] || st)}</p>` : '')
      + (p.formattedAddress ? `<p><small>${esc(p.formattedAddress)}</small></p>` : '')
      + (p.googleMapsURI ? `<p><a href="${esc(p.googleMapsURI)}" target="_blank" rel="noopener">Apri in Google Maps</a> <small>(foto e recensioni)</small></p>` : '')
      + (km > 1.5 ? '<p class="bad">Lontano dal punto: forse è un altro posto.</p>' : '');
  } catch (e) { box.textContent = 'Google non risponde (' + (e.message || e) + ').'; }
  finally { b.disabled = false; }
}
document.querySelector('.rgrid')?.addEventListener('click', async (ev) => {
  const g = ev.target.closest('.gbtn'); if (g) return vediGoogle(g.closest('.rc'), g);
  const b = ev.target.closest('.rbtn button[data-d]'); if (!b) return;
  const card = b.closest('.rc'); const d = b.dataset.d;
  b.disabled = true;
  try {
    const r = await fetch('revisione.php', { method: 'POST', body: new URLSearchParams({ csrf: <?= json_encode(csrf()) ?>, id: card.dataset.id, d }) });
    if (!r.ok) throw new Error(r.status);
    card.classList.remove('is-k', 'is-t'); if (d) card.classList.add('is-' + d);
  } catch (e) { alert('Non salvato, riprova (' + e.message + ')'); }
  b.disabled = false;
});
</script>
<?php admin_footer(); ?>
