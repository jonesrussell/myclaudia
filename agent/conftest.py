"""Pytest configuration for agent tests.

Ensures tool modules are imported before eval modules to prevent
import order pollution. Also adds agent/ to sys.path for eval imports.
"""
import sys
from pathlib import Path

# Add agent/ to path for eval module imports
_agent_dir = str(Path(__file__).parent)
if _agent_dir not in sys.path:
    sys.path.insert(0, _agent_dir)

# Pre-import tool modules before pytest collection can pollute them
# via eval module imports. This ensures test_tools.py and test_http.py
# get the real modules, not stubs.
import tools.gmail_list  # noqa: E402, F401
import tools.gmail_read  # noqa: E402, F401
import tools.gmail_send  # noqa: E402, F401
import tools.calendar_list  # noqa: E402, F401
import tools.calendar_create  # noqa: E402, F401
import util.http  # noqa: E402, F401
