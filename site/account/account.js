// Pagina account (account/index.php): accesso, registrazione, Google, conferma email, nuova password, profilo,
// cantine preferite, consigli, uscita e cancellazione. Tutto passa da /api/auth.php (JSON).
(() => {
"use strict";
const C = window.WTM_ACCOUNT || {}, $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
async function api(a, body, url) {
  const r = await fetch(url || `/api/auth.php?a=${a}`, body === undefined ? { credentials: "same-origin" }
    : { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  let d = {}; try { d = await r.json(); } catch (e) {}
  if (!r.ok) throw Object.assign(new Error(d.error || "error"), { data: d, status: r.status });
  return d;
}
const MSG = {
  wrong: "Email o password sbagliate.", google_only: "Questo account usa l'accesso con Google: usa il pulsante Google.",
  exists: "Questa email ha già un account: accedi.", email: "Controlla l'email.", password: "La password deve avere almeno 8 caratteri.",
  too_many: "Troppi tentativi: riprova tra qualche minuto.", token: "Il link non è più valido: chiedine uno nuovo.",
  google: "Accesso con Google non riuscito.", message: "Scrivi qualche parola in più.",
};
const say = (el, e) => { el.textContent = MSG[e.message] || "Qualcosa è andato storto, riprova."; el.hidden = false; };
const done = () => { location.href = C.next || "/"; };
const busy = (form, on) => form.querySelectorAll("button").forEach((b) => { b.disabled = on; });

// ---------- conferma email ----------
if (C.verify) {
  api("verify", { token: C.verify }).then(() => { $("vMsg").textContent = "Email confermata, grazie!"; $("vMsg").className = "ok"; history.replaceState(null, "", "/account/"); })
    .catch((e) => { $("vMsg").textContent = MSG[e.message] || "Il link non è valido."; $("vMsg").className = "err"; });
}
// ---------- nuova password ----------
if ($("resetForm")) $("resetForm").onsubmit = async (ev) => {
  ev.preventDefault(); const f = ev.target; busy(f, true); $("rErr").hidden = true;
  try { await api("reset", { token: C.reset, password: $("rPw").value }); location.href = "/account/"; }
  catch (e) { say($("rErr"), e); busy(f, false); }
};
// ---------- accesso e registrazione ----------
if ($("loginForm")) {
  const show = (which) => {
    $("loginForm").hidden = which !== "login"; $("regForm").hidden = which !== "reg"; $("forgotForm").hidden = which !== "forgot";
    $("tLogin").setAttribute("aria-selected", String(which === "login")); $("tReg").setAttribute("aria-selected", String(which === "reg"));
  };
  $("tLogin").onclick = () => show("login"); $("tReg").onclick = () => show("reg");
  $("toForgot").onclick = () => { $("fEmail").value = $("lEmail").value; show("forgot"); };
  $("backLogin").onclick = () => show("login");
  if (location.hash === "#registrati") show("reg");
  $("loginForm").onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; busy(f, true); $("lErr").hidden = true;
    try { await api("login", { email: $("lEmail").value, password: $("lPw").value }); done(); }
    catch (e) { say($("lErr"), e); busy(f, false); }
  };
  $("regForm").onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; busy(f, true); $("gErr").hidden = true;
    try { await api("register", { name: $("gName").value, email: $("gEmail").value, password: $("gPw").value }); done(); }
    catch (e) { say($("gErr"), e); busy(f, false); if (e.message === "exists") { $("lEmail").value = $("gEmail").value; } }
  };
  $("forgotForm").onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; busy(f, true); $("fErr").hidden = true; $("fOk").hidden = true;
    try { const d = await api("forgot", { email: $("fEmail").value });
      $("fOk").textContent = d.mail === false ? "La posta del sito non è ancora attiva: scrivici dalla pagina dei contatti." : "Se l'email ha un account, tra poco ti arriva il link (controlla anche lo spam).";
      $("fOk").hidden = false; }
    catch (e) { say($("fErr"), e); }
    busy(f, false);
  };
  // Google Identity Services: pulsante ufficiale, il "credential" va verificato dal server
  if (C.google) {
    const init = () => {
      if (!window.google || !google.accounts) return setTimeout(init, 150);
      google.accounts.id.initialize({ client_id: C.google, callback: async (resp) => {
        try { await api("google", { credential: resp.credential }); done(); } catch (e) { say($("lErr"), e); }
      } });
      google.accounts.id.renderButton($("gBtn"), { type: "standard", theme: "outline", size: "large", shape: "pill", text: "continue_with", width: 300, locale: "it" });
    };
    init();
  }
}
// ---------- account ----------
if ($("favs")) {
  const load = async () => {
    try {
      const d = await api("", undefined, "/api/fav.php");
      $("favs").innerHTML = d.items.length ? d.items.map((x) => `<li><a href="/?c=${encodeURIComponent(x.id)}"><b>${esc(x.name || "Cantina")}</b><small>${esc(x.town || "")}</small></a>
        <button class="btn small" data-id="${esc(x.id)}">Togli</button></li>`).join("")
        : `<li class="muted">Ancora nessuna: nella scheda di una cantina tocca il cuore per salvarla.</li>`;
      $("favs").querySelectorAll("button[data-id]").forEach((b) => b.onclick = async () => { b.disabled = true; await api("", { id: b.dataset.id, on: false }, "/api/fav.php").catch(() => {}); load(); });
    } catch (e) { $("favs").innerHTML = `<li class="err">Non riesco a caricare le preferite.</li>`; }
  };
  load();
  // cantine visitate (pulsante "Segna visitata" nella scheda della mappa): elenco e link alla mappa 3D personale (/?visited=1)
  const loadVisits = async () => {
    try {
      const d = await api("", undefined, "/api/visit.php");
      $("visAll").hidden = !d.items.length;
      $("visN").textContent = d.items.length === 1 ? "1 cantina" : d.items.length + " cantine";
      $("visits").innerHTML = d.items.length ? d.items.map((x) => `<li><a href="/?c=${encodeURIComponent(x.id)}"><b>${esc(x.name || "Cantina")}</b><small>${esc([x.town, x.at].filter(Boolean).join(" · "))}</small></a>
        <button class="btn small" data-vid="${esc(x.id)}">Togli</button></li>`).join("")
        : `<li class="muted">Ancora nessuna: nella scheda di una cantina tocca «Segna visitata».</li>`;
      $("visits").querySelectorAll("button[data-vid]").forEach((b) => b.onclick = async () => { b.disabled = true; await api("", { id: b.dataset.vid, on: false }, "/api/visit.php").catch(() => {}); loadVisits(); });
    } catch (e) { $("visits").innerHTML = `<li class="err">Non riesco a caricare le cantine visitate.</li>`; }
  };
  loadVisits();
  // trip: si aprono nella mappa (?trip=id)
  const loadTrips = async () => {
    try {
      const d = await api("", undefined, "/api/trip.php");
      $("trips").innerHTML = d.items.length ? d.items.map((x) => `<li><a href="/?trip=${x.id}"><b>${esc(x.name)}</b><small>${x.n === 1 ? "1 tappa" : x.n + " tappe"}</small></a>
        <a class="btn small" href="/?trip=${x.id}&edit=1">Modifica</a> <button class="btn small" data-trip="${x.id}">Elimina</button></li>`).join("")
        : `<li class="muted">Ancora nessuno: nella mappa apri una cantina e tocca «Aggiungi al trip».</li>`;
      $("trips").querySelectorAll("button[data-trip]").forEach((b) => b.onclick = async () => {
        if (!confirm("Eliminare questo trip?")) return;
        b.disabled = true; await api("", { id: +b.dataset.trip, del: true }, "/api/trip.php").catch(() => {}); loadTrips();
      });
    } catch (e) { $("trips").innerHTML = `<li class="err">Non riesco a caricare i trip.</li>`; }
  };
  loadTrips();
  if ($("resend")) $("resend").onclick = async () => {
    $("resend").disabled = true;
    try { const d = await api("resend", {}); $("resendMsg").textContent = d.ok ? "Mandato." : "La posta del sito non è ancora attiva."; }
    catch (e) { $("resendMsg").textContent = MSG[e.message] || "Non riuscito."; }
  };
  $("profForm").onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; busy(f, true); $("pErr").hidden = true; $("pOk").hidden = true;
    try { await api("update", { name: $("pName").value, password_current: $("pCur") ? $("pCur").value : "", password_new: $("pNew").value });
      $("pOk").textContent = "Salvato."; $("pOk").hidden = false; $("pNew").value = ""; if ($("pCur")) $("pCur").value = ""; }
    catch (e) { say($("pErr"), e); if (e.message === "wrong") $("pErr").textContent = "La password attuale non è giusta."; }
    busy(f, false);
  };
  $("fbForm").onsubmit = async (ev) => {
    ev.preventDefault(); const f = ev.target; busy(f, true); $("fbErr").hidden = true; $("fbOk").hidden = true;
    try { await api("", { kind: $("fbKind").value, message: $("fbMsg").value, context: "account" }, "/api/feedback.php");
      $("fbOk").textContent = "Grazie, ricevuto!"; $("fbOk").hidden = false; $("fbMsg").value = ""; }
    catch (e) { say($("fbErr"), e); }
    busy(f, false);
  };
  $("logout").onclick = async () => { await api("logout", {}).catch(() => {}); location.href = "/"; };
  $("del").onclick = async () => {
    if (!confirm("Cancellare l'account? Le cantine preferite e i tuoi dati spariscono per sempre.")) return;
    try { await api("delete", { confirm: "DELETE" }); location.href = "/"; } catch (e) { alert("Non riuscito, riprova."); }
  };
}
})();
