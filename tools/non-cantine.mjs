// Enoteche, wine bar, negozi e ristoranti segnati in OpenStreetMap come cantine (craft=winery): non vanno sulla mappa.
// I tag di OSM non bastano (tante cantine vere hanno anche shop=wine per il loro punto vendita), quindi si guarda il nome:
// fuori se il nome dice "enoteca / wine bar / wine shop…" e non dice anche "cantina / winery / weingut / domaine…".
// TOGLI e TIENI correggono a mano i casi che il nome non basta a decidere (controllati uno per uno il 2026-11-14).

const SHOP = /(enotec|vinotec|vineria|vinería|wine ?bar|wine ?shop|wine ?store|bottle ?shop|liquor|caviste|cave à vins?|bar à vins?|vinoth[eè]que|weinbar|weinhandlung|weinladen|wine merchant|bodega de vinos|pizzeria|osteria|trattoria|ristorante|\bpub\b|bistrot?\b|wine ?(club|lounge|emporium|boutique|shoppe)|spirits)/i;
const WINERY = /(winery|vineyard|cantina|weingut|domaine|ch[aâ]teau|tenuta|cellars|estates?\b|kellerei|azienda)/i;

const TOGLI = new Set([
  'n10725630068', // Enoteca La Cantina
  'n5792747460', // Enogastronomia "Dolce & Piccante", Roma
  'n6314010504', // Nel paese del vino, Roma
  'n10249572076', // Il gusto di Virdis, Milano
  'n11197787993', // Il nuovo principe a tavola, Milano
  'n11932521485', // Eno Club di Malfassi, Milano
  'n12757796797', // tana pubblica, Milano
  'n13189249445', // L'enoluogo, Milano
  'n4640831802', // Cantine Isola, wine bar a Milano
  'n7914177595', 'n13859584622', // Les Amoureuses, Parigi
  'n11300316770', // Gilgamesh, Parigi
  'n289424461', // Diogenes the Dog, Londra
  'n13972492605', // Vagabond, Londra
  'w1028386404', // Bibendum, Londra
]);
const TIENI = new Set([
  'n13706101145', // Vineria Castel Sallegg (della cantina)
  'n6421972225', // Enoteca di Nipozzano (Frescobaldi)
  'n7397548800', // Roccapesta - Wine shop
  'n9838131175', // Nals Margreid Enoteca 1764
  'n10173372690', // Weißenburg - Wine club, Freyburg
  'n13757363154', // Apple Barn Dessert Wine Shoppe
  'w887013939', // Fantini Wine Store
  'w1117726434', // Caymus-Suisun Wine Shop
  'n1599445174', // Merricks General Wine Store - Elgee Park Wines
  'w1289355071', // Bodega de Vinos Matetic
  'w643395204', // Bodega de Vinos Viña Cousiño
  'w141028896', // Ca' Botta Wine shop, Grezzana
]);

export function nonCantina(id, name = '') {
  if (TIENI.has(id)) return false;
  if (TOGLI.has(id)) return true;
  return SHOP.test(name) && !WINERY.test(name);
}
