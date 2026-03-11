#!/bin/sh
# If Claude config was mounted read-only to a staging path, copy it
# to the writable location. When mounted directly to /root/.claude (rw),
# this is a no-op.
if [ -d /root/.claude-config ] && [ ! -d /root/.claude ]; then
    cp -a /root/.claude-config/. /root/.claude/
fi

exec "$@"
