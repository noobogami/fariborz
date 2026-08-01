# Code sandbox: a general-purpose build/test environment the agent operates in.
# Node (for the service + JS apps) + Python + git + build tools + PHP-CLI cover
# most "build me an app" tasks. Everything the agent runs stays in this container.
FROM node:20-bookworm

# Common toolchains for building/testing apps the agent might create.
# Common toolchains pre-installed so the agent doesn't spend a turn installing
# them. It is root with network, so it can also install ANYTHING else on demand
# (apt-get / pip / npm / cargo / go) — all into this isolated container.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git python3 python3-pip python3-venv build-essential ca-certificates \
        php-cli sqlite3 curl jq iproute2 procps \
        rustc cargo golang-go \
    && rm -rf /var/lib/apt/lists/*

# Persisted install/cache dirs (mounted as volumes) so on-demand installs
# (cargo/go/pip packages) survive container recreation and are reused.
ENV CARGO_HOME=/root/.cargo \
    GOPATH=/root/go \
    PIP_CACHE_DIR=/root/.cache/pip \
    PATH=/root/.cargo/bin:/root/go/bin:/root/.local/bin:/usr/local/bin:/usr/bin:/bin

# Playwright + Chromium preinstalled (with system deps) so the agent can build
# and TEST browser-automation bots immediately. Downloading the ~190 MB browser
# at runtime reliably exceeds the per-command timeout — that was a real failure
# mode. Installed globally + a shared browser cache so scripts resolve it via
# NODE_PATH without a local `npm install playwright` (and reuse the cached
# browser if they do install it).
ENV PLAYWRIGHT_BROWSERS_PATH=/ms-playwright \
    NODE_PATH=/usr/local/lib/node_modules
RUN npm install -g playwright \
    && npx playwright install --with-deps chromium \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY package.json ./
RUN npm install --omit=dev
COPY server.js ./

# The agent's files/builds live here (one subdir per research job).
RUN mkdir -p /workspace
ENV WORKSPACE_ROOT=/workspace

EXPOSE 3000
CMD ["node", "server.js"]
