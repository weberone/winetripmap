// Rete comune agli strumenti che leggono i siti delle cantine (vini-dai-siti.mjs, descrizioni.mjs):
// si presenta come WineTripMapBot e rispetta robots.txt.
export const UA = 'WineTripMapBot/1.0 (+https://winetripmap.com)';
export async function get(url, { json = false, max = 3e6 } = {}) {
  try {
    const r = await fetch(url, { headers: { 'User-Agent': UA, 'Accept-Language': 'it,en;q=0.8,*;q=0.5', Accept: json ? 'application/json' : 'text/html,application/xhtml+xml' }, redirect: 'follow', signal: AbortSignal.timeout(15000) });
    const ct = r.headers.get('content-type') || '';
    if (!r.ok) return { status: r.status, url: r.url };
    if (json ? !/json/.test(ct) : !/html|xml/.test(ct)) return { status: 'type', url: r.url };
    const reader = r.body.getReader(); const chunks = []; let n = 0;
    for (;;) { const { done, value } = await reader.read(); if (done) break; n += value.length; if (n > max) { reader.cancel(); break; } chunks.push(value); }
    const text = Buffer.concat(chunks).toString('utf8');
    return { status: 200, url: r.url, text };
  } catch (e) { return { status: 'err', err: String(e.cause?.code || e.name || e.message).slice(0, 60) }; }
}

// robots.txt: gruppi per "*" e per il nostro nome; Disallow/Allow con * e $ (la regola più lunga vince)
export function robotsRules(txt) {
  const groups = []; let cur = null, lastUA = false;
  for (const raw of (txt || '').split(/\r?\n/)) {
    const line = raw.replace(/#.*/, '').trim(); if (!line) continue;
    const m = line.match(/^([a-z-]+)\s*:\s*(.*)$/i); if (!m) continue;
    const k = m[1].toLowerCase(), v = m[2].trim();
    if (k === 'user-agent') { if (!lastUA) { cur = { ua: [], rules: [] }; groups.push(cur); } cur.ua.push(v.toLowerCase()); lastUA = true; continue; }
    lastUA = false; if (!cur) continue;
    if ((k === 'disallow' || k === 'allow') && v) cur.rules.push({ allow: k === 'allow', path: v });
  }
  const mine = groups.filter((g) => g.ua.some((u) => u.includes('winetripmap')));
  const use = mine.length ? mine : groups.filter((g) => g.ua.includes('*'));
  return use.flatMap((g) => g.rules).map((r) => ({ ...r, re: new RegExp('^' + r.path.replace(/[.+?^{}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\\\$$/, '$')) }));
}
export const allowed = (rules, path) => { let best = null; for (const r of rules) if (r.re.test(path) && (!best || r.path.length > best.path.length)) best = r; return !best || best.allow; };
