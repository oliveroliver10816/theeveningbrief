# Where dailynews18.com's feed comes from — teardown, 2026-08-19

## Verdict

It is a **PHP + MySQL RSS aggregator on GoDaddy shared hosting**. There is no
proprietary feed, no paid wire, no API deal. It re-publishes the *headline,
the feed-provided summary, and the source link* from ~25 public RSS feeds, and
**hotlinks the publishers' own images straight off their CDNs**.

## Evidence

| Signal | Finding |
|---|---|
| DNS | `173.201.178.221`, NS `ns31/ns32.domaincontrol.com` → GoDaddy shared hosting |
| URLs | `index.php`, `section.php?slug=`, `article.php?id=`, `api/save.php`, `api/follow.php`, `admin/` → server-rendered PHP, MySQL-backed, sequential integer article ids (~1700 rows) |
| Images | Zero images self-hosted. All from publisher CDNs: `s.abcnews.com`, `www.srnnews.com`, `static01.nyt.com`, `wtop.com`, `ktla.com`, `media-cldnry.s-nbcnews.com`, `cdnph.upi.com`, `kffhealthnews.org`, `dw-wp-production.imgix.net`, `d3i6fh83elv35t.cloudfront.net` (PBS) |
| Hotlink handling | `referrerpolicy="no-referrer"` + an inline `onerror` that hides the image and reveals a text card — i.e. they *expect* hotlinks to break |
| Article body | ~1,050 words total per article page, of which the story itself is one feed summary paragraph, then *"Read full story at The New York Times ↗"* and *"This portal aggregates the headline, feed-provided summary and source link. The full article remains with the original publisher."* |
| Markets | Three **TradingView** embed widgets (`s3.tradingview.com/external-embedding/embed-widget-mini-symbol-overview.js`) in a CSS marquee — S&P 500, BTC/USD, XAU/USD |
| Client JS | One 1.5 KB `app.js`: a clock, a save button, a follow button. Nothing else. |
| Lazy loading | 60 × `loading="lazy"`, **0 × `srcset`** |

## The publishers it pulls from (named in its own markup)

ABC News (Top Stories, U.S., Business, Health, Technology, Entertainment),
ABC/ESPN Sports, SRN News (Headlines, U.S., World, Business, Politics, Sports,
Religious News — Salem Radio, which carries AP and Reuters copy), NPR Politics,
CBS News (Top Stories, U.S., Entertainment), NBC News, The New York Times, UPI,
WTOP, KTLA, KFF Health News, DW, PBS NewsHour, National Weather Service active alerts.

## Gaps we exploit

1. **No `robots.txt` and no `sitemap.xml`** — both 404. For a site whose whole
   business is indexed news pages, that is the biggest single miss.
2. **No `srcset`** — full-size publisher images shipped to phones.
3. **Its "recipes" section does not exist.** `section.php?slug=recipes` returns 200
   but silently serves the *Nation* feed. Bob's recipes ask is a real differentiator.
4. **Finance is everywhere up top** — three TradingView widgets marquee across the
   masthead before a single headline. Three third-party scripts before first paint.
5. No caching layer; every page is a fresh PHP+MySQL render on shared hosting.

## Feed roster for our build — every URL HTTP-200 verified 2026-08-19

**US national**
- `https://feeds.abcnews.com/abcnews/usheadlines`
- `https://feeds.abcnews.com/abcnews/topstories`
- `https://www.cbsnews.com/latest/rss/us`
- `https://feeds.nbcnews.com/nbcnews/public/news`
- `https://feeds.npr.org/1001/rss.xml` (News), `1014` (Politics)
- `https://rss.nytimes.com/services/xml/rss/nyt/US.xml`, `HomePage.xml`
- `https://rss.upi.com/news/top_news.rss`
- `https://www.pbs.org/newshour/feeds/rss/headlines`
- `https://www.srnnews.com/feed/`
- `https://moxie.foxnews.com/google-publisher/latest.xml`

**International / World**
- `https://feeds.abcnews.com/abcnews/internationalheadlines`
- `https://www.cbsnews.com/latest/rss/world`
- `https://rss.nytimes.com/services/xml/rss/nyt/World.xml`
- `https://rss.upi.com/news/world_news.rss`
- `https://feeds.bbci.co.uk/news/world/rss.xml`, `https://feeds.bbci.co.uk/news/rss.xml`
- `https://www.theguardian.com/world/rss`, `/us-news/rss`
- `https://www.aljazeera.com/xml/rss/all.xml`
- `https://rss.dw.com/rdf/rss-en-world`
- `https://feeds.skynews.com/feeds/rss/world.xml`
- `https://feeds.washingtonpost.com/rss/world`

**Sections** — ABC money/technology/entertainment/health/sports headlines feeds,
`https://www.espn.com/espn/rss/news`.

**Weather** — `https://api.weather.gov/alerts/active` (alerts, needs a real UA) +
`https://api.open-meteo.com/v1/forecast` (no key, no signup).

**Recipes** — `https://www.budgetbytes.com/feed/`, `https://smittenkitchen.com/feed/`,
`https://www.loveandlemons.com/feed/`, `https://www.themealdb.com/api/json/v1/1/random.php`.

⚠ Dead or blocked from this box, do not add: `seriouseats.com/feeds/all` (402),
`simplyrecipes.com/feeds/all` (402), `food52.com/blog.rss` (429),
`apnews.com/hub/ap-top-news/rss` (404), `reutersagency.com/feed/` (404).
AP and Reuters copy still reaches us via SRN News, which carries both.
