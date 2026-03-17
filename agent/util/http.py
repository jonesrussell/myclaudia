"""Thin HTTP client for calling Claudriel's internal PHP API."""

import httpx


class PhpApiClient:
    """Calls PHP internal API endpoints with HMAC auth."""

    def __init__(self, api_base: str, api_token: str, account_id: str) -> None:
        self._client = httpx.Client(
            base_url=api_base.rstrip("/"),
            headers={
                "Authorization": f"Bearer {api_token}",
                "X-Account-Id": account_id,
                "Content-Type": "application/json",
            },
            timeout=30.0,
        )

    def get(self, path: str, params: dict | None = None) -> dict:
        response = self._client.get(path, params=params)
        response.raise_for_status()
        return response.json()

    def post(self, path: str, json_data: dict | None = None) -> dict:
        response = self._client.post(path, json=json_data)
        response.raise_for_status()
        return response.json()

    def close(self) -> None:
        self._client.close()
