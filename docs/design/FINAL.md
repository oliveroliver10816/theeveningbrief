# FINAL — judged design + renderer contract

## Winner: **AUTHORITY** (the evening broadsheet)

Judged against the six criteria:

| Criterion | AUTHORITY | SIGNAL | EVENING |
|---|---|---|---|
| (a) Serious news brand, not template | **Best** — 3 faces/3 jobs, rule hierarchy, edition plate, lead top-right | Sharp but dashboard-adjacent | Most decorative; 6 hues read magazine, not daily |
| (b) 2560px used well | **Best** — no max-width, width becomes columns; 2/4/6 hero | Good (6-up at 1900px) | Good (5/4/3 hero) |
| (c) Hierarchy under real headline lengths | **Best** — Newsreader opsz, per-block density rhythms | One family, weight-only | Fine |
| (d) Missing/odd-ratio image | OK but under-marked | Good (accent top-rule) | **Best** (deep-paper text card) |
| (e) Cheap server-side string render | **Worst** — column wrappers + nth-child structure | **Best** — flat class-driven cards | Middling (per-card inline `--hue`) |
| (f) Accessibility | **Best** — skip link, 44px targets, focus-visible, ticker pause | No skip link | No skip link |

AUTHORITY wins (a)(b)(c)(f); its two weaknesses are exactly what the other two do best, so:

## Grafted in

1. **From SIGNAL — the flat card grammar.** AUTHORITY's `.cols > .col` column
   wrappers and `nth-child` borders are gone. Every grid is a flat list of
   self-contained `.card`s; size is a class, never a position. A hairline sits
   *above every card* in a grid, so any card count / any column count resolves
   with zero renderer logic and a missing card can't break a border pattern.
2. **From SIGNAL — `.searchbar`** (bordered input + ink button, 48px).
3. **From EVENING — the text-only fallback card**: `.card--text` = deep paper +
   4px accent top rule + lift shadow. A missing publisher image produces a
   *designed state*, not a hole. Emitted server-side for imageless rows, and
   any card upgrades itself to it via the img `onerror` (below).
4. **Rejected on purpose:** EVENING's pull-quote interlude (we have no honest
   quote-extraction from feed summaries — fabrication risk) and its 6-hue
   section coding (AUTHORITY's two-tier accent — whisper teal / shout red — is
   the stronger, cheaper discipline).

Everything else is AUTHORITY as drawn: Newsreader + Libre Franklin + IBM Plex
Mono with real fallback stacks, warm paper `#FBFAF7` / dark `#141519`, Oxford
double rule, edition plate with clock, headline ticker (never markets),
markets once/low/quiet, radius 0, no boxes.

## Tokens (all in `src/design.css` `:root`, dark under `prefers-color-scheme`)

`--paper --paper-2 --paper-deep --well --ink --ink-2 --rule --rule-strong
--accent --alert --up --chip-bg --chip-ink` · type `--serif --sans --mono`,
scale `--fs-meta --fs-ui --fs-body --fs-hed-s --fs-hed-m --fs-hed-l
--fs-hed-lead --fs-wordmark` · spacing `--s1…--s9 --pad` · `--radius`(0)
`--shadow-none --shadow-lift`. All colour pairs measured ≥ AA at used sizes
(values carried over from dir-authority, which documents the ratios).

## Component class list + HTML skeletons

Page order: `.skip` → `.ticker` → `.masthead` → `.oxford` → `.nav` → `<main>`
(`.hero`, `.adslot`, `.block`×n, `.weather-strip`, `.block` recipes,
`.markets-strip`) → `.footer`. Gutter = wrap components in `class="wrap"`
(components with a band background — ticker/weather/markets/adslot/footer —
are full-bleed and take a `.wrap` INNER div).

### .ticker  (CSS-animated; pauses on hover/focus; reduced-motion → scrollable)
```html
<div class="ticker" aria-label="Latest headlines">
  <div class="ticker-bug"><span class="dot" aria-hidden="true"></span>LATEST</div>
  <div class="ticker-vp">
    <div class="ticker-track">
      <ul>
        <li><a href="/article/…"><span class="chip">Breaking</span> Headline <span class="s">Source</span></a></li>
        …
      </ul>
      <ul aria-hidden="true"><!-- exact duplicate; every <a> gets tabindex="-1" --></ul>
    </div>
  </div>
</div>
```
The duplicate `<ul>` is REQUIRED (the -50% keyframe loops over it). `.chip`
only on genuinely fresh items.

### .masthead
```html
<header class="masthead wrap">
  <div class="masthead-grid">
    <div class="masthead-side"><strong>DATE</strong><br>CITY · TEMP COND<br><span class="m">sunset … · edition …</span></div>
    <div class="masthead-brand">
      <p class="wordmark"><a href="/">{cfg.siteName}</a></p>
      <p class="tag">{cfg.tagline}</p>
    </div>
    <div class="masthead-plate" aria-label="Edition">
      <p class="ed">Evening edition</p>
      <p class="stars" aria-hidden="true">★ ★ ★</p>
      <p class="clock" id="clock">6:42:07 PM</p>
      <p class="no">Vol. I · No. {dayOfYear} · {tz}</p>
    </div>
  </div>
</header>
<div class="oxford" aria-hidden="true"></div>
```
Clock is server-rendered; an optional ≤8-line script makes it tick.

### .nav
```html
<nav class="nav" aria-label="Sections">
  <ul>
    <li><a class="on" href="/" aria-current="page">Front page</a></li>
    <li><a href="/section/us">U.S.</a></li> …
    <li class="push"><a href="/search">Search</a></li>
  </ul>
</nav>
```
`.on` = current page. `li.push` right-aligns the tail items.

### .searchbar  (search page / can sit in a block head)
```html
<form class="searchbar" action="/search" method="get" role="search">
  <input type="search" name="q" value="…" placeholder="Search the brief…" aria-label="Search">
  <button type="submit">Search</button>
</form>
```

### .card — ONE skeleton, size via class
Sizes: `card--lead` `card--large` `card--medium` `card--small` `card--text`.
```html
<article class="card card--medium">
  <a class="card-media" href="{articleUrl}" tabindex="-1" aria-hidden="true">
    <img src="{image_url}" alt="" width="{w}" height="{h}"
         loading="lazy" decoding="async" referrerpolicy="no-referrer"
         onerror="this.closest('.card').classList.add('card--text');this.closest('.card-media').hidden=true">
    <span class="credit">PHOTO · {SOURCE}</span>   <!-- optional -->
  </a>
  <p class="kicker">{Section label}</p>
  <h3 class="card-hed"><a href="{articleUrl}">{title}</a></h3>
  <p class="card-sum">{summary}</p>
  <p class="card-src">{source} · <time class="t" datetime="…">5:48 p.m.</time></p>
</article>
```
Rules:
- **Hero lead only**: `card--lead`, `<h2>` instead of `<h3>`, img gets
  `loading="eager" fetchpriority="high"` (NO lazy), and after `.card-src` add
  `<a class="card-out" href="{url}">Read the full story at {source} →</a>`.
- No image in the row → emit `card--text` and OMIT `.card-media` entirely.
  The `onerror` handler upgrades a broken hotlink to the same state at runtime.
- `card--small` renders headline+source only (CSS hides media/summary, but
  omit them server-side anyway — cheaper strings).
- The whole card is tappable via `.card-hed a::after` stretch; `.card-out`
  stays independently clickable.
- The `.card-media` box is `aspect-ratio:3/2` (lead 2/1, recipes 4/3) with
  `object-fit:cover` — odd publisher ratios crop, never distort, never shift.

### .hero  (rail 2fr | subs 4fr | lead 6fr; lead top-RIGHT)
```html
<section class="hero wrap" aria-label="Top stories">
  <div class="hero-rail">
    <div class="rail-head"><p class="kicker">The 6 O’Clock Brief</p><p class="rail-note">…</p></div>
    <ol class="rail-list">
      <li><a href="…"><span class="n">1</span><span><span class="h">Headline</span><span class="s">SOURCE</span></span></a></li>
      … (6 items)
    </ol>
    <div class="rail-foot"><a href="…">Read the full brief →</a></div>
  </div>
  <div class="hero-subs"><!-- 4 × .card card--medium (2 with image, ok to mix) --></div>
  <article class="card card--lead hero-lead">
    <div class="kline"><span class="chip">Live</span><span class="kicker">{topic}</span></div>
    <h2 class="card-hed"><a>…</a></h2>
    <a class="card-media">…eager img…</a>
    <p class="card-sum">…</p><p class="card-src">…</p>
    <a class="card-out">Read the full story at {source} →</a>
  </article>
</section>
```
DOM order rail→subs→lead; grid areas place the lead visually right. Collapses
1680px → subs|lead + rail-below (rail becomes 3-col), 1023px → single column
lead-first, 640px → everything stacks.

### .block  (US / International / World / Recipes / section pages)
```html
<section class="block wrap" aria-label="{label}">
  <div class="block-head">
    <p><span class="block-label">{label}</span> <span class="block-note">— {note}</span></p>
    <a class="block-more" href="/section/{slug}">More {label} →</a>
  </div>
  <div class="block-grid">          <!-- flat list of .card, nothing else -->
    <article class="card card--large">…</article>
    <article class="card card--medium">…</article>
    <article class="card card--text">…</article>
    …
  </div>
</section>
```
Grid modifiers: default 4-col; `block-grid--3`, `block-grid--2`;
`block-grid--wire` = dense text rows (World desk: emit `card--small`s);
add `block-grid--6up` alongside --3/--4 to go 6-col at ≥1900px (International).
`card--large` spans 2 rows in the 4-col grid. Every card draws its own top
hairline — order and count are free.

### .adslot  (reserved height, zero CLS; render via adSlot(name) helper)
```html
<div class="adslot" aria-label="Advertisement slot">
  <div class="adslot-frame"><span class="adslot-label">Advertisement</span><span class="adslot-dim">970 × 250</span></div>
</div>
```
`adslot--box` variant = 300×250 (can sit inside a `.block-grid` as a grid
child). Billboard min-height 250px (110px ≤768px); box always 250px.

### .weather-strip
```html
<section class="weather-strip" aria-label="Weather">
  <div class="wrap weather-row">
    <div class="wx-now">
      <span class="wx-temp">84°</span>
      <div><span class="wx-cond">Partly cloudy · {city}</span><span class="wx-hilo">H 96° / L 78° · wind SW 8</span></div>
    </div>
    <p class="wx-alert"><span class="chip">Alert</span> <a href="/weather">{NWS alert headline}</a></p>  <!-- omit if none -->
    <ul class="wx-days">
      <li><span class="d">THU</span><span class="n2">94/77</span><span class="c">Hazy sun</span></li> ×5
    </ul>
    <a class="block-more" href="/weather">Full forecast →</a>
  </div>
</section>
```

### .recipe-card  (inside a recipes `.block` with `block-grid`)
```html
<article class="card recipe-card">
  <a class="card-media">…4:3 img…</a>
  <h3 class="card-hed"><a>…</a></h3>
  <p class="recipe-meta"><b>35 min</b> · one pot · vegetarian</p>
  <p class="card-src">{source}</p>
</article>
```

### .markets-strip  (once, LOW on the page, after recipes; max 2 cards — quota)
```html
<section class="markets-strip" aria-label="Markets">
  <div class="wrap">
    <div class="mk-row">
      <p class="mk-label">Markets · after the close</p>
      <ul class="mk-quotes">
        <li><span class="nm">S&amp;P 500</span>6,412.18 <span class="up">▲ 0.4%</span></li>
        <li><span class="nm">BTC</span>118,240 <span class="down">▼ 1.8%</span></li> …
      </ul>
      <span class="mk-fine">delayed at least 15 min</span>
    </div>
    <div class="mk-cards"><!-- ≤2 × .card card--small --></div>
  </div>
</section>
```

### .pagination  (section pages, search)
```html
<nav class="pagination" aria-label="Pages">
  <a class="pg pg-prev" href="…">← Newer</a>
  <a class="pg" href="…">1</a>
  <span class="pg pg-now" aria-current="page">2</span>
  <a class="pg" href="…">3</a>
  <span class="pg pg-gap">…</span>
  <a class="pg" href="…">12</a>
  <a class="pg pg-next" href="…">Older →</a>
</nav>
```
Omit prev/next when absent (don't render disabled links).

### .footer
```html
<footer class="footer">
  <div class="oxford" aria-hidden="true"></div>
  <div class="wrap">
    <div class="footer-grid">
      <div class="footer-brand">
        <p class="fbrand">{cfg.siteName}</p>
        <p class="standing">We publish headlines, feed-provided summaries and source links only. Every full story remains with — and links to — its original publisher.</p>
        <p class="m">{city} · Vol. I, No. {n}</p>
      </div>
      <div><h5>Sections</h5><ul>…</ul></div>
      <div><h5>Our sources</h5><ul class="two">…</ul></div>
      <div><h5>About</h5><ul>…</ul></div>
      <div><h5>Follow</h5><ul>…</ul></div>
    </div>
    <div class="footer-bar"><span>© {year} {cfg.siteName}</span><span class="m">…</span></div>
  </div>
</footer>
```

### Also available
`.skip` (skip link — render it first in `<body>`, target `#top-stories` on
`<main>`), `.kicker` `.chip` `.card-out` `.oxford` `.kline` text roles,
`.up`/`.down` market colours.

## Hard guarantees the stylesheet makes
- `img{max-width:100%;height:auto;display:block}` global (cards ship
  width/height attrs; without height:auto they'd squash 38–42%).
- Ad slots have fixed `min-height` — zero CLS.
- Ticker: pure CSS animation, pauses on `:hover`/`:focus-within`, DISABLED
  under `prefers-reduced-motion: reduce` (viewport becomes scrollable).
- One `@import` only: the single Google Fonts stylesheet; every face has a
  real system fallback stack (Georgia / Franklin-Helvetica-Arial / Consolas).
- Light palette on bare `:root`; dark fully redefined under
  `@media (prefers-color-scheme: dark)`. Pair it with
  `<meta name="color-scheme" content="light dark">` in layout().
- No horizontal overflow: grids use `1fr` on `min-width:0` cards; ticker and
  nav scroll inside their own boxes.
- All tap targets ≥44px; `:focus-visible` outline; no brand literal anywhere
  in the CSS (grep-verified).
