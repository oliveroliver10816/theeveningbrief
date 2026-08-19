THE EVENING BRIEF
=================
A self-updating news site. It gathers headlines from ~34 public newsroom feeds,
lays out a front page, and gives every story its own small page.


WHAT TO UPLOAD
--------------
Unzip, then upload EVERYTHING inside the folder to where the site should live:

  - a web root          ->  public_html/
  - or a subfolder      ->  public_html/news/

Both work with no edits. Nothing in the code assumes it is at the top level.

Make sure your FTP client is showing hidden files, or it will silently skip
.htaccess and data/.htaccess. Both matter.

Then open install.php in a browser (e.g. yoursite.com/install.php). It checks
everything and tells you, in plain English, what to fix if anything is wrong.
Delete install.php when you are done.


ON HEROKU (OR ANY PLATFORM THAT WIPES THE DISK)
-----------------------------------------------
Heroku deletes the dyno's filesystem on every restart, so a file-based database
there would lose every story roughly once a day. Give it a real database and
the problem disappears:

    heroku addons:create jawsdb:kitefin -a YOUR-APP-NAME

That is the whole fix. The add-on sets JAWSDB_URL, the site reads it
automatically on the next boot, and nothing in config.php needs editing.
Stories then persist across restarts and redeploys, exactly like a normal host.

It also recognises JAWSDB_MARIA_URL, CLEARDB_DATABASE_URL, DATABASE_URL and
MYSQL_URL, plus discrete MYSQL_HOST / MYSQL_DATABASE / MYSQL_USER /
MYSQL_PASSWORD variables. PostgreSQL is not supported by this build; if
DATABASE_URL points at Postgres it is ignored and logged, not silently broken.

Deploying by ZIP rather than git? Use theeveningbrief-heroku.zip — it is flat
and carries composer.json, composer.lock, Procfile and apache.conf. The plain
ZIP has a wrapper folder that hides those from the buildpack.


IT WORKS WITH NO DATABASE SETUP
-------------------------------
Out of the box it stores everything in a single SQLite file it creates itself
in data/. There is nothing to configure and no database to create.

If the host supplies a database in the environment (see the Heroku section
above) that always wins and you can ignore this.

To point it at MySQL yourself, open config.php and change:

    'driver' => 'sqlite',        ->   'driver' => 'mysql',

then fill in host / name / user / pass just below it. Create an empty database
first; the tables build themselves on the first page load.

data/ must be writable (755, or 775 if your host is strict). That is the one
permission that matters.


KEEPING IT UPDATED
------------------
Add a cron job in cPanel set to every 10 minutes:

    */10 * * * *   cd /home/USER/public_html && /usr/local/bin/php cron/ingest.php >/dev/null 2>&1

Replace the path with your own; cPanel's Cron Jobs page shows the correct PHP
binary. install.php prints the exact line for your server.

If you never set up cron the site still works — it refreshes itself when a
visitor arrives and the stories are more than 20 minutes old. Cron just makes
it predictable and keeps that cost off your visitors.


CHANGING THE NAME OR THE DOMAIN
-------------------------------
Everything visible comes from config.php, at the top:

    'name'    => 'The Evening Brief',
    'domain'  => 'theeveningbrief.com',
    'tagline' => '...',

Change those and the whole site follows — page titles, the masthead, the RSS
feed, the sitemap, the social preview. The name is not written anywhere else.


A NOTE ABOUT .htaccess
----------------------
It deliberately contains NO redirects. No force-HTTPS, no force-www, no
force-non-www, no hardcoded domain.

That is on purpose. A redirect to the final domain means that the moment you
upload this anywhere else — a CDN, a staging subdomain, a test folder, an IP —
the browser is thrown off to a domain that may not be serving yet, and you can
never see what you just uploaded. Worse, browsers cache those redirects, so it
keeps happening after the rule is removed.

The only rewrite in the file is internal: it hands unknown paths to index.php
without changing the address bar.

Once the site is confirmed working on its real domain, canonical-domain rules
(forcing https, or www vs non-www) belong in your host's or CDN's settings
panel — not in this file.

If your host ignores .htaccess entirely, the site detects that and switches to
?r=/section/us style links automatically. It will not break.


WHAT IT DOES AND DOES NOT PUBLISH
---------------------------------
Each card shows the publisher's own headline, the summary they put in their
feed, and a link back to them. Full article text is never reproduced. Every
article page says so and credits the newsroom. See the /sources page.


SECTIONS
--------
U.S. first, then International, then World, then Weather, then Recipes.
Business and markets are deliberately kept off the top of the front page and
appear only in a small strip lower down.

To add or remove a feed, edit app/Feeds.php — it is a plain list, not code.


TROUBLESHOOTING
---------------
Blank page or an error       -> open install.php; it names the cause.
No stories                   -> install.php -> "Fetch stories now". If that
                                fails, your host is blocking outbound HTTP.
Links 404 in a subfolder     -> normal if mod_rewrite is off; the site should
                                have switched to ?r= links by itself. If it did
                                not, set 'TEB_BASE' in your server environment
                                to the subfolder path, e.g. /news
Images missing               -> some publishers block hotlinking from certain
                                servers. Those cards fall back to a text card
                                by design; nothing is broken.
Status at a glance           -> /healthz returns JSON with the last fetch time
                                and any failing feeds.
