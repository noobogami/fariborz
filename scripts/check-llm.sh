#!/usr/bin/env bash
#
# check-llm.sh — verify the LLM wiring end to end.
#
# Confirms, in order:
#   1. the host can reach Ollama (native, GPU-accelerated) and lists its models
#   2. Ollama is bound so CONTAINERS can reach it (0.0.0.0, not just 127.0.0.1)
#   3. the LiteLLM gateway is up (/health) and lists its configured models
#   4. the gateway container can actually reach the host's Ollama
#      (the cross-boundary hop that host.docker.internal must satisfy)
#
# Usage:
#   ./scripts/check-llm.sh
#
# Override endpoints/keys via env (falls back to the project defaults):
#   OLLAMA_HOST_URL   default http://localhost:11434
#   LITELLM_URL       default http://localhost:4000
#   LLM_GATEWAY_KEY   sent as a Bearer token to the gateway when set
#
# Exits non-zero if any REQUIRED check fails (container checks are skipped, not
# failed, when Docker/Compose isn't available).

set -uo pipefail

OLLAMA_HOST_URL="${OLLAMA_HOST_URL:-http://localhost:11434}"
LITELLM_URL="${LITELLM_URL:-http://localhost:4000}"
LLM_GATEWAY_KEY="${LLM_GATEWAY_KEY:-sk-fariborz-local}"   # project dev default
GATEWAY_OLLAMA_URL="http://host.docker.internal:11434"   # what containers use

# ── pretty output ────────────────────────────────────────────────────────────
if [ -t 1 ]; then
  G=$'\033[32m'; R=$'\033[31m'; Y=$'\033[33m'; DIM=$'\033[2m'; B=$'\033[1m'; Z=$'\033[0m'
else
  G=""; R=""; Y=""; DIM=""; B=""; Z=""
fi
fails=0
pass() { printf "  ${G}✔${Z} %s\n" "$1"; }
warn() { printf "  ${Y}○${Z} %s\n" "$1"; }
fail() { printf "  ${R}✘${Z} %s\n" "$1"; fails=$((fails + 1)); }
hint() { printf "    ${DIM}↳ %s${Z}\n" "$1"; }
head() { printf "\n${B}%s${Z}\n" "$1"; }

# Extract "…":"value" occurrences for a given key from a JSON blob (no jq needed).
json_values() { grep -o "\"$1\":\"[^\"]*\"" | sed -E "s/\"$1\":\"([^\"]*)\"/\1/"; }

curl_get() { # curl_get <url> [bearer]
  local url="$1" key="${2:-}"
  if [ -n "$key" ]; then
    curl -fsS --max-time 6 -H "Authorization: Bearer $key" "$url" 2>/dev/null
  else
    curl -fsS --max-time 6 "$url" 2>/dev/null
  fi
}

printf "${B}LLM connectivity check${Z}  ${DIM}(ollama: %s · gateway: %s)${Z}\n" "$OLLAMA_HOST_URL" "$LITELLM_URL"

# ── 1. host → Ollama ─────────────────────────────────────────────────────────
head "1. Host → Ollama"
if tags="$(curl_get "$OLLAMA_HOST_URL/api/tags")"; then
  models="$(printf '%s' "$tags" | json_values name | paste -sd , -)"
  count="$(printf '%s' "$tags" | json_values name | grep -c . || true)"
  pass "reachable — ${count:-0} model(s): ${models:-<none pulled>}"
  [ "${count:-0}" -eq 0 ] && hint "pull one on the host: ollama pull qwen3:8b"
else
  fail "cannot reach Ollama at $OLLAMA_HOST_URL"
  hint "is it running? on macOS open Ollama.app, or run: ollama serve"
fi

# ── 2. Ollama bound for container access ─────────────────────────────────────
head "2. Ollama exposed to containers"
# 127.0.0.1-only binding is invisible to Docker; 0.0.0.0 (or a LAN IP) is needed.
oh="${OLLAMA_HOST:-}"
if printf '%s' "$oh" | grep -q '0\.0\.0\.0'; then
  pass "OLLAMA_HOST=$oh (containers can reach it)"
elif [ -n "$oh" ]; then
  warn "OLLAMA_HOST=$oh — make sure this is reachable from containers"
else
  warn "OLLAMA_HOST not set in this shell (default binds 127.0.0.1 — containers can't reach it)"
  hint 'macOS: launchctl setenv OLLAMA_HOST "0.0.0.0:11434" then restart Ollama.app'
  hint 'the container hop in step 4 is the real test — trust that over this note'
fi

# ── docker availability ──────────────────────────────────────────────────────
DC=""
if command -v docker >/dev/null 2>&1; then
  if docker compose version >/dev/null 2>&1; then DC="docker compose";
  elif command -v docker-compose >/dev/null 2>&1; then DC="docker-compose"; fi
fi

# ── 3. host → LiteLLM ────────────────────────────────────────────────────────
head "3. Host → LiteLLM gateway"
if health="$(curl_get "$LITELLM_URL/health/liveliness")" || health="$(curl_get "$LITELLM_URL/health")"; then
  pass "gateway is up (/health)"
  if models="$(curl_get "$LITELLM_URL/v1/models" "$LLM_GATEWAY_KEY")"; then
    names="$(printf '%s' "$models" | json_values id | paste -sd , -)"
    count="$(printf '%s' "$models" | json_values id | grep -c . || true)"
    pass "serves ${count:-0} model(s): ${names:-<none>}"
  else
    fail "/v1/models did not respond as expected"
    hint "if the gateway needs a key, run with LLM_GATEWAY_KEY=… ./scripts/check-llm.sh"
  fi
else
  fail "cannot reach LiteLLM at $LITELLM_URL"
  if [ -n "$DC" ]; then hint "start it: $DC up -d litellm  ·  logs: $DC logs litellm";
  else hint "start the gateway (docker compose up -d litellm)"; fi
fi

# ── 4. gateway container → host Ollama ───────────────────────────────────────
head "4. Gateway container → host Ollama"
if [ -z "$DC" ]; then
  warn "Docker Compose not found — skipping the in-container check"
elif ! $DC ps --status running 2>/dev/null | grep -q litellm; then
  warn "litellm container isn't running — skipping (start: $DC up -d litellm)"
else
  # The LiteLLM image ships python, not curl — probe from inside with urllib.
  probe="import urllib.request as u,sys; u.urlopen('$GATEWAY_OLLAMA_URL/api/tags',timeout=6).read(); print('ok')"
  if $DC exec -T litellm python3 -c "$probe" >/dev/null 2>&1; then
    pass "litellm reaches Ollama at $GATEWAY_OLLAMA_URL"
  else
    fail "litellm CANNOT reach Ollama at $GATEWAY_OLLAMA_URL"
    hint "bind Ollama to 0.0.0.0 (step 2) so the container network can reach the host"
    hint "on Linux, host.docker.internal needs the extra_hosts mapping (already in compose)"
  fi
fi

# ── summary ──────────────────────────────────────────────────────────────────
if [ "$fails" -eq 0 ]; then
  printf "\n${G}${B}All required checks passed.${Z}\n"
else
  printf "\n${R}${B}%d check(s) failed.${Z} See the ↳ hints above.\n" "$fails"
fi
exit "$fails"
