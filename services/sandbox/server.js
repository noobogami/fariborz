'use strict';

/**
 * Isolated code sandbox for the research agent.
 *
 * The agent NEVER runs commands on the host — it calls this service, which
 * executes everything inside THIS container's /workspace. Each research job
 * gets its own subdirectory (/workspace/<jobId>) so builds don't collide.
 *
 * Endpoints:
 *   GET  /health
 *   POST /exec   { job, cmd, cwd?, timeout? }  -> { exit_code, stdout, stderr, duration_ms, killed }
 *   POST /write  { job, path, content }        -> { ok, path, bytes }
 *   GET  /read   ?job=&path=                    -> { path, content }
 *   GET  /list   ?job=&path=                    -> { entries }
 *   GET  /logs   ?job=                          -> { logs }   (recent command history)
 */

const express = require('express');
const { exec, spawn } = require('child_process');
const fs = require('fs/promises');
const fss = require('fs');
const path = require('path');

const app = express();
app.use(express.json({ limit: '8mb' }));

const ROOT = process.env.WORKSPACE_ROOT || '/workspace';
const MAX_OUTPUT = 24000; // chars of stdout/stderr returned per command
const logs = [];          // ring buffer of recent commands

function workspace(job) {
  const base = path.resolve(ROOT, (job || 'default').replace(/[^a-zA-Z0-9._-]/g, '_'));
  if (!base.startsWith(ROOT)) throw new Error('invalid job');
  return base;
}

// Resolve a path INSIDE the job workspace, refusing to escape it. Tolerant of
// the absolute paths models commonly use — "/workspace" and
// "/workspace/<jobId>/..." are remapped into this job's dir instead of rejected.
function resolveIn(job, p) {
  const base = workspace(job);
  let rel = p == null || p === '' ? '.' : String(p);

  if (rel === ROOT || rel.startsWith(ROOT + '/')) {
    rel = rel.slice(ROOT.length).replace(/^\/+/, '') || '.';
    const jid = path.basename(base);
    if (rel === jid) rel = '.';
    else if (rel.startsWith(jid + '/')) rel = rel.slice(jid.length + 1);
  }

  const full = path.resolve(base, rel);
  if (full !== base && !full.startsWith(base + path.sep)) {
    throw new Error('path escapes the workspace');
  }
  return { base, full };
}

app.get('/health', (_req, res) => res.json({ ok: true, service: 'code-sandbox', root: ROOT }));

// Self-diagnosis: everything the agent needs to understand + adapt to this
// environment (permissions, the host-published port, toolchains, what's already
// listening). The agent calls this instead of guessing.
app.get('/info', (req, res) => {
  // Report THIS job's actual workspace dir (/workspace/<jobId>), not the root —
  // otherwise the agent uses the parent and "escapes" its own jail.
  const jobWorkspace = req.query.job ? workspace(req.query.job) : ROOT;

  // The pool of ports published from this container to the host. Bind a web
  // server to 0.0.0.0 on a FREE one to make it viewable by the user.
  const range = process.env.SANDBOX_APP_PORTS || '8090-8099';
  const [lo, hi] = range.split('-').map(Number);

  const probe =
    "echo '# user'; whoami; echo; echo '# toolchains (you are root — install anything else with apt/pip/npm/cargo/go)'; " +
    "node -v; python3 -V 2>&1; php -v 2>/dev/null | head -1; rustc --version 2>/dev/null; go version 2>/dev/null; git --version; " +
    "echo; echo '# listening ports'; { ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null; } | awk 'NR==1||/LISTEN/'; " +
    "echo; echo '# running processes'; ps -eo pid,comm,args 2>/dev/null | head -15";

  exec(probe, { timeout: 10000, shell: '/bin/bash' }, async (_e, stdout, stderr) => {
    const out = stdout || stderr || '';
    // Which ports in the pool are already bound. Read /proc/net/tcp directly so
    // this works even without ss/netstat installed.
    const listening = await listeningPorts();
    const free = [];
    const inUse = [];
    for (let p = lo; p <= hi; p++) (listening.has(p) ? inUse : free).push(p);

    res.json({
      workspace_root: jobWorkspace,
      write_jail: `Your workspace is ${jobWorkspace}. Prefer RELATIVE paths (e.g. "server.js", "app.log"). Do not write to /etc; keep everything inside your workspace (redirects like "> app.log" too, not "> /workspace/app.log").`,
      is_root: !!(process.getuid && process.getuid() === 0),
      published_app_ports: range,
      free_app_ports: free,
      in_use_app_ports: inUse,
      diagnostics: out.slice(0, 6000),
    });
  });
});

app.post('/exec', async (req, res) => {
  const { job = 'default', cmd, cwd = '.', timeout = 180 } = req.body || {};
  if (!cmd || typeof cmd !== 'string') {
    return res.status(400).json({ error: 'cmd (string) is required' });
  }

  let base, runDir;
  try {
    base = workspace(job);
    runDir = resolveIn(job, cwd).full;
  } catch (e) {
    return res.status(400).json({ error: e.message });
  }
  await fs.mkdir(runDir, { recursive: true });

  const limitMs = Math.min(Math.max(1, Number(timeout) || 180), 900) * 1000;
  const started = Date.now();

  // Run in its OWN process group (detached) so a timeout can kill the WHOLE
  // tree. child_process.exec's built-in timeout only signals the shell and then
  // waits for stdio EOF — so a command that forks downloaders/servers (e.g.
  // `npx playwright install`, which spawns download workers) keeps its pipes
  // open and the request hangs for many minutes past the limit. Here we own the
  // group and SIGKILL it, guaranteeing the call returns near the deadline.
  const child = spawn('/bin/bash', ['-c', cmd], { cwd: runDir, env: process.env, detached: true });

  let stdout = '';
  let stderr = '';
  let done = false;
  let timedOut = false;
  const cap = (buf) => (buf.length > MAX_OUTPUT * 2 ? buf.slice(-MAX_OUTPUT * 2) : buf);
  child.stdout.on('data', (d) => { stdout = cap(stdout + d); });
  child.stderr.on('data', (d) => { stderr = cap(stderr + d); });

  const killTree = (sig) => { try { process.kill(-child.pid, sig); } catch { /* already gone */ } };
  const timer = setTimeout(() => {
    timedOut = true;
    killTree('SIGTERM');
    setTimeout(() => killTree('SIGKILL'), 3000).unref();   // hard-kill stragglers
  }, limitMs);

  const finish = (code, signal) => {
    if (done) return;
    done = true;
    clearTimeout(timer);
    const entry = {
      ts: new Date().toISOString(),
      job,
      cmd,
      cwd,
      exit_code: timedOut ? 124 : (typeof code === 'number' ? code : (signal ? 137 : 1)),
      killed: timedOut,
      timed_out: timedOut,
      stdout: stdout.slice(-MAX_OUTPUT),
      stderr: (timedOut && stderr === '' ? `[killed after ${Math.round(limitMs / 1000)}s timeout]` : stderr).slice(-MAX_OUTPUT),
      duration_ms: Date.now() - started,
    };
    logs.push({ ts: entry.ts, job, cmd, cwd, exit_code: entry.exit_code, duration_ms: entry.duration_ms });
    if (logs.length > 300) logs.shift();
    res.json(entry);
  };

  child.on('close', (code, signal) => finish(code, signal));
  child.on('error', (e) => {
    if (done) return;
    done = true;
    clearTimeout(timer);
    res.status(500).json({ error: e.message });
  });
});

// Start a LONG-LIVED server correctly. /exec is for commands that FINISH — it
// runs in a detached group and SIGKILLs the whole tree at the timeout, which is
// exactly why `node server.js` in the foreground gets killed and the agent kept
// falling into that trap. /serve instead: fully detaches (own session, stdio to
// a log file, stdin off), returns immediately so the server keeps running, then
// tells the TRUTH — is the port actually listening, or did it crash? — with a
// log tail, so a MODULE_NOT_FOUND (e.g. require('express') with nothing
// installed) is seen instead of a false "it's serving on 8090".
app.post('/serve', async (req, res) => {
  const { job = 'default', cmd, cwd = '.', port, log = 'server.log', wait_ms = 5000 } = req.body || {};
  if (!cmd || typeof cmd !== 'string') return res.status(400).json({ error: 'cmd (string) is required' });

  let runDir, logFull;
  try {
    runDir = resolveIn(job, cwd).full;
    logFull = resolveIn(job, log).full;
  } catch (e) {
    return res.status(400).json({ error: e.message });
  }
  await fs.mkdir(runDir, { recursive: true });

  let out;
  try { out = fss.openSync(logFull, 'a'); } catch (e) { return res.status(400).json({ error: 'cannot open log file: ' + e.message }); }

  // Own session (detached) + stdio redirected to the log and OFF the request, so
  // this call returns immediately and the process outlives it.
  const child = spawn('/bin/bash', ['-c', cmd], { cwd: runDir, env: process.env, detached: true, stdio: ['ignore', out, out] });
  const pid = child.pid;
  child.on('error', () => {});
  child.unref();
  try { fss.closeSync(out); } catch { /* child holds its own fd */ }

  // Poll until the port is listening (success) or we crash / hit the deadline.
  const deadline = Date.now() + Math.min(Math.max(500, Number(wait_ms) || 5000), 20000);
  const isAlive = () => { try { process.kill(pid, 0); return true; } catch { return false; } };
  let listening = port ? false : null;
  while (Date.now() < deadline) {
    if (port) {
      if ((await listeningPorts()).has(Number(port))) { listening = true; break; }
    } else if (!isAlive()) {
      break;               // no port to probe; stop once the process is gone
    }
    await new Promise((r) => setTimeout(r, 250));
  }

  let logTail = '';
  try { logTail = (await fs.readFile(logFull, 'utf8')).slice(-4000); } catch { /* no log yet */ }

  logs.push({ ts: new Date().toISOString(), job, cmd: '[serve] ' + cmd, cwd, exit_code: listening ? 0 : 1, duration_ms: 0 });
  res.json({
    pid,
    alive: isAlive(),
    port: port ?? null,
    listening,
    url: port && listening ? `http://localhost:${port}` : null,
    log,
    log_tail: logTail,
  });
});

app.post('/write', async (req, res) => {
  const { job = 'default', path: p, content = '' } = req.body || {};
  if (!p) return res.status(400).json({ error: 'path is required' });
  try {
    const { full } = resolveIn(job, p);
    await fs.mkdir(path.dirname(full), { recursive: true });
    await fs.writeFile(full, String(content));
    res.json({ ok: true, path: p, bytes: Buffer.byteLength(String(content)) });
  } catch (e) {
    res.status(400).json({ error: e.message });
  }
});

app.get('/read', async (req, res) => {
  try {
    const { full } = resolveIn(req.query.job, req.query.path);
    const content = await fs.readFile(full, 'utf8');
    res.json({ path: req.query.path, content: content.slice(0, 100000) });
  } catch (e) {
    res.status(404).json({ error: e.message });
  }
});

app.get('/list', async (req, res) => {
  try {
    const { full, base } = resolveIn(req.query.job, req.query.path);
    const entries = [];
    await walk(full, base, entries, 400);
    res.json({ entries });
  } catch (e) {
    res.status(404).json({ error: e.message });
  }
});

app.get('/logs', (req, res) => {
  const job = req.query.job;
  const out = job ? logs.filter((l) => l.job === job) : logs;
  res.json({ logs: out.slice(-60) });
});

// Running network services the agent started (background servers), with the pid,
// the port, and the workspace/job they belong to — so the user can see + kill them.
app.get('/processes', (_req, res) => {
  exec('ss -Htlnp 2>/dev/null', { shell: '/bin/bash', timeout: 8000 }, async (_e, ssout) => {
    const procs = [];
    for (const line of (ssout || '').split('\n')) {
      const cols = line.trim().split(/\s+/);
      if (cols[0] !== 'LISTEN') continue;
      const local = cols[3] || '';
      const port = Number(local.split(':').pop());
      const pidM = line.match(/pid=(\d+)/);
      if (!pidM) continue;
      const pid = Number(pidM[1]);
      if (pid === 1 || pid === process.pid) continue; // never the sandbox service itself
      let cmd = '';
      let cwd = '';
      try { cmd = (await fs.readFile(`/proc/${pid}/cmdline`, 'utf8')).replace(/\0/g, ' ').trim(); } catch {}
      try { cwd = await fs.readlink(`/proc/${pid}/cwd`); } catch {}
      procs.push({
        pid,
        port,
        command: cmd.slice(0, 140),
        workspace: cwd,
        job: cwd.startsWith(ROOT + '/') ? cwd.slice(ROOT.length + 1) : null,
      });
    }
    // Dedup by pid (a process can bind several addrs for one port).
    const seen = new Set();
    res.json({ processes: procs.filter((p) => (seen.has(p.pid) ? false : seen.add(p.pid))) });
  });
});

// All running processes (longest-running first), so the agent can spot a hung or
// runaway command (high elapsed/cpu) the way a human would with `ps`, then /kill it.
app.get('/ps', (_req, res) => {
  exec('ps -eo pid,ppid,etimes,pcpu,pmem,args --sort=-etimes 2>/dev/null', { shell: '/bin/bash', timeout: 8000 }, async (_e, out) => {
    const procs = [];
    for (const line of (out || '').split('\n').slice(1)) {
      const m = line.trim().match(/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(.*)$/);
      if (!m) continue;
      const pid = Number(m[1]);
      if (pid === 1 || pid === process.pid) continue;       // hide init + the sandbox service
      const args = m[6];
      if (/ps -eo pid,ppid,etimes/.test(args)) continue;    // hide this probe itself
      if (args.includes('<defunct>')) continue;             // zombies aren't actionable
      let cwd = '';
      try { cwd = await fs.readlink(`/proc/${pid}/cwd`); } catch {}
      procs.push({
        pid,
        ppid: Number(m[2]),
        elapsed_s: Number(m[3]),
        cpu: Number(m[4]),
        mem: Number(m[5]),
        command: args.slice(0, 200),
        job: cwd.startsWith(ROOT + '/') ? cwd.slice(ROOT.length + 1).split('/')[0] : null,
      });
    }
    res.json({ processes: procs });
  });
});

// Terminate a process the agent started (by pid). Never the sandbox service.
app.post('/kill', (req, res) => {
  const pid = Number((req.body || {}).pid);
  if (!pid || pid === 1 || pid === process.pid) {
    return res.status(400).json({ error: 'a valid non-service pid is required' });
  }
  exec(`kill -TERM ${pid} 2>&1; sleep 0.3; kill -KILL ${pid} 2>/dev/null; echo ok`,
    { shell: '/bin/bash', timeout: 6000 }, (_e, out) => res.json({ ok: true, output: (out || '').trim() }));
});

// Listening TCP ports, parsed from /proc (no ss/netstat dependency).
async function listeningPorts() {
  const ports = new Set();
  for (const f of ['/proc/net/tcp', '/proc/net/tcp6']) {
    let data;
    try {
      data = await fs.readFile(f, 'utf8');
    } catch {
      continue;
    }
    for (const line of data.split('\n').slice(1)) {
      const cols = line.trim().split(/\s+/);
      if (cols.length < 4) continue;
      if (cols[3] !== '0A') continue; // 0A = TCP LISTEN
      const portHex = (cols[1] || '').split(':')[1];
      if (portHex) ports.add(parseInt(portHex, 16));
    }
  }
  return ports;
}

async function walk(dir, base, out, cap) {
  let items;
  try {
    items = await fs.readdir(dir, { withFileTypes: true });
  } catch {
    return;
  }
  for (const e of items) {
    if (out.length >= cap) return;
    const full = path.join(dir, e.name);
    const rel = path.relative(base, full) || e.name;
    if (['node_modules', '.git', 'vendor', 'dist', 'target'].includes(e.name)) {
      out.push(rel + '/ (…skipped)');
      continue;
    }
    if (e.isDirectory()) {
      out.push(rel + '/');
      await walk(full, base, out, cap);
    } else {
      out.push(rel);
    }
  }
}

const port = process.env.PORT || 3000;
app.listen(port, () => console.log(`code-sandbox listening on :${port} (root ${ROOT})`));
