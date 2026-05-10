"""
Zscaler ZIA and ZPA API clients.

ZIA Auth (legacy):   POST /api/v1/authenticatedSession  — JSESSIONID cookie
ZPA Auth (legacy):   POST https://config.private.zscaler.com/signin  — Bearer token

The API key obfuscation algorithm required by ZIA:
  Take the last 6 digits of the epoch-millisecond timestamp, reverse them,
  then use each digit (and digit+2) as an index into the raw API key string.
"""

import logging
import time
from dataclasses import dataclass
from typing import Any, Dict, List, Optional

import requests

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# ZIA
# ---------------------------------------------------------------------------

@dataclass
class ZscalerZIAConfig:
    cloud: str      # e.g. "zscaler.net", "zscalerone.net", "zscloud.net"
    username: str
    password: str
    api_key: str    # raw (non-obfuscated) key from ZIA admin portal

    @property
    def base_url(self) -> str:
        return f"https://zsapi.{self.cloud}/api/v1"


class ZscalerZIAClient:
    """Zscaler Internet Access (ZIA) REST API client."""

    # Cloud App Control rule type identifiers
    APP_RULE_TYPES: List[str] = [
        "STREAMING_MEDIA",
        "BUSINESS_PRODUCTIVITY",
        "SOCIAL_NETWORKING",
        "WEBMAIL",
        "GENERAL_BROWSING",
        "ADVANCED_SECURITY",
        "ENTERPRISE_COLLABORATION",
        "SALES_AND_MARKETING",
        "SYSTEM_AND_DEVELOPMENT",
        "CONSUMER",
    ]

    def __init__(self, config: ZscalerZIAConfig) -> None:
        self.config = config
        self.session = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})

    # ------------------------------------------------------------------
    # Auth helpers
    # ------------------------------------------------------------------

    @staticmethod
    def _obfuscate_api_key(raw_key: str, timestamp_ms: int) -> str:
        """Derive the obfuscated API key Zscaler requires for session auth."""
        ts = str(timestamp_ms)[-6:]
        ts_rev = ts[::-1]
        obf = ""
        for c in ts:
            obf += raw_key[int(c)]
        for c in ts_rev:
            obf += raw_key[int(c) + 2]
        return obf

    def authenticate(self) -> None:
        ts = int(time.time() * 1000)
        payload = {
            "apiKey":   self._obfuscate_api_key(self.config.api_key, ts),
            "username": self.config.username,
            "password": self.config.password,
            "timestamp": ts,
        }
        resp = self.session.post(f"{self.config.base_url}/authenticatedSession", json=payload)
        resp.raise_for_status()
        logger.info("ZIA: authenticated (cloud=%s)", self.config.cloud)

    def logout(self) -> None:
        self.session.delete(f"{self.config.base_url}/authenticatedSession")
        logger.info("ZIA: logged out")

    def activate_changes(self) -> Dict[str, Any]:
        """Push pending config changes to enforcement."""
        resp = self.session.post(f"{self.config.base_url}/status/activate")
        resp.raise_for_status()
        logger.info("ZIA: changes activated")
        return resp.json()

    def __enter__(self):
        self.authenticate()
        return self

    def __exit__(self, *_):
        self.logout()

    # ------------------------------------------------------------------
    # URL Filtering
    # ------------------------------------------------------------------

    def get_url_filtering_rules(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/urlFilteringRules")
        resp.raise_for_status()
        return resp.json()

    def create_url_filtering_rule(self, rule: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/urlFilteringRules", json=rule)
        resp.raise_for_status()
        return resp.json()

    def update_url_filtering_rule(self, rule_id: int, rule: Dict) -> Dict:
        resp = self.session.put(f"{self.config.base_url}/urlFilteringRules/{rule_id}", json=rule)
        resp.raise_for_status()
        return resp.json()

    def delete_url_filtering_rule(self, rule_id: int) -> None:
        resp = self.session.delete(f"{self.config.base_url}/urlFilteringRules/{rule_id}")
        resp.raise_for_status()

    def get_url_categories(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/urlCategories")
        resp.raise_for_status()
        return resp.json()

    def create_url_category(self, category: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/urlCategories", json=category)
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # DLP
    # ------------------------------------------------------------------

    def get_dlp_web_rules(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/webDlpRules")
        resp.raise_for_status()
        return resp.json()

    def create_dlp_web_rule(self, rule: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/webDlpRules", json=rule)
        resp.raise_for_status()
        return resp.json()

    def update_dlp_web_rule(self, rule_id: int, rule: Dict) -> Dict:
        resp = self.session.put(f"{self.config.base_url}/webDlpRules/{rule_id}", json=rule)
        resp.raise_for_status()
        return resp.json()

    def get_dlp_dictionaries(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/dlpDictionaries")
        resp.raise_for_status()
        return resp.json()

    def create_dlp_dictionary(self, dictionary: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/dlpDictionaries", json=dictionary)
        resp.raise_for_status()
        return resp.json()

    def get_dlp_engines(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/dlpEngines")
        resp.raise_for_status()
        return resp.json()

    def get_dlp_notification_templates(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/dlpNotificationTemplates")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Cloud App Control (inline CASB)
    # ------------------------------------------------------------------

    def get_cloud_app_rules(self, rule_type: str) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/webApplicationRules/{rule_type}")
        resp.raise_for_status()
        return resp.json()

    def create_cloud_app_rule(self, rule_type: str, rule: Dict) -> Dict:
        resp = self.session.post(
            f"{self.config.base_url}/webApplicationRules/{rule_type}", json=rule
        )
        resp.raise_for_status()
        return resp.json()

    def get_all_cloud_app_rules(self) -> Dict[str, List[Dict]]:
        """Fetch rules for every cloud app control category."""
        results: Dict[str, List[Dict]] = {}
        for rt in self.APP_RULE_TYPES:
            try:
                results[rt] = self.get_cloud_app_rules(rt)
            except Exception as exc:
                logger.warning("ZIA cloud app rules [%s]: %s", rt, exc)
                results[rt] = []
        return results

    # ------------------------------------------------------------------
    # SSL Inspection
    # ------------------------------------------------------------------

    def get_ssl_settings(self) -> Dict[str, Any]:
        resp = self.session.get(f"{self.config.base_url}/sslSettings")
        resp.raise_for_status()
        return resp.json()

    def get_ssl_exempt_urls(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/sslExemptedUrls")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Cloud Firewall
    # ------------------------------------------------------------------

    def get_firewall_rules(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/firewallFilteringRules")
        resp.raise_for_status()
        return resp.json()

    def create_firewall_rule(self, rule: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/firewallFilteringRules", json=rule)
        resp.raise_for_status()
        return resp.json()

    def get_ip_destination_groups(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/ipDestinationGroups")
        resp.raise_for_status()
        return resp.json()

    def get_network_services(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/networkServices")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Threat Protection
    # ------------------------------------------------------------------

    def get_malware_protection(self) -> Dict[str, Any]:
        resp = self.session.get(f"{self.config.base_url}/malwareProtection")
        resp.raise_for_status()
        return resp.json()

    def get_advanced_threat_settings(self) -> Dict[str, Any]:
        resp = self.session.get(f"{self.config.base_url}/advancedThreatSettings")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Traffic Forwarding / Locations
    # ------------------------------------------------------------------

    def get_locations(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/locations")
        resp.raise_for_status()
        return resp.json()

    def create_location(self, location: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/locations", json=location)
        resp.raise_for_status()
        return resp.json()

    def get_vpn_credentials(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/vpnCredentials")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Users / Groups / Departments
    # ------------------------------------------------------------------

    def get_users(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/users")
        resp.raise_for_status()
        return resp.json()

    def get_groups(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/groups")
        resp.raise_for_status()
        return resp.json()

    def get_departments(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/departments")
        resp.raise_for_status()
        return resp.json()

    def get_rule_labels(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/ruleLabels")
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Aggregate
    # ------------------------------------------------------------------

    def get_all_policies(self) -> Dict[str, Any]:
        """Retrieve all major ZIA policy objects in one call."""
        result: Dict[str, Any] = {}
        _fetch = {
            "url_filtering_rules":       self.get_url_filtering_rules,
            "url_categories":            self.get_url_categories,
            "dlp_web_rules":             self.get_dlp_web_rules,
            "dlp_dictionaries":          self.get_dlp_dictionaries,
            "dlp_engines":               self.get_dlp_engines,
            "dlp_notification_templates": self.get_dlp_notification_templates,
            "cloud_app_rules":           self.get_all_cloud_app_rules,
            "ssl_settings":              self.get_ssl_settings,
            "ssl_exempt_urls":           self.get_ssl_exempt_urls,
            "firewall_rules":            self.get_firewall_rules,
            "ip_destination_groups":     self.get_ip_destination_groups,
            "network_services":          self.get_network_services,
            "malware_protection":        self.get_malware_protection,
            "advanced_threat_settings":  self.get_advanced_threat_settings,
            "locations":                 self.get_locations,
        }
        for key, fn in _fetch.items():
            try:
                result[key] = fn()
                logger.info("Fetched ZIA %-30s  OK", key)
            except requests.HTTPError as exc:
                logger.warning("Fetched ZIA %-30s  HTTP %s", key, exc.response.status_code)
                result[key] = {} if "settings" in key or "protection" in key else []
            except Exception as exc:
                logger.warning("Fetched ZIA %-30s  ERROR: %s", key, exc)
                result[key] = {} if "settings" in key or "protection" in key else []
        return result


# ---------------------------------------------------------------------------
# ZPA
# ---------------------------------------------------------------------------

@dataclass
class ZscalerZPAConfig:
    client_id: str
    client_secret: str
    customer_id: str
    cloud: str = "config.private.zscaler.com"  # or config.zpabeta.net / config.zpatwo.net

    @property
    def base_url(self) -> str:
        return f"https://{self.cloud}/mgmtconfig/v1/admin/customers/{self.customer_id}"

    @property
    def token_url(self) -> str:
        return f"https://{self.cloud}/signin"


class ZscalerZPAClient:
    """Zscaler Private Access (ZPA) management API client."""

    def __init__(self, config: ZscalerZPAConfig) -> None:
        self.config = config
        self.session = requests.Session()
        self.session.headers.update({"Content-Type": "application/json"})

    def authenticate(self) -> None:
        resp = requests.post(
            self.config.token_url,
            json={"apiKey": self.config.client_id, "secret": self.config.client_secret},
        )
        resp.raise_for_status()
        token: str = resp.json().get("token") or resp.json().get("access_token", "")
        self.session.headers.update({"Authorization": f"Bearer {token}"})
        logger.info("ZPA: authenticated (customer=%s)", self.config.customer_id)

    def __enter__(self):
        self.authenticate()
        return self

    def __exit__(self, *_):
        pass  # ZPA has no explicit logout endpoint

    # ------------------------------------------------------------------
    # Application Segments
    # ------------------------------------------------------------------

    def get_applications(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/application")
        resp.raise_for_status()
        return resp.json().get("list", [])

    def create_application(self, app: Dict) -> Dict:
        resp = self.session.post(f"{self.config.base_url}/application", json=app)
        resp.raise_for_status()
        return resp.json()

    # ------------------------------------------------------------------
    # Segment / Server Groups
    # ------------------------------------------------------------------

    def get_segment_groups(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/segmentGroup")
        resp.raise_for_status()
        return resp.json().get("list", [])

    def get_server_groups(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/serverGroup")
        resp.raise_for_status()
        return resp.json().get("list", [])

    # ------------------------------------------------------------------
    # Access Policies
    # ------------------------------------------------------------------

    POLICY_TYPES = [
        "ACCESS_POLICY",
        "TIMEOUT_POLICY",
        "FORWARDING_POLICY",
        "INSPECTION_POLICY",
        "ISOLATION_POLICY",
    ]

    def get_policy_rules(self, policy_type: str) -> List[Dict]:
        """Retrieve rules for a specific ZPA policy set type."""
        # First resolve the policy set id for this type
        resp = self.session.get(
            f"{self.config.base_url}/policySet/policyType/{policy_type}"
        )
        resp.raise_for_status()
        policy_set_id = resp.json().get("id", "")
        rules_resp = self.session.get(
            f"{self.config.base_url}/policySet/{policy_set_id}/rule"
        )
        rules_resp.raise_for_status()
        return rules_resp.json().get("list", [])

    def get_all_access_policies(self) -> Dict[str, List[Dict]]:
        results: Dict[str, List[Dict]] = {}
        for pt in self.POLICY_TYPES:
            try:
                results[pt] = self.get_policy_rules(pt)
            except Exception as exc:
                logger.warning("ZPA policy [%s]: %s", pt, exc)
                results[pt] = []
        return results

    def create_policy_rule(self, policy_type: str, rule: Dict) -> Dict:
        resp = self.session.get(
            f"{self.config.base_url}/policySet/policyType/{policy_type}"
        )
        resp.raise_for_status()
        policy_set_id = resp.json()["id"]
        create_resp = self.session.post(
            f"{self.config.base_url}/policySet/{policy_set_id}/rule", json=rule
        )
        create_resp.raise_for_status()
        return create_resp.json()

    # ------------------------------------------------------------------
    # App Connectors
    # ------------------------------------------------------------------

    def get_app_connectors(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/connector")
        resp.raise_for_status()
        return resp.json().get("list", [])

    def get_app_connector_groups(self) -> List[Dict]:
        resp = self.session.get(f"{self.config.base_url}/appConnectorGroup")
        resp.raise_for_status()
        return resp.json().get("list", [])

    # ------------------------------------------------------------------
    # Aggregate
    # ------------------------------------------------------------------

    def get_all_policies(self) -> Dict[str, Any]:
        result: Dict[str, Any] = {}
        _fetch = {
            "applications":      self.get_applications,
            "segment_groups":    self.get_segment_groups,
            "server_groups":     self.get_server_groups,
            "access_policies":   self.get_all_access_policies,
            "app_connectors":    self.get_app_connectors,
            "connector_groups":  self.get_app_connector_groups,
        }
        for key, fn in _fetch.items():
            try:
                result[key] = fn()
                logger.info("Fetched ZPA %-25s  OK", key)
            except Exception as exc:
                logger.warning("Fetched ZPA %-25s  ERROR: %s", key, exc)
                result[key] = {}
        return result
