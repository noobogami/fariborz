# Code sandbox: a general-purpose build/test environment the agent operates in.
# Node (for the service + JS apps) + Python + git + build tools + PHP-CLI cover
# most "build me an app" tasks. Everything the agent runs stays in this container.
FROM node:20-bookworm

# Keep apt's downloads in a BuildKit cache mount instead of throwing them away.
# This layer pulls ~174 MB from the Debian CDN; without a cache a single dropped
# connection means the NEXT attempt re-downloads all of it (that is what turned a
# flaky fetch of golang-1.19-go into a 7-minute build that failed with exit 100).
# With the cache, a retry resumes from what already landed.
#   - Keep-Downloaded-Packages: apt normally deletes the .debs after installing.
#   - docker-clean: the base image installs hooks that wipe the cache; drop them.
# Retries/timeouts make a stalled mirror recoverable inside one attempt too.
RUN rm -f /etc/apt/apt.conf.d/docker-clean \
    && printf '%s\n' \
        'Binary::apt::APT::Keep-Downloaded-Packages "true";' \
        'Acquire::Retries "20";' \
        'Acquire::http::Timeout "120";' \
        'Acquire::https::Timeout "120";' \
        'Acquire::http::Pipeline-Depth "0";' \
        > /etc/apt/apt.conf.d/99fariborz-resilient

# Common toolchains pre-installed so the agent doesn't spend a turn installing
# them. It is root with network, so it can also install ANYTHING else on demand
# (apt-get / pip / npm / cargo / go) — all into this isolated container.
# Split from the heavy compilers below: these are small and rarely the thing that
# fails, so a flaky Rust/Go fetch doesn't invalidate them and force a re-download.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        git python3 python3-pip python3-venv build-essential ca-certificates \
        php-cli sqlite3 curl jq iproute2 procps

# Rust + Go: ~700 MB of the image and by far the largest downloads, so they get
# their own layer — this is the one that realistically fails on a slow link, and
# on its own it can be retried without redoing anything above.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    apt-get update && apt-get install -y --no-install-recommends \
        rustc cargo golang-go

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
# `--with-deps` shells out to apt for Chromium's system libraries, so this is a
# second large download that benefits from the same cache + retry treatment.
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    --mount=type=cache,target=/var/lib/apt/lists,sharing=locked \
    --mount=type=cache,target=/root/.npm \
    npm install -g playwright \
    && npx playwright install --with-deps chromium

WORKDIR /app
COPY package.json ./
RUN --mount=type=cache,target=/root/.npm npm install --omit=dev
COPY server.js ./

# The agent's files/builds live here (one subdir per research job).
RUN mkdir -p /workspace
ENV WORKSPACE_ROOT=/workspace

EXPOSE 3000
CMD ["node", "server.js"]
