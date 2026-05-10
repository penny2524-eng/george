"""
Rich terminal tables for visualising Skyhigh and Zscaler policies
and the side-by-side migration comparison.
"""

from typing import Any, Dict, List, Optional

from rich.console import Console
from rich.panel import Panel
from rich.table import Table
from rich import box

console = Console()


# ---------------------------------------------------------------------------
# Skyhigh tables
# ---------------------------------------------------------------------------

def show_skyhigh_swg_rules(rules: List[Dict], title: str = "Skyhigh SWG Rules") -> None:
    t = Table(title=title, box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",           style="dim",     width=4,  no_wrap=True)
    t.add_column("Name",        style="cyan",    min_width=20)
    t.add_column("Enabled",     justify="center", width=8)
    t.add_column("Action",      style="bold",    width=12)
    t.add_column("URL Categories",               min_width=25)
    t.add_column("Groups / Locations",           min_width=20)
    t.add_column("Protocols",                    width=16)

    for i, rule in enumerate(rules, 1):
        enabled_str   = "[green]Yes[/]" if rule.get("enabled", True) else "[red]No[/]"
        action        = rule.get("action", "—")
        action_styled = _style_action(action)
        categories    = ", ".join(rule.get("urlCategories", [])) or "—"
        groups        = ", ".join(
            [g.get("name", "") for g in rule.get("groups", [])]
            + [loc.get("name", "") for loc in rule.get("locations", [])]
        ) or "—"
        protocols     = ", ".join(rule.get("protocols", [])) or "—"

        t.add_row(
            str(i),
            rule.get("name", "—"),
            enabled_str,
            action_styled,
            categories,
            groups,
            protocols,
        )

    console.print(t)


def show_skyhigh_dlp_rules(rules: List[Dict], title: str = "Skyhigh DLP Rules") -> None:
    t = Table(title=title, box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",               style="dim",  width=4,  no_wrap=True)
    t.add_column("Name",            style="cyan", min_width=20)
    t.add_column("Enabled",         justify="center", width=8)
    t.add_column("Action",          style="bold", width=12)
    t.add_column("Classifications",               min_width=30)
    t.add_column("File Types",                    min_width=20)

    for i, rule in enumerate(rules, 1):
        enabled_str = "[green]Yes[/]" if rule.get("enabled", True) else "[red]No[/]"
        action      = rule.get("action", "—")
        classes     = ", ".join(rule.get("classifications", [])) or "—"
        file_types  = ", ".join(rule.get("fileTypes", [])) or "—"

        t.add_row(
            str(i), rule.get("name", "—"), enabled_str,
            _style_action(action), classes, file_types,
        )

    console.print(t)


def show_skyhigh_casb_groups(groups: List[Dict], title: str = "Skyhigh CASB Service Groups") -> None:
    t = Table(title=title, box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",          style="dim",  width=4,  no_wrap=True)
    t.add_column("Name",       style="cyan", min_width=20)
    t.add_column("Category",               min_width=22)
    t.add_column("Action",     style="bold", width=12)
    t.add_column("# Services", justify="right", width=10)
    t.add_column("Sample Services",        min_width=30)

    for i, grp in enumerate(groups, 1):
        services = grp.get("services", [])
        sample   = ", ".join(services[:5]) + ("…" if len(services) > 5 else "")

        t.add_row(
            str(i),
            grp.get("name", "—"),
            grp.get("category", "—"),
            _style_action(grp.get("action", "Allow")),
            str(len(services)),
            sample or "—",
        )

    console.print(t)


def show_skyhigh_cwpp_policies(policies: List[Dict], title: str = "Skyhigh CWPP / CSPM Policies") -> None:
    t = Table(title=title, box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",          style="dim",  width=4)
    t.add_column("Name",       style="cyan", min_width=25)
    t.add_column("Type",                    min_width=15)
    t.add_column("Severity",   style="bold", width=12)
    t.add_column("Enabled",    justify="center", width=8)
    t.add_column("Description",             min_width=30)

    for i, pol in enumerate(policies, 1):
        enabled_str = "[green]Yes[/]" if pol.get("enabled", True) else "[red]No[/]"
        severity    = pol.get("severity", "—")
        sev_styled  = _style_severity(severity)

        t.add_row(
            str(i),
            pol.get("name", "—"),
            pol.get("type", "—"),
            sev_styled,
            enabled_str,
            pol.get("description", "—")[:60],
        )

    console.print(t)


def show_skyhigh_all(policies: Dict[str, Any]) -> None:
    console.print(Panel("[bold magenta]Skyhigh Security — Policy Inventory[/]", expand=False))

    swg_rules = policies.get("swg_rule_sets") or policies.get("swg_feature_config", {}).get("ruleSets", [])
    if swg_rules:
        show_skyhigh_swg_rules(swg_rules)

    swg_lists = policies.get("swg_lists", [])
    if swg_lists:
        _show_list_summary(swg_lists, "Skyhigh SWG URL/IP Lists")

    dlp_rules = policies.get("dlp_classifications", [])
    if dlp_rules:
        show_skyhigh_dlp_rules(dlp_rules)

    casb_groups = policies.get("casb_service_groups", [])
    if casb_groups:
        show_skyhigh_casb_groups(casb_groups)

    cwpp = policies.get("cwpp_policies", [])
    cspm = policies.get("cspm_policies", [])
    if cwpp or cspm:
        show_skyhigh_cwpp_policies(cwpp + cspm)


def _show_list_summary(lists: List[Dict], title: str) -> None:
    t = Table(title=title, box=box.SIMPLE_HEAVY, expand=True)
    t.add_column("#", style="dim", width=4)
    t.add_column("Name", style="cyan", min_width=25)
    t.add_column("Type", width=15)
    t.add_column("# Entries", justify="right", width=10)
    for i, lst in enumerate(lists, 1):
        entries = lst.get("entries", lst.get("urls", []))
        t.add_row(str(i), lst.get("name", "—"), lst.get("type", "—"), str(len(entries)))
    console.print(t)


# ---------------------------------------------------------------------------
# Zscaler tables
# ---------------------------------------------------------------------------

def show_zia_url_filtering(rules: List[Dict], title: str = "Zscaler ZIA — URL Filtering Rules") -> None:
    t = Table(title=title, box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",             style="dim",  width=4)
    t.add_column("Order",         justify="right", width=6)
    t.add_column("Name",          style="cyan", min_width=22)
    t.add_column("State",         justify="center", width=10)
    t.add_column("Action",        style="bold", width=12)
    t.add_column("URL Categories",              min_width=25)
    t.add_column("Protocols",                   width=22)

    for i, rule in enumerate(rules, 1):
        state = rule.get("state", "ENABLED")
        state_styled = "[green]ENABLED[/]" if state == "ENABLED" else "[red]DISABLED[/]"
        t.add_row(
            str(i),
            str(rule.get("order", i)),
            rule.get("name", "—"),
            state_styled,
            _style_action(rule.get("action", "—")),
            ", ".join(rule.get("urlCategories", [])) or "—",
            ", ".join(rule.get("protocols", [])) or "—",
        )

    console.print(t)


def show_zia_dlp_rules(rules: List[Dict], title: str = "Zscaler ZIA — DLP Web Rules") -> None:
    t = Table(title=title, box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",              style="dim",  width=4)
    t.add_column("Name",           style="cyan", min_width=22)
    t.add_column("Enabled",        justify="center", width=8)
    t.add_column("Action",         style="bold", width=12)
    t.add_column("DLP Engines",                 min_width=22)
    t.add_column("File Types",                  min_width=20)

    for i, rule in enumerate(rules, 1):
        enabled_str = "[green]Yes[/]" if rule.get("enabled", True) else "[red]No[/]"
        engines = ", ".join(
            e.get("name", str(e)) if isinstance(e, dict) else str(e)
            for e in rule.get("dlpEngines", [])
        ) or "—"
        t.add_row(
            str(i),
            rule.get("name", "—"),
            enabled_str,
            _style_action(rule.get("action", "—")),
            engines,
            ", ".join(rule.get("fileTypes", [])) or "—",
        )

    console.print(t)


def show_zia_dlp_dictionaries(dicts: List[Dict]) -> None:
    t = Table(title="Zscaler ZIA — DLP Dictionaries", box=box.ROUNDED, expand=True)
    t.add_column("#",          style="dim",  width=4)
    t.add_column("Name",       style="cyan", min_width=25)
    t.add_column("Type",                    width=18)
    t.add_column("# Phrases",  justify="right", width=10)
    t.add_column("# Patterns", justify="right", width=10)

    for i, d in enumerate(dicts, 1):
        t.add_row(
            str(i),
            d.get("name", "—"),
            d.get("dictionaryType", "—"),
            str(len(d.get("phrases", []))),
            str(len(d.get("patterns", []))),
        )

    console.print(t)


def show_zia_cloud_app_rules(rules_by_type: Dict[str, List[Dict]]) -> None:
    for rule_type, rules in rules_by_type.items():
        if not rules:
            continue
        t = Table(
            title=f"Zscaler ZIA — Cloud App Control: {rule_type}",
            box=box.ROUNDED, show_lines=True, expand=True,
        )
        t.add_column("#",       style="dim",  width=4)
        t.add_column("Name",    style="cyan", min_width=22)
        t.add_column("State",   justify="center", width=10)
        t.add_column("Action",  style="bold", width=12)
        t.add_column("Applications",           min_width=30)

        for i, rule in enumerate(rules, 1):
            state = rule.get("state", "ENABLED")
            state_styled = "[green]ENABLED[/]" if state == "ENABLED" else "[red]DISABLED[/]"
            apps = ", ".join(
                a.get("name", str(a)) if isinstance(a, dict) else str(a)
                for a in rule.get("applications", [])
            ) or "—"
            t.add_row(str(i), rule.get("name", "—"), state_styled,
                      _style_action(rule.get("action", "—")), apps)

        console.print(t)


def show_zia_firewall_rules(rules: List[Dict]) -> None:
    t = Table(title="Zscaler ZIA — Cloud Firewall Rules", box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("#",             style="dim",  width=4)
    t.add_column("Order",         justify="right", width=6)
    t.add_column("Name",          style="cyan", min_width=22)
    t.add_column("State",         justify="center", width=10)
    t.add_column("Action",        style="bold", width=12)
    t.add_column("Dest Groups",              min_width=20)
    t.add_column("Services",                 min_width=20)

    for i, rule in enumerate(rules, 1):
        state = rule.get("state", "ENABLED")
        state_styled = "[green]ENABLED[/]" if state == "ENABLED" else "[red]DISABLED[/]"
        dest = ", ".join(
            g.get("name", str(g)) if isinstance(g, dict) else str(g)
            for g in rule.get("destIpGroups", [])
        ) or "—"
        services = ", ".join(
            s.get("name", str(s)) if isinstance(s, dict) else str(s)
            for s in rule.get("nwServices", [])
        ) or "—"
        t.add_row(str(i), str(rule.get("order", i)), rule.get("name", "—"),
                  state_styled, _style_action(rule.get("action", "—")), dest, services)

    console.print(t)


def show_zia_all(policies: Dict[str, Any]) -> None:
    console.print(Panel("[bold blue]Zscaler ZIA — Policy Inventory[/]", expand=False))

    if policies.get("url_filtering_rules"):
        show_zia_url_filtering(policies["url_filtering_rules"])

    if policies.get("dlp_web_rules"):
        show_zia_dlp_rules(policies["dlp_web_rules"])

    if policies.get("dlp_dictionaries"):
        show_zia_dlp_dictionaries(policies["dlp_dictionaries"])

    if policies.get("cloud_app_rules"):
        show_zia_cloud_app_rules(policies["cloud_app_rules"])

    if policies.get("firewall_rules"):
        show_zia_firewall_rules(policies["firewall_rules"])


# ---------------------------------------------------------------------------
# Migration comparison table
# ---------------------------------------------------------------------------

def show_migration_mapping_tables() -> None:
    """Print all static field-mapping reference tables."""
    console.print(Panel("[bold yellow]Migration Mapping Reference Tables: Skyhigh → Zscaler[/]", expand=False))

    # Action map
    t = Table(title="SWG / CASB Action Mapping", box=box.SIMPLE_HEAVY, expand=False)
    t.add_column("Skyhigh Action",  style="cyan",  min_width=18)
    t.add_column("Zscaler Action",  style="green", min_width=18)
    t.add_column("Notes",                          min_width=40)

    _swg_action_notes = {
        "Allow":      "Direct equivalent",
        "Block":      "Direct equivalent",
        "Coach":      "ZIA Caution page; configure page URL in ZIA settings",
        "Redirect":   "Override — requires ZIA Safe Search / redirect URL",
        "SSL Scan":   "Handled by ZIA SSL Inspection policy, not URL filter",
        "Authenticate": "No direct ZIA equivalent — use AD/LDAP auth + BLOCK fallback",
    }
    from migration_mapper import SWG_ACTION_MAP
    for shn, zia in SWG_ACTION_MAP.items():
        t.add_row(shn, zia, _swg_action_notes.get(shn, ""))
    console.print(t)

    # URL category map (first 20 entries)
    t2 = Table(title="URL Category Mapping (sample — 20 of many)", box=box.SIMPLE_HEAVY, expand=False)
    t2.add_column("Skyhigh Category",  style="cyan",  min_width=28)
    t2.add_column("Zscaler Category",  style="green", min_width=28)

    from migration_mapper import URL_CATEGORY_MAP
    for shn, zia in list(URL_CATEGORY_MAP.items())[:20]:
        t2.add_row(shn, zia)
    console.print(t2)

    # DLP classification map
    t3 = Table(title="DLP Classification → ZIA DLP Engine Mapping", box=box.SIMPLE_HEAVY, expand=False)
    t3.add_column("Skyhigh Classification", style="cyan",  min_width=30)
    t3.add_column("ZIA DLP Engine Name",    style="green", min_width=28)

    from migration_mapper import DLP_CLASSIFICATION_MAP
    for shn, zia in DLP_CLASSIFICATION_MAP.items():
        t3.add_row(shn, zia)
    console.print(t3)

    # CASB category map
    t4 = Table(title="CASB App Category → ZIA Cloud App Rule Type", box=box.SIMPLE_HEAVY, expand=False)
    t4.add_column("Skyhigh CASB Category",   style="cyan",  min_width=22)
    t4.add_column("ZIA Cloud App Rule Type", style="green", min_width=30)

    from migration_mapper import CASB_CATEGORY_MAP
    for shn, zia in CASB_CATEGORY_MAP.items():
        t4.add_row(shn, zia)
    console.print(t4)


def show_migration_report(report: Dict[str, Any]) -> None:
    """Render a full migration report produced by migration_mapper.full_migration_report()."""
    console.print(Panel("[bold yellow]Migration Report: Skyhigh → Zscaler[/]", expand=False))

    # Summary counts
    summary = report.get("summary", {})
    t = Table(title="Summary", box=box.SIMPLE_HEAVY, expand=False)
    t.add_column("Metric",    style="dim",   min_width=35)
    t.add_column("Count",     justify="right", style="bold", width=8)

    metrics = [
        ("Skyhigh SWG rules",         "skyhigh_swg_rules_count"),
        ("Skyhigh DLP rules",          "skyhigh_dlp_rules_count"),
        ("Skyhigh CASB service groups","skyhigh_casb_groups_count"),
        ("Skyhigh CWPP policies",      "skyhigh_cwpp_pols_count"),
        ("→ ZIA URL filtering rules",  "zia_url_filtering_rules"),
        ("→ ZIA DLP web rules",        "zia_dlp_web_rules"),
        ("→ ZIA cloud app rules",      "zia_cloud_app_rules"),
        ("→ ZIA custom URL categories","zia_custom_url_categories"),
        ("→ ZPA access rules",         "zpa_access_rules"),
    ]
    for label, key in metrics:
        t.add_row(label, str(summary.get(key, 0)))
    console.print(t)

    # Migration notes
    notes = summary.get("migration_notes", [])
    if notes:
        console.print("\n[bold red]Migration Notes (require manual review):[/]")
        for note in notes:
            console.print(f"  [yellow]•[/] {note}")

    # Per-domain detail tables
    if report.get("zia_url_filtering_rules"):
        show_zia_url_filtering(
            report["zia_url_filtering_rules"],
            title="Migrated → ZIA URL Filtering Rules",
        )

    if report.get("zia_dlp_web_rules"):
        show_zia_dlp_rules(
            report["zia_dlp_web_rules"],
            title="Migrated → ZIA DLP Web Rules",
        )

    if report.get("zia_cloud_app_rules"):
        show_zia_cloud_app_rules(
            {"CLOUD_APP_CONTROL": report["zia_cloud_app_rules"]}
        )


def show_side_by_side_comparison(
    skyhigh_policies: Dict[str, Any],
    zscaler_policies: Dict[str, Any],
) -> None:
    """High-level capability/count comparison of what each platform has configured."""
    console.print(Panel("[bold]Side-by-Side Policy Comparison: Skyhigh vs Zscaler[/]", expand=False))

    t = Table(box=box.ROUNDED, show_lines=True, expand=True)
    t.add_column("Policy Domain",       style="bold",  min_width=28)
    t.add_column("Skyhigh",             style="cyan",  min_width=20, justify="center")
    t.add_column("Zscaler Equivalent",  style="green", min_width=28, justify="center")
    t.add_column("Count (Skyhigh)",     justify="right", width=16)
    t.add_column("Count (Zscaler)",     justify="right", width=16)

    rows = [
        ("SWG URL Filtering",
         "SWG Rule Sets",
         "ZIA URL Filtering Rules",
         len(skyhigh_policies.get("swg_rule_sets", [])),
         len(zscaler_policies.get("url_filtering_rules", []))),
        ("DLP Policies",
         "DLP Classifications",
         "ZIA DLP Web Rules",
         len(skyhigh_policies.get("dlp_classifications", [])),
         len(zscaler_policies.get("dlp_web_rules", []))),
        ("DLP Dictionaries / Engines",
         "DLP Classifications",
         "ZIA DLP Dictionaries",
         len(skyhigh_policies.get("dlp_classifications", [])),
         len(zscaler_policies.get("dlp_dictionaries", []))),
        ("CASB / Cloud App Control",
         "CASB Service Groups",
         "ZIA Cloud App Rules",
         len(skyhigh_policies.get("casb_service_groups", [])),
         sum(len(v) for v in zscaler_policies.get("cloud_app_rules", {}).values())),
        ("URL / IP Lists",
         "SWG Lists",
         "ZIA Custom URL Categories",
         len(skyhigh_policies.get("swg_lists", [])),
         len([c for c in zscaler_policies.get("url_categories", []) if c.get("superCategory") == "USER_DEFINED"])),
        ("Cloud Firewall",
         "N/A (SWG only)",
         "ZIA Firewall Rules",
         0,
         len(zscaler_policies.get("firewall_rules", []))),
        ("Cloud Workload Protection",
         "CWPP Policies",
         "ZPA Inspection / Access",
         len(skyhigh_policies.get("cwpp_policies", [])),
         len(zscaler_policies.get("zpa_access_policies", []))),
        ("SSL Inspection",
         "SSL Scanner Rules",
         "ZIA SSL Settings",
         len(skyhigh_policies.get("ssl_rules", [])),
         1 if zscaler_policies.get("ssl_settings") else 0),
        ("Locations / Sites",
         "SWG Locations",
         "ZIA Locations",
         len(skyhigh_policies.get("swg_locations", [])),
         len(zscaler_policies.get("locations", []))),
    ]

    for domain, shn_label, zia_label, shn_count, zia_count in rows:
        t.add_row(domain, shn_label, zia_label, str(shn_count), str(zia_count))

    console.print(t)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _style_action(action: str) -> str:
    mapping = {
        "ALLOW":         "[green]ALLOW[/]",
        "Allow":         "[green]Allow[/]",
        "BLOCK":         "[red]BLOCK[/]",
        "Block":         "[red]Block[/]",
        "CAUTION":       "[yellow]CAUTION[/]",
        "Coach":         "[yellow]Coach[/]",
        "OVERRIDE":      "[blue]OVERRIDE[/]",
        "Redirect":      "[blue]Redirect[/]",
        "DENY":          "[red]DENY[/]",
        "Monitor":       "[cyan]Monitor[/]",
        "Quarantine":    "[magenta]Quarantine[/]",
        "ICAP_RESPONSE": "[magenta]ICAP[/]",
    }
    return mapping.get(action, action)


def _style_severity(severity: str) -> str:
    mapping = {
        "CRITICAL": "[bold red]CRITICAL[/]",
        "HIGH":     "[red]HIGH[/]",
        "MEDIUM":   "[yellow]MEDIUM[/]",
        "LOW":      "[green]LOW[/]",
        "INFO":     "[dim]INFO[/]",
    }
    return mapping.get(severity.upper() if severity else "", severity or "—")
