<?php

namespace Native\Mobile\Edge\Web\Protocol;

use Illuminate\Http\JsonResponse;

/**
 * Live updates for the web target — the browser half of `native:watch`.
 *
 * The device half of hot reload pushes changed files over adb/devicectl
 * and drops a reload signal the runtime notices. A browser needs no push:
 * it is already reading the same working copy the editor writes to. So the
 * whole mechanism is one cheap question — "has anything I render from
 * changed since the frame I'm showing?" — answered by a TOKEN the client
 * polls and compares to the one its current frame was rendered with.
 *
 * The token folds together two independent sources, so it moves whichever
 * way a change arrives:
 *
 *   1. A filesystem fingerprint (max mtime + file count over the app's
 *      source paths). Needs no watcher at all: `native:watch android` in
 *      one terminal syncs the device, and the browser sees the same saves
 *      land on disk — both surfaces update from one command, which is the
 *      point. ~1ms for a few hundred files.
 *   2. An explicit signal file (storage/framework/edge-hot.json), written
 *      by `edge:watch` (and anything else that wants to force browsers to
 *      re-render — a CSS rebuild, a manual reload key). Carries the
 *      changed path so the client can log what moved.
 *
 * Deliberately dev-only (see enabled()): the endpoint discloses source
 * mtimes and invites a poll per open tab. Off by default unless
 * app.debug is on, and overridable via `config('nativephp-web.hot')`.
 */
class EdgeHot
{
    /**
     * Signal file, relative to storage_path(). Carries two independent
     * facts, deliberately on different channels:
     *
     *   mtime    — the watcher's HEARTBEAT (touched every tick). Freshness
     *              is what turns the whole feature on, so a heartbeat must
     *              NOT move the token, or every tick would be a reload.
     *   contents — the last CHANGE ({ts, file}), which does move it.
     */
    public const SIGNAL_FILE = 'framework/edge-hot.json';

    /**
     * How stale the heartbeat may get before the watcher counts as gone.
     * Generous next to the watcher's ~500ms tick: a busy `edge:css`
     * rebuild blocks the loop for seconds, and a browser standing down
     * mid-rebuild would be exactly wrong.
     */
    public const HEARTBEAT_TTL = 15;

    /** Client poll cadence when nothing is configured. */
    public const DEFAULT_INTERVAL = 750;

    /**
     * Source paths whose contents a rendered frame depends on, relative to
     * base_path(). Deliberately tighter than core's device watch list:
     * this is scanned on every poll, so it covers what changes a SCREEN
     * (PHP, Blade, routes, config, fonts) and nothing that merely changes
     * with app traffic.
     */
    protected const DEFAULT_PATHS = [
        'app',
        'routes',
        'config',
        'database/migrations',
        'resources/views',
        'resources/fonts',
        'resources/css',
    ];

    /**
     * Individual files outside those trees that a frame depends on — the
     * built Tailwind sheet, so `edge:css` rebuilds reach open tabs.
     */
    protected const EXTRA_FILES = [
        'public/vendor/edge/app.css',
    ];

    /**
     * Extensions whose mtime tracks app TRAFFIC rather than app SOURCE.
     * Including any of these would put the browser in a reload loop (the
     * poll writes a session, the session bumps the file, the token moves).
     */
    protected const IGNORED_EXTENSIONS = [
        'sqlite', 'sqlite3', 'db', 'journal', 'log', 'lock', 'cache',
        'tmp', 'swp', 'swo', 'swx',
    ];

    /**
     * Live updates exist only while you are actively developing — which
     * means while a WATCHER is running, not merely while debug is on. A
     * page rendered with no watcher arms nothing: no socket, no timer, no
     * requests. Stop watching and open tabs stand down on their own.
     *
     * `config('nativephp-web.hot')` overrides in both directions: `false`
     * to opt out entirely, `true` to force it on (useful when driving the
     * signal file from something other than a watcher).
     */
    public static function enabled(): bool
    {
        $configured = config('nativephp-web.hot');

        if ($configured !== null) {
            return (bool) $configured;
        }

        return static::watching();
    }

    /**
     * Should the poll route exist at all?
     *
     * Broader than enabled() on purpose: a tab whose watcher has just
     * stopped needs somewhere to ask "are you still there?" and get a
     * civil no. Without the route it gets a 404, which is indistinguishable
     * from a broken deploy — so it keeps retrying instead of standing
     * down, which is exactly the idle traffic this feature promises not
     * to generate. Still dev-only: no debug, no route.
     */
    public static function routable(): bool
    {
        if (config('nativephp-web.hot') === false) {
            return false;
        }

        return static::enabled() || (bool) config('app.debug');
    }

    /**
     * Is a watcher publishing a heartbeat right now?
     *
     * Freshness rather than existence: watchers get killed, crash, and
     * get Ctrl+C'd, and none of those paths can be trusted to clean up
     * after themselves. A stale file simply ages out.
     */
    public static function watching(): bool
    {
        $hb = (int) (static::readSignal()['hb'] ?? 0);

        return $hb > 0 && (round(microtime(true) * 1000) - $hb) < static::HEARTBEAT_TTL * 1000;
    }

    /** Port of the running reload server, when it published one. */
    public static function wsPort(): ?int
    {
        $port = (int) (static::readSignal()['ws'] ?? 0);

        return $port > 0 ? $port : null;
    }

    /** PID of the running reload server, for stopping it. */
    public static function serverPid(): ?int
    {
        $pid = (int) (static::readSignal()['pid'] ?? 0);

        return $pid > 0 ? $pid : null;
    }

    /**
     * Retire the heartbeat without disturbing the last change (`ts`), so
     * a tab mid-reload isn't left thinking nothing happened.
     */
    public static function clearServerState(): void
    {
        $path = storage_path(static::SIGNAL_FILE);

        if (! is_file($path)) {
            return;
        }

        @file_put_contents($path, json_encode([
            ...static::readSignal(),
            'hb' => 0,
            'ws' => null,
            'pid' => null,
        ]));
    }

    /**
     * Publish a heartbeat from a watcher that isn't the reload server
     * (`edge:watch` without a socket, say) — enough to turn the HTTP
     * fallback on for as long as it keeps calling.
     */
    public static function heartbeat(): void
    {
        static::write(['hb' => (int) round(microtime(true) * 1000)]);
    }

    /** Client poll cadence in ms, clamped to something sane. */
    public static function interval(): int
    {
        $ms = (int) config('nativephp-web.hot_interval', static::DEFAULT_INTERVAL);

        return max(100, min($ms, 60_000));
    }

    /**
     * The `hot` key in #edge-state: everything the client runtime needs to
     * start polling, or null when the feature is off (the runtime then
     * never arms its timer — no endpoint, no cost).
     */
    public static function clientState(): ?array
    {
        if (! static::enabled()) {
            return null;
        }

        return [
            // Preferred transport: one socket per tab, pushed to. The
            // client builds the URL from the page's own hostname, so a
            // site served as native.test or localhost both resolve.
            'ws' => static::wsPort(),
            // Fallback for tabs that can't hold a socket — an https page
            // may refuse ws://, and a proxy may not upgrade. Only ever
            // used after a socket has actually failed.
            'endpoint' => EdgeEndpoint::prefix().'/hot',
            'token' => static::token(),
            'interval' => static::interval(),
        ];
    }

    /**
     * GET {prefix}/hot — the poll. Intentionally routed WITHOUT the `web`
     * middleware group: a session-backed route would take the session file
     * lock on every tick and serialize the user's real updates behind the
     * poll. Nothing here reads request state, so there is nothing to
     * protect with CSRF either.
     */
    public function poll(): JsonResponse
    {
        $signal = static::readSignal();

        return response()->json([
            'token' => static::token(),
            'file' => $signal['file'] ?? null,
            'at' => $signal['ts'] ?? null,
            // The client stops polling entirely when this goes false —
            // the watcher is gone, so there is nothing left to report.
            'watching' => static::watching(),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    /**
     * The change token for the current working copy. Readable on purpose
     * (`<maxMtime>.<fileCount>.<signalTs>`) — when live updates misbehave,
     * the first question is always "which half moved?".
     */
    public static function token(): string
    {
        [$mtime, $count] = static::fingerprint();

        $signal = static::readSignal();

        return sprintf('%d.%d.%d', $mtime, $count, (int) ($signal['ts'] ?? 0));
    }

    /**
     * Force every open tab to re-render on its next poll, regardless of
     * what the filesystem says. `edge:watch` calls this on each change so
     * browsers never wait on a scan, and it is the hook for anything else
     * that invalidates a frame without touching a watched path.
     */
    public static function signal(?string $file = null): void
    {
        // Fresh microtime per call: two saves inside the same second must
        // still move the token. Doubles as a heartbeat — anything
        // signalling changes is, by definition, watching.
        static::write([
            'ts' => (int) round(microtime(true) * 1000),
            'file' => $file,
            'hb' => (int) round(microtime(true) * 1000),
        ]);
    }

    /** Merge keys into the state file, leaving the rest intact. */
    protected static function write(array $merge): void
    {
        $path = storage_path(static::SIGNAL_FILE);

        @mkdir(dirname($path), 0755, true);
        @file_put_contents($path, json_encode([...static::readSignal(), ...$merge]));
    }

    /** @return array{0: int, 1: int} [newest mtime, file count] */
    protected static function fingerprint(): array
    {
        $newest = 0;
        $count = 0;

        foreach (static::watchPaths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            try {
                // No FOLLOW_SYMLINKS: public/storage points back into
                // storage/app, and user uploads are not source.
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ($iterator as $file) {
                    if (! $file->isFile() || static::ignores($file->getFilename())) {
                        continue;
                    }

                    $count++;
                    $mtime = $file->getMTime();

                    if ($mtime > $newest) {
                        $newest = $mtime;
                    }
                }
            } catch (\Throwable) {
                // Directory vanished or is unreadable mid-scan — a poll is
                // not the place to care; the next one sees the new shape.
                continue;
            }
        }

        foreach (static::EXTRA_FILES as $relative) {
            $mtime = @filemtime(base_path($relative));

            if ($mtime !== false) {
                $count++;

                if ($mtime > $newest) {
                    $newest = $mtime;
                }
            }
        }

        return [$newest, $count];
    }

    /** Absolute source paths to fingerprint. */
    public static function watchPaths(): array
    {
        $paths = config('nativephp-web.hot_paths');

        if (! is_array($paths) || $paths === []) {
            $paths = static::DEFAULT_PATHS;
        }

        return array_map(
            fn (string $path) => str_starts_with($path, '/') ? $path : base_path($path),
            $paths,
        );
    }

    public static function ignores(string $filename): bool
    {
        if (str_starts_with($filename, '.')) {
            return true;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, static::IGNORED_EXTENSIONS, true);
    }

    /** @return array{ts?: int, file?: ?string} */
    protected static function readSignal(): array
    {
        $raw = @file_get_contents(storage_path(static::SIGNAL_FILE));

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
