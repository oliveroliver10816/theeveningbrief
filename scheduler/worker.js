/**
 * Keeps The Evening Brief fetching on a fixed timetable.
 *
 * Heroku Scheduler can only be configured by clicking around its dashboard —
 * there is no API for it — so the schedule lives here instead, where it can be
 * deployed and changed without anyone touching a control panel.
 *
 * Every 10 minutes it calls the site's own token-guarded fetch endpoint. Once an
 * hour it also asks for a prune, to drop stories past the retention window.
 */
export default {
  async scheduled(event, env, ctx) {
    const base = (env.SITE_URL || '').replace(/\/+$/, '')
    if (!base || !env.INGEST_TOKEN) {
      console.log('scheduler: SITE_URL or INGEST_TOKEN not set — nothing to do')
      return
    }

    // The hourly tick also prunes. cron is "*/10 * * * *"; minute 0 is the hour.
    const prune = new Date(event.scheduledTime).getUTCMinutes() < 10 ? 1 : 0
    const url = `${base}/admin/ingest?token=${encodeURIComponent(env.INGEST_TOKEN)}${prune ? '&prune=1' : ''}`

    // Two attempts. A dyno that has gone to sleep answers the first request
    // slowly or not at all, and a single timeout should not cost us the tick.
    for (let attempt = 1; attempt <= 2; attempt++) {
      try {
        const res = await fetch(url, {
          method: 'POST',
          headers: { 'User-Agent': 'TheEveningBrief-Scheduler/1.0' },
          signal: AbortSignal.timeout(55000),
        })
        const body = await res.text()
        console.log(`scheduler: attempt ${attempt} HTTP ${res.status} ${body.slice(0, 300)}`)
        if (res.ok) return
      } catch (e) {
        console.log(`scheduler: attempt ${attempt} failed — ${e.name}: ${e.message}`)
      }
    }
    console.log('scheduler: both attempts failed this tick')
  },

  // A plain GET is a manual "run it now", so the schedule can be checked
  // without waiting for the next tick. It carries no token itself.
  async fetch(request, env, ctx) {
    if (new URL(request.url).pathname !== '/run') {
      return new Response('The Evening Brief scheduler. POST nothing here; it runs on a cron.\n', {
        headers: { 'Content-Type': 'text/plain' },
      })
    }
    await this.scheduled({ scheduledTime: Date.now() }, env, ctx)
    return new Response('tick requested — see the Worker log for the result\n', {
      headers: { 'Content-Type': 'text/plain' },
    })
  },
}
