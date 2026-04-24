#!/usr/bin/env python3
"""
Deye Inverter API Client
========================
Connects to Deye inverters via the Solarman cloud platform.

USAGE
-----
1. Set credentials in environment variables (recommended):

    export DEYE_APP_ID="your_app_id"
    export DEYE_APP_SECRET="your_app_secret"
    export DEYE_EMAIL="you@example.com"
    export DEYE_PASSWORD="yourpassword"
    export DEYE_DEVICE_SN="your_device_serial"   # optional

2. Then run one of the commands below:

    python3 deye_inverter.py stations            # list all your plants/stations
    python3 deye_inverter.py devices <station_id># list inverters at a station
    python3 deye_inverter.py realtime <device_sn># live data from one inverter
    python3 deye_inverter.py realtime            # uses DEYE_DEVICE_SN env var
    python3 deye_inverter.py history  <device_sn> [YYYY-MM-DD]

   Or pass credentials inline (not recommended for production):

    python3 deye_inverter.py realtime <device_sn> \\
        --app-id 2029... --app-secret abc... \\
        --email you@example.com --password secret

HOW TO GET API CREDENTIALS
---------------------------
1. Go to https://home.solarmanpv.com  (global) or https://pro.solarmanpv.com
2. Sign up / log in with your Solarman account
3. Navigate to  Developer → App Management → Create App
4. Copy the  App ID  and  App Secret  shown there
5. Your Device Serial Number is printed on the data-logger/stick
   (the small Wi-Fi dongle plugged into the inverter)

REQUIREMENTS
------------
    pip install requests
"""

import argparse
import hashlib
import json
import os
import sys
import time
from datetime import date
from typing import Any

# ── Try to import requests; give a helpful message if missing ─────────────────
try:
    import requests
except ImportError:
    sys.exit(
        "Error: 'requests' is not installed.\n"
        "Fix:   pip install requests"
    )

# ── Constants ─────────────────────────────────────────────────────────────────

GLOBAL_BASE_URL = "https://globalapi.solarmanpv.com"
CHINA_BASE_URL  = "https://api.solarmanpv.com"

# Human-readable labels for Solarman data-point keys
METRIC_LABELS: dict[str, tuple[str, str]] = {
    # key         label                      unit
    "DV1":  ("PV1 Voltage",          "V"),
    "DC1":  ("PV1 Current",          "A"),
    "DP1":  ("PV1 Power",            "W"),
    "DV2":  ("PV2 Voltage",          "V"),
    "DC2":  ("PV2 Current",          "A"),
    "DP2":  ("PV2 Power",            "W"),
    "SV1":  ("Grid L1 Voltage",      "V"),
    "SC1":  ("Grid L1 Current",      "A"),
    "SV2":  ("Grid L2 Voltage",      "V"),
    "SC2":  ("Grid L2 Current",      "A"),
    "SV3":  ("Grid L3 Voltage",      "V"),
    "SC3":  ("Grid L3 Current",      "A"),
    "APo":  ("Active Power",         "W"),
    "RPo":  ("Reactive Power",       "Var"),
    "APPo": ("Apparent Power",       "VA"),
    "PF":   ("Power Factor",         ""),
    "Fac":  ("Grid Frequency",       "Hz"),
    "Etdy": ("Energy Today",         "kWh"),
    "Etot": ("Total Energy",         "kWh"),
    "Tmp":  ("Inverter Temperature", "°C"),
    "BV":   ("Battery Voltage",      "V"),
    "BC":   ("Battery Current",      "A"),
    "BP":   ("Battery Power",        "W"),
    "BSOC": ("Battery State of Charge", "%"),
    "BST":  ("Battery Status",       ""),
    "LV":   ("Load Voltage",         "V"),
    "LC":   ("Load Current",         "A"),
    "LP":   ("Load Power",           "W"),
}


# ── API client ────────────────────────────────────────────────────────────────

class SolarmanClient:
    """
    Thin wrapper around the Solarman v1.0 cloud API.

    Authentication uses HMAC-style signing:
        sign = MD5(app_id + timestamp + app_secret)
    """

    def __init__(self, app_id: str, app_secret: str, base_url: str = GLOBAL_BASE_URL) -> None:
        self.app_id     = app_id
        self.app_secret = app_secret
        self.base_url   = base_url.rstrip("/")
        self.token: str = ""
        self.session    = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})

    # ── Auth ──────────────────────────────────────────────────────────────────

    def login(self, email: str, password: str) -> dict:
        """
        Exchange credentials for a Bearer access token.
        Password is MD5-hashed before being sent.
        """
        timestamp = int(time.time())
        sign      = hashlib.md5(
            f"{self.app_id}{timestamp}{self.app_secret}".encode()
        ).hexdigest()

        params = {
            "appId":     self.app_id,
            "language":  "en",
            "timestamp": timestamp,
            "sign":      sign,
        }
        body = {
            "appSecret": self.app_secret,
            "email":     email,
            "password":  hashlib.md5(password.encode()).hexdigest(),
        }

        data = self._post("/account/v1.0/token", body, params=params, auth=False)
        self.token = data.get("access_token", "")
        return data

    # ── Stations ──────────────────────────────────────────────────────────────

    def list_stations(self, page: int = 1, size: int = 20) -> dict:
        """List all plants / stations linked to this account."""
        return self._post("/station/v1.0/list", {"page": page, "size": size})

    def get_station(self, station_id: int) -> dict:
        """Detailed info for a single station."""
        return self._post("/station/v1.0/detail", {"stationId": station_id})

    # ── Devices ───────────────────────────────────────────────────────────────

    def list_devices(self, station_id: int, page: int = 1, size: int = 20) -> dict:
        """List data-loggers / inverters attached to a station."""
        return self._post(
            "/station/v1.0/device",
            {"stationId": station_id, "page": page, "size": size},
        )

    # ── Data ──────────────────────────────────────────────────────────────────

    def get_realtime_data(self, device_sn: str) -> dict:
        """Fetch the latest real-time data points from a device."""
        return self._post("/device/v1.0/currentData", {"deviceSn": device_sn})

    def get_historical_data(self, device_sn: str, day: str) -> dict:
        """
        Fetch historical data for a given day.
        day format: 'YYYY-MM-DD'
        """
        return self._post(
            "/device/v1.0/historical/day",
            {"deviceSn": device_sn, "date": day},
        )

    # ── HTTP ──────────────────────────────────────────────────────────────────

    def _post(
        self,
        path: str,
        body: dict,
        params: dict | None = None,
        auth: bool = True,
    ) -> dict:
        url = self.base_url + path
        headers: dict[str, str] = {}
        if auth and self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        try:
            resp = self.session.post(
                url,
                json=body,
                params=params,
                headers=headers,
                timeout=15,
            )
            resp.raise_for_status()
            return resp.json()
        except requests.exceptions.ConnectionError as exc:
            sys.exit(f"Connection error: {exc}")
        except requests.exceptions.Timeout:
            sys.exit("Request timed out (15 s). Check your network.")
        except requests.exceptions.HTTPError as exc:
            sys.exit(f"HTTP {exc.response.status_code}: {exc.response.text[:200]}")
        except ValueError:
            sys.exit("Server returned non-JSON response.")


# ── Display helpers ───────────────────────────────────────────────────────────

def _header(title: str) -> None:
    width = 60
    print("\n" + "─" * width)
    print(f"  {title}")
    print("─" * width)


def print_realtime(data: dict) -> None:
    device_sn  = data.get("deviceSn", "unknown")
    data_list  = data.get("dataList", [])
    collect_at = data.get("collectTime", "")

    _header(f"Real-time Data  |  SN: {device_sn}")
    if collect_at:
        print(f"  Collected at: {collect_at}\n")

    if not data_list:
        print("  No data points returned.")
        return

    col_w = 32
    print(f"  {'Metric':<{col_w}} Value")
    print(f"  {'─'*col_w} {'─'*16}")
    for point in data_list:
        key   = point.get("key", "")
        value = point.get("value", "—")
        unit  = point.get("unit", "")

        label, default_unit = METRIC_LABELS.get(key, (key, ""))
        if not unit:
            unit = default_unit

        display = f"{value} {unit}".strip() if value not in ("", None) else "—"
        print(f"  {label:<{col_w}} {display}")

    print()


def print_stations(data: dict) -> None:
    stations = data.get("stationList", [])
    _header(f"Stations  ({len(stations)} found)")
    if not stations:
        print("  No stations found.")
        return

    for s in stations:
        print(f"\n  ID       : {s.get('id')}")
        print(f"  Name     : {s.get('name')}")
        print(f"  Address  : {s.get('locationAddress', '—')}")
        print(f"  Capacity : {s.get('capacity', '—')} kWp")
        print(f"  Power    : {s.get('generationPower', '—')} W")
    print()


def print_devices(data: dict) -> None:
    devices = data.get("deviceListItems", [])
    _header(f"Devices  ({len(devices)} found)")
    if not devices:
        print("  No devices found.")
        return

    for d in devices:
        print(f"\n  Serial   : {d.get('deviceSn', '—')}")
        print(f"  Name     : {d.get('deviceName', '—')}")
        print(f"  Type     : {d.get('deviceType', '—')}")
        print(f"  Status   : {d.get('deviceState', '—')}")
    print()


def print_history(data: dict) -> None:
    records = data.get("dataList", [])
    _header(f"Historical Data  ({len(records)} records)")
    if not records:
        print("  No historical data returned.")
        return

    for rec in records:
        ts = rec.get("collectTime", "")
        print(f"\n  Time: {ts}")
        for point in rec.get("dataList", []):
            key   = point.get("key", "")
            value = point.get("value", "—")
            unit  = point.get("unit", "")
            label, default_unit = METRIC_LABELS.get(key, (key, ""))
            if not unit:
                unit = default_unit
            print(f"    {label}: {value} {unit}".rstrip())
    print()


# ── CLI ───────────────────────────────────────────────────────────────────────

def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="deye_inverter.py",
        description="Deye inverter client via Solarman Cloud API",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )

    # Credential flags (fall back to env vars)
    p.add_argument("--app-id",     default=os.getenv("DEYE_APP_ID",     ""), metavar="ID")
    p.add_argument("--app-secret", default=os.getenv("DEYE_APP_SECRET", ""), metavar="SECRET")
    p.add_argument("--email",      default=os.getenv("DEYE_EMAIL",      ""), metavar="EMAIL")
    p.add_argument("--password",   default=os.getenv("DEYE_PASSWORD",   ""), metavar="PASS")
    p.add_argument("--base-url",   default=os.getenv("DEYE_BASE_URL", GLOBAL_BASE_URL),
                   help="API base URL (default: global endpoint)")

    sub = p.add_subparsers(dest="command", required=True)

    # stations
    sub.add_parser("stations", help="List all plants/stations on the account")

    # devices
    dev = sub.add_parser("devices", help="List inverters at a station")
    dev.add_argument("station_id", type=int, help="Station ID (from 'stations' command)")

    # realtime
    rt = sub.add_parser("realtime", help="Fetch live data from a device")
    rt.add_argument(
        "device_sn",
        nargs="?",
        default=os.getenv("DEYE_DEVICE_SN", ""),
        help="Device serial number (or set DEYE_DEVICE_SN env var)",
    )

    # history
    hist = sub.add_parser("history", help="Fetch historical data for a day")
    hist.add_argument(
        "device_sn",
        nargs="?",
        default=os.getenv("DEYE_DEVICE_SN", ""),
        help="Device serial number",
    )
    hist.add_argument(
        "day",
        nargs="?",
        default=str(date.today()),
        help="Date in YYYY-MM-DD format (default: today)",
    )

    return p


def require_credentials(args: argparse.Namespace) -> None:
    missing = [
        name for name, val in [
            ("--app-id / DEYE_APP_ID",         args.app_id),
            ("--app-secret / DEYE_APP_SECRET",  args.app_secret),
            ("--email / DEYE_EMAIL",             args.email),
            ("--password / DEYE_PASSWORD",       args.password),
        ]
        if not val
    ]
    if missing:
        sys.exit(
            "Missing credentials:\n"
            + "\n".join(f"  {m}" for m in missing)
            + "\n\nSee --help or the top of the script for setup instructions."
        )


def main() -> None:
    parser = build_parser()
    args   = parser.parse_args()

    require_credentials(args)

    client = SolarmanClient(args.app_id, args.app_secret, args.base_url)

    print("Authenticating...", end=" ", flush=True)
    login_result = client.login(args.email, args.password)

    if not client.token:
        msg = login_result.get("msg", json.dumps(login_result))
        sys.exit(f"\nLogin failed: {msg}")
    print("OK")

    if args.command == "stations":
        data = client.list_stations()
        print_stations(data)

    elif args.command == "devices":
        data = client.list_devices(args.station_id)
        print_devices(data)

    elif args.command == "realtime":
        if not args.device_sn:
            sys.exit(
                "No device serial number provided.\n"
                "Usage: python3 deye_inverter.py realtime <device_sn>\n"
                "   or: export DEYE_DEVICE_SN=<sn> then omit the argument."
            )
        data = client.get_realtime_data(args.device_sn)
        if "dataList" not in data:
            sys.exit(f"Error: {data.get('msg', json.dumps(data))}")
        print_realtime(data)

    elif args.command == "history":
        if not args.device_sn:
            sys.exit("No device serial number provided.")
        data = client.get_historical_data(args.device_sn, args.day)
        print_history(data)


if __name__ == "__main__":
    main()
