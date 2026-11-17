# Blocco della spesa su Google Cloud per winetripmap.com (come SkateboardMap).
# Il budget "WTM zero spesa" manda un messaggio Pub/Sub (argomento wtm-budget) a ogni aggiornamento della spesa;
# appena la spesa reale del mese supera SOGLIA_EURO (0) questa funzione scollega la fatturazione dal progetto:
# Google non può più addebitare nulla, le mappe Google si spengono e il sito passa da solo a Mapbox o OpenFreeMap.
# Per riattivare: Google Cloud → Fatturazione → collega di nuovo l'account di fatturazione al progetto.
import base64, json, os
import functions_framework
from googleapiclient import discovery

PROJECT = os.environ["PROGETTO"]
SOGLIA = float(os.environ.get("SOGLIA_EURO", "0"))


@functions_framework.cloud_event
def stop_billing(ev):
    d = json.loads(base64.b64decode(ev.data["message"]["data"]).decode())
    costo = float(d.get("costAmount", 0))
    if costo <= SOGLIA:
        print(f"Spesa {costo}: sotto la soglia {SOGLIA}")
        return
    b = discovery.build("cloudbilling", "v1", cache_discovery=False)
    name = f"projects/{PROJECT}"
    if not b.projects().getBillingInfo(name=name).execute().get("billingEnabled"):
        print("Fatturazione già scollegata")
        return
    b.projects().updateBillingInfo(name=name, body={"billingAccountName": ""}).execute()
    print(f"Spesa {costo}: fatturazione SCOLLEGATA da {PROJECT}")
