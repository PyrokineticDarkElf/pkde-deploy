#!/usr/bin/env bash
#
# Sourced by the deploy workflow: retry <label> <command...> runs the command up to 3 times with a growing pause
# (10s, 20s), for the transport steps only (ssh, rsync, scp) where a dropped connection says nothing about the
# result. The label is printed instead of the command because the command carries the secret host name.
# The activation step must never go through this: a half-run activation needs a person.
# RETRY_PAUSE (seconds, default 10) exists so the test does not wait.

retry() {
  local label="$1" attempt=1 max=3
  shift
  until "$@"; do
    if [ "$attempt" -ge "$max" ]; then
      echo "::error::$label failed after $max attempts"
      return 1
    fi
    echo "::warning::$label failed (attempt $attempt of $max), retrying in $((attempt * ${RETRY_PAUSE:-10}))s"
    sleep $((attempt * ${RETRY_PAUSE:-10}))
    attempt=$((attempt + 1))
  done
}
