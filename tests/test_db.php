<?php

declare(strict_types=1);

/**
 * app/Db.php — storage layer.
 *
 * Everything here runs against a REAL temporary SQLite file (never data/), and the
 * MySQL leg runs for real too when a server is reachable, otherwise it says so and
 * skips rather than pretending to have tested it.
 */

require_once __DIR__ . '/lib.php';
teb_require_app('Db');

use TEB\Db;

/**
 * A PDO that counts transaction calls. This is how "the 500-row batch runs in ONE
 * transaction" becomes an assertion that can actually fail, instead of a claim.
 */
if (!class_exists('TebCountingPdo', false)) {
    class TebCountingPdo extends PDO
    {
        public int $begins = 0;
        public int $commits = 0;
        public int $rollbacks = 0;

        public function beginTransaction(): bool
        {
            $this->begins++;

            return parent::beginTransaction();
        }

        public function commit(): bool
        {
            $this->commits++;

            return parent::commit();
        }

        public function rollBack(): bool
        {
            $this->rollbacks++;

            return parent::rollBack();
        }
    }
}

/**
 * A PDOStatement that records the TYPE every parameter was bound with. This is how the
 * "LIMIT is bound as an integer" rule becomes testable on any driver: MySQL 8 with real
 * prepared statements rejects a string-bound LIMIT, MariaDB quietly coerces it, so the
 * bug would ship invisible from this box without an assertion on the bind itself.
 */
if (!class_exists('TebRecordingStatement', false)) {
    class TebRecordingStatement extends PDOStatement
    {
        /** @var array<int,array{0:mixed,1:mixed,2:int}> */
        public static array $binds = [];

        public function bindValue($param, $value, $type = PDO::PARAM_STR): bool
        {
            self::$binds[] = [$param, $value, $type];

            return parent::bindValue($param, $value, $type);
        }
    }
}

/**
 * A PDO whose exec() can be made to lose a migration race: the first statement matching
 * $raceOn fails the way a second concurrent request would. With $raceReal it fails the way
 * a broken host would instead, so the guard can be shown to swallow one and not the other.
 */
if (!class_exists('TebRacingPdo', false)) {
    class TebRacingPdo extends PDO
    {
        public string $raceOn = '';
        public bool $raceReal = false;
        public int $raced = 0;

        #[\ReturnTypeWillChange]
        public function exec($statement)
        {
            if ($this->raceOn !== '' && strpos((string) $statement, $this->raceOn) !== false) {
                $this->raced++;
                throw new PDOException(
                    $this->raceReal
                        ? 'SQLSTATE[HY000]: General error: 10 disk I/O error'
                        : 'SQLSTATE[HY000]: General error: 1 duplicate column name: guid_hash'
                );
            }

            return parent::exec($statement);
        }
    }
}

/**
 * A PDO whose prepared statements throw a NON-PDOException on execute. That is the case the
 * rollback used to miss: catching only PDOException let it escape with the write
 * transaction still open, holding the SQLite lock for the rest of the request.
 */
if (!class_exists('TebExplodingStatement', false)) {
    class TebExplodingStatement extends PDOStatement
    {
        public static string $on = '';

        #[\ReturnTypeWillChange]
        public function execute($params = null)
        {
            if (self::$on !== '' && strpos((string) $this->queryString, self::$on) !== false) {
                throw new LogicException('simulated non-PDO failure mid-batch');
            }

            return parent::execute($params);
        }
    }
}

if (!class_exists('TebExplodingPdo', false)) {
    class TebExplodingPdo extends PDO
    {
        public string $explodeOn = '';

        #[\ReturnTypeWillChange]
        public function prepare($query, $options = [])
        {
            TebExplodingStatement::$on = $this->explodeOn;
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TebExplodingStatement::class]);

            return parent::prepare($query, $options);
        }
    }
}

/** Fresh migrated SQLite database in a scratch directory. */
function teb_db_fresh(): PDO
{
    $dir = teb_tmp_dir('teb-db');
    $p   = Db::connect(['db' => ['driver' => 'sqlite', 'sqlite_path' => $dir . '/test.sqlite']]);
    Db::migrate($p);

    return $p;
}

/**
 * One fixed "now" for the whole run, so fixtures are fresh news relative to the real
 * clock (health and the 24h/7d windows read time(), and a hardcoded epoch would age).
 */
function teb_now(): int
{
    static $t = 0;
    if ($t === 0) {
        $t = (int) floor(microtime(true) * 1000);
    }

    return $t;
}

/** One article row in the shape TEB\Ingest hands to insertArticles(). */
function teb_row(array $over = []): array
{
    static $n = 0;
    $n++;

    return $over + [
        'source_id'    => 1,
        'source_slug'  => 'abc-us',
        'source_name'  => 'ABC News',
        'section'      => 'us',
        'guid'         => 'g' . $n,
        'url'          => 'https://example.com/story-' . $n,
        'title'        => 'Sample headline number ' . $n . ' about a harbour rescue',
        'summary'      => 'Summary text for story ' . $n . '.',
        'image_url'    => '',
        'published_at' => teb_now() - ($n * 60000),
        'fetched_at'   => teb_now(),
        'author'       => '',
    ];
}

/**
 * Open a MySQL connection if one is reachable, otherwise return null so the caller can
 * say it skipped. Never fakes a pass. Credentials come from TEB_TEST_MYSQL_* when set,
 * otherwise the two combinations a developer box normally has.
 */
function teb_mysql_connect(): ?PDO
{
    $host = getenv('TEB_TEST_MYSQL_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('TEB_TEST_MYSQL_PORT') ?: 3306);
    $sock = @fsockopen($host, $port, $errno, $errstr, 0.35);
    if ($sock === false) {
        return null;
    }
    fclose($sock);

    $name  = getenv('TEB_TEST_MYSQL_DB') ?: 'teb_test';
    $tries = [];
    if (getenv('TEB_TEST_MYSQL_USER') !== false) {
        $tries[] = [getenv('TEB_TEST_MYSQL_USER'), (string) getenv('TEB_TEST_MYSQL_PASS')];
    }
    $tries[] = ['teb', 'teb'];
    $tries[] = ['root', ''];

    foreach ($tries as [$user, $pass]) {
        try {
            return \TEB\Db::connect(['db' => [
                'driver' => 'mysql', 'host' => $host, 'port' => $port,
                'name' => $name, 'user' => $user, 'pass' => $pass,
            ]]);
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

return [

    // ------------------------------------------------------------------ schema

    'connect creates data/, enables WAL and a busy timeout' => function (): void {
        $dir  = teb_tmp_dir('teb-db');
        $data = $dir . '/nested/data';
        assertFalse(is_dir($data), 'precondition: the data directory does not exist yet');

        $p = Db::connect(['root' => $dir, 'db' => [
            'driver'      => 'sqlite',
            'sqlite_path' => 'nested/data/teb.sqlite',
        ]]);

        assertTrue(is_dir($data), 'connect() must create the data directory');
        assertFileExists($data . '/.htaccess');
        assertContains(
            'Require all denied',
            file_get_contents($data . '/.htaccess'),
            'a data/ created at runtime must deny web access to the database'
        );

        $mode = (string) $p->query('PRAGMA journal_mode')->fetchColumn();
        assertSame('wal', strtolower($mode), 'WAL is what stops "database is locked" during ingest');

        $busy = (int) $p->query('PRAGMA busy_timeout')->fetchColumn();
        assertGreaterThanOrEqual(3000, $busy, 'busy_timeout must be several seconds on shared hosting');
    },

    'a relative sqlite_path resolves against the project root, not the cwd' => function (): void {
        $dir = teb_tmp_dir('teb-db');
        $p   = Db::connect(['root' => $dir, 'db' => ['driver' => 'sqlite', 'sqlite_path' => 'data/teb.sqlite']]);
        Db::migrate($p);
        assertFileExists($dir . '/data/teb.sqlite');
    },

    'migrate is idempotent — twice on one handle and again on a reopen' => function (): void {
        $dir  = teb_tmp_dir('teb-db');
        $file = $dir . '/idem.sqlite';
        $cfg  = ['db' => ['driver' => 'sqlite', 'sqlite_path' => $file]];

        $p = Db::connect($cfg);
        Db::migrate($p);
        $after1 = Db::describe($p);

        Db::migrate($p);
        $after2 = Db::describe($p);
        assertEquals($after1, $after2, 'a second migrate() on the same handle changed the schema');

        // A different process arriving at an existing database must also be a no-op.
        $p2 = Db::connect($cfg);
        Db::migrate($p2);
        assertEquals($after1, Db::describe($p2), 'migrate() on an existing file changed the schema');

        // And it must have built the schema this workload actually needs.
        assertSame(['articles', 'ingest_runs', 'sources'], array_keys($after1));
        foreach (['id', 'source_id', 'section', 'guid_hash', 'url', 'title', 'title_key',
                  'summary', 'image_url', 'published_at', 'fetched_at'] as $col) {
            assertContains($col, $after1['articles']['columns'], 'articles is missing a column');
        }
        foreach (['ux_articles_guid_hash', 'ix_articles_section_pub', 'ix_articles_pub',
                  'ix_articles_title_key', 'ix_articles_source'] as $idx) {
            assertContains($idx, $after1['articles']['indexes'], 'a required index was not created');
        }
        assertContains('ux_sources_slug', $after1['sources']['indexes']);
    },

    'migrate adds a column that a previous version did not have' => function (): void {
        $dir  = teb_tmp_dir('teb-db');
        $file = $dir . '/upgrade.sqlite';
        $p    = Db::connect(['db' => ['driver' => 'sqlite', 'sqlite_path' => $file]]);

        // Stand in for a database written by an older build.
        $p->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL)');
        $p->exec("INSERT INTO articles (url) VALUES ('https://example.com/old')");

        Db::migrate($p);

        $cols = Db::describe($p)['articles']['columns'];
        assertContains('guid_hash', $cols, 'migrate() must add missing columns to an existing table');
        assertContains('title_key', $cols);
        assertSame(1, (int) $p->query('SELECT COUNT(*) FROM articles')->fetchColumn(), 'existing rows survived');
    },

    'migrate survives losing the upgrade race to another request' => function (): void {
        // Two first requests can arrive together, both read the catalogue, both decide a
        // column is missing, and the loser gets "duplicate column name". The index creation
        // was guarded against exactly this; the ADD COLUMN beside it was not, so the loser
        //500'd the front page. Simulated deterministically by making the ALTER lose.
        $dir = teb_tmp_dir('teb-db');
        $p   = new TebRacingPdo('sqlite:' . $dir . '/race.sqlite', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $p->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL)');

        $p->raceOn = 'ADD COLUMN';
        Db::migrate($p);   // must not throw
        assertGreaterThan(0, $p->raced, 'the simulated race never fired — this test proved nothing');

        // The loser did not create the columns, so it must have been the winner that did:
        // re-run with the race off and confirm the schema completes.
        $p->raceOn = '';
        Db::migrate($p);
        $cols = Db::describe($p)['articles']['columns'];
        assertContains('guid_hash', $cols);
        assertContains('title_key', $cols);

        // A REAL error must still escape — the guard must not swallow everything.
        $p->raceOn   = 'ADD COLUMN';
        $p->raceReal = true;
        $p2 = new TebRacingPdo('sqlite:' . $dir . '/race2.sqlite', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $p2->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, url TEXT NOT NULL)');
        $p2->raceOn   = 'ADD COLUMN';
        $p2->raceReal = true;
        assertThrows(
            static function () use ($p2): void {
                Db::migrate($p2);
            },
            'disk I/O error',
            'a genuine failure must not be mistaken for a lost race'
        );
    },

    'a batch that blows up mid-write never leaves the write lock held' => function (): void {
        // An open SQLite write transaction locks the database for the rest of the request:
        // every other visitor then meets "database is locked". The rollback used to be
        // reached only for PDOException, so anything else escaped with the lock held.
        $dir = teb_tmp_dir('teb-db');
        $p   = new TebExplodingPdo('sqlite:' . $dir . '/boom.sqlite', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        Db::migrate($p);

        $p->explodeOn = 'INSERT INTO articles';
        assertThrows(
            static function () use ($p): void {
                Db::insertArticles($p, [teb_row(['url' => 'https://example.com/boom', 'title' => 'A story that detonates on the way in'])]);
            },
            LogicException::class
        );
        assertFalse($p->inTransaction(), 'the transaction was left OPEN — the database stays locked');

        // Prove the handle is actually usable again, not merely reported clean.
        $p->explodeOn = '';
        assertSame(1, Db::insertArticles($p, [teb_row(['url' => 'https://example.com/after-boom', 'title' => 'The next batch still writes normally'])])['inserted']);

        // Same guarantee for the other transactional writer.
        $p->explodeOn = 'INSERT INTO sources';
        assertThrows(static function () use ($p): void {
            Db::upsertSources($p, [['slug' => 'boom', 'name' => 'Boom Wire', 'feed' => 'https://boom.test/rss']]);
        }, LogicException::class);
        assertFalse($p->inTransaction(), 'upsertSources left the transaction open');
    },

    'the unique index on guid_hash is real, not decorative' => function (): void {
        $p = teb_db_fresh();
        $p->exec("INSERT INTO articles (guid_hash, url, title) VALUES ('" . str_repeat('a', 64) . "', 'u', 't')");
        assertThrows(
            static function () use ($p): void {
                $p->exec("INSERT INTO articles (guid_hash, url, title) VALUES ('" . str_repeat('a', 64) . "', 'u2', 't2')");
            },
            PDOException::class,
            'a duplicate guid_hash must be rejected by the database itself'
        );
    },

    // ------------------------------------------------------------------ url + key normalisation

    'canonicalUrl strips every tracking parameter we named, and nothing else' => function (): void {
        $base = 'https://example.com/news/story';

        foreach ([
            'utm_source=twitter',
            'utm_medium=social',
            'utm_campaign=spring',
            'utm_term=news',
            'utm_content=card',
            'utm_id=99',
            'fbclid=IwAR123',
            'gclid=CjwKCA',
            'mc_cid=abc123',
            'ref=rss',
        ] as $param) {
            assertSame(
                $base,
                Db::canonicalUrl($base . '?' . $param),
                'not stripped: ' . $param
            );
        }

        // Case-insensitive on the key.
        assertSame($base, Db::canonicalUrl($base . '?UTM_SOURCE=x&FBCLID=y&Ref=z'));

        // Real parameters survive, in order.
        assertSame($base . '?id=5&page=2', Db::canonicalUrl($base . '?id=5&page=2'));
        assertSame($base . '?id=5', Db::canonicalUrl($base . '?utm_source=x&id=5&fbclid=y'));
        assertSame($base . '?id=5&page=2', Db::canonicalUrl($base . '?id=5&utm_campaign=x&page=2'));

        // A prefix match must not swallow an unrelated parameter.
        assertSame($base . '?reference=9', Db::canonicalUrl($base . '?reference=9'));
        assertSame($base . '?utmx=1', Db::canonicalUrl($base . '?utmx=1'));
        assertSame($base . '?referrer=x', Db::canonicalUrl($base . '?referrer=x'));

        // Trailing '?' and '#'.
        assertSame($base, Db::canonicalUrl($base . '?'));
        assertSame($base, Db::canonicalUrl($base . '#'));
        assertSame($base, Db::canonicalUrl($base . '?utm_source=x#'));
        assertSame($base . '#section-2', Db::canonicalUrl($base . '#section-2'), 'a real fragment is kept');

        // Scheme and host are case-insensitive; the path is not.
        assertSame('https://example.com/News', Db::canonicalUrl('HTTPS://Example.COM/News'));

        // Junk in, something sane out — never an exception.
        assertSame('', Db::canonicalUrl(''));
        assertSame('', Db::canonicalUrl('   '));
        assertSame('not a url', Db::canonicalUrl('not a url'));
        assertSame('https://example.com/a b', Db::canonicalUrl("https://example.com/a b\n"));
    },

    'canonicalUrl never invents or destroys the authority part of a URL' => function (): void {
        // '//' belongs to the AUTHORITY, not the scheme. Gluing it to the scheme rewrote
        // every authority-less URL into a DIFFERENT one, and dropped it from a
        // protocol-relative one — turning an absolute URL into a relative path that would
        // resolve against our own host.
        assertSame('//example.com/x', Db::canonicalUrl('//example.com/x'), 'protocol-relative must stay absolute');
        assertSame('//example.com/x', Db::canonicalUrl('//example.com/x?utm_source=rss'));
        assertSame('mailto:newsdesk@example.com', Db::canonicalUrl('mailto:newsdesk@example.com'));
        assertSame('javascript:alert(1)', Db::canonicalUrl('javascript:alert(1)'), 'a scheme with no authority must not gain one');
        assertSame('tel:+15550100', Db::canonicalUrl('tel:+15550100'));

        // …while every real article URL is untouched.
        assertSame('https://example.com/a', Db::canonicalUrl('https://example.com/a'));
        assertSame('https://example.com:8443/a', Db::canonicalUrl('https://example.com:8443/a'));
        assertSame('https://u:p@example.com/a', Db::canonicalUrl('https://u:p@example.com/a?gclid=1'));
        assertSame('http://example.com/', Db::canonicalUrl('http://example.com/'));

        // And a mangled scheme must not change the dedup hash of a good URL.
        assertNotSame(Db::guidHash('//example.com/x'), Db::guidHash('example.com/x'));
    },

    'guidHash is a sha256 hex that ignores tracking noise' => function (): void {
        $h = Db::guidHash('https://example.com/x?utm_source=rss');
        assertSame(64, strlen($h));
        assertMatches('/^[0-9a-f]{64}$/', $h);
        assertSame(Db::guidHash('https://example.com/x'), $h, 'utm noise must not change the hash');
        assertNotSame(Db::guidHash('https://example.com/y'), $h);
    },

    'titleKey collapses punctuation, drops stopwords and keeps 8 tokens' => function (): void {
        assertSame(
            Db::titleKey("Trump's Tariffs Hit the E.U., Officials Say"),
            Db::titleKey("Trump\u{2019}s tariffs hit the E.U. \u{2014} officials say"),
            'the same headline punctuated differently must key identically'
        );
        assertSame(Db::titleKey('US Open'), Db::titleKey('U.S. Open'));
        assertSame(Db::titleKey('Feds cut rates'), Db::titleKey("Fed's cut rates"));

        // A different story must NOT key the same — otherwise dedup eats the news.
        assertNotSame(
            Db::titleKey("Trump's Tariffs Hit the E.U., Officials Say"),
            Db::titleKey("Trump's Tariffs Hit the U.K., Officials Say")
        );

        // Eight tokens, stopwords removed.
        assertSame(
            'one two three four five six seven eight',
            Db::titleKey('The one and two of three, four; five! six? seven the eight nine ten')
        );

        // A headline of nothing but stopwords must not key to '' — an empty key would
        // soft-dedup every such headline into one.
        assertNotSame('', Db::titleKey('It Is What It Is'));
        assertNotSame('', Db::titleKey('To be or not to be'));
        assertSame('', Db::titleKey('   '), 'only an empty title keys to empty');
    },

    // ------------------------------------------------------------------ dedup

    'an exact duplicate URL is skipped, and the counts are honest' => function (): void {
        $p = teb_db_fresh();

        $a = teb_row(['url' => 'https://example.com/a', 'title' => 'Harbour rescue leaves four crew stranded overnight']);
        $r = Db::insertArticles($p, [$a]);
        assertSame(1, $r['inserted']);
        assertSame(0, $r['skipped']);

        // Same URL again, in a later batch.
        $r2 = Db::insertArticles($p, [$a]);
        assertSame(0, $r2['inserted'], 'the same URL must not be inserted twice');
        assertSame(1, $r2['skipped']);
        assertSame(1, $r2['dup_guid']);

        // Same URL twice inside ONE batch.
        $r3 = Db::insertArticles($p, [
            teb_row(['url' => 'https://example.com/b', 'title' => 'Bridge closes for repairs after inspection finds cracks']),
            teb_row(['url' => 'https://example.com/b?utm_source=rss', 'title' => 'Something else entirely different happens today']),
        ]);
        assertSame(1, $r3['inserted'], 'the tracking-tagged twin is the same URL');
        assertSame(1, $r3['dup_guid']);

        assertSame(2, Db::countArticles($p));
    },

    'one story from two sources with different punctuation is soft-deduped' => function (): void {
        $p = teb_db_fresh();

        $r = Db::insertArticles($p, [
            teb_row([
                'source_slug' => 'abc-us',
                'source_name' => 'ABC News',
                'url'         => 'https://abcnews.go.com/politics/tariffs-eu',
                'title'       => "Trump's Tariffs Hit the E.U., Officials Say",
            ]),
            teb_row([
                'source_slug' => 'srn',
                'source_name' => 'SRN News',
                'url'         => 'https://www.srnnews.com/trump-tariffs-eu/',
                'title'       => "Trump\u{2019}s tariffs hit the E.U. \u{2014} officials say",
            ]),
        ]);

        assertSame(1, $r['inserted'], 'the syndicated twin must not produce a second card');
        assertSame(1, $r['dup_title']);
        assertSame(0, $r['dup_guid'], 'the URLs differ — this is the SOFT path, not the hard one');

        // A genuinely different story from the same desk still lands.
        $r2 = Db::insertArticles($p, [teb_row([
            'url'   => 'https://www.srnnews.com/trump-tariffs-uk/',
            'title' => "Trump's Tariffs Hit the U.K., Officials Say",
        ])]);
        assertSame(1, $r2['inserted'], 'soft dedup must not swallow a different story');
        assertSame(2, Db::countArticles($p));
    },

    'soft dedup only looks back over a recent window' => function (): void {
        $p     = teb_db_fresh();
        $now   = teb_now();
        $title = 'City council approves the new waterfront transit plan';

        Db::insertArticles($p, [teb_row([
            'url'          => 'https://example.com/old-take',
            'title'        => $title,
            'published_at' => $now - (10 * 86400000),
            'fetched_at'   => $now - (10 * 86400000),
        ])], ['now_ms' => $now]);

        // Same headline, ten days later: that is a new story, not a duplicate.
        $r = Db::insertArticles($p, [teb_row([
            'url'          => 'https://example.com/new-take',
            'title'        => $title,
            'published_at' => $now,
        ])], ['now_ms' => $now]);

        assertSame(1, $r['inserted'], 'a headline outside the soft window must not be deduped');
        assertSame(2, Db::countArticles($p));
    },

    'a row with no url or no title is rejected, not stored' => function (): void {
        $p = teb_db_fresh();
        $r = Db::insertArticles($p, [
            teb_row(['url' => '']),
            teb_row(['title' => '   ']),
            ['nonsense' => true],
            teb_row(['url' => 'https://example.com/ok', 'title' => 'A perfectly good headline about the docks']),
        ]);
        assertSame(1, $r['inserted']);
        assertSame(3, $r['invalid']);
        assertSame(3, $r['skipped']);
        assertSame(4, $r['inserted'] + $r['skipped'], 'inserted + skipped must equal the rows handed in');
    },

    'a broken publication date is repaired rather than trusted' => function (): void {
        $p   = teb_db_fresh();
        $now = teb_now();
        Db::insertArticles($p, [
            teb_row(['url' => 'https://example.com/zero', 'title' => 'Feed with no date at all on its items', 'published_at' => 0]),
            teb_row(['url' => 'https://example.com/future', 'title' => 'Feed whose clock is set five years ahead', 'published_at' => $now + (5 * 365 * 86400000)]),
            teb_row(['url' => 'https://example.com/1970', 'title' => 'Feed emitting a nineteen seventy timestamp', 'published_at' => 1000]),
        ], ['now_ms' => $now]);

        // Count first: a bare foreach over the result would have passed on an EMPTY table,
        // so a change that stopped inserting anything at all would have read as green.
        $stored = Db::recentArticles($p, ['limit' => 10]);
        assertCount(3, $stored, 'all three rows must be stored — a repaired date is not a rejected row');
        foreach ($stored as $row) {
            assertSame($now, $row['published_at'], 'an implausible date must fall back to now: ' . $row['url']);
        }

        // A plausible date is left alone — otherwise "repair" could just be "overwrite everything".
        $good = $now - (3 * 3600000);
        Db::insertArticles($p, [teb_row([
            'url' => 'https://example.com/good-date', 'title' => 'A story whose feed keeps an honest clock',
            'published_at' => $good,
        ])], ['now_ms' => $now]);
        assertSame($good, Db::recentArticles($p, ['limit' => 10])[3]['published_at'], 'a valid date must survive');
    },

    // ------------------------------------------------------------------ batch behaviour

    'a 500-row batch inserts inside exactly one transaction' => function (): void {
        $dir  = teb_tmp_dir('teb-db');
        $file = $dir . '/batch.sqlite';
        // Same options Db::connect uses, on a PDO subclass that counts transactions.
        $p = new TebCountingPdo('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $p->exec('PRAGMA busy_timeout = 8000');
        $p->exec('PRAGMA journal_mode = WAL');
        Db::migrate($p);

        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = teb_row([
                'url'   => 'https://example.com/batch/' . $i,
                'title' => 'Batch story ' . $i . ' concerning the northern harbour rebuild',
            ]);
        }

        $t0  = microtime(true);
        $res = Db::insertArticles($p, $rows);
        $ms  = (microtime(true) - $t0) * 1000;

        assertSame(500, $res['inserted']);
        assertSame(0, $res['skipped']);
        assertSame(500, Db::countArticles($p));

        assertSame(1, $p->begins, '500 rows must open exactly one transaction, not 500');
        assertSame(1, $p->commits);
        assertSame(0, $p->rollbacks);
        assertLessThan(4000.0, $ms, '500 rows took ' . round($ms) . 'ms — the transaction is not doing its job');
    },

    'a duplicate in the middle of a batch does not stop the rest' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [teb_row(['url' => 'https://example.com/dup', 'title' => 'Already stored headline about the ferry terminal'])]);

        $rows = [];
        for ($i = 0; $i < 20; $i++) {
            $rows[] = teb_row([
                'url'   => 'https://example.com/mid/' . $i,
                'title' => 'Mid batch story ' . $i . ' regarding the ferry terminal works',
            ]);
        }
        // The duplicate sits in the middle.
        array_splice($rows, 10, 0, [teb_row(['url' => 'https://example.com/dup', 'title' => 'Already stored headline about the ferry terminal'])]);

        $r = Db::insertArticles($p, $rows);
        assertSame(20, $r['inserted'], 'rows after the duplicate must still be written');
        assertSame(1, $r['skipped']);
        assertSame(21, Db::countArticles($p));
    },

    'an empty batch is a no-op that still returns honest counts' => function (): void {
        $p = teb_db_fresh();
        $r = Db::insertArticles($p, []);
        assertSame(['inserted' => 0, 'skipped' => 0, 'dup_guid' => 0, 'dup_title' => 0, 'invalid' => 0], $r);
        assertSame(0, Db::countArticles($p));
    },

    'four concurrent writers do not produce "database is locked"' => function (): void {
        // This is the failure this module's SQLite settings exist to prevent: an inline
        // ingest running while visitors hit the site. WAL + busy_timeout + one transaction
        // per batch is the fix, and nothing else here would notice if any of the three
        // were removed — so it is tested with REAL concurrent processes, not a claim.
        $dir  = teb_tmp_dir('teb-db');
        $file = $dir . '/concurrent.sqlite';
        $p    = Db::connect(['db' => ['driver' => 'sqlite', 'sqlite_path' => $file]]);
        Db::migrate($p);

        $worker = $dir . '/worker.php';
        file_put_contents($worker, '<' . '?php
declare(strict_types=1);
require ' . var_export(teb_root() . '/app/Db.php', true) . ';
$tag  = $argv[1];
$file = $argv[2];
$p    = TEB\\Db::connect(["db" => ["driver" => "sqlite", "sqlite_path" => $file]]);
$rows = [];
for ($i = 0; $i < 250; $i++) {
    $rows[] = [
        "source_id" => 1, "source_slug" => $tag, "source_name" => $tag, "section" => "us",
        "url" => "https://example.com/" . $tag . "/" . $i,
        "title" => "Concurrent " . $tag . " story " . $i . " from the overnight desk",
        "summary" => "S", "published_at" => 1787000000000 + $i, "fetched_at" => 1787000000000,
    ];
}
$r = TEB\\Db::insertArticles($p, $rows);
// Read while the other writers are still going.
$seen = count(TEB\\Db::recentArticles($p, ["limit" => 5]));
fwrite(STDOUT, $r["inserted"] . ":" . $seen);
exit($r["inserted"] === 250 ? 0 : 1);
');

        $php   = PHP_BINARY;
        $procs = [];
        foreach (['alpha', 'bravo', 'charlie', 'delta'] as $tag) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $h = proc_open(
                escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . escapeshellarg($tag) . ' ' . escapeshellarg($file),
                $descriptors,
                $pipes
            );
            assertTrue(is_resource($h), 'could not start the concurrent writer');
            $procs[$tag] = [$h, $pipes];
        }

        $errors = [];
        foreach ($procs as $tag => [$h, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($h);
            if ($code !== 0 || $err !== '') {
                $errors[] = $tag . ' exit=' . $code . ' stderr=' . trim($err) . ' stdout=' . trim($out);
            }
        }

        assertSame([], $errors, 'a concurrent writer failed — this is the "database is locked" bug');
        assertSame(1000, Db::countArticles($p), 'every row from all four writers must have landed');
    },

    // ------------------------------------------------------------------ reads

    'recentArticles filters by section, orders newest first and honours limit' => function (): void {
        $p   = teb_db_fresh();
        $now = teb_now();
        $rows = [];
        foreach (['us', 'us', 'world', 'international', 'business'] as $i => $sect) {
            $rows[] = teb_row([
                'section'      => $sect,
                'url'          => 'https://example.com/s/' . $i,
                'title'        => 'Story ' . $i . ' filed from the ' . $sect . ' desk this evening',
                'published_at' => $now - ($i * 3600000),
                'image_url'    => $i % 2 === 0 ? 'https://cdn.example.com/' . $i . '.jpg' : '',
            ]);
        }
        Db::insertArticles($p, $rows, ['now_ms' => $now]);

        $us = Db::recentArticles($p, ['section' => 'us']);
        assertCount(2, $us);
        foreach ($us as $r) {
            assertSame('us', $r['section']);
        }
        assertGreaterThanOrEqual($us[1]['published_at'], $us[0]['published_at'], 'newest first');

        assertCount(3, Db::recentArticles($p, ['section' => ['us', 'world']]));
        assertCount(4, Db::recentArticles($p, ['exclude_sections' => ['business']]));
        assertCount(2, Db::recentArticles($p, ['limit' => 2]));
        assertCount(3, Db::recentArticles($p, ['has_image' => true]));
        assertCount(3, Db::recentArticles($p, ['limit' => 3, 'offset' => 2]));

        $first = Db::recentArticles($p, ['limit' => 1])[0];
        assertCount(4, Db::recentArticles($p, ['exclude_ids' => [$first['id']]]));

        // Types must be right for Compose: ints as ints, weights as floats.
        assertTrue(is_int($first['id']) && is_int($first['published_at']), 'ids and times must be ints');
        assertTrue(is_float($first['source_weight']), 'source_weight must be a float');
    },

    'a sitemap-sized read is not silently truncated at the page ceiling' => function (): void {
        $p    = teb_db_fresh();
        $rows = [];
        for ($i = 0; $i < 620; $i++) {
            $rows[] = teb_row([
                'url'   => 'https://example.com/sitemap/' . $i,
                'title' => 'Sitemap story ' . $i . ' filed from the regional bureau',
            ]);
        }
        assertSame(620, Db::insertArticles($p, $rows)['inserted']);
        assertCount(620, Db::recentArticles($p, ['limit' => 5000]), 'a sitemap build must not stop at 500');
        assertCount(500, Db::recentArticles($p, ['limit' => 500]));
        assertCount(1, Db::recentArticles($p, ['limit' => 0]), 'a nonsense limit is clamped, never unbounded');
        assertCount(620, Db::recentArticles($p, ['limit' => 999999]), 'an absurd limit is capped at the ceiling, not honoured');
    },

    'a read still works when the source row is missing, and picks up its weight when it is not' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [teb_row([
            'source_id'   => 0,
            'source_slug' => 'orphan',
            'source_name' => 'Orphan Wire',
            'url'         => 'https://example.com/orphan',
            'title'       => 'A story whose source row has not been registered yet',
        ])]);

        $r = Db::recentArticles($p, ['limit' => 1])[0];
        assertSame('Orphan Wire', $r['source_name'], 'the denormalised name is the fallback');
        assertSame(1.0, $r['source_weight'], 'a missing source must not yield a NULL weight');

        Db::upsertSources($p, [[
            'slug' => 'abc-us', 'name' => 'ABC News', 'feed' => 'https://feeds.abcnews.com/abcnews/usheadlines',
            'section' => 'us', 'country' => 'us', 'tier' => 1, 'weight' => 1.4,
            'homepage' => 'https://abcnews.go.com/',
        ]]);
        $id = Db::sourceBySlug($p, 'abc-us')['id'];
        Db::insertArticles($p, [teb_row([
            'source_id' => $id, 'source_slug' => 'abc-us', 'source_name' => 'stale name',
            'url' => 'https://example.com/joined', 'title' => 'A story whose source row does exist in the table',
        ])]);

        $j = Db::recentArticles($p, ['source_id' => $id, 'limit' => 1])[0];
        assertSame('ABC News', $j['source_name'], 'the sources table wins over a stale denormalised name');
        assertSame(1.4, $j['source_weight']);
    },

    'integers are bound as integers — LIMIT is the one MySQL 8 rejects as a string' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [
            teb_row(['url' => 'https://example.com/b1', 'title' => 'One story about the northern light rail extension']),
            teb_row(['url' => 'https://example.com/b2', 'title' => 'Another story about the southern light rail extension']),
        ]);

        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS, [TebRecordingStatement::class]);
        TebRecordingStatement::$binds = [];
        $rows = Db::recentArticles($p, ['section' => 'us', 'limit' => 1, 'offset' => 0]);
        assertCount(1, $rows, 'the query still has to work');

        $binds = TebRecordingStatement::$binds;
        assertGreaterThanOrEqual(3, count($binds), 'section, limit and offset are all bound');
        foreach ($binds as [$pos, $value, $type]) {
            if (is_int($value)) {
                assertSame(
                    PDO::PARAM_INT,
                    $type,
                    'parameter ' . $pos . ' (' . $value . ') was bound as a string; MySQL 8 rejects that in LIMIT'
                );
            }
        }
        // The last two bound values are LIMIT and OFFSET, and both must be integers.
        $tail = array_slice($binds, -2);
        assertSame(1, $tail[0][1]);
        assertSame(PDO::PARAM_INT, $tail[0][2]);
        assertSame(PDO::PARAM_INT, $tail[1][2]);
        $p->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['PDOStatement']);
    },

    'articleById returns one row or null, never a fatal' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [teb_row(['url' => 'https://example.com/one', 'title' => 'The only story currently in the database'])]);
        $id = Db::recentArticles($p, ['limit' => 1])[0]['id'];

        $a = Db::articleById($p, $id);
        assertNotNull($a);
        assertSame('The only story currently in the database', $a['title']);
        assertNull(Db::articleById($p, 999999));
        assertNull(Db::articleById($p, 0));
        assertNull(Db::articleById($p, -3));
    },

    'relatedArticles prefers the same section and never returns the article itself' => function (): void {
        $p = teb_db_fresh();
        $rows = [];
        for ($i = 0; $i < 5; $i++) {
            $rows[] = teb_row(['section' => 'us', 'url' => 'https://example.com/us/' . $i, 'title' => 'United States story ' . $i . ' from the evening desk']);
        }
        for ($i = 0; $i < 5; $i++) {
            $rows[] = teb_row(['section' => 'world', 'url' => 'https://example.com/w/' . $i, 'title' => 'World story ' . $i . ' from the overnight desk']);
        }
        Db::insertArticles($p, $rows);

        $a   = Db::recentArticles($p, ['section' => 'us', 'limit' => 1])[0];
        $rel = Db::relatedArticles($p, $a, 4);

        assertCount(4, $rel);
        foreach ($rel as $r) {
            assertNotSame($a['id'], $r['id'], 'an article must never be related to itself');
            assertSame('us', $r['section'], 'same-section stories exist, so they must be used first');
        }

        // Only two US stories in total: the rest must be topped up from elsewhere.
        $p2 = teb_db_fresh();
        Db::insertArticles($p2, [
            teb_row(['section' => 'us', 'url' => 'https://example.com/only-us', 'title' => 'The single United States story on file tonight']),
            teb_row(['section' => 'world', 'url' => 'https://example.com/w1', 'title' => 'A world story about the northern shipping lanes']),
            teb_row(['section' => 'world', 'url' => 'https://example.com/w2', 'title' => 'Another world story about the southern shipping lanes']),
        ]);
        $a2 = Db::recentArticles($p2, ['section' => 'us', 'limit' => 1])[0];
        assertCount(2, Db::relatedArticles($p2, $a2, 4), 'related must top up across sections');
    },

    // ------------------------------------------------------------------ search

    'search treats a quote, a percent and an underscore as literal text' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [
            teb_row([
                'url'     => 'https://example.com/mayor',
                'title'   => 'Mayor\'s office says it is a "100% win" for the _south_ district',
                'summary' => 'The mayor spoke on Tuesday.',
            ]),
            teb_row(['url' => 'https://example.com/aardvark', 'title' => 'Aardvark spotted near the reservoir at dawn', 'summary' => 'Wildlife officers responded.']),
            teb_row(['url' => 'https://example.com/budget', 'title' => 'Budget vote delayed again in the state assembly', 'summary' => 'A percent of members abstained.']),
        ]);
        assertSame(3, Db::countArticles($p));

        // Positive control first — if this fails, every "returns nothing" below is vacuous.
        assertCount(1, Db::searchArticles($p, 'aardvark'), 'plain search must work');
        assertCount(1, Db::searchArticles($p, 'district'));

        // % is a literal, not a wildcard.
        $pct = Db::searchArticles($p, '100%');
        assertCount(1, $pct);
        assertSame('https://example.com/mayor', $pct[0]['url']);

        // A bare % must not match everything.
        assertCount(1, Db::searchArticles($p, '%'), 'a bare % matched more than the row containing one');

        // _ is a literal, not a single-character wildcard.
        assertCount(1, Db::searchArticles($p, '_south_'));
        assertCount(0, Db::searchArticles($p, 'a_rdvark'), 'underscore must not act as a wildcard');
        assertCount(0, Db::searchArticles($p, 'a%k'), 'percent must not act as a wildcard');

        // Quotes are data.
        assertCount(1, Db::searchArticles($p, "mayor's"));
        assertCount(1, Db::searchArticles($p, '"100%'));
    },

    'search cannot be injected and never throws' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [
            teb_row(['url' => 'https://example.com/1', 'title' => 'First story about the harbour lights at midnight']),
            teb_row(['url' => 'https://example.com/2', 'title' => 'Second story about the harbour ferries at dawn']),
        ]);

        foreach ([
            "' OR '1'='1",
            "'; DROP TABLE articles; --",
            "x' UNION SELECT 1,2,3 --",
            '\\',
            '%%%',
            '____',
            "\x00binary",
            str_repeat('a', 5000),
            "harbour' --",
            'harbour" OR 1=1 #',
        ] as $evil) {
            $res = Db::searchArticles($p, $evil);
            assertTrue(is_array($res), 'search must always return an array');
            assertLessThan(2, count($res), 'an injection attempt matched more than one row: ' . $evil);
        }

        // The table is still there and still holds both rows.
        assertSame(2, Db::countArticles($p), 'the articles table survived every injection attempt');

        // Empty and whitespace queries return nothing rather than everything.
        assertCount(0, Db::searchArticles($p, ''));
        assertCount(0, Db::searchArticles($p, '    '));

        // Multiple terms are ANDed.
        assertCount(1, Db::searchArticles($p, 'harbour ferries'));
        assertCount(2, Db::searchArticles($p, 'harbour'));
        assertCount(0, Db::searchArticles($p, 'harbour zeppelin'));
    },

    'search ranks a headline hit above a summary hit' => function (): void {
        $p = teb_db_fresh();
        Db::insertArticles($p, [
            teb_row(['url' => 'https://example.com/sum', 'title' => 'An unrelated headline about the county fair', 'summary' => 'Officials mentioned the levee repairs.', 'published_at' => teb_now()]),
            teb_row(['url' => 'https://example.com/hed', 'title' => 'Levee repairs begin ahead of the storm season', 'summary' => 'Work starts Monday.', 'published_at' => teb_now() - 100000000]),
        ]);
        $res = Db::searchArticles($p, 'levee');
        assertCount(2, $res);
        assertSame('https://example.com/hed', $res[0]['url'], 'the older headline match must outrank the newer summary match');
    },

    // ------------------------------------------------------------------ sources + maintenance

    'upsertSources inserts once, updates in place and keeps runtime state' => function (): void {
        $p     = teb_db_fresh();
        $feeds = [
            ['slug' => 'abc-us', 'name' => 'ABC News', 'feed' => 'https://feeds.abcnews.com/abcnews/usheadlines',
             'section' => 'us', 'country' => 'us', 'tier' => 1, 'weight' => 1.4, 'homepage' => 'https://abcnews.go.com/'],
            ['slug' => 'bbc-world', 'name' => 'BBC News', 'feed' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
             'section' => 'world', 'country' => 'gb', 'tier' => 1, 'weight' => 1.3, 'homepage' => 'https://www.bbc.co.uk/news'],
        ];
        Db::upsertSources($p, $feeds);
        Db::upsertSources($p, $feeds);
        assertCount(2, Db::sources($p), 'upsert must not duplicate on a second run');

        // Park a feed, then re-upsert: the park must survive.
        Db::recordFeedResult($p, 'bbc-world', false, 'timeout', teb_now(), 2);
        Db::recordFeedResult($p, 'bbc-world', false, 'timeout', teb_now() + 1000, 2);
        $bbc = Db::sourceBySlug($p, 'bbc-world');
        assertSame(2, $bbc['fail_count']);
        assertSame(0, $bbc['active'], 'a feed must be parked after the configured failure count');

        $feeds[1]['name'] = 'BBC News (World)';
        Db::upsertSources($p, $feeds);
        $bbc = Db::sourceBySlug($p, 'bbc-world');
        assertSame('BBC News (World)', $bbc['name'], 'metadata must update');
        assertSame(0, $bbc['active'], 're-registering a feed must not silently un-park it');
        assertCount(1, Db::sources($p, true), 'only one source is active while the other is parked');

        // A success revives it and clears the counter.
        Db::recordFeedResult($p, 'bbc-world', true, '', teb_now() + 2000);
        $bbc = Db::sourceBySlug($p, 'bbc-world');
        assertSame(0, $bbc['fail_count']);
        assertSame(1, $bbc['active']);
        assertSame('', $bbc['last_error']);
        assertCount(2, Db::sources($p, true), 'a successful fetch revives a parked feed');

        assertNull(Db::sourceBySlug($p, 'nope'));
    },

    'a feed is parked on exactly the configured failure, not one early' => function (): void {
        $p = teb_db_fresh();
        Db::upsertSources($p, [['slug' => 'flaky', 'name' => 'Flaky Wire', 'feed' => 'https://flaky.test/rss', 'section' => 'world']]);

        // The off-by-one this guards is driver-specific: MySQL evaluates SET assignments
        // left to right against already-updated values, SQLite against the original row.
        Db::recordFeedResult($p, 'flaky', false, 'timeout 1', teb_now(), 3);
        assertSame(1, Db::sourceBySlug($p, 'flaky')['fail_count']);
        assertSame(1, Db::sourceBySlug($p, 'flaky')['active'], 'one failure must not park a feed');

        Db::recordFeedResult($p, 'flaky', false, 'timeout 2', teb_now(), 3);
        assertSame(2, Db::sourceBySlug($p, 'flaky')['fail_count']);
        assertSame(1, Db::sourceBySlug($p, 'flaky')['active'], 'two failures out of three must not park a feed');

        Db::recordFeedResult($p, 'flaky', false, 'timeout 3', teb_now(), 3);
        assertSame(3, Db::sourceBySlug($p, 'flaky')['fail_count']);
        assertSame(0, Db::sourceBySlug($p, 'flaky')['active'], 'the third failure parks it');
        assertSame('timeout 3', Db::sourceBySlug($p, 'flaky')['last_error']);
    },

    'pruneOld deletes only what is past retention on BOTH dates' => function (): void {
        $p   = teb_db_fresh();
        $now = teb_now();
        $day = 86400000;

        Db::insertArticles($p, [
            teb_row(['url' => 'https://example.com/old', 'title' => 'Old story published and fetched forty days ago',
                     'published_at' => $now - (40 * $day), 'fetched_at' => $now - (40 * $day)]),
            teb_row(['url' => 'https://example.com/backdated', 'title' => 'Back dated story that only reached us today',
                     'published_at' => $now - (40 * $day), 'fetched_at' => $now]),
            teb_row(['url' => 'https://example.com/edge31', 'title' => 'Story from thirty one days ago on both clocks',
                     'published_at' => $now - (31 * $day), 'fetched_at' => $now - (31 * $day)]),
            teb_row(['url' => 'https://example.com/edge29', 'title' => 'Story from twenty nine days ago on both clocks',
                     'published_at' => $now - (29 * $day), 'fetched_at' => $now - (29 * $day)]),
            teb_row(['url' => 'https://example.com/fresh', 'title' => 'Story filed this evening from the city desk',
                     'published_at' => $now - 3600000, 'fetched_at' => $now]),
        ], ['now_ms' => $now]);
        assertSame(5, Db::countArticles($p));

        $deleted = Db::pruneOld($p, 30, $now);
        assertSame(2, $deleted, 'exactly the two rows past retention on both clocks');

        $left = array_column(Db::recentArticles($p, ['limit' => 50]), 'url');
        sort($left);
        assertEquals([
            'https://example.com/backdated',
            'https://example.com/edge29',
            'https://example.com/fresh',
        ], $left);

        // Running it again deletes nothing.
        assertSame(0, Db::pruneOld($p, 30, $now));
    },

    'ingest runs are recorded and read back, and health survives an empty database' => function (): void {
        $p = teb_db_fresh();

        $h = Db::health($p);
        assertSame(0, $h['articles']);
        assertSame(0, $h['sources']);
        assertNull($h['last_ingest'], 'health must not invent an ingest that never happened');
        assertNull($h['newest_article_at']);
        assertNull($h['stale_minutes']);

        Db::upsertSources($p, [['slug' => 'abc-us', 'name' => 'ABC News', 'feed' => 'https://feeds.abcnews.com/abcnews/usheadlines', 'section' => 'us']]);
        Db::insertArticles($p, [teb_row(['url' => 'https://example.com/h1', 'title' => 'A story to make the health report non empty'])]);
        Db::recordFeedResult($p, 'abc-us', false, 'HTTP 500 from the publisher', Db::nowMs());

        $id = Db::recordIngestRun($p, [
            'started_at'   => Db::nowMs() - 4000,
            'finished_at'  => Db::nowMs(),
            'run_mode'     => 'cli',
            'feeds_ok'     => 33,
            'feeds_failed' => 1,
            'inserted'     => 41,
            'skipped'      => 118,
            'errors'       => [['slug' => 'abc-us', 'error' => 'HTTP 500']],
            'notes'        => 'nightly',
        ]);
        assertGreaterThan(0, $id);

        $last = Db::lastIngestRun($p);
        assertSame(33, $last['feeds_ok']);
        assertSame(41, $last['inserted']);
        assertSame('cli', $last['run_mode']);
        assertTrue(is_array($last['errors']) && $last['errors'][0]['slug'] === 'abc-us', 'errors round-trip as an array');

        $h = Db::health($p);
        assertSame(1, $h['articles']);
        assertSame(1, $h['sources']);
        assertCount(1, $h['sources_failing']);
        assertSame('abc-us', $h['sources_failing'][0]['slug']);
        assertFalse($h['sources_failing'][0]['parked'], 'one failure is not a park');
        assertSame('sqlite', $h['driver']);
        assertNotNull($h['last_ingest']);
        assertLessThan(2, $h['stale_minutes']);
        assertArrayHasKey('us', $h['sections']);
    },

    'an over-long summary or image URL does not abort a batch' => function (): void {
        $p = teb_db_fresh();
        $r = Db::insertArticles($p, [
            teb_row([
                'url'       => 'https://example.com/long',
                'title'     => 'A story whose feed sent an enormous summary blob',
                'summary'   => str_repeat('word ', 4000),
                'image_url' => 'https://cdn.example.com/' . str_repeat('a', 1200) . '.jpg',
            ]),
            teb_row(['url' => 'https://example.com/after-long', 'title' => 'The story that comes after the enormous one']),
            teb_row(['url' => 'https://example.com/' . str_repeat('b', 1200), 'title' => 'A story with an absurd URL that cannot be stored']),
        ]);
        assertSame(2, $r['inserted']);
        assertSame(1, $r['invalid'], 'a URL too long for the column is rejected, not truncated into a 404');

        // Look the row up BY URL. Reading it by position silently pointed at the short
        // control row, which made both assertions below pass no matter what the code did:
        // deleting the summary cap AND the over-long-image drop outright left this test green.
        $byUrl = [];
        foreach (Db::recentArticles($p, ['limit' => 5]) as $a) {
            $byUrl[$a['url']] = $a;
        }
        assertArrayHasKey('https://example.com/long', $byUrl);
        assertArrayHasKey('https://example.com/after-long', $byUrl, 'the row after the enormous one must still land');

        $row = $byUrl['https://example.com/long'];
        assertGreaterThan(0, mb_strlen($row['summary']), 'the summary must be stored, not thrown away');
        assertLessThanOrEqual(4000, mb_strlen($row['summary']), 'the summary must be capped to fit the column');
        assertSame('', $row['image_url'], 'a truncated image URL would 404 — drop it and let the text card render');

        // And the control row is untouched by any of that capping.
        assertSame('', $byUrl['https://example.com/after-long']['image_url']);
    },

    // ------------------------------------------------------------------ mysql

    'MySQL: same logical schema, same behaviour (skipped when no server)' => function (): void {
        $m = teb_mysql_connect();
        if ($m === null) {
            echo "        [skip] no usable MySQL on 127.0.0.1:3306 — set TEB_TEST_MYSQL_* to run this leg\n";

            return;
        }

        // Only ever touch a database that is clearly a test database.
        $dbName = (string) $m->query('SELECT DATABASE()')->fetchColumn();
        if (strpos($dbName, 'test') === false) {
            echo '        [skip] refusing to migrate "' . $dbName . "\" — the name must contain 'test'\n";

            return;
        }
        // This leg DROPs and rebuilds fixed-name tables in a SHARED database, so two test
        // runs at once (the normal state of this build — several agents run the suite
        // together) tear each other's fixtures down mid-assertion and both report a FAIL
        // that is not a bug. Serialise on a MySQL named lock; if another run holds it,
        // skip honestly rather than emit a false failure OR a false pass.
        $lock = $m->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute(['teb_test_db_schema', 25]);
        if ((int) $lock->fetchColumn() !== 1) {
            echo "        [skip] another test run holds the MySQL fixture lock\n";

            return;
        }
        $release = static function (PDO $m): void {
            $st = $m->prepare('SELECT RELEASE_LOCK(?)');
            $st->execute(['teb_test_db_schema']);
            $st->fetchColumn();
        };

        $drop = static function (PDO $m): void {
            foreach (['articles', 'ingest_runs', 'sources'] as $t) {
                $m->exec('DROP TABLE IF EXISTS `' . $t . '`');
            }
        };

        try {
            $drop($m);

            // --- schema
            Db::migrate($m);
            $first = Db::describe($m);
            Db::migrate($m);
            assertEquals($first, Db::describe($m), 'migrate() is not idempotent on MySQL');
            assertEquals(Db::describe(teb_db_fresh()), $first, 'sqlite and mysql must land on the same logical schema');

            // --- dedup, both paths
            $r = Db::insertArticles($m, [
                teb_row(['url' => 'https://example.com/mysql-a', 'title' => "Trump's Tariffs Hit the E.U., Officials Say"]),
                teb_row(['url' => 'https://example.com/mysql-a?utm_source=rss', 'title' => 'A different headline at the same underlying URL']),
                teb_row(['url' => 'https://example.com/mysql-b', 'title' => "Trump\u{2019}s tariffs hit the E.U. \u{2014} officials say"]),
                teb_row(['url' => 'https://example.com/mysql-c', 'title' => 'Ferry services resume after the overnight storm passes', 'section' => 'world']),
            ]);
            assertSame(2, $r['inserted'], 'hard and soft dedup must behave identically on MySQL');
            assertSame(1, $r['dup_guid']);
            assertSame(1, $r['dup_title']);

            // --- 4-byte UTF-8 and an over-long summary under strict mode
            $r2 = Db::insertArticles($m, [teb_row([
                'url'     => 'https://example.com/mysql-utf8',
                'title'   => 'Storm warning issued for the coast 🌊 tonight',
                'summary' => str_repeat('naïve café ', 900),
            ])]);
            assertSame(1, $r2['inserted'], 'utf8mb4 and a long summary must not abort a MySQL batch');
            assertSame(3, Db::countArticles($m));

            // --- LIMIT/OFFSET are bound, and MySQL native prepares reject a string there
            assertCount(2, Db::recentArticles($m, ['limit' => 2]), 'LIMIT must be bound as an integer');
            assertCount(1, Db::recentArticles($m, ['limit' => 2, 'offset' => 2]));
            assertCount(1, Db::recentArticles($m, ['section' => 'world']));
            assertCount(2, Db::recentArticles($m, ['exclude_sections' => ['world']]));

            // --- LIKE ESCAPE behaves the same way on MySQL as on SQLite
            Db::insertArticles($m, [teb_row([
                'url'   => 'https://example.com/mysql-pct',
                'title' => 'Turnout rose by 100% in the _north_ ward last night',
            ])]);
            assertCount(1, Db::searchArticles($m, '100%'));
            assertCount(1, Db::searchArticles($m, '_north_'));
            assertCount(0, Db::searchArticles($m, 'f_rry'), 'underscore must be a literal on MySQL too');
            assertCount(0, Db::searchArticles($m, "' OR '1'='1"));
            assertCount(1, Db::searchArticles($m, 'ferry services'));
            assertSame(4, Db::countArticles($m), 'the table survived the injection attempt');

            // --- sources, health, retention
            Db::upsertSources($m, [['slug' => 'abc-us', 'name' => 'ABC News', 'feed' => 'https://feeds.abcnews.com/abcnews/usheadlines',
                                     'section' => 'us', 'country' => 'us', 'tier' => 1, 'weight' => 1.4, 'homepage' => 'https://abcnews.go.com/']]);
            Db::upsertSources($m, [['slug' => 'abc-us', 'name' => 'ABC News', 'feed' => 'https://feeds.abcnews.com/abcnews/usheadlines',
                                     'section' => 'us', 'country' => 'us', 'tier' => 1, 'weight' => 1.4, 'homepage' => 'https://abcnews.go.com/']]);
            assertCount(1, Db::sources($m), 'upsert must not duplicate on MySQL either');
            Db::recordFeedResult($m, 'abc-us', false, 'HTTP 500', Db::nowMs(), 3);
            assertSame(1, Db::sourceBySlug($m, 'abc-us')['active'], 'one failure of three must not park it on MySQL');
            Db::recordFeedResult($m, 'abc-us', false, 'HTTP 500', Db::nowMs(), 3);
            assertSame(1, Db::sourceBySlug($m, 'abc-us')['active'], 'MySQL applies SET left to right — this is where it parks one early');
            Db::recordFeedResult($m, 'abc-us', false, 'HTTP 500', Db::nowMs(), 3);
            assertSame(0, Db::sourceBySlug($m, 'abc-us')['active'], 'the third failure parks it on MySQL too');
            assertSame(3, Db::sourceBySlug($m, 'abc-us')['fail_count']);

            Db::recordIngestRun($m, ['run_mode' => 'cli', 'feeds_ok' => 1, 'feeds_failed' => 1, 'inserted' => 3, 'skipped' => 2, 'errors' => [['slug' => 'abc-us']]]);
            $h = Db::health($m);
            assertSame('mysql', $h['driver']);
            assertSame(4, $h['articles']);
            assertSame(3, $h['last_ingest']['inserted']);
            assertCount(1, $h['sources_failing']);

            $now = Db::nowMs();
            Db::insertArticles($m, [teb_row([
                'url' => 'https://example.com/mysql-old', 'title' => 'An old story that is well past the retention window',
                'published_at' => $now - (40 * 86400000), 'fetched_at' => $now - (40 * 86400000),
            ])], ['now_ms' => $now]);
            assertSame(1, Db::pruneOld($m, 30, $now), 'retention must delete exactly the expired row');
            assertSame(4, Db::countArticles($m));
        } finally {
            $drop($m);
            $release($m);
        }
    },
];
