<?php
// Base delle pagine di admin/: solo per gli amministratori (account del sito, admins.json nella cartella privata),
// menu comune, protezione dei moduli (gettone CSRF legato alla sessione), formati di numeri e date.
require_once dirname(__DIR__) . '/api/lib.php';
require_once dirname(__DIR__) . '/api/page.php';
header('Cache-Control: no-store');

function admin_me(): array {
  $u = current_user();
  if (!is_admin($u)) { header('Location: /admin/'); exit; }
  return $u;
}
// gettone dei moduli: deriva dalla sessione, così un altro sito non può mandare azioni a nome dell'admin
function csrf(): string { return hash_hmac('sha256', (string)($_COOKIE[SESSION_COOKIE] ?? ''), cfg()['ip_salt'] . ':csrf'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_ok(): bool { return hash_equals(csrf(), (string)($_POST['csrf'] ?? '')); }

function num($n): string { return number_format((float)$n, 0, ',', '.'); }
function when(?string $t): string { return $t ? (new DateTime($t . ' UTC'))->setTimezone(new DateTimeZone('Europe/Rome'))->format('d/m/Y H:i') : '—'; }
function ago_days(int $d): string { return gmdate('Y-m-d H:i:s', time() - $d * 86400); }
// ultimi $n giorni (ora italiana) => 0, da riempire
function day_series(int $n): array {
  $out = []; $tz = new DateTimeZone('Europe/Rome');
  for ($i = $n - 1; $i >= 0; $i--) $out[(new DateTime("-$i days", $tz))->format('Y-m-d')] = 0;
  return $out;
}
function chart(string $title, array $days, string $note = ''): string {
  $series = h(json_encode(array_map(fn($d, $n) => [$d, $n], array_keys($days), array_values($days))));
  return '<figure class="card chart"><figcaption><b>' . h($title) . '</b><span class="muted">' . h($note ?: count($days) . ' giorni · ' . num(array_sum($days))) . '</span></figcaption>'
    . '<div class="cols" data-series="' . $series . '" role="img" aria-label="' . h($title) . '"></div>'
    . '<div class="xaxis"><span>' . (new DateTime(array_key_first($days)))->format('d/m') . '</span><span>oggi</span></div></figure>';
}
function hbars(array $rows, string $empty = 'Ancora nessun dato.'): string {
  if (!$rows) return '<p class="muted">' . h($empty) . '</p>';
  $max = max(array_column($rows, 'n')) ?: 1; $o = '';
  foreach ($rows as $r) $o .= '<div class="hbar"><span title="' . h($r['k']) . '">' . h($r['k'] ?: '—') . '</span><i style="width:' . round($r['n'] / $max * 100, 1) . '%"></i><b>' . num($r['n']) . '</b></div>';
  return $o;
}

function admin_header(string $title, string $active): void {
  $fb = (int)one("SELECT COUNT(*) FROM feedback WHERE status = 'new'");
  $menu = [['/', '← Mappa', 'app', 0], ['dashboard.php', 'Dashboard', 'dash', 0], ['consumi.php', 'Consumi', 'cons', 0], ['users.php', 'Utenti', 'users', 0],
    ['opens.php', 'Aperture', 'opens', 0], ['feedback.php', 'Feedback', 'fb', $fb], ['settings.php', 'Impostazioni', 'set', 0], ['/account/', 'Il mio account', 'acc', 0]];
  $links = ''; $opts = '';
  foreach ($menu as [$href, $label, $key, $cnt]) {
    $links .= '<a class="navlink' . ($key === $active ? ' on" aria-current="page' : '') . '" href="' . h($href) . '">' . h($label) . ($cnt ? " <b class=\"pill\">$cnt</b>" : '') . '</a>';
    $opts .= '<option value="' . h($href) . '"' . ($key === $active ? ' selected' : '') . '>' . h($label) . ($cnt ? " ($cnt)" : '') . '</option>';
  }
  // su telefono i pulsanti non ci stanno: un menu a tendina con le stesse pagine
  $sel = '<select class="anav" aria-label="Pagine dell\'admin" onchange="location.href=this.value">' . $opts . '</select>';
  page_start($title . ' · Admin', $links . $sel, 'dashboard.php');
  echo '<main class="w wide"><h1 class="ptitle">' . h($title) . '</h1>';
}
function admin_footer(bool $charts = false): void {
  echo '</main><div class="tip" id="tip" hidden></div>' . ($charts ? '<script src="/core/admin/charts.js"></script>' : '') . '</body></html>';
}
