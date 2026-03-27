"""Tests for util.http.PhpApiClient."""

from util.http import PhpApiClient


def test_client_sets_bearer_token():
    client = PhpApiClient("http://localhost:8000", "my-token", "acct-1")
    headers = client._client.headers
    assert headers["authorization"] == "Bearer my-token"
    assert headers["x-account-id"] == "acct-1"
    assert headers["content-type"] == "application/json"
    client.close()


def test_client_strips_trailing_slash_from_base_url():
    client = PhpApiClient("http://localhost:8000/", "tok", "acct")
    assert str(client._client.base_url) == "http://localhost:8000"
    client.close()


def test_client_sets_timeout():
    client = PhpApiClient("http://localhost:8000", "tok", "acct")
    assert client._client.timeout.read == 30.0
    client.close()
