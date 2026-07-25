#!/usr/bin/env python3
"""
Deye Cloud — Battery Status
============================
Connects to deyecloud.com API and prints the status of both batteries.

Usage (run from your local machine, NOT the server):
    pip install requests

    python3 deye_batteries.py

Or with credentials inline:
    python3 deye_batteries.py \
        --app-id 202604284470012 \
        --app-secret bd6abff1bb8c315065df6fd5a63286d1 \
        --email penny2524@gmail.com \
        --password Penny2000! \
        --device 2507133177
"""

import argparse
import hashlib
import json
import os
import sys

try:
    import requests
except ImportError:
    sys.exit("Install requests first:  pip install requests")


BASE_URL = "https://eu1-developer.deyecloud.com/v1.0"

# Known battery measure-point keys for Deye inverters.
# The script prints ALL points it finds, plus highlights these if present.
BATTERY_KEYS = {
    # SOC / charge level
    "SoC_of_battery_1",   "SoC_of_battery_2",
    "Battery_SOC",        "SOC",
    "BatSOC",             "Batt_SOC",
    # Voltage
    "Battery_Voltage",    "BatVolt",
    "Bat1_Voltage",       "Bat2_Voltage",
    # Current
    "Battery_Current",    "BatCurr",
    "Bat1_Current",       "Bat2_Current",
    # Power (negative = charging, positive = discharging)
    "Battery_Power",      "BatPower",
    "Bat1_Power",         "Bat2_Power",
    # Temperature
    "Battery_Temp",       "BatTemp",
    "Bat1_Temp",          "Bat2_Temp",
    # State / status
    "Battery_Status",     "BatStatus",
    "Bat1_Status",        "Bat2_Status",
    # Capacity
    "Battery_Capacity",
    "Bat1_Capacity",      "Bat2_Capacity",
    # Cycles
    "Battery_Cycles",
    "Bat1_Cycles",        "Bat2_Cycles",
}


def sha256(text: str) -> str:
    return hashlib.sha256(text.encode()).hexdigest()


def api_post(session, path: str, body: dict, params: dict | None = None) -> dict:
    url = BASE_URL + path
    try:
        r = session.post(url, json=body, params=params, timeout=15)
        r.raise_for_status()
        return r.json()
    except requests.exceptions.ConnectionError as e:
        sys.exit(f"Connection error — is this machine online?\n{e}")
    except requests.exceptions.HTTPError as e:
        sys.exit(f"HTTP {e.response.status_code}: {e.response.text[:400]}")


def login(session, app_id: str, app_secret: str, email: str, password: str) -> str:
    data = api_post(
        session,
        "/account/token",
        {"appSecret": app_secret, "email": email,
         "password": sha256(password), "companyId": "0"},
        params={"appId": app_id},
    )
    token = data.get("accessToken") or data.get("access_token", "")
    if not token:
        msg = data.get("message") or data.get("msg") or json.dumps(data)
        sys.exit(f"Login failed: {msg}")
    return token


def bar(pct: float, width: int = 20) -> str:
    filled = int(round(pct / 100 * width))
    return "[" + "█" * filled + "░" * (width - filled) + f"]  {pct:.0f}%"


def print_battery_report(device_data: dict) -> None:
    device_list = device_data.get("deviceList") or []

    # Group measure points by battery index
    bat: dict[str, dict] = {"1": {}, "2": {}, "other": {}}

    def bucket(key: str) -> str:
        k = key.lower()
        if "bat1" in k or "battery_1" in k or "batt1" in k or "bms1" in k:
            return "1"
        if "bat2" in k or "battery_2" in k or "batt2" in k or "bms2" in k:
            return "2"
        if "bat" in k or "soc" in k or "batt" in k:
            return "other"
        return ""

    for device in device_list:
        sn = device.get("deviceSn", "?")
        collected = device.get("collectTime", "")
        points = device.get("dataList") or []

        print(f"\n{'═'*60}")
        print(f"  Device SN : {sn}")
        if collected:
            print(f"  Collected : {collected}")
        print(f"{'═'*60}")

        if not points:
            print("  No data points returned.")
            continue

        # Sort into buckets
        for p in points:
            key  = p.get("key", "")
            b    = bucket(key)
            if b:
                bat[b][key] = p

        # Print Battery 1
        if bat["1"] or bat["other"]:
            print("\n  ─── Battery 1 ───────────────────────────")
            for key, p in {**bat["other"], **bat["1"]}.items():
                name  = p.get("name") or key
                value = p.get("value", "—")
                unit  = p.get("unit", "")
                if "soc" in key.lower() and isinstance(value, (int, float)):
                    print(f"    {name:<32} {bar(float(value))}")
                else:
                    print(f"    {name:<32} {value} {unit}".rstrip())

        # Print Battery 2
        if bat["2"]:
            print("\n  ─── Battery 2 ───────────────────────────")
            for key, p in bat["2"].items():
                name  = p.get("name") or key
                value = p.get("value", "—")
                unit  = p.get("unit", "")
                if "soc" in key.lower() and isinstance(value, (int, float)):
                    print(f"    {name:<32} {bar(float(value))}")
                else:
                    print(f"    {name:<32} {value} {unit}".rstrip())

        # Fallback: print ALL points when bucketing found nothing useful
        if not bat["1"] and not bat["2"] and not bat["other"]:
            print("\n  ─── All measure points ──────────────────")
            for p in points:
                key   = p.get("key", "")
                name  = p.get("name") or key
                value = p.get("value", "—")
                unit  = p.get("unit", "")
                print(f"    {key:<15} {name:<30} {value} {unit}".rstrip())

    if not device_list:
        print("No device data returned. Check the device serial number.")
        print("Raw response:", json.dumps(device_data, indent=2)[:600])

    print()


def main() -> None:
    p = argparse.ArgumentParser(description="Deye Cloud battery status checker")
    p.add_argument("--app-id",     default=os.getenv("DEYE_APP_ID",     "202604284470012"))
    p.add_argument("--app-secret", default=os.getenv("DEYE_APP_SECRET", "bd6abff1bb8c315065df6fd5a63286d1"))
    p.add_argument("--email",      default=os.getenv("DEYE_EMAIL",      "penny2524@gmail.com"))
    p.add_argument("--password",   default=os.getenv("DEYE_PASSWORD",   "Penny2000!"))
    p.add_argument("--device",     default=os.getenv("DEYE_DEVICE_SN",  "2507133177"),
                   metavar="SN", help="Device serial number")
    args = p.parse_args()

    session = requests.Session()
    session.headers.update({"Content-Type": "application/json"})

    print(f"Connecting to {BASE_URL} ...")
    token = login(session, args.app_id, args.app_secret, args.email, args.password)
    session.headers.update({"Authorization": f"bearer {token}"})
    print("Authenticated. Fetching battery data...\n")

    data = api_post(session, "/device/latest", {"deviceList": [args.device]})
    print_battery_report(data)


if __name__ == "__main__":
    main()
