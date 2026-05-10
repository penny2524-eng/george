"""
Policy migration mapper: Skyhigh Security → Zscaler ZIA / ZPA.

Each mapper function accepts a Skyhigh policy object and returns the
nearest equivalent Zscaler structure.  Because the two platforms have
fundamentally different policy models (Skyhigh: XML event-driven rule
engine; Zscaler: ordered JSON rule lists with fixed attribute schemas)
the output is a best-effort translation that requires manual review.

Mapping tables are also provided as plain dicts for documentation and
comparison purposes.
"""

from typing import Any, Dict, List, Optional

# ---------------------------------------------------------------------------
# Field-level mapping reference tables
# ---------------------------------------------------------------------------

# SWG action → ZIA URL filtering action
SWG_ACTION_MAP: Dict[str, str] = {
    "Allow":      "ALLOW",
    "Block":      "BLOCK",
    "Coach":      "CAUTION",
    "Redirect":   "OVERRIDE",
    "SSL Scan":   "ALLOW",          # handled by separate SSL inspection rule
    "Authenticate": "BLOCK",        # force re-auth; map to block + note
}

# Skyhigh URL category names → Zscaler predefined URL category IDs
# (non-exhaustive; extend as needed)
URL_CATEGORY_MAP: Dict[str, str] = {
    "Pornography":              "ADULT_CONTENT",
    "Adult Content":            "ADULT_CONTENT",
    "Gambling":                 "GAMBLING",
    "Social Networking":        "SOCIAL_NETWORKING",
    "Streaming Media":          "STREAMING_MEDIA",
    "P2P/File Sharing":         "PEER_TO_PEER",
    "Malicious Sites":          "MALWARE_SITES",
    "Phishing":                 "PHISHING",
    "Spyware":                  "SPYWARE_ADWARE_KEYLOGGERS",
    "Hacking":                  "HACKING",
    "Anonymizers/Proxies":      "PROXY_AVOIDANCE",
    "News and Media":           "NEWS_AND_MEDIA",
    "Shopping":                 "SHOPPING_AND_AUCTIONS",
    "Instant Messaging":        "WEB_BASED_EMAIL",
    "Weapons":                  "WEAPONS",
    "Violence":                 "VIOLENCE",
    "Drugs":                    "DRUGS",
    "Finance and Investment":   "FINANCE",
    "Health":                   "HEALTH",
    "Entertainment":            "ENTERTAINMENT",
    "Sports":                   "SPORTS",
    "Games":                    "ONLINE_GAMES",
    "Job Search":               "JOB_SEARCH",
    "Real Estate":              "REAL_ESTATE",
    "Education":                "EDUCATION",
    "Government":               "GOVERNMENT",
    "Religious":                "RELIGION",
    "Miscellaneous":            "OTHER_ADULT_MATERIAL",
}

# Skyhigh DLP classification → Zscaler DLP engine name pattern
DLP_CLASSIFICATION_MAP: Dict[str, str] = {
    "Credit Card Numbers":          "CREDIT_CARDS",
    "Social Security Numbers":      "SSN",
    "Bank Account Numbers":         "BANK_ACCOUNTS",
    "HIPAA Protected Health Info":  "HIPAA",
    "PCI DSS":                      "PCI",
    "GDPR Personal Data":           "GDPR",
    "Source Code":                  "SOURCE_CODE",
    "Confidential":                 "CONFIDENTIAL_DATA",
    "Internal":                     "INTERNAL_DATA",
    "Password":                     "PASSWORDS",
    "Intellectual Property":        "IP_AND_COPYRIGHT_CONCERNS",
}

# Skyhigh CASB app action → Zscaler Cloud App Control action
CASB_ACTION_MAP: Dict[str, str] = {
    "Allow":      "ALLOW",
    "Block":      "BLOCK",
    "Monitor":    "ALLOW",    # no direct monitor; use ALLOW + logging
    "Quarantine": "BLOCK",
    "Encrypt":    "ALLOW",    # encryption is a separate Zscaler capability
    "Notify":     "CAUTION",
}

# Skyhigh CASB app category → Zscaler Cloud App rule type
CASB_CATEGORY_MAP: Dict[str, str] = {
    "Collaboration":        "ENTERPRISE_COLLABORATION",
    "Cloud Storage":        "BUSINESS_PRODUCTIVITY",
    "Webmail":              "WEBMAIL",
    "Social Media":         "SOCIAL_NETWORKING",
    "Streaming":            "STREAMING_MEDIA",
    "Business Apps":        "BUSINESS_PRODUCTIVITY",
    "Developer Tools":      "SYSTEM_AND_DEVELOPMENT",
    "Sales & CRM":          "SALES_AND_MARKETING",
    "Finance":              "BUSINESS_PRODUCTIVITY",
    "HR & Recruiting":      "BUSINESS_PRODUCTIVITY",
    "Consumer":             "CONSUMER",
    "Generative AI":        "BUSINESS_PRODUCTIVITY",
}

# Skyhigh on-prem rule set types → Zscaler policy domain
ONPREM_RULESET_TYPE_MAP: Dict[str, str] = {
    "URL Filtering":          "url_filtering_rules",
    "SSL Scanner":            "ssl_settings",
    "DLP":                    "dlp_web_rules",
    "Proxy Settings":         "locations",
    "Authentication":         "url_filtering_rules",
    "Content Inspection":     "dlp_web_rules",
    "Connection":             "firewall_rules",
}


# ---------------------------------------------------------------------------
# SWG → ZIA URL Filtering
# ---------------------------------------------------------------------------

def map_swg_rule_to_zia_url_filter(
    skyhigh_rule: Dict[str, Any], order: int = 1
) -> Dict[str, Any]:
    """
    Convert a Skyhigh SWG allow/block rule to a ZIA URL Filtering Rule.

    Skyhigh fields used:
      name, description, enabled, action, urlCategories, groups,
      locations, time (time-of-day window), protocols

    Zscaler fields produced:
      name, description, state, order, action, urlCategories,
      protocols, groups, locations, timeWindows, requestMethods
    """
    name: str         = skyhigh_rule.get("name", f"Migrated-Rule-{order}")
    description: str  = skyhigh_rule.get("description", "Migrated from Skyhigh SWG")
    enabled: bool     = skyhigh_rule.get("enabled", True)
    shn_action: str   = skyhigh_rule.get("action", "Block")
    zia_action: str   = SWG_ACTION_MAP.get(shn_action, "BLOCK")

    # Map URL categories
    shn_categories: List[str] = skyhigh_rule.get("urlCategories", [])
    zia_categories: List[str] = [
        URL_CATEGORY_MAP.get(c, f"CUSTOM_{c.upper().replace(' ', '_')}")
        for c in shn_categories
    ]

    # Map request methods (Skyhigh: list of verbs; ZIA: same verbs uppercase)
    methods: List[str] = [
        m.upper() for m in skyhigh_rule.get("requestMethods", ["GET", "POST"])
    ]

    # Map protocol scope
    shn_protocols: List[str] = skyhigh_rule.get("protocols", ["HTTP", "HTTPS"])
    zia_protocols: List[str] = []
    for p in shn_protocols:
        if p.upper() in ("HTTP",):
            zia_protocols.append("HTTP_RULE")
        elif p.upper() in ("HTTPS", "SSL"):
            zia_protocols.append("HTTPS_RULE")
        elif p.upper() in ("FTP",):
            zia_protocols.append("FTP_RULE")

    # Groups / locations are referenced by {id, name} objects in ZIA
    groups: List[Dict] = [
        {"id": g.get("id", 0), "name": g.get("name", "")}
        for g in skyhigh_rule.get("groups", [])
    ]
    locations: List[Dict] = [
        {"id": loc.get("id", 0), "name": loc.get("name", "")}
        for loc in skyhigh_rule.get("locations", [])
    ]

    return {
        "name":            name,
        "description":     description,
        "order":           order,
        "state":           "ENABLED" if enabled else "DISABLED",
        "action":          zia_action,
        "urlCategories":   zia_categories,
        "protocols":       zia_protocols or ["HTTP_RULE", "HTTPS_RULE"],
        "requestMethods":  methods,
        "groups":          groups,
        "locations":       locations,
        # timeWindows need a ZIA time-window ID; flag for manual resolution
        "_migration_notes": _collect_notes(shn_action, shn_categories, skyhigh_rule),
    }


def _collect_notes(action: str, categories: List[str], rule: Dict) -> List[str]:
    notes: List[str] = []
    if action == "Authenticate":
        notes.append("Skyhigh 'Authenticate' action has no ZIA equivalent — verify intended behaviour.")
    unmapped = [c for c in categories if c not in URL_CATEGORY_MAP]
    if unmapped:
        notes.append(f"Unmapped URL categories (need custom ZIA category): {unmapped}")
    if rule.get("time"):
        notes.append("Time-of-day condition: create a matching ZIA Time Window and assign manually.")
    if rule.get("sslBypass"):
        notes.append("SSL bypass flag detected: add to ZIA SSL Exemption list separately.")
    return notes


# ---------------------------------------------------------------------------
# DLP → ZIA DLP Web Rule
# ---------------------------------------------------------------------------

def map_dlp_rule_to_zia(
    skyhigh_dlp: Dict[str, Any], order: int = 1
) -> Dict[str, Any]:
    """
    Convert a Skyhigh DLP policy/classification rule to a ZIA DLP Web Rule.

    Skyhigh fields used:
      name, description, enabled, action, classifications,
      fileTypes, cloudApplications

    Zscaler fields produced:
      name, description, order, enabled, action, protocols,
      dlpEngines, fileTypes, cloudApplications, _migration_notes
    """
    name: str        = skyhigh_dlp.get("name", f"DLP-Rule-{order}")
    shn_action: str  = skyhigh_dlp.get("action", "Block")
    zia_action: str  = {
        "Block":      "BLOCK",
        "Allow":      "ALLOW",
        "Notify":     "ALLOW",    # enable incident creation separately
        "Quarantine": "ICAP_RESPONSE",
        "Encrypt":    "ALLOW",
    }.get(shn_action, "BLOCK")

    # Map DLP classifications to ZIA engine references
    shn_classes: List[str] = skyhigh_dlp.get("classifications", [])
    zia_engines: List[Dict] = []
    unmapped_classes: List[str] = []
    for cls in shn_classes:
        mapped = DLP_CLASSIFICATION_MAP.get(cls)
        if mapped:
            zia_engines.append({"name": mapped})
        else:
            unmapped_classes.append(cls)

    # File types are compatible (both use MIME / extension strings)
    file_types: List[str] = skyhigh_dlp.get("fileTypes", [])

    notes: List[str] = []
    if unmapped_classes:
        notes.append(f"Unmapped DLP classifications (create ZIA custom dictionary): {unmapped_classes}")
    if shn_action == "Notify":
        notes.append("Skyhigh Notify → ZIA ALLOW; enable DLP Notification Template separately.")
    if shn_action == "Encrypt":
        notes.append("Encryption is handled by Zscaler CASB/ZIA SaaS Security, not DLP web rules.")

    return {
        "name":               name,
        "description":        skyhigh_dlp.get("description", "Migrated from Skyhigh DLP"),
        "order":              order,
        "enabled":            skyhigh_dlp.get("enabled", True),
        "action":             zia_action,
        "protocols":          ["FTP_RULE", "HTTPS_RULE", "HTTP_RULE"],
        "dlpEngines":         zia_engines,
        "fileTypes":          file_types,
        "cloudApplications":  skyhigh_dlp.get("cloudApplications", []),
        "_migration_notes":   notes,
    }


# ---------------------------------------------------------------------------
# CASB → ZIA Cloud App Control Rule
# ---------------------------------------------------------------------------

def map_casb_service_group_to_zia(
    shn_service_group: Dict[str, Any], order: int = 1
) -> Dict[str, Any]:
    """
    Convert a Skyhigh CASB Service Group to a ZIA Cloud App Control rule.

    Skyhigh fields used:
      name, description, services (list of SaaS app names),
      action, category

    Zscaler fields produced:
      name, description, order, state, type, applications, action,
      _migration_notes
    """
    name: str        = shn_service_group.get("name", f"CASB-Rule-{order}")
    shn_action: str  = shn_service_group.get("action", "Allow")
    zia_action: str  = CASB_ACTION_MAP.get(shn_action, "ALLOW")
    category: str    = shn_service_group.get("category", "Business Apps")
    rule_type: str   = CASB_CATEGORY_MAP.get(category, "BUSINESS_PRODUCTIVITY")

    # Services list → Zscaler application IDs (require lookup in ZIA)
    services: List[str] = shn_service_group.get("services", [])
    notes: List[str] = [
        "Application IDs must be resolved via GET /api/v1/cloudApplications before creating this rule.",
    ]
    if shn_action == "Monitor":
        notes.append("Skyhigh Monitor → ZIA ALLOW; enable logging/reporting in ZIA analytics.")

    return {
        "name":         name,
        "description":  shn_service_group.get("description", "Migrated from Skyhigh CASB"),
        "order":        order,
        "state":        "ENABLED" if shn_service_group.get("enabled", True) else "DISABLED",
        "type":         rule_type,
        "applications": services,   # replace with resolved ZIA app IDs before pushing
        "action":       zia_action,
        "_migration_notes": notes,
    }


# ---------------------------------------------------------------------------
# CASB Service Group → ZIA Custom URL Category (for Shadow IT sync)
# ---------------------------------------------------------------------------

def map_casb_service_group_to_url_category(
    shn_service_group: Dict[str, Any],
) -> Dict[str, Any]:
    """
    Convert a Skyhigh CASB Service Group to a Zscaler Custom URL Category.
    This mirrors the native Skyhigh→Zscaler CASB integration (SHN-prefix categories).
    """
    urls: List[str] = shn_service_group.get("urls", [])
    return {
        "configuredName":  f"SHN_{shn_service_group.get('name', 'Unknown')}",
        "type":            "URL_CATEGORY",
        "urls":            urls,
        "description":     f"Auto-migrated from Skyhigh CASB: {shn_service_group.get('name')}",
        "superCategory":   "USER_DEFINED",
        "_migration_notes": ["Max 25,000 URLs across all custom ZIA categories."],
    }


# ---------------------------------------------------------------------------
# CWPP / CSPM → ZPA  (structural mapping; no direct API equivalent)
# ---------------------------------------------------------------------------

def map_cwpp_policy_to_zpa_access(
    shn_policy: Dict[str, Any], order: int = 1
) -> Dict[str, Any]:
    """
    Approximate mapping of a Skyhigh CWPP policy to a ZPA Access Policy rule.
    CWPP workload protection has no direct ZPA equivalent; this maps the
    access-control aspects (who can reach which workload) to ZPA app segments.
    """
    notes: List[str] = [
        "CWPP posture/vulnerability policies have no ZPA equivalent — handle via ZPA Inspection Policy.",
        "Review 'conditions' manually: OS, vulnerability severity, and compliance checks are CWPP-specific.",
    ]
    return {
        "name":        shn_policy.get("name", f"CWPP-Policy-{order}"),
        "description": shn_policy.get("description", "Migrated from Skyhigh CWPP"),
        "action":      "ALLOW" if shn_policy.get("action", "allow") == "allow" else "DENY",
        "policyType":  "ACCESS_POLICY",
        "_migration_notes": notes,
    }


# ---------------------------------------------------------------------------
# Batch migration helpers
# ---------------------------------------------------------------------------

def migrate_swg_rules(skyhigh_rules: List[Dict]) -> List[Dict]:
    """Map a list of Skyhigh SWG rules to ZIA URL filtering format."""
    return [
        map_swg_rule_to_zia_url_filter(rule, order=i + 1)
        for i, rule in enumerate(skyhigh_rules)
    ]


def migrate_dlp_rules(skyhigh_dlp_rules: List[Dict]) -> List[Dict]:
    """Map a list of Skyhigh DLP rules to ZIA DLP web rule format."""
    return [
        map_dlp_rule_to_zia(rule, order=i + 1)
        for i, rule in enumerate(skyhigh_dlp_rules)
    ]


def migrate_casb_service_groups(
    service_groups: List[Dict],
    mode: str = "cloud_app",   # "cloud_app" or "url_category"
) -> List[Dict]:
    """Map Skyhigh CASB service groups to ZIA cloud app rules or custom categories."""
    if mode == "url_category":
        return [map_casb_service_group_to_url_category(sg) for sg in service_groups]
    return [
        map_casb_service_group_to_zia(sg, order=i + 1)
        for i, sg in enumerate(service_groups)
    ]


def full_migration_report(skyhigh_policies: Dict[str, Any]) -> Dict[str, Any]:
    """
    Produce a complete migration report from a Skyhigh policy dump.

    Returns a dict with keys matching ZIA/ZPA policy domains and a
    top-level 'summary' section describing coverage and known gaps.
    """
    swg_rules    = skyhigh_policies.get("swg_rule_sets", [])
    dlp_rules    = skyhigh_policies.get("dlp_classifications", [])
    casb_groups  = skyhigh_policies.get("casb_service_groups", [])
    cwpp_pols    = skyhigh_policies.get("cwpp_policies", [])

    zia_url_rules     = migrate_swg_rules(swg_rules)
    zia_dlp_rules     = migrate_dlp_rules(dlp_rules)
    zia_casb_rules    = migrate_casb_service_groups(casb_groups, mode="cloud_app")
    zia_casb_cats     = migrate_casb_service_groups(casb_groups, mode="url_category")
    zpa_access_rules  = [map_cwpp_policy_to_zpa_access(p, i+1) for i, p in enumerate(cwpp_pols)]

    all_notes: List[str] = []
    for rule in zia_url_rules + zia_dlp_rules + zia_casb_rules + zpa_access_rules:
        all_notes.extend(rule.get("_migration_notes", []))

    return {
        "summary": {
            "skyhigh_swg_rules_count":   len(swg_rules),
            "skyhigh_dlp_rules_count":   len(dlp_rules),
            "skyhigh_casb_groups_count": len(casb_groups),
            "skyhigh_cwpp_pols_count":   len(cwpp_pols),
            "zia_url_filtering_rules":   len(zia_url_rules),
            "zia_dlp_web_rules":         len(zia_dlp_rules),
            "zia_cloud_app_rules":       len(zia_casb_rules),
            "zia_custom_url_categories": len(zia_casb_cats),
            "zpa_access_rules":          len(zpa_access_rules),
            "migration_notes":           sorted(set(all_notes)),
        },
        "zia_url_filtering_rules":   zia_url_rules,
        "zia_dlp_web_rules":         zia_dlp_rules,
        "zia_cloud_app_rules":       zia_casb_rules,
        "zia_custom_url_categories": zia_casb_cats,
        "zpa_access_rules":          zpa_access_rules,
    }
