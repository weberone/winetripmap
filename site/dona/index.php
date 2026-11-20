<?php
// Pagina delle donazioni (/dona/), come quella di SkateboardMap: due righe sul perché, importi piccoli suggeriti e il link
// alla pagina di pagamento su Ko-fi (lo stesso account di SkateboardMap). Ci si arriva da "🥂 Cin cin!" sulla mappa (telefono),
// "🥂 Supporta" in alto (computer) e dal link in fondo al pannello. Testi nelle 12 lingue della mappa; quello che manca prende l'inglese.
// Conteggi in api/track.php (donate:…): da dove arriva, importo scelto, quanto resta, chi torna da Ko-fi.
require dirname(__DIR__) . '/api/lib.php';
require dirname(__DIR__) . '/api/page.php';
const KOFI_URL = 'https://ko-fi.com/skateboardmap';
$LANGS = ['en', 'it', 'es', 'pt', 'fr', 'de', 'ru', 'ja', 'zh', 'pl', 'ko', 'id'];
$lang = (string)($_GET['lang'] ?? '');
if (!in_array($lang, $LANGS, true)) $lang = in_array($b = substr(strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')), 0, 2), $LANGS, true) ? $b : 'en';
$TX = [
 'en' => ['t' => 'Enjoying WineTripMap?', 'p1' => 'A map of the world’s wineries, kept up to date and ad-free, to plan a wine trip in a few taps.',
   'p2' => 'Maps, photos and servers cost every month. If the map helped you find a good winery, buy me a glass.',
   'c' => ['☕ A coffee', '🍷 A glass of wine', '🍾 A bottle'], 'free' => 'Or choose the amount you like', 'go' => 'Support WineTripMap',
   'note' => 'No strings attached: the map stays open to everyone. Thanks for being part of it 🥂', 'back' => '← Back to the map', 'minor' => '✋ Under 18? Ask a parent first'],
 'it' => ['t' => 'Ti piace WineTripMap?', 'p1' => 'Una mappa delle cantine di tutto il mondo, aggiornata e senza pubblicità, per organizzare un viaggio del vino in pochi tocchi.',
   'p2' => 'Mappe, foto e server costano ogni mese. Se la mappa ti ha fatto trovare una bella cantina, offrimi un calice! 😉',
   'c' => ['☕ Un caffè', '🍷 Un calice di vino', '🍾 Una bottiglia'], 'free' => 'Oppure scegli tu quanto', 'go' => 'Sostieni WineTripMap',
   'note' => 'Nessun impegno: la mappa resta aperta a tutti. Grazie di farne parte 🥂', 'back' => '← Torna alla mappa', 'minor' => '✋ Hai meno di 18 anni? Chiedi prima a un genitore'],
 'es' => ['t' => '¿Te gusta WineTripMap?', 'p1' => 'Un mapa de las bodegas de todo el mundo, al día y sin publicidad, para organizar una ruta del vino en pocos toques.',
   'p2' => 'Mapas, fotos y servidores cuestan cada mes. Si el mapa te ayudó a encontrar una buena bodega, invítame a una copa.',
   'c' => ['☕ Un café', '🍷 Una copa de vino', '🍾 Una botella'], 'free' => 'O elige tú cuánto', 'go' => 'Apoya WineTripMap',
   'note' => 'Sin compromiso: el mapa sigue abierto para todos. Gracias por ser parte 🥂', 'back' => '← Volver al mapa', 'minor' => '✋ ¿Tienes menos de 18 años? Pregunta antes a tus padres'],
 'pt' => ['t' => 'Gostas do WineTripMap?', 'p1' => 'Um mapa das adegas de todo o mundo, atualizado e sem publicidade, para planear uma viagem do vinho em poucos toques.',
   'p2' => 'Mapas, fotos e servidores custam todos os meses. Se o mapa te ajudou a encontrar uma boa adega, paga-me um copo.',
   'c' => ['☕ Um café', '🍷 Um copo de vinho', '🍾 Uma garrafa'], 'free' => 'Ou escolhe tu o valor', 'go' => 'Apoia o WineTripMap',
   'note' => 'Sem compromisso: o mapa continua aberto a todos. Obrigado por fazeres parte 🥂', 'back' => '← Voltar ao mapa', 'minor' => '✋ Tens menos de 18 anos? Pergunta primeiro a um dos teus pais'],
 'fr' => ['t' => 'Tu aimes WineTripMap ?', 'p1' => 'Une carte des domaines viticoles du monde entier, à jour et sans pub, pour organiser une route des vins en quelques gestes.',
   'p2' => 'Cartes, photos et serveurs coûtent chaque mois. Si la carte t’a fait découvrir un beau domaine, offre-moi un verre.',
   'c' => ['☕ Un café', '🍷 Un verre de vin', '🍾 Une bouteille'], 'free' => 'Ou choisis le montant', 'go' => 'Soutenir WineTripMap',
   'note' => 'Sans engagement : la carte reste ouverte à tous. Merci d’en faire partie 🥂', 'back' => '← Retour à la carte', 'minor' => '✋ Moins de 18 ans ? Demande d’abord à un parent'],
 'de' => ['t' => 'Gefällt dir WineTripMap?', 'p1' => 'Eine Karte der Weingüter weltweit, aktuell und ohne Werbung, um eine Weinreise mit wenigen Klicks zu planen.',
   'p2' => 'Karten, Fotos und Server kosten jeden Monat. Wenn dir die Karte ein schönes Weingut gezeigt hat, spendier mir ein Glas.',
   'c' => ['☕ Ein Kaffee', '🍷 Ein Glas Wein', '🍾 Eine Flasche'], 'free' => 'Oder wähl selbst den Betrag', 'go' => 'WineTripMap unterstützen',
   'note' => 'Ganz unverbindlich: Die Karte bleibt für alle offen. Danke, dass du dabei bist 🥂', 'back' => '← Zurück zur Karte', 'minor' => '✋ Unter 18? Frag vorher deine Eltern'],
 'ru' => ['t' => 'Нравится WineTripMap?', 'p1' => 'Карта виноделен всего мира, актуальная и без рекламы, чтобы спланировать винное путешествие в пару касаний.',
   'p2' => 'Карты, фото и серверы стоят денег каждый месяц. Если карта помогла найти хорошую винодельню, угости меня бокалом.',
   'c' => ['☕ Кофе', '🍷 Бокал вина', '🍾 Бутылка'], 'free' => 'Или выбери сумму сам', 'go' => 'Поддержать WineTripMap',
   'note' => 'Без обязательств: карта остаётся открытой для всех. Спасибо, что ты с нами 🥂', 'back' => '← Назад к карте', 'minor' => '✋ Тебе меньше 18? Сначала спроси родителей'],
 'ja' => ['t' => 'WineTripMap、気に入った？', 'p1' => '世界中のワイナリーの地図。広告なしで最新の情報を、ワイン旅の計画に。',
   'p2' => '地図、写真、サーバーには毎月費用がかかります。素敵なワイナリーが見つかったら、ワイン1杯分の応援を。',
   'c' => ['☕ コーヒー1杯', '🍷 ワイン1杯', '🍾 ボトル1本'], 'free' => '金額は自由に選べます', 'go' => 'WineTripMapを応援',
   'note' => '気軽にどうぞ。地図はこれからもみんなに開かれています。ありがとう 🥂', 'back' => '← 地図に戻る', 'minor' => '✋ 18歳未満の人は、まず保護者に聞いてね'],
 'zh' => ['t' => '喜欢 WineTripMap 吗？', 'p1' => '全球酒庄地图，持续更新，没有广告，轻点几下就能规划葡萄酒之旅。',
   'p2' => '地图、照片和服务器每月都有费用。如果地图帮你找到了一家好酒庄，请我喝一杯吧。',
   'c' => ['☕ 一杯咖啡', '🍷 一杯葡萄酒', '🍾 一瓶酒'], 'free' => '或者自己选金额', 'go' => '支持 WineTripMap',
   'note' => '没有任何负担：地图继续向所有人开放。谢谢你的参与 🥂', 'back' => '← 返回地图', 'minor' => '✋ 未满 18 岁？请先问问家长'],
 'pl' => ['t' => 'Lubisz WineTripMap?', 'p1' => 'Mapa winnic z całego świata, aktualna i bez reklam, żeby zaplanować winną podróż w kilka kliknięć.',
   'p2' => 'Mapy, zdjęcia i serwery kosztują co miesiąc. Jeśli dzięki mapie znalazłeś dobrą winnicę, postaw mi kieliszek.',
   'c' => ['☕ Kawa', '🍷 Kieliszek wina', '🍾 Butelka'], 'free' => 'Albo wybierz kwotę sam', 'go' => 'Wesprzyj WineTripMap',
   'note' => 'Bez zobowiązań: mapa zostaje otwarta dla wszystkich. Dzięki, że jesteś z nami 🥂', 'back' => '← Wróć do mapy', 'minor' => '✋ Masz mniej niż 18 lat? Najpierw zapytaj rodzica'],
 'ko' => ['t' => 'WineTripMap 마음에 드세요?', 'p1' => '전 세계 와이너리 지도. 광고 없이 최신 정보로, 몇 번의 터치로 와인 여행을 계획하세요.',
   'p2' => '지도, 사진, 서버에는 매달 비용이 들어요. 좋은 와이너리를 찾았다면 와인 한 잔 사 주세요.',
   'c' => ['☕ 커피 한 잔', '🍷 와인 한 잔', '🍾 와인 한 병'], 'free' => '금액은 직접 정해도 돼요', 'go' => 'WineTripMap 후원하기',
   'note' => '부담 갖지 마세요. 지도는 계속 모두에게 열려 있어요. 함께해 줘서 고마워요 🥂', 'back' => '← 지도로 돌아가기', 'minor' => '✋ 18세 미만이라면 먼저 부모님께 물어봐요'],
 'id' => ['t' => 'Suka WineTripMap?', 'p1' => 'Peta kilang anggur di seluruh dunia, selalu update dan tanpa iklan, untuk merencanakan wisata anggur dalam beberapa ketukan.',
   'p2' => 'Peta, foto, dan server butuh biaya tiap bulan. Kalau peta ini membantumu menemukan kilang anggur yang bagus, traktir aku segelas.',
   'c' => ['☕ Secangkir kopi', '🍷 Segelas anggur', '🍾 Sebotol'], 'free' => 'Atau pilih jumlahnya sendiri', 'go' => 'Dukung WineTripMap',
   'note' => 'Tanpa kewajiban: peta tetap terbuka untuk semua. Terima kasih sudah jadi bagian 🥂', 'back' => '← Kembali ke peta', 'minor' => '✋ Di bawah 18 tahun? Tanya orang tua dulu ya'],
];
$T = $TX[$lang] + $TX['en'];
$amounts = [2, 5, 10]; // come su Ko-fi
$logged = false; try { $logged = (bool)current_user(); } catch (Throwable $e) {}
header('Cache-Control: private, max-age=0');
ob_start(); page_start($T['go'], '<a class="navlink" href="/?lang=' . h($lang) . '">' . h(ltrim($T['back'], '← ')) . '</a>');
echo str_replace('<html lang="it">', '<html lang="' . h($lang) . '">', ob_get_clean());
?>
<style>
.dona{max-width:520px;margin:0 auto;padding:18px 16px 40px;text-align:center}
.dona .glass{font-size:52px;line-height:1;margin:10px 0 4px}
.dona h1{margin:6px 0 12px;font-size:26px}
.dona p{line-height:1.55}
.dona .lead{font-size:17px}
.amts{display:grid;gap:10px;margin:18px 0 8px}
.amt{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border:1.5px solid var(--line);border-radius:12px;text-decoration:none;color:var(--ink);font-weight:600;background:var(--surface)}
.amt b{font-size:18px;font-weight:800;color:var(--accent)}
.amt:hover{border-color:var(--accent)}
.dona .free{display:inline-block;margin-top:6px}
.dona .small{font-size:13px;color:var(--muted)}
.dona .note{color:var(--muted);font-size:14px;margin-top:22px}
</style>
<main class="dona">
  <div class="glass" aria-hidden="true">🥂</div>
  <h1><?= h($T['t']) ?></h1>
  <p class="lead"><?= h($T['p1']) ?></p>
  <p><?= preg_replace('/^(.*?[.。])\s*/u', '$1<br>', h($T['p2']), 1) ?></p>
  <div class="amts">
  <?php foreach ($amounts as $i => $a): ?>
    <a class="amt" href="<?= h(KOFI_URL) ?>" target="_blank" rel="noopener" data-amount="<?= $a ?>"><span><?= h($T['c'][$i]) ?></span><b><?= $a ?> €</b></a>
  <?php endforeach; ?>
  </div>
  <a class="free" href="<?= h(KOFI_URL) ?>" target="_blank" rel="noopener" data-amount="free"><?= h($T['free']) ?> →</a>
  <p class="small"><?= h($T['minor']) ?></p>
  <p class="note"><?= h($T['note']) ?></p>
  <p><a href="/?lang=<?= h($lang) ?>" class="back"><?= h($T['back']) ?></a></p>
</main>
<script>
// conteggi (api/track.php → Admin → Visite): da dove arriva, importo scelto, quanto resta, chi torna da Ko-fi.
// Con un account o con i cookie accettati le stesse azioni finiscono anche nel percorso (stessa sessione della mappa: wtm_sid).
(() => {
  const LOGGED = <?= $logged ? 'true' : 'false' ?>, t0 = Date.now(), ev = [], q = [];
  let consent = false; try { consent = !!(window.wtmConsent && wtmConsent.given()); } catch (e) {}
  let sid = ""; try { sid = sessionStorage.getItem("wtm_sid") || ""; if (!/^[a-z0-9]{8,40}$/.test(sid)) { sid = (Math.random().toString(36).slice(2, 10) + Date.now().toString(36)).toLowerCase(); sessionStorage.setItem("wtm_sid", sid); } } catch (e) {}
  const journey = (LOGGED || consent) && sid;
  const add = (e, x) => { ev.push(e); if (journey) q.push([Date.now(), e, x || ""]); };
  const send = (beacon) => {
    if (!ev.length && !q.length) return;
    const p = { e: ev.splice(0, 50) }; if (journey && q.length) Object.assign(p, { sid, j: q.splice(0, 50) });
    const body = JSON.stringify(p);
    try { if (beacon && navigator.sendBeacon) navigator.sendBeacon("/api/track.php", new Blob([body], { type: "application/json" }));
      else fetch("/api/track.php", { method: "POST", headers: { "Content-Type": "application/json" }, body, keepalive: true }); } catch (x) {}
  };
  const from = (new URLSearchParams(location.search).get("from") || "link").replace(/[^a-z]/g, "").slice(0, 12) || "link";
  const bucket = () => { const s = (Date.now() - t0) / 1000; return s < 10 ? "10s" : s < 30 ? "30s" : s < 120 ? "2m" : "more"; };
  let kofi = 0, away = 0, gone = false;
  add("donate:view", "pagina Dona (" + document.documentElement.lang + ")"); add("donate:from-" + from, document.referrer.slice(0, 120)); send(false);
  document.querySelectorAll("[data-amount]").forEach((a) => a.addEventListener("click", () => {
    const k = a.dataset.amount; kofi++; add("donate:kofi-" + k, k === "free" ? "importo libero" : k + " €"); if (kofi === 1) add("donate:t-kofi-" + bucket(), ""); send(true);
  }));
  document.querySelectorAll("a.back, .navlink").forEach((a) => a.addEventListener("click", () => { add("donate:back", "torna alla mappa"); send(true); }));
  // Ko-fi si apre in un'altra scheda: quando si torna qui, quanto è stato via (pochi secondi = non ha pagato)
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) { if (kofi) away = Date.now(); return; }
    if (kofi && away) { const s = (Date.now() - away) / 1000; add("donate:return", Math.round(s) + " s su Ko-fi"); add("donate:away-" + (s < 20 ? "20s" : s < 60 ? "60s" : s < 180 ? "3m" : "more"), ""); send(false); away = 0; }
  });
  addEventListener("pagehide", () => { if (gone) return; gone = true; add("donate:t-" + bucket(), Math.round((Date.now() - t0) / 1000) + " s sulla pagina"); if (!kofi) add("donate:onlyview", "uscito senza andare su Ko-fi"); send(true); });
})();
</script>
</body></html>
