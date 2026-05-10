#!/usr/bin/env python3
"""
Skyhigh Security → Zscaler Policy Migration CLI

Commands
--------
  fetch-skyhigh   Retrieve all policies from Skyhigh Cloud SSE
  fetch-onprem    Retrieve rule sets from Skyhigh SWG On-Premises
  fetch-zscaler   Retrieve all policies from Zscaler ZIA
  tables          Print static mapping-reference tables
  compare         Fetch both platforms and show a side-by-side comparison
  migrate         Fetch Skyhigh policies, map them, and print/push to Zscaler
  push            Read a migration JSON file and push it to Zscaler ZIA

Usage
-----
  python migrate.py --help
  python migrate.py fetch-skyhigh --output skyhigh_policies.json
  python migrate.py fetch-zscaler --output zscaler_policies.json
  python migrate.py compare
  python migrate.py tables
  python migrate.py migrate --dry-run
  python migrate.py migrate --push
  python migrate.py push --input migration_report.json
"""

import argparse
import json
import logging
import os
import sys
from pathlib import Path

from dotenv import load_dotenv

from skyhigh_client import (
    SkyhighCloudClient, SkyhighCloudConfig,
    SkyhighOnPremClient, SkyhighOnPremConfig,
)
from zscaler_client import ZscalerZIAClient, ZscalerZIAConfig
from migration_mapper import full_migration_report
from policy_tables import (
    console,
    show_skyhigh_all,
    show_zia_all,
    show_migration_mapping_tables,
    show_migration_report,
    show_side_by_side_comparison,
)

load_dotenv()

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
    datefmt="%H:%M:%S",
)
logger = logging.getLogger("migrate")


# ---------------------------------------------------------------------------
# Config builders from environment variables
# ---------------------------------------------------------------------------

def _skyhigh_cloud_config() -> SkyhighCloudConfig:
    required = ["SHN_TENANT_ID", "SHN_USERNAME", "SHN_PASSWORD"]
    _check_env(required)
    return SkyhighCloudConfig(
        tenant_id=os.environ["SHN_TENANT_ID"],
        username=os.environ["SHN_USERNAME"],
        password=os.environ["SHN_PASSWORD"],
        region=os.environ.get("SHN_REGION", "us"),
    )


def _skyhigh_onprem_config() -> SkyhighOnPremConfig:
    required = ["SHN_ONPREM_HOST", "SHN_ONPREM_USERNAME", "SHN_ONPREM_PASSWORD"]
    _check_env(required)
    return SkyhighOnPremConfig(
        host=os.environ["SHN_ONPREM_HOST"],
        username=os.environ["SHN_ONPREM_USERNAME"],
        password=os.environ["SHN_ONPREM_PASSWORD"],
        port=int(os.environ.get("SHN_ONPREM_PORT", 4711)),
        use_ssl=os.environ.get("SHN_ONPREM_SSL", "false").lower() == "true",
    )


def _zia_config() -> ZscalerZIAConfig:
    required = ["ZIA_CLOUD", "ZIA_USERNAME", "ZIA_PASSWORD", "ZIA_API_KEY"]
    _check_env(required)
    return ZscalerZIAConfig(
        cloud=os.environ["ZIA_CLOUD"],
        username=os.environ["ZIA_USERNAME"],
        password=os.environ["ZIA_PASSWORD"],
        api_key=os.environ["ZIA_API_KEY"],
    )


def _check_env(keys: list) -> None:
    missing = [k for k in keys if not os.environ.get(k)]
    if missing:
        console.print(f"[bold red]Missing required environment variables: {missing}[/]")
        console.print("Copy [bold].env.example[/] to [bold].env[/] and fill in the values.")
        sys.exit(1)


# ---------------------------------------------------------------------------
# Command implementations
# ---------------------------------------------------------------------------

def cmd_fetch_skyhigh(args) -> None:
    config = _skyhigh_cloud_config()
    client = SkyhighCloudClient(config)

    console.print("[cyan]Authenticating to Skyhigh Cloud…[/]")
    client.authenticate()

    console.print("[cyan]Fetching all policies…[/]")
    policies = client.get_all_policies()

    show_skyhigh_all(policies)

    if args.output:
        Path(args.output).write_text(json.dumps(policies, indent=2, default=str))
        console.print(f"[green]Saved to {args.output}[/]")


def cmd_fetch_onprem(args) -> None:
    config = _skyhigh_onprem_config()

    console.print(f"[cyan]Connecting to Skyhigh On-Prem ({config.host})…[/]")
    with SkyhighOnPremClient(config) as client:
        rule_sets = client.get_all_rule_sets()
        policies = {"swg_rule_sets": rule_sets}

    show_skyhigh_all(policies)

    if args.output:
        Path(args.output).write_text(json.dumps(policies, indent=2, default=str))
        console.print(f"[green]Saved to {args.output}[/]")


def cmd_fetch_zscaler(args) -> None:
    config = _zia_config()

    console.print("[cyan]Authenticating to Zscaler ZIA…[/]")
    with ZscalerZIAClient(config) as client:
        console.print("[cyan]Fetching all ZIA policies…[/]")
        policies = client.get_all_policies()

    show_zia_all(policies)

    if args.output:
        Path(args.output).write_text(json.dumps(policies, indent=2, default=str))
        console.print(f"[green]Saved to {args.output}[/]")


def cmd_tables(_args) -> None:
    show_migration_mapping_tables()


def cmd_compare(args) -> None:
    # Load from files if provided, else fetch live
    if args.skyhigh_file and args.zscaler_file:
        skyhigh = json.loads(Path(args.skyhigh_file).read_text())
        zscaler = json.loads(Path(args.zscaler_file).read_text())
    else:
        console.print("[cyan]Fetching Skyhigh policies…[/]")
        shn_cfg = _skyhigh_cloud_config()
        shn_client = SkyhighCloudClient(shn_cfg)
        shn_client.authenticate()
        skyhigh = shn_client.get_all_policies()

        console.print("[cyan]Fetching Zscaler ZIA policies…[/]")
        zia_cfg = _zia_config()
        with ZscalerZIAClient(zia_cfg) as zia_client:
            zscaler = zia_client.get_all_policies()

    show_side_by_side_comparison(skyhigh, zscaler)


def cmd_migrate(args) -> None:
    # Load Skyhigh policies
    if args.input:
        skyhigh = json.loads(Path(args.input).read_text())
    else:
        console.print("[cyan]Fetching Skyhigh policies…[/]")
        shn_cfg = _skyhigh_cloud_config()
        shn_client = SkyhighCloudClient(shn_cfg)
        shn_client.authenticate()
        skyhigh = shn_client.get_all_policies()

    # Build migration report
    console.print("[cyan]Building migration report…[/]")
    report = full_migration_report(skyhigh)
    show_migration_report(report)

    if args.output:
        Path(args.output).write_text(json.dumps(report, indent=2, default=str))
        console.print(f"[green]Migration report saved to {args.output}[/]")

    if args.push:
        _push_migration(report)


def cmd_push(args) -> None:
    report = json.loads(Path(args.input).read_text())
    _push_migration(report)


def _push_migration(report: dict) -> None:
    """Push a migration report to Zscaler ZIA via the REST API."""
    config = _zia_config()
    console.print("[cyan]Connecting to Zscaler ZIA for push…[/]")

    with ZscalerZIAClient(config) as client:
        pushed_url  = 0
        pushed_dlp  = 0
        pushed_casb = 0
        errors      = []

        for rule in report.get("zia_url_filtering_rules", []):
            clean = {k: v for k, v in rule.items() if not k.startswith("_")}
            try:
                client.create_url_filtering_rule(clean)
                pushed_url += 1
            except Exception as exc:
                errors.append(f"URL rule '{rule.get('name')}': {exc}")

        for rule in report.get("zia_dlp_web_rules", []):
            clean = {k: v for k, v in rule.items() if not k.startswith("_")}
            try:
                client.create_dlp_web_rule(clean)
                pushed_dlp += 1
            except Exception as exc:
                errors.append(f"DLP rule '{rule.get('name')}': {exc}")

        for rule in report.get("zia_cloud_app_rules", []):
            clean = {k: v for k, v in rule.items() if not k.startswith("_")}
            rule_type = clean.pop("type", "BUSINESS_PRODUCTIVITY")
            try:
                client.create_cloud_app_rule(rule_type, clean)
                pushed_casb += 1
            except Exception as exc:
                errors.append(f"CASB rule '{rule.get('name')}': {exc}")

        if not errors:
            console.print("[cyan]Activating changes…[/]")
            client.activate_changes()

    console.print(f"\n[green]Push complete:[/]")
    console.print(f"  URL filtering rules pushed : {pushed_url}")
    console.print(f"  DLP web rules pushed       : {pushed_dlp}")
    console.print(f"  Cloud app rules pushed     : {pushed_casb}")

    if errors:
        console.print(f"\n[red]Errors ({len(errors)}):[/]")
        for e in errors:
            console.print(f"  [yellow]•[/] {e}")
        console.print("[yellow]Changes were NOT activated due to errors. Review and activate manually.[/]")


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(
        prog="migrate",
        description="Skyhigh Security → Zscaler policy migration toolkit",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    # fetch-skyhigh
    p = sub.add_parser("fetch-skyhigh", help="Fetch all policies from Skyhigh Cloud SSE")
    p.add_argument("--output", "-o", metavar="FILE", help="Save JSON output to file")

    # fetch-onprem
    p = sub.add_parser("fetch-onprem", help="Fetch rule sets from Skyhigh SWG On-Premises")
    p.add_argument("--output", "-o", metavar="FILE", help="Save JSON output to file")

    # fetch-zscaler
    p = sub.add_parser("fetch-zscaler", help="Fetch all policies from Zscaler ZIA")
    p.add_argument("--output", "-o", metavar="FILE", help="Save JSON output to file")

    # tables
    sub.add_parser("tables", help="Print static Skyhigh→Zscaler mapping reference tables")

    # compare
    p = sub.add_parser("compare", help="Side-by-side policy comparison between platforms")
    p.add_argument("--skyhigh-file", metavar="FILE", help="Use saved Skyhigh JSON instead of live fetch")
    p.add_argument("--zscaler-file", metavar="FILE", help="Use saved Zscaler JSON instead of live fetch")

    # migrate
    p = sub.add_parser("migrate", help="Map Skyhigh policies to Zscaler format and optionally push")
    p.add_argument("--input",  "-i", metavar="FILE", help="Load Skyhigh policies from file (skip live fetch)")
    p.add_argument("--output", "-o", metavar="FILE", help="Save migration report JSON to file")
    p.add_argument("--push",   action="store_true",  help="Push migrated rules to Zscaler ZIA via API")
    p.add_argument("--dry-run", action="store_true", help="Show what would be pushed (implies no --push)")

    # push
    p = sub.add_parser("push", help="Push a previously saved migration JSON report to Zscaler ZIA")
    p.add_argument("--input", "-i", required=True, metavar="FILE")

    args = parser.parse_args()

    dispatch = {
        "fetch-skyhigh": cmd_fetch_skyhigh,
        "fetch-onprem":  cmd_fetch_onprem,
        "fetch-zscaler": cmd_fetch_zscaler,
        "tables":        cmd_tables,
        "compare":       cmd_compare,
        "migrate":       cmd_migrate,
        "push":          cmd_push,
    }
    dispatch[args.command](args)


if __name__ == "__main__":
    main()
