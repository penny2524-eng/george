#!/usr/bin/env python3
"""
Deye Cloud — Full battery probe
================================
Queries the two physical battery modules directly and dumps every data point
they return so we can discover the exact key names for cycle count, health, etc.

Run from YOUR local machine (NOT the server):
    pip install requests
    python3 deye_probe.py

Output is also written to  deye_probe_results.json  in the same directory.
"""

import hashlib, json, sys
try:
    import requests
except ImportError:
    sys.exit("pip install requests")

BASE       = "https://eu1-developer.deyecloud.com/v1.0"
APP_ID     = "202604284470012"
APP_SECRET = "bd6abff1bb8c315065df6fd5a63286d1"
EMAIL      = "penny2524@gmail.com"
PASSWORD   = "Penny2000!"
STATION    = 61523693
INVERTER_SN_HINT = "2507133177"

# The two physical battery modules visible in Deye Cloud
BATTERY_SNS = ["16903000D6120043", "25407000E5140658"]


def sha256(s):
    return hashlib.sha256(s.encode()).hexdigest()


def post(session, path, body, params=None):
    r = session.post(BASE + path, json=body, params=params, timeout=15)
    r.raise_for_status()
    return r.json()


def login(session):
    data = post(session, "/account/token",
                {"appSecret": APP_SECRET, "email": EMAIL,
                 "password": sha256(PASSWORD), "companyId": "0"},
                params={"appId": APP_ID})
    token = data.get("accessToken") or data.get("access_token") or \
            (data.get("data") or {}).get("accessToken")
    if not token:
        sys.exit(f"Login failed: {data.get('msg') or json.dumps(data)[:300]}")
    return token


def dump_device(label, sn, dataList):
    print(f"\n{'═'*70}")
    print(f"  {label}  (SN: {sn})")
    print(f"{'═'*70}")
    if not dataList:
        print("  ⚠  No data points returned for this device.")
        return
    print(f"  {'KEY':<40} {'NAME':<30} VALUE   UNIT")
    print(f"  {'-'*40} {'-'*30} {'-'*7} {'-'*5}")
    for p in sorted(dataList, key=lambda x: x.get("key", "")):
        key   = p.get("key",   "")
        name  = p.get("name",  "")
        value = p.get("value", "")
        unit  = p.get("unit",  "")
        flag  = ""
        kl, nl = key.lower(), name.lower()
        if any(w in kl or w in nl for w in ("cycle","health","soh","soc","capacity","charge")):
            flag = "  ◀◀◀"
        print(f"  {key:<40} {name:<30} {str(value):<7} {unit}{flag}")


def main():
    session = requests.Session()
    session.headers.update({"Content-Type": "application/json"})

    print("Authenticating with Deye Cloud ...")
    token = login(session)
    session.headers.update({"Authorization": f"bearer {token}"})
    print("✓ Authenticated\n")

    # ── Station overview ──────────────────────────────────────────────────────
    print("Fetching station overview ...")
    st = post(session, "/station/latest", {"stationId": STATION})
    stData = st.get("stationDataList", [{}])[0] if st.get("stationDataList") else st
    print(f"  Battery SOC  : {stData.get('batterySOC', '?')} %")
    print(f"  Battery Power: {stData.get('batteryPower', '?')} W  (neg = charging)")
    print(f"  Generation   : {stData.get('generationPower', '?')} W")
    print(f"  Last update  : {stData.get('lastUpdateTime', '?')}")

    # ── Device list ───────────────────────────────────────────────────────────
    print("\nFetching station device list ...")
    devList = post(session, "/station/device", {"stationIds": [STATION]})
    items = devList.get("deviceListItems") or devList.get("deviceList") or []
    all_sns = []
    for d in items:
        sn   = d.get("deviceSn", "?")
        typ  = d.get("deviceType", "?")
        name = d.get("deviceName") or d.get("name") or ""
        print(f"  {typ:<20} {sn}  {name}")
        all_sns.append(sn)

    # Always include the known battery module SNs
    for sn in BATTERY_SNS:
        if sn not in all_sns:
            all_sns.append(sn)
            print(f"  (added known battery SN: {sn})")

    # ── Query every device ────────────────────────────────────────────────────
    print(f"\nQuerying /device/latest for {len(all_sns)} devices ...")
    devData = post(session, "/device/latest", {"deviceList": all_sns})

    raw_results = {}
    entries = devData.get("deviceDataList") or devData.get("deviceList") or []

    for entry in entries:
        sn       = entry.get("deviceSn", "?")
        dataList = entry.get("dataList") or []
        label    = "Inverter" if INVERTER_SN_HINT in sn else f"Battery {sn}"
        dump_device(label, sn, dataList)
        raw_results[sn] = dataList

    # Any requested SNs that didn't come back
    returned_sns = {e.get("deviceSn") for e in entries}
    for sn in all_sns:
        if sn not in returned_sns:
            print(f"\n  ⚠  SN {sn} was requested but NOT in the API response.")

    # ── Save raw JSON ─────────────────────────────────────────────────────────
    out = "deye_probe_results.json"
    with open(out, "w") as f:
        json.dump({
            "station": stData,
            "device_list": items,
            "device_data": raw_results,
        }, f, indent=2)
    print(f"\n✓ Full raw JSON saved to  {out}\n")


if __name__ == "__main__":
    main()
