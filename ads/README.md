# The Evening Brief — search campaign v1

One campaign, three ad groups. Search partners OFF, auto-tagging ON.

⚠️ **DKI runs in ad groups 1 and 2 ONLY. Never in ad group 3.** See the note at the bottom —
it is a policy line, not a preference.

---

## Ad group 1 — NEWS  (DKI RSA)

**Headlines** (30 char max — all verified)

| # | Headline | Chars |
|---|---|---|
| 1 | `{KeyWord:Breaking News}` | 13 (default) |
| 2 | Today's Top Stories | 19 |
| 3 | US News, World & Weather | 24 |
| 4 | Updated Every 10 Minutes | 24 |
| 5 | The Evening Brief | 17 |
| 6 | Free - No Sign Up Needed | 24 |
| 7 | Top Stories In One Place | 24 |
| 8 | Read It In Five Minutes | 23 |
| 9 | News From Trusted Sources | 25 |
| 10 | Today's Headlines, Fast | 23 |
| 11 | Catch Up In One Scroll | 22 |
| 12 | One Page. The Whole Day. | 24 |

**Descriptions** (90 char max)

1. Top US and world stories, refreshed every 10 minutes. Weather, forecasts and recipes. *(85)*
2. One page for the day's news. No sign-up, no paywall, no clutter. Free to read. *(78)*
3. Headlines from trusted newsrooms, gathered and set in order. Updated all day. *(77)*
4. Breaking news, five-day forecasts and severe weather alerts in one clean page. *(78)*

**Paths:** `/news` · `/today`
**Final URL:** `https://theeveningbrief.com/`

---

## Ad group 2 — WEATHER  (DKI RSA)

Same descriptions. Headlines swap 3, 7, 9 for:

- `{KeyWord:Weather Forecast}` — 16 (default)
- Weather Forecast & Alerts — 25
- Live Radar & 5-Day Outlook — 26
- Severe Weather Alerts — 21

**Paths:** `/weather` · `/forecast`
**Final URL:** `https://theeveningbrief.com/weather`

---

## Ad group 3 — BRAND CONQUEST  (**NO DKI** — static RSA)

Headlines must never contain a competitor's name.

- The Evening Brief — 17
- All Today's News, One Page — 26
- Free News. No Paywall. — 22
- US, World & Weather Daily — 25
- No Sign Up. No Clutter. — 23
- Updated Every 10 Minutes — 24
- Read The Day In 5 Minutes — 25

Descriptions: reuse 1, 2 and 4 above. **Do not** use description 3 here.

---

## Keywords

**Ad group 1 — news** (exact and phrase live in separate ad groups; split on import)

```
[breaking news]            [latest news]            [news today]
[us news]                  [top news today]         [world news]
[news headlines]           [breaking news today]    [current events today]
[national news]            [international news]     [live news updates]
"breaking news"            "latest news today"      "us news headlines"
"world news today"
```

**Ad group 2 — weather**

```
[weather today]            [weather forecast]       [local weather]
[hourly weather]           [10 day forecast]        [weather radar]
[weather near me]          [tomorrow weather]       [severe weather alerts]
[weather warnings]         [temperature today]      [rain forecast today]
"weather forecast today"   "10 day weather forecast"  "severe weather alert"
"local weather radar"
```

**Ad group 3 — brand conquest** (phrase only, so we collect the tail and can curate)

```
"cnn news"          "fox news today"     "nbc news"        "cbs news"
"abc news today"    "usa today news"     "npr news"        "bbc news"
"weather channel"   "accuweather"        "weather underground"
"news app free"     "free news website"  "news without paywall"
```

⚠️ Bidding on a competitor's brand **as a keyword** is allowed. Putting their name **in the
ad text** is not, and Google will act on a trademark complaint. That is the entire reason
group 3 has no DKI: DKI would paste "accuweather" or "cnn news" straight into our headline.

⚠️ Brand terms are navigational — someone typing "cnn news" wants CNN. Expect a low CTR and
a high CPC. Start group 3 at a **small separate budget** and judge it on its own numbers, not
on the campaign average.

---

## Negatives (start here, one shared account list)

```
jobs, career, salary, hiring, login, sign in, account, subscription, cancel,
stock, share price, wikipedia, meaning, definition, download, apk, app store,
paper, newspaper delivery, obituary, obituaries, crossword, puzzle, lottery
```

⚠️ Do not blanket-block `weather` inside the news group or `news` inside the weather group —
they are load-bearing in real queries on both sides.

---

## Before spend

1. **Pull real volumes and top-of-page bids in Keyword Planner.** Nothing here carries a
   verified volume yet — the list is built on intent and structure, not on a data pull.
2. **The domain is not live.** `theeveningbrief.com` is unregistered; the site currently runs
   on a herokuapp.com URL, which is not a credible final URL for a news brand.
3. Confirm the landing page states what the site is — an aggregator — above the fold.
