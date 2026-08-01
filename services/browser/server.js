'use strict';

/**
 * Headless-browser microservice for the research agent.
 *
 * Laravel (the PHP `browser_search` / `read_webpage` tools) calls this over
 * HTTP. It drives a real Chromium via Playwright so the agent can use free web
 * UIs — search engines and public pages — that either have no API or only a
 * billable one. It does NOT automate login-gated proprietary UIs.
 *
 * Endpoints:
 *   GET  /health
 *   POST /search   { query, engine=duckduckgo|google, limit=5 }
 *   POST /extract  { url, max_chars=8000 }
 */

const express = require('express');
const { chromium } = require('playwright');

const app = express();
app.use(express.json({ limit: '1mb' }));

const UA =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';

let browserPromise = null;
async function getBrowser() {
  if (!browserPromise) {
    browserPromise = chromium.launch({ args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  }
  return browserPromise;
}

async function withPage(fn) {
  const browser = await getBrowser();
  const context = await browser.newContext({ userAgent: UA, locale: 'en-US' });
  const page = await context.newPage();
  try {
    return await fn(page);
  } finally {
    await context.close();
  }
}

app.get('/health', (_req, res) => res.json({ ok: true, service: 'playwright-browser' }));

app.post('/search', async (req, res) => {
  const { query, engine = 'bing', limit = 5 } = req.body || {};
  if (!query || typeof query !== 'string') {
    return res.status(400).json({ error: 'query (string) is required' });
  }

  try {
    // Keyless best-effort (search engines fight scraping, so this is limited):
    //  1) DuckDuckGo Instant Answer API — fast, reliable for entities/definitions.
    //  2) Bing SERP via headless Chromium — best-effort for open-ended queries;
    //     often blocked/empty. For reliable search, configure a Tavily/Brave/
    //     SerpAPI key (Settings) which enables a proper search tool.
    let results = await searchDuckDuckGoApi(query);
    let source = 'ddg-instant-answer';

    if (results.length === 0) {
      try {
        results = await withPage((p) => searchBing(p, query));
        source = 'bing';
      } catch (_) {
        source = 'blocked';
      }
    }

    // Always-available keyless seed: Wikipedia article URLs for the query terms.
    // Even if SERPs are blocked, this gives the agent real pages to start
    // reading + crawling links from (the human-like loop).
    if (results.length === 0) {
      results = await searchWikipedia(query);
      source = 'wikipedia';
    }

    res.json({ query, engine, source, results: results.slice(0, Math.max(1, Math.min(10, limit))) });
  } catch (e) {
    res.status(502).json({ error: `search failed: ${e.message}` });
  }
});

// Bing SERP — the one major engine that doesn't bot-block our headless browser.
async function searchBing(page, query) {
  await page.goto('https://www.bing.com/search?setlang=en&cc=US&q=' + encodeURIComponent(query), {
    waitUntil: 'domcontentloaded',
    timeout: 20000,
  });
  // Bing injects organic results via JS — wait briefly, then fast-fail so a
  // blocked/empty response doesn't stall the agent for long.
  try {
    await page.waitForSelector('li.b_algo h2 a', { timeout: 5000 });
  } catch (_) {
    // no organic results block appeared (Bing often serves headless a page without them)
  }

  // Use textContent (not innerText — headless has no layout, so innerText is empty).
  const raw = await page.$$eval('li.b_algo', (nodes) =>
    nodes
      .map((n) => {
        const h2 = n.querySelector('h2');
        const a = n.querySelector('h2 a');
        const cap = n.querySelector('.b_caption p') || n.querySelector('.b_algoSlug') || n.querySelector('.b_caption');
        return a && a.href
          ? {
              title: (h2 ? h2.textContent : a.textContent).trim(),
              url: a.href,
              snippet: cap ? cap.textContent.trim() : '',
            }
          : null;
      })
      .filter(Boolean)
  );

  return raw.map((r) => ({ ...r, url: decodeBingUrl(r.url) }));
}

// Bing wraps result links in https://www.bing.com/ck/a?...&u=a1<base64url>. Unwrap it.
function decodeBingUrl(url) {
  try {
    const u = new URL(url);
    if (u.hostname.endsWith('bing.com') && u.pathname.startsWith('/ck/a')) {
      const up = u.searchParams.get('u');
      if (up && up.startsWith('a1')) {
        const b64 = up.slice(2).replace(/-/g, '+').replace(/_/g, '/');
        const padded = b64 + '==='.slice((b64.length + 3) % 4);
        const real = Buffer.from(padded, 'base64').toString('utf8');
        if (/^https?:\/\//i.test(real)) return real;
      }
    }
  } catch (_) {}
  return url;
}

// DuckDuckGo Instant Answer API — keyless JSON, no browser, not bot-blocked.
// Returns an abstract + related topics (each with a real URL). Great for facts,
// entities, definitions; sparse for very obscure/long-tail queries.
async function searchDuckDuckGoApi(query) {
  const url =
    'https://api.duckduckgo.com/?format=json&no_html=1&no_redirect=1&t=research-agent&q=' +
    encodeURIComponent(query);

  let j;
  try {
    const r = await fetch(url, { headers: { 'User-Agent': UA } });
    if (!r.ok) return [];
    const body = await r.text();
    if (!body.trim()) return [];
    j = JSON.parse(body);
  } catch (_) {
    return []; // flaky/rate-limited/non-JSON → treat as "no results", never throw
  }

  const out = [];
  if (j.AbstractText) {
    out.push({ title: j.Heading || query, url: j.AbstractURL || '', snippet: j.AbstractText });
  }
  const walk = (arr) => {
    for (const t of arr || []) {
      if (t.Text && t.FirstURL) {
        out.push({ title: (t.Text.split(' - ')[0] || t.Text).slice(0, 120), url: t.FirstURL, snippet: t.Text });
      } else if (t.Topics) {
        walk(t.Topics);
      }
    }
  };
  walk(j.RelatedTopics);
  return out;
}

// Wikipedia opensearch — keyless, reliable, returns real article URLs for the
// query terms. The perfect keyless seed: the agent reads these and follows
// their links/citations outward to primary sources.
async function searchWikipedia(query) {
  try {
    // Full-text search (better recall than opensearch for verbose queries).
    const url =
      'https://en.wikipedia.org/w/api.php?action=query&list=search&srlimit=5&format=json&srsearch=' +
      encodeURIComponent(query);
    const r = await fetch(url, { headers: { 'User-Agent': UA } });
    if (!r.ok) return [];
    const hits = (await r.json())?.query?.search || [];

    return hits.map((h) => ({
      title: h.title,
      url: 'https://en.wikipedia.org/wiki/' + encodeURIComponent(h.title.replace(/ /g, '_')),
      snippet: (h.snippet || '').replace(/<[^>]+>/g, ''),
    }));
  } catch (_) {
    return [];
  }
}

app.post('/extract', async (req, res) => {
  const { url, max_chars = 8000 } = req.body || {};
  if (!url || !/^https?:\/\//i.test(url)) {
    return res.status(400).json({ error: 'a valid http(s) url is required' });
  }

  try {
    const data = await withPage(async (page) => {
      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
      return page.evaluate(() => {
        // Scope to the main content so we skip site nav/footer chrome and get the
        // article text + its real content/reference links.
        // Prefer the narrowest article-body container FIRST. (A comma selector list
        // would return the first match in document order — i.e. the outer <main> —
        // so we must try each selector in priority order explicitly.)
        const pick = (sel) => document.querySelector(sel);
        const root =
          pick('.mw-parser-output') || pick('#mw-content-text') || pick('article') ||
          pick('main') || pick('[role="main"]') || pick('#content') || document.body;
        const text = root ? (root.innerText || root.textContent || '') : '';

        // Common boilerplate anchor text to ignore (nav/footer/account links).
        const skip = /^(main page|contents|current events|random article|about( wikipedia)?|contact us|help|donate|log ?in|log ?out|create account|privacy( policy)?|terms( of use)?|cookie|disclaimers?|sign ?in|sign ?up|subscribe|home|menu|search|jump to|edit|view (source|history)|talk|read|tools|print\/export|download|newsletter|advertise|careers|share|tweet|facebook|instagram|youtube|linkedin)$/i;

        const seen = new Set();
        const links = [];
        for (const a of Array.from(root.querySelectorAll('a[href]'))) {
          const href = a.href;
          if (!/^https?:\/\//i.test(href)) continue;      // skip mailto/js/anchors
          if (href.includes('#')) continue;               // skip in-page fragments
          if (seen.has(href)) continue;
          const label = (a.innerText || a.textContent || '').trim().replace(/\s+/g, ' ');
          if (label.length < 4) continue;                 // skip icon/empty links
          if (skip.test(label)) continue;                 // skip boilerplate
          seen.add(href);
          links.push({ text: label.slice(0, 90), url: href });
          if (links.length >= 40) break;
        }
        return { title: document.title, text, links };
      });
    });

    res.json({
      url,
      title: data.title,
      text: (data.text || '').replace(/\n{3,}/g, '\n\n').trim().slice(0, max_chars),
      links: data.links || [],
    });
  } catch (e) {
    res.status(502).json({ error: `extract failed: ${e.message}` });
  }
});

// DuckDuckGo's HTML endpoint is automation-friendly (no JS/CAPTCHA) — the default.
async function searchDuckDuckGo(page, query) {
  await page.goto('https://html.duckduckgo.com/html/?q=' + encodeURIComponent(query), {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });

  const raw = await page.$$eval('.result', (nodes) =>
    nodes
      .map((n) => {
        const a = n.querySelector('a.result__a');
        const s = n.querySelector('.result__snippet');
        return a ? { title: a.innerText.trim(), url: a.href, snippet: s ? s.innerText.trim() : '' } : null;
      })
      .filter(Boolean)
  );

  // DuckDuckGo wraps links in a redirect (…/l/?uddg=<real-url>); unwrap it.
  return raw.map((r) => {
    try {
      const u = new URL(r.url);
      const uddg = u.searchParams.get('uddg');
      if (uddg) r.url = decodeURIComponent(uddg);
    } catch (_) {}
    return r;
  });
}

// Google is brittle (consent walls, markup churn, CAPTCHA under load). Best-effort.
async function searchGoogle(page, query) {
  await page.goto('https://www.google.com/search?hl=en&q=' + encodeURIComponent(query), {
    waitUntil: 'domcontentloaded',
    timeout: 30000,
  });

  return page.$$eval('a:has(h3)', (anchors) =>
    anchors
      .slice(0, 10)
      .map((a) => {
        const h3 = a.querySelector('h3');
        const container = a.closest('div');
        const snippetEl = container ? container.parentElement : null;
        return h3
          ? {
              title: h3.innerText.trim(),
              url: a.href,
              snippet: snippetEl ? snippetEl.innerText.slice(0, 300).trim() : '',
            }
          : null;
      })
      .filter(Boolean)
  );
}

const port = process.env.PORT || 3000;
app.listen(port, () => console.log(`browser service listening on :${port}`));

// Graceful shutdown so Chromium doesn't leak.
for (const sig of ['SIGINT', 'SIGTERM']) {
  process.on(sig, async () => {
    try {
      if (browserPromise) (await browserPromise).close();
    } finally {
      process.exit(0);
    }
  });
}
