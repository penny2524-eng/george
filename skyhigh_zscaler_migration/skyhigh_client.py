"""
Skyhigh Security API clients — Cloud SSE and On-Premises SWG.

Authentication:
  Cloud:    IAM Bearer token via POST /shnapi/rest/external/api/v1/token
  On-Prem:  Session cookie via POST /Konfigurator/REST/login
"""

import base64
import logging
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

import requests

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Cloud SSE
# ---------------------------------------------------------------------------

@dataclass
class SkyhighCloudConfig:
    tenant_id: str
    username: str
    password: str
    region: str = "us"   # us | eu | ca | gov

    _REGION_HOSTS: Dict[str, str] = field(default_factory=lambda: {
        "us":  "https://www.myshn.net",
        "eu":  "https://www.myshn.eu",
        "ca":  "https://www.myshn.ca",
        "gov": "https://www.govshn.net",
    }, repr=False, compare=False)

    @property
    def base_url(self) -> str:
        return self._REGION_HOSTS.get(self.region, "https://www.myshn.net")

    @property
    def api_gateway_url(self) -> str:
        return "https://api-gateway.skyhigh.cloud"


class SkyhighCloudClient:
    """Skyhigh Security SSE Cloud REST API client."""

    def __init__(self, config: SkyhighCloudConfig) -> None:
        self.config = config
        self.session = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})

    # ------------------------------------------------------------------
    # Auth
    # ------------------------------------------------------------------

    def authenticate(self) -> str:
        """Obtain IAM Bearer token and attach it to the session."""
        url = f"{self.config.base_url}/shnapi/rest/external/api/v1/token"
        creds = base64.b64encode(
            f"{self.config.username}:{self.config.password}".encode()
        ).decode()
        headers = {
            "Authorization": f"Basic {creds}",
            "BPS-TENANT-ID": self.config.tenant_id,
        }
        resp = self.session.post(
            url,
            headers=headers,
            params={"grant_type": "password", "token_type": "iam"},
        )
        resp.raise_for_status()
        token: str = resp.json().get("access_token") or resp.json().get("token", "")
        self.session.headers.update({"Authorization": f"Bearer {token}"})
        logger.info("Skyhigh Cloud: authenticated successfully (tenant=%s)", self.config.tenant_id)
        return token

    # ------------------------------------------------------------------
    # SWG (Secure Web Gateway)
    # ------------------------------------------------------------------

    def get_policy_backup(self) -> bytes:
        """Download full SWG policy as a binary archive (ZIP/BIN)."""
        resp = self.session.get(
            f"{self.config.api_gateway_url}/backup-web",
            headers={"Accept": "application/octet-stream"},
        )
        resp.raise_for_status()
        logger.info("SWG policy backup downloaded: %d bytes", len(resp.content))
        return resp.content

    def get_swg_feature_config(self) -> Dict[str, Any]:
        """Retrieve SWG feature-level configuration."""
        resp = self.session.get(f"{self.config.api_gateway_url}/web/feature-config")
        resp.raise_for_status()
        return resp.json()

    def get_swg_lists(self) -> List[Dict]:
        """Retrieve SWG URL/IP/FQDN lists used in policies."""
        resp = self.session.get(f"{self.config.api_gateway_url}/web/lists")
        resp.raise_for_status()
        return resp.json().get("lists", [])

    def get_swg_locations(self) -> List[Dict]:
        """Retrieve SWG locations (sites, branch offices, cloud connectors)."""
        resp = self.session.get(f"{self.config.api_gateway_url}/web/locations")
        resp.raise_for_status()
        return resp.json().get("locations", [])

    def get_scp_config(self) -> Dict[str, Any]:
        """Retrieve Skyhigh Client Proxy (SCP) policy configuration."""
        resp = self.session.get(f"{self.config.api_gateway_url}/scp/config")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # DLP (Data Loss Prevention)
    # ------------------------------------------------------------------

    def get_dlp_incidents(self, page: int = 1, page_size: int = 100) -> Dict[str, Any]:
        """Retrieve DLP violation incidents (paged)."""
        resp = self.session.get(
            f"{self.config.base_url}/shnapi/rest/external/api/v1/incidents",
            params={"page": page, "pageSize": page_size, "type": "dlp"},
        )
        resp.raise_for_status()
        return resp.json()

    def get_dlp_classifications(self) -> List[Dict]:
        """Retrieve DLP data classification definitions."""
        resp = self.session.get(
            f"{self.config.base_url}/shnapi/rest/external/api/v1/dlp/classifications"
        )
        resp.raise_for_status()
        return resp.json().get("classifications", [])

    # ------------------------------------------------------------------
    # CASB (Cloud Access Security Broker)
    # ------------------------------------------------------------------

    def get_casb_service_groups(self) -> List[Dict]:
        """Retrieve CASB service groups (shadow IT app categories)."""
        resp = self.session.get(
            f"{self.config.base_url}/shnapi/rest/external/api/v1/serviceGroups"
        )
        resp.raise_for_status()
        return resp.json().get("serviceGroups", [])

    def get_casb_incidents(self, page: int = 1, page_size: int = 100) -> Dict[str, Any]:
        """Retrieve CASB policy violation incidents (paged)."""
        resp = self.session.get(
            f"{self.config.base_url}/shnapi/rest/external/api/v1/incidents",
            params={"page": page, "pageSize": page_size, "type": "casb"},
        )
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # CNAPP / CWPP / CSPM
    # ------------------------------------------------------------------

    def get_cwpp_policies(self) -> List[Dict]:
        """Retrieve CNAPP Cloud Workload Protection policies."""
        resp = self.session.get(
            f"{self.config.base_url}/shnapi/rest/external/api/v1/cwpp/policies"
        )
        resp.raise_for_status()
        return resp.json().get("policies", [])

    def get_cspm_policies(self) -> List[Dict]:
        """Retrieve Cloud Security Posture Management policies."""
        resp = self.session.get(
            f"{self.config.base_url}/shnapi/rest/external/api/v1/cspm/policies"
        )
        resp.raise_for_status()
        return resp.json().get("policies", [])

    # ------------------------------------------------------------------
    # Aggregate
    # ------------------------------------------------------------------

    def get_all_policies(self) -> Dict[str, Any]:
        """Attempt to retrieve all policy categories, logging failures individually."""
        result: Dict[str, Any] = {}
        _fetch = {
            "swg_feature_config":   self.get_swg_feature_config,
            "swg_lists":            self.get_swg_lists,
            "swg_locations":        self.get_swg_locations,
            "scp_config":           self.get_scp_config,
            "dlp_incidents":        self.get_dlp_incidents,
            "dlp_classifications":  self.get_dlp_classifications,
            "casb_service_groups":  self.get_casb_service_groups,
            "casb_incidents":       self.get_casb_incidents,
            "cwpp_policies":        self.get_cwpp_policies,
            "cspm_policies":        self.get_cspm_policies,
        }
        for key, fn in _fetch.items():
            try:
                result[key] = fn()
                logger.info("Fetched %-22s  OK", key)
            except requests.HTTPError as exc:
                logger.warning("Fetched %-22s  HTTP %s", key, exc.response.status_code)
                result[key] = [] if key not in ("swg_feature_config", "scp_config", "dlp_incidents") else {}
            except Exception as exc:
                logger.warning("Fetched %-22s  ERROR: %s", key, exc)
                result[key] = [] if key not in ("swg_feature_config", "scp_config", "dlp_incidents") else {}
        return result


# ---------------------------------------------------------------------------
# On-Premises SWG
# ---------------------------------------------------------------------------

@dataclass
class SkyhighOnPremConfig:
    host: str           # hostname or IP of the MWG appliance
    username: str
    password: str
    port: int = 4711
    use_ssl: bool = False

    @property
    def base_url(self) -> str:
        scheme = "https" if self.use_ssl else "http"
        return f"{scheme}://{self.host}:{self.port}/Konfigurator/REST"


class SkyhighOnPremClient:
    """Skyhigh SWG On-Premises REST Interface client (XML-based)."""

    def __init__(self, config: SkyhighOnPremConfig) -> None:
        self.config = config
        self.session = requests.Session()
        if config.use_ssl:
            self.session.verify = False  # set to CA bundle path in production

    def __enter__(self):
        self.authenticate()
        return self

    def __exit__(self, *_):
        self.logout()

    def authenticate(self) -> None:
        resp = self.session.post(
            f"{self.config.base_url}/login",
            params={"userName": self.config.username, "pass": self.config.password},
        )
        resp.raise_for_status()
        logger.info("Skyhigh On-Prem: authenticated (host=%s)", self.config.host)

    def logout(self) -> None:
        self.session.post(f"{self.config.base_url}/logout")
        logger.info("Skyhigh On-Prem: logged out")

    def get_rule_sets(
        self, top_level_only: bool = True, page: int = 1, page_size: int = 50
    ) -> Dict[str, Any]:
        resp = self.session.get(
            f"{self.config.base_url}/rulesets",
            params={
                "topLevelOnly": str(top_level_only).lower(),
                "page": page,
                "pageSize": page_size,
            },
        )
        resp.raise_for_status()
        return resp.json()

    def get_all_rule_sets(self) -> List[Dict]:
        """Auto-paginate through all top-level rule sets."""
        all_sets, page = [], 1
        while True:
            data = self.get_rule_sets(page=page)
            batch = data.get("ruleSets", [])
            all_sets.extend(batch)
            if len(batch) < 50:
                break
            page += 1
        logger.info("On-Prem: retrieved %d rule sets", len(all_sets))
        return all_sets

    def get_rule_set(self, rule_set_id: str) -> Dict[str, Any]:
        resp = self.session.get(f"{self.config.base_url}/rulesets/{rule_set_id}")
        resp.raise_for_status()
        return resp.json()

    def get_setting(self, cfg_id: str) -> str:
        """Return raw XML for a named configuration object."""
        resp = self.session.get(f"{self.config.base_url}/setting/{cfg_id}")
        resp.raise_for_status()
        return resp.text

    def commit(self, comment: str = "API commit") -> None:
        resp = self.session.post(f"{self.config.base_url}/commit", data=comment)
        resp.raise_for_status()
        logger.info("On-Prem: changes committed")
