# Installa il blocco della spesa nel progetto Google Cloud di WTM. Da incollare in Cloud Shell (shell.cloud.google.com).
P=project-01cb823d-edc4-46be-8f7
gcloud config set project $P
mkdir -p ~/stop-billing && cd ~/stop-billing
cat > requirements.txt <<'R'
functions-framework==3.*
google-api-python-client==2.*
R
cat > main.py <<'M'
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
        print("Fatturazione gia scollegata")
        return
    b.projects().updateBillingInfo(name=name, body={"billingAccountName": ""}).execute()
    print(f"Spesa {costo}: fatturazione SCOLLEGATA da {PROJECT}")
M
gcloud functions deploy stop-billing --gen2 --runtime=python312 --region=us-central1 --source=. --entry-point=stop_billing --trigger-topic=wtm-budget --set-env-vars=PROGETTO=$P,SOGLIA_EURO=0 --max-instances=1 --memory=256Mi --quiet
SA=$(gcloud functions describe stop-billing --region=us-central1 --format='value(serviceConfig.serviceAccountEmail)')
gcloud projects add-iam-policy-binding $P --member=serviceAccount:$SA --role=roles/billing.projectManager --condition=None --quiet >/dev/null && echo "BLOCCO INSTALLATO: $SA"
