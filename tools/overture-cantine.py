# Cantine dal dataset Places di Overture Maps (https://overturemaps.org, gratuito, file Parquet pubblici su S3):
# luoghi con la categoria "winery" (o "vineyard") in tutto il mondo, più enoteche, wine bar e negozi di alcolici, che servono
# a tools/produttori.mjs per riconoscere i negozi segnati come cantine.
# Overture unisce Meta, Microsoft, Foursquare e altri: tanti produttori che OpenStreetMap non ha (es. Anselmi, Pieropan, Inama).
# Licenze: CDLA Permissive 2.0 (Meta, Microsoft…), Apache 2.0 (Foursquare), CC0 (AllThePlaces): attribuzione nella privacy
# e nella scheda della cantina. Si salvano solo i fatti (nome, posizione, sito, telefono, indirizzo, categoria).
#
# usage: python3 tools/overture-cantine.py [release]   (serve: pip install duckdb)
# → tools/.cache/overture/places.json.gz { release, items: [[gers, lat, lng, nome, comune, cc, sito, tel, indirizzo, conf, cat, fonti], …] }
#   cat: w winery · v vineyard · s negozio di vini/alcolici · b wine bar · h ingrosso di vini · a alloggio/agriturismo/ristorante con un vigneto entro ~1 km
import duckdb, gzip, json, os, sys, urllib.request

here = os.path.dirname(os.path.abspath(__file__))
out = os.path.join(here, '.cache', 'overture', 'places.json.gz')

release = sys.argv[1] if len(sys.argv) > 1 else json.load(urllib.request.urlopen('https://stac.overturemaps.org/catalog.json', timeout=60))['latest']
print('Overture', release)
CATS = {'winery': 'w', 'vineyard': 'v', 'beer_wine_spirits_store': 's', 'liquor_store': 's', 'wine_bar': 'b', 'wine_wholesaler': 'h', 'ospitalita': 'a'}
con = duckdb.connect()
con.execute("INSTALL httpfs; LOAD httpfs; SET s3_region='us-west-2';")
src = f"read_parquet('s3://overturemaps-us-west-2/release/{release}/theme=places/type=place/*', hive_partitioning=1)"
cats = ','.join(f"'{c}'" for c in CATS)
rows = con.execute(f"""
  SELECT id, bbox.ymin, bbox.xmin, names.primary, addresses[1].locality, addresses[1].country, websites[1], phones[1], addresses[1].freeform,
         confidence, CASE WHEN taxonomy.primary IN ({cats}) THEN taxonomy.primary
                          WHEN list_contains(taxonomy.alternates, 'winery') THEN 'winery' ELSE 'vineyard' END,
         list_distinct([s.dataset for s in sources]), operating_status
  FROM {src}
  WHERE (taxonomy.primary IN ({cats}) OR list_contains(taxonomy.alternates, 'winery') OR list_contains(taxonomy.alternates, 'vineyard'))
    AND coalesce(operating_status, 'open') <> 'permanently_closed'
""").fetchall()
# alloggi, agriturismi e ristoranti con un sito, solo con un vigneto OSM nello stesso quadratino di ~1 km (tools/.cache/osm/vigneti.json):
# relais e agriturismi che fanno vino (Corte dei Soavi a Soave è una "casa vacanze" per Overture). Con la sola "zona del vino" erano
# 274.000 (tutti gli alberghi dei paesi del vino), così ~50.000. Entrano sulla mappa solo se il loro sito dice che producono vino (produttori.mjs)
vf = os.path.join(here, '.cache', 'osm', 'vigneti.json')
if os.path.exists(vf):
  zone = sorted({(int(e['lat'] * 100 // 1), int(e['lon'] * 100 // 1)) for e in json.load(open(vf))['elements']})
  con.execute('CREATE TABLE zc (a INTEGER, b INTEGER)'); con.executemany('INSERT INTO zc VALUES (?, ?)', zone)
  print(len(zone), 'quadratini con vigneti')
  rows += [r[:10] + ('ospitalita',) + r[11:] for r in con.execute(f"""
    SELECT id, bbox.ymin, bbox.xmin, names.primary, addresses[1].locality, addresses[1].country, websites[1], phones[1], addresses[1].freeform,
           confidence, taxonomy.primary, list_distinct([s.dataset for s in sources]), operating_status
    FROM {src} p JOIN zc ON zc.a = CAST(floor(p.bbox.ymin * 100) AS INTEGER) AND zc.b = CAST(floor(p.bbox.xmin * 100) AS INTEGER)
    WHERE websites[1] IS NOT NULL AND coalesce(operating_status, 'open') <> 'permanently_closed'
      AND (taxonomy.hierarchy[1] = 'lodging'
           OR (taxonomy.hierarchy[2] = 'restaurant' AND regexp_matches(lower(names.primary), 'agritur|cantin|tenut|vign|wine|vino|wein|domaine|ch[aâ]teau|bodeg|quinta|heurig|buschensch|fattoria|podere|cascina|corte|masseria|azienda agricola')))
  """).fetchall()]
r5 = lambda x: round(x, 5)
items = []
for (gid, lat, lng, name, loc, cc, web, tel, addr, conf, cat, ds, st) in rows:
  if lat is None or not name: continue
  items.append([gid.replace('-', ''), r5(lat), r5(lng), name, loc or '', (cc or '').upper(), web or '', tel or '', addr or '',
                round(conf or 0, 2), CATS[cat], ','.join(sorted(ds or []))])
os.makedirs(os.path.dirname(out), exist_ok=True)
with gzip.open(out, 'wt', encoding='utf-8') as f: json.dump({'release': release, 'items': items}, f, ensure_ascii=False, separators=(',', ':'))
from collections import Counter
print(len(items), 'luoghi:', dict(Counter(x[10] for x in items)))
print('cantine per paese:', Counter(x[5] for x in items if x[10] == 'w').most_common(25))
