"""Tests for HTTP error handling in PhpApiClient."""

from unittest.mock import MagicMock, patch

import httpx
import pytest
from util.http import PhpApiClient


def _make_client():
    return PhpApiClient("http://localhost:8000", "tok", "acct-1")


def test_get_raises_on_4xx():
    client = _make_client()
    mock_response = httpx.Response(
        401,
        json={"error": "Unauthorized"},
        request=httpx.Request("GET", "http://localhost:8000/test"),
    )
    with patch.object(client._client, "get", return_value=mock_response):
        with pytest.raises(httpx.HTTPStatusError):
            client.get("/test")
    client.close()


def test_get_raises_on_5xx():
    client = _make_client()
    mock_response = httpx.Response(
        503,
        json={"error": "Service unavailable"},
        request=httpx.Request("GET", "http://localhost:8000/test"),
    )
    with patch.object(client._client, "get", return_value=mock_response):
        with pytest.raises(httpx.HTTPStatusError):
            client.get("/test")
    client.close()


def test_post_raises_on_4xx():
    client = _make_client()
    mock_response = httpx.Response(
        400,
        json={"error": "Bad request"},
        request=httpx.Request("POST", "http://localhost:8000/test"),
    )
    with patch.object(client._client, "post", return_value=mock_response):
        with pytest.raises(httpx.HTTPStatusError):
            client.post("/test", json_data={"key": "val"})
    client.close()


def test_post_raises_on_5xx():
    client = _make_client()
    mock_response = httpx.Response(
        500,
        json={"error": "Internal error"},
        request=httpx.Request("POST", "http://localhost:8000/test"),
    )
    with patch.object(client._client, "post", return_value=mock_response):
        with pytest.raises(httpx.HTTPStatusError):
            client.post("/test", json_data={})
    client.close()


def test_get_raises_on_timeout():
    client = _make_client()
    with patch.object(
        client._client, "get", side_effect=httpx.ReadTimeout("read timed out")
    ):
        with pytest.raises(httpx.ReadTimeout):
            client.get("/test")
    client.close()


def test_post_raises_on_timeout():
    client = _make_client()
    with patch.object(
        client._client, "post", side_effect=httpx.ReadTimeout("read timed out")
    ):
        with pytest.raises(httpx.ReadTimeout):
            client.post("/test", json_data={})
    client.close()


def test_get_raises_on_connect_error():
    client = _make_client()
    with patch.object(
        client._client, "get", side_effect=httpx.ConnectError("connection refused")
    ):
        with pytest.raises(httpx.ConnectError):
            client.get("/test")
    client.close()


def test_tool_execute_catches_http_errors():
    """Verify that tool functions propagate HTTP errors to the caller."""
    from tools.gmail_list import execute as gmail_list_exec

    api = MagicMock()
    api.get.side_effect = httpx.HTTPStatusError(
        "401 Unauthorized",
        request=httpx.Request("GET", "http://localhost:8000/api/internal/gmail/list"),
        response=httpx.Response(401),
    )

    with pytest.raises(httpx.HTTPStatusError):
        gmail_list_exec(api, {"query": "is:unread"})
