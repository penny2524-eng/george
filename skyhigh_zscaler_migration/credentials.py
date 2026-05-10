"""
╔══════════════════════════════════════════════════════════════════════════════╗
║          CREDENTIALS & CONFIGURATION — fill in your values here            ║
║                                                                              ║
║  This is the single file you need to edit before running any command.        ║
║  Each section has inline notes explaining where to find each value.          ║
║                                                                              ║
║  SECURITY: do NOT commit this file with real credentials in source control.  ║
║  Add credentials.py to .gitignore once you have filled it in.               ║
╚══════════════════════════════════════════════════════════════════════════════╝
"""

import os

# ─────────────────────────────────────────────────────────────────────────────
#  SECTION 1 — SKYHIGH SECURITY (Cloud SSE)
#
#  Used by:  fetch-skyhigh, compare, migrate
#
#  Where to find these values:
#    • Tenant ID  → Skyhigh console ▸ Settings ▸ Account ▸ Tenant Information
#    • Username   → your Skyhigh admin login e-mail
#    • Password   → your Skyhigh admin password
#    • Region     → one of:  us | eu | ca | gov
#                   (us  → www.myshn.net   / api-gateway.skyhigh.cloud)
#                   (eu  → www.myshn.eu)
#                   (ca  → www.myshn.ca)
#                   (gov → www.govshn.net)
# ─────────────────────────────────────────────────────────────────────────────

SKYHIGH_CLOUD = {
    "tenant_id": os.environ.get("SHN_TENANT_ID",  ""),   # e.g. "a1b2c3d4-e5f6-..."
    "username":  os.environ.get("SHN_USERNAME",   ""),   # e.g. "admin@company.com"
    "password":  os.environ.get("SHN_PASSWORD",   ""),   # e.g. "MyP@ssw0rd"
    "region":    os.environ.get("SHN_REGION",     "us"), # us | eu | ca | gov
}


# ─────────────────────────────────────────────────────────────────────────────
#  SECTION 2 — SKYHIGH SWG ON-PREMISES
#
#  Used by:  fetch-onprem
#
#  Where to find these values:
#    • Host      → IP address or hostname of your MWG appliance
#    • Port      → default is 4711 (Konfigurator REST port)
#    • Username  → MWG admin account
#    • Password  → MWG admin password
#    • use_ssl   → set True if the management interface is HTTPS
# ─────────────────────────────────────────────────────────────────────────────

SKYHIGH_ONPREM = {
    "host":     os.environ.get("SHN_ONPREM_HOST",     ""),      # e.g. "10.0.1.50" or "mwg.corp.local"
    "port":     int(os.environ.get("SHN_ONPREM_PORT", "4711")), # default: 4711
    "username": os.environ.get("SHN_ONPREM_USERNAME", ""),      # e.g. "admin"
    "password": os.environ.get("SHN_ONPREM_PASSWORD", ""),      # e.g. "Admin@123"
    "use_ssl":  os.environ.get("SHN_ONPREM_SSL", "false").lower() == "true",
}


# ─────────────────────────────────────────────────────────────────────────────
#  SECTION 3 — ZSCALER INTERNET ACCESS (ZIA)
#
#  Used by:  fetch-zscaler, compare, migrate --push, push
#
#  Where to find these values:
#    • Cloud     → Administration ▸ Cloud Configuration
#                  Common values:  zscaler.net | zscalerone.net | zscalertwo.net
#                                  zscalerthree.net | zscalerbeta.net | zscloud.net
#                  Quick check: in the ZIA portal URL, the domain after "admin."
#                  is your cloud name (e.g. admin.zscaler.net → "zscaler.net")
#    • Username  → ZIA super-admin e-mail
#    • Password  → ZIA super-admin password
#    • API Key   → Administration ▸ API Key Management ▸ copy the RAW key
#                  (the tool obfuscates it automatically — paste the plain key)
# ─────────────────────────────────────────────────────────────────────────────

ZSCALER_ZIA = {
    "cloud":    os.environ.get("ZIA_CLOUD",    ""),  # e.g. "zscaler.net"
    "username": os.environ.get("ZIA_USERNAME", ""),  # e.g. "admin@company.com"
    "password": os.environ.get("ZIA_PASSWORD", ""),  # e.g. "Admin@123"
    "api_key":  os.environ.get("ZIA_API_KEY",  ""),  # e.g. "6a8b2c9d3e7f1..."
}


# ─────────────────────────────────────────────────────────────────────────────
#  SECTION 4 — ZSCALER PRIVATE ACCESS (ZPA)
#
#  Used by:  fetch-zscaler (ZPA segment), migrate (CWPP → ZPA mapping)
#
#  Where to find these values:
#    • Client ID / Secret → ZPA admin portal ▸ Administration ▸ API Key Management
#                           Create a new key pair; copy both values immediately.
#    • Customer ID        → ZPA admin portal ▸ Administration ▸ Company Information
#    • Cloud              → usually "config.private.zscaler.com"
#                           Beta tenants: "config.zpabeta.net"
#                           ZPATwo tenants: "config.zpatwo.net"
# ─────────────────────────────────────────────────────────────────────────────

ZSCALER_ZPA = {
    "client_id":     os.environ.get("ZPA_CLIENT_ID",     ""),  # e.g. "zpa-client-abc123"
    "client_secret": os.environ.get("ZPA_CLIENT_SECRET", ""),  # e.g. "zpa-secret-xyz789"
    "customer_id":   os.environ.get("ZPA_CUSTOMER_ID",   ""),  # e.g. "123456789"
    "cloud":         os.environ.get("ZPA_CLOUD", "config.private.zscaler.com"),
}


# ─────────────────────────────────────────────────────────────────────────────
#  SECTION 5 — REPORTING / FORENSICS (optional)
#
#  Used by:  fetch-skyhigh (log export, DLP forensics)
#
#  Where to find these values:
#    • Customer ID  → same as SHN_TENANT_ID above
#    • Region code  → one of:  us | eu | gb | de | ca | au | sg | in | sa | ae
#                    (maps to <region>.logapi.skyhigh.cloud)
# ─────────────────────────────────────────────────────────────────────────────

SKYHIGH_REPORTING = {
    "customer_id":  os.environ.get("SHN_TENANT_ID", ""),
    "region_code":  os.environ.get("SHN_REGION",    "us"),
}


# ─────────────────────────────────────────────────────────────────────────────
#  SECTION 6 — GENERAL BEHAVIOUR (optional tweaks)
# ─────────────────────────────────────────────────────────────────────────────

SETTINGS = {
    # How many DLP/CASB incident pages to retrieve (100 items each)
    "max_incident_pages": int(os.environ.get("MAX_INCIDENT_PAGES", "5")),

    # Where to write output files when --output is not specified on the CLI
    "default_output_dir": os.environ.get("OUTPUT_DIR", "."),

    # Set to True to skip SSL certificate verification for on-prem hosts
    # with self-signed certificates (NOT recommended for production)
    "onprem_skip_tls_verify": os.environ.get("ONPREM_SKIP_TLS", "false").lower() == "true",
}


# ─────────────────────────────────────────────────────────────────────────────
#  VALIDATION HELPER  (called at startup by migrate.py)
# ─────────────────────────────────────────────────────────────────────────────

def validate(section: str) -> list[str]:
    """
    Return a list of missing/empty fields for the given section name.
    Sections: "skyhigh_cloud" | "skyhigh_onprem" | "zia" | "zpa"
    """
    checks = {
        "skyhigh_cloud":  (SKYHIGH_CLOUD,  ["tenant_id", "username", "password"]),
        "skyhigh_onprem": (SKYHIGH_ONPREM, ["host", "username", "password"]),
        "zia":            (ZSCALER_ZIA,    ["cloud", "username", "password", "api_key"]),
        "zpa":            (ZSCALER_ZPA,    ["client_id", "client_secret", "customer_id"]),
    }
    cfg, required = checks[section]
    return [f for f in required if not cfg.get(f)]
