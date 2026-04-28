#!/usr/bin/env python3
"""
Deye Inverter API Client  (deyecloud.com)
=========================================
Connects to Deye inverters via the official Deye Cloud OpenAPI.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 1 — GET API CREDENTIALS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. Go to  https://developer.deyecloud.com
2. Sign in with your Deye Cloud account (same login as the app)
3. Create an application → copy your  App ID  and  App Secret
4. Your Device Serial Number (deviceSn) is on the label of the
   Wi-Fi/LAN data-logger dongle plugged into the inverter

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 2 — INSTALL DEPENDENCY
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    pip install requests

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 3 — SET CREDENTIALS (env vars recommended)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
    export DEYE_APP_ID="your_app_id"
    export DEYE_APP_SECRET="your_app_secret"
    export DEYE_EMAIL="you@example.com"
    export DEYE_PASSWORD="yourpassword"
    export DEYE_DEVICE_SN="2306xxxxxxxx"   # optional

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
STEP 4 — RUN COMMANDS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

  List all your plants/stations:
    python3 deye_inverter.py stations

  List devices at a station (use ID from 'stations' output):
    python3 deye_inverter.py devices 12345

  Live data from one or more inverters:
    python3 deye_inverter.py latest 2306xxxxxxxx
    python3 deye_inverter.py latest              # uses DEYE_DEVICE_SN

  Historical data (granularity: 1=daily, 2=31-day, 3=monthly, 4=yearly):
    python3 deye_inverter.py history 2306xxxxxxxx --start 2026-04-01
    python3 deye_inverter.py history 2306xxxxxxxx --start 2026-04-01 --end 2026-04-28 --gran 2

  Station latest summary:
    python3 deye_inverter.py station-latest 12345

  Or pass credentials inline instead of env vars:
    python3 deye_inverter.py latest 2306xxxxxxxx \\
        --app-id 2029... --app-secret abc... \\
        --email you@example.com --password secret

  Use US region (default is EU):
    python3 deye_inverter.py --region us stations

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
"""

import argparse
import hashlib
import json
import os
import sys
from datetime import date
from typing import Any

try:
    import requests
except ImportError:
    sys.exit("Error: 'requests' is not installed.\nFix:   pip install requests")


# ── Region base URLs ──────────────────────────────────────────────────────────

BASE_URLS: dict[str, str] = {
    "eu": "https://eu1-developer.deyecloud.com/v1.0",
    "us": "https://us1-developer.deyecloud.com/v1.0",
}


# ── Deye Cloud API client ─────────────────────────────────────────────────────

class DeyeCloudClient:
    """
    Client for the Deye Cloud OpenAPI v1.0.

    Auth flow:
        POST /account/token?appId=<appId>
        Body: { appSecret, email, password (SHA-256 hex), companyId }
        Response contains a JWT used as  Authorization: bearer <token>
    """

    def __init__(self, app_id: str, app_secret: str, base_url: str) -> None:
        self.app_id     = app_id
        self.app_secret = app_secret
        self.base_url   = base_url.rstrip("/")
        self.token: str = ""
        self._session   = requests.Session()
        self._session.headers.update({"Content-Type": "application/json"})

    # ── Auth ──────────────────────────────────────────────────────────────────

    def login(self, email: str, password: str, company_id: str = "0") -> dict:
        """
        Obtain a Bearer JWT token.
        Password is SHA-256 hashed before transmission.
        company_id: "0" for personal account; use your company ID for business.
        """
        hashed_pw = hashlib.sha256(password.encode()).hexdigest()
        body = {
            "appSecret":  self.app_secret,
            "email":      email,
            "password":   hashed_pw,
            "companyId":  company_id,
        }
        data = self._post(
            "/account/token",
            body,
            params={"appId": self.app_id},
            authenticated=False,
        )
        self.token = data.get("accessToken") or data.get("access_token", "")
        if self.token:
            self._session.headers.update({"Authorization": f"bearer {self.token}"})
        return data

    # ── Stations ──────────────────────────────────────────────────────────────

    def list_stations(self, page: int = 1, size: int = 10) -> dict:
        """Paginated list of all plants/stations on the account."""
        return self._post("/station/list", {"page": page, "size": size})

    def station_latest(self, station_id: int) -> dict:
        """Latest summary for a single station (power, energy, status)."""
        return self._post("/station/latest", {"stationId": station_id})

    def station_devices(self, station_id: int, page: int = 1, size: int = 10) -> dict:
        """List devices (inverters/loggers) attached to a station."""
        return self._post(
            "/station/device",
            {"page": page, "size": size, "stationIds": [station_id]},
        )

    # ── Devices ───────────────────────────────────────────────────────────────

    def device_latest(self, *device_sns: str) -> dict:
        """
        Latest real-time data for up to 10 devices at once.
        Pass one or more serial numbers.
        """
        return self._post("/device/latest", {"deviceList": list(device_sns[:10])})

    def device_history(
        self,
        device_sn: str,
        start_at: str,
        end_at: str | None = None,
        granularity: int = 1,
        measure_points: list[str] | None = None,
    ) -> dict:
        """
        Historical data for a device.

        granularity:
            1 = intraday (startAt = 'YYYY-MM-DD', returns per-interval rows)
            2 = up to 31 days with daily totals
            3 = monthly totals (up to 12 months)
            4 = yearly totals

        measure_points: list of metric keys, e.g. ["SOC", "APo"]
                        pass None/[] to get all available points
        """
        body: dict[str, Any] = {
            "deviceSn":    device_sn,
            "granularity": granularity,
            "startAt":     start_at,
        }
        if end_at:
            body["endAt"] = end_at
        if measure_points:
            body["measurePoints"] = measure_points
        return self._post("/device/history", body)

    def device_measure_points(self, device_sn: str) -> dict:
        """List all available measure point keys for a device."""
        return self._post("/device/measurePoints", {"deviceSn": device_sn})

    # ── HTTP ──────────────────────────────────────────────────────────────────

    def _post(
        self,
        path: str,
        body: dict,
        params: dict | None = None,
        authenticated: bool = True,
    ) -> dict:
        url = self.base_url + path
        headers: dict[str, str] = {}
        if not authenticated:
            # remove auth header for the login call
            headers["Authorization"] = ""

        try:
            resp = self._session.post(
                url,
                json=body,
                params=params,
                headers={k: v for k, v in headers.items() if v} or None,
                timeout=15,
            )
            resp.raise_for_status()
            return resp.json()
        except requests.exceptions.ConnectionError as exc:
            sys.exit(f"Connection error — check your network.\n{exc}")
        except requests.exceptions.Timeout:
            sys.exit("Request timed out (15 s). Check your network or try another region.")
        except requests.exceptions.HTTPError as exc:
            body_text = exc.response.text[:300]
            sys.exit(f"HTTP {exc.response.status_code}: {body_text}")
        except ValueError:
            sys.exit("Server returned non-JSON response.")


# ── Pretty-print helpers ──────────────────────────────────────────────────────

def _rule(title: str = "", width: int = 62) -> None:
    if title:
        pad = (width - len(title) - 2) // 2
        print("─" * pad + f" {title} " + "─" * (width - pad - len(title) - 2))
    else:
        print("─" * width)


def _kv(label: str, value: Any, indent: int = 2) -> None:
    print(f"{' ' * indent}{label:<28} {value}")


def print_stations(data: dict) -> None:
    stations = data.get("stationList") or data.get("list") or []
    total    = data.get("total", len(stations))
    _rule(f"Stations  ({total} total)")
    if not stations:
        print("  No stations found.")
        return
    for s in stations:
        print()
        _kv("ID",             s.get("id", "—"))
        _kv("Name",           s.get("name", "—"))
        _kv("Address",        s.get("locationAddress") or "—")
        _kv("Capacity",       f"{s.get('capacity', '—')} kWp")
        _kv("Current power",  f"{s.get('generationPower', '—')} W")
        _kv("Energy today",   f"{s.get('generationValue', '—')} kWh")
        _kv("Status",         s.get("status", "—"))
    print()


def print_station_latest(data: dict) -> None:
    _rule("Station Latest")
    if not data:
        print("  No data returned.")
        return
    for k, v in data.items():
        _kv(k, v)
    print()


def print_station_devices(data: dict) -> None:
    devices = data.get("deviceList") or data.get("list") or []
    _rule(f"Devices  ({len(devices)} found)")
    if not devices:
        print("  No devices found.")
        return
    for d in devices:
        print()
        _kv("Serial (deviceSn)",  d.get("deviceSn", "—"))
        _kv("Name",               d.get("deviceName") or d.get("name", "—"))
        _kv("Type",               d.get("deviceType", "—"))
        _kv("Status",             d.get("deviceState") or d.get("status", "—"))
        _kv("Firmware",           d.get("firmwareVersion", "—"))
    print()


def print_device_latest(data: dict) -> None:
    device_list = data.get("deviceList") or []
    if not device_list:
        # fallback: data itself may be a flat dict of metrics
        _rule("Device Latest")
        for k, v in data.items():
            _kv(k, v)
        print()
        return

    for device in device_list:
        sn = device.get("deviceSn", "unknown")
        _rule(f"Device Latest  |  SN: {sn}")
        collect_time = device.get("collectTime", "")
        if collect_time:
            print(f"  Collected: {collect_time}\n")

        points = device.get("dataList") or []
        if points:
            print(f"  {'Key':<12} {'Name':<30} {'Value':<12} Unit")
            print(f"  {'─'*12} {'─'*30} {'─'*12} {'─'*8}")
            for p in points:
                key   = p.get("key", "")
                name  = p.get("name") or p.get("label", key)
                value = p.get("value", "—")
                unit  = p.get("unit", "")
                print(f"  {key:<12} {name:<30} {str(value):<12} {unit}")
        else:
            # flat key/value response
            for k, v in device.items():
                if k not in ("deviceSn", "collectTime"):
                    _kv(k, v)
        print()


def print_device_history(data: dict) -> None:
    records = (
        data.get("dataList")
        or data.get("list")
        or data.get("records")
        or []
    )
    _rule(f"Historical Data  ({len(records)} records)")
    if not records:
        print("  No historical data returned.")
        print(f"  Raw response: {json.dumps(data, indent=2)[:400]}")
        return
    for rec in records:
        ts = rec.get("collectTime") or rec.get("date") or rec.get("time", "")
        print(f"\n  ── {ts}")
        points = rec.get("dataList") or []
        if points:
            for p in points:
                key   = p.get("key", "")
                name  = p.get("name") or key
                value = p.get("value", "—")
                unit  = p.get("unit", "")
                print(f"    {name}: {value} {unit}".rstrip())
        else:
            for k, v in rec.items():
                if k not in ("collectTime", "date", "time"):
                    print(f"    {k}: {v}")
    print()


def print_measure_points(data: dict) -> None:
    points = data.get("measurePoints") or data.get("list") or []
    _rule(f"Measure Points  ({len(points)} available)")
    if not points:
        print("  No measure points returned.")
        return
    print(f"\n  {'Key':<15} {'Name':<35} Unit")
    print(f"  {'─'*15} {'─'*35} {'─'*8}")
    for p in points:
        key  = p.get("key", "")
        name = p.get("name") or p.get("label", "")
        unit = p.get("unit", "")
        print(f"  {key:<15} {name:<35} {unit}")
    print()


# ── CLI ───────────────────────────────────────────────────────────────────────

def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="deye_inverter.py",
        description="Deye inverter CLI — connects via deyecloud.com OpenAPI",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=__doc__,
    )

    # Global flags
    p.add_argument("--app-id",     default=os.getenv("DEYE_APP_ID",     ""), metavar="ID",
                   help="App ID from developer.deyecloud.com (or DEYE_APP_ID env var)")
    p.add_argument("--app-secret", default=os.getenv("DEYE_APP_SECRET", ""), metavar="SECRET",
                   help="App Secret (or DEYE_APP_SECRET env var)")
    p.add_argument("--email",      default=os.getenv("DEYE_EMAIL",      ""), metavar="EMAIL",
                   help="Deye Cloud account email (or DEYE_EMAIL env var)")
    p.add_argument("--password",   default=os.getenv("DEYE_PASSWORD",   ""), metavar="PASS",
                   help="Deye Cloud account password (or DEYE_PASSWORD env var)")
    p.add_argument("--region",     default=os.getenv("DEYE_REGION", "eu"),
                   choices=["eu", "us"],
                   help="API region: eu (default) or us")
    p.add_argument("--base-url",   default=os.getenv("DEYE_BASE_URL", ""),
                   help="Override API base URL entirely")

    sub = p.add_subparsers(dest="command", required=True)

    # stations
    sub.add_parser("stations", help="List all stations/plants on the account")

    # station-latest
    sl = sub.add_parser("station-latest", help="Latest summary for a station")
    sl.add_argument("station_id", type=int, help="Station ID (from 'stations' output)")

    # devices
    dv = sub.add_parser("devices", help="List devices at a station")
    dv.add_argument("station_id", type=int, help="Station ID")

    # latest
    lt = sub.add_parser("latest", help="Live data from one or more devices")
    lt.add_argument(
        "device_sns",
        nargs="*",
        default=[os.getenv("DEYE_DEVICE_SN", "")],
        metavar="DEVICE_SN",
        help="One or more serial numbers (up to 10). Falls back to DEYE_DEVICE_SN.",
    )

    # history
    hist = sub.add_parser("history", help="Historical data for a device")
    hist.add_argument(
        "device_sn",
        nargs="?",
        default=os.getenv("DEYE_DEVICE_SN", ""),
        metavar="DEVICE_SN",
    )
    hist.add_argument("--start", default=str(date.today()), metavar="YYYY-MM-DD",
                      help="Start date (default: today)")
    hist.add_argument("--end",   default=None,              metavar="YYYY-MM-DD",
                      help="End date (optional, for gran 2/3/4)")
    hist.add_argument("--gran",  type=int, default=1, choices=[1, 2, 3, 4],
                      help="Granularity: 1=intraday 2=daily(≤31d) 3=monthly 4=yearly (default: 1)")
    hist.add_argument("--points", nargs="*", metavar="KEY",
                      help="Measure point keys to fetch, e.g. --points SOC APo (default: all)")

    # measure-points
    mp = sub.add_parser("measure-points", help="List all available metric keys for a device")
    mp.add_argument("device_sn", nargs="?", default=os.getenv("DEYE_DEVICE_SN", ""),
                    metavar="DEVICE_SN")

    return p


def require_credentials(args: argparse.Namespace) -> None:
    missing = [
        name for name, val in [
            ("--app-id / DEYE_APP_ID",        args.app_id),
            ("--app-secret / DEYE_APP_SECRET", args.app_secret),
            ("--email / DEYE_EMAIL",            args.email),
            ("--password / DEYE_PASSWORD",      args.password),
        ]
        if not val
    ]
    if missing:
        sys.exit(
            "Missing required credentials:\n"
            + "\n".join(f"  {m}" for m in missing)
            + "\n\nRun  python3 deye_inverter.py --help  for full setup instructions."
        )


def main() -> None:
    parser = build_parser()
    args   = parser.parse_args()

    require_credentials(args)

    base_url = args.base_url or BASE_URLS.get(args.region, BASE_URLS["eu"])
    client   = DeyeCloudClient(args.app_id, args.app_secret, base_url)

    print(f"Authenticating with {base_url} ...", end=" ", flush=True)
    login_result = client.login(args.email, args.password)

    if not client.token:
        msg = (
            login_result.get("message")
            or login_result.get("msg")
            or json.dumps(login_result)
        )
        sys.exit(f"\nLogin failed: {msg}")
    print("OK\n")

    # ── Dispatch ──────────────────────────────────────────────────────────────

    if args.command == "stations":
        print_stations(client.list_stations())

    elif args.command == "station-latest":
        print_station_latest(client.station_latest(args.station_id))

    elif args.command == "devices":
        print_station_devices(client.station_devices(args.station_id))

    elif args.command == "latest":
        sns = [s for s in (args.device_sns or []) if s]
        if not sns:
            sys.exit(
                "No device serial number provided.\n"
                "Usage: python3 deye_inverter.py latest <DEVICE_SN>\n"
                "   or: export DEYE_DEVICE_SN=<sn>"
            )
        data = client.device_latest(*sns)
        print_device_latest(data)

    elif args.command == "history":
        if not args.device_sn:
            sys.exit(
                "No device serial number provided.\n"
                "Usage: python3 deye_inverter.py history <DEVICE_SN> --start YYYY-MM-DD"
            )
        data = client.device_history(
            args.device_sn,
            start_at=args.start,
            end_at=args.end,
            granularity=args.gran,
            measure_points=args.points,
        )
        print_device_history(data)

    elif args.command == "measure-points":
        if not args.device_sn:
            sys.exit("No device serial number provided.")
        print_measure_points(client.device_measure_points(args.device_sn))


if __name__ == "__main__":
    main()
