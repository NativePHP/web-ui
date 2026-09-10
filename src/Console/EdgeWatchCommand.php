<?php

namespace Native\Mobile\Edge\Web\Console;

use Illuminate\Console\Command;
use Native\Mobile\Concerns\ManagesPollingWatcher;
use Native\Mobile\Edge\Web\Protocol\EdgeHot;
use Native\Mobile\Edge\Web\Server\ReloadServer;
use Native\Mobile\Support\Watch\WatchConsole;

/**
 * `native:watch` for the browser.
 *
 * The device watcher exists because a phone can't see your working copy:
 * files have to be pushed over adb/devicectl and the runtime told to
 * reload. A browser has neither problem, so open tabs already follow along
 * while `native:watch android|ios` is running — the EdgeHot fingerprint
 * moves the moment a save lands on disk, whichever watcher (or none) is
 * running. Run both surfaces from one terminal and they update together.
 *
 * This command is for the two things that needs a process of its own:
 *
 *   1. Web-only sessions — no device, no simulator, just the browser, with
 *      the same sticky-footer console as `native:watch` so it's obvious
 *      what's being watched and what just changed.
 *   2. Keeping the BUILT Tailwind sheet honest. Once
 *      public/vendor/edge/app.css exists, WebShell serves it instead of the
 *      browser CDN — so a view that introduces a new utility class renders
 *      unstyled until `edge:css` runs again. Watching rebuilds it for you
 *      (and the rebuilt sheet is itself part of the token, so tabs pick up
 *      the styles in the same beat).
 */
class EdgeWatchCommand extends Command
{
    use ManagesPollingWatcher;

    protected $signature = 'edge:watch
        {--css : Rebuild the EDGE stylesheet on view changes (default when a built sheet exists)}
        {--no-css : Never rebuild the stylesheet, even when a built sheet exists}
        {--no-socket : Skip the reload server; browsers fall back to HTTP polling}';

    protected $description = 'Watch for file changes and push live updates to open browsers';

    protected ?WatchConsole $console = null;

    /** Coalescing window: editors and formatters save in bursts. */
    protected const DEBOUNCE = 0.2;

    protected ?float $pendingSince = null;

    protected ?string $pendingFile = null;

    protected bool $pendingCss = false;

    protected int $changeCount = 0;

    public function handle(): int
    {
        // NB: no EdgeHot::enabled() precheck — this command is what turns
        // it on. Only an explicit opt-out stands in the way.
        if (config('nativephp-web.hot') === false) {
            $this->error('Live updates are disabled by config (`nativephp-web.hot` is false).');

            return self::FAILURE;
        }

        $paths = array_values(array_filter(EdgeHot::watchPaths(), 'is_dir'));

        if ($paths === []) {
            $this->error('None of the watched paths exist — nothing to watch.');

            return self::FAILURE;
        }

        // The socket is what browsers actually listen on; this command's
        // own watching is the fallback channel (and drives CSS rebuilds).
        $port = $this->option('no-socket') ? null : ReloadServer::start();

        $this->console = new WatchConsole($this->output, $this->input->isInteractive());
        $this->console->keys([
            'r' => 'reload browsers',
            'c' => 'clear',
            'q' => 'quit',
        ]);
        $this->console->status([
            [null, 'web'],
            ['browsers', $port !== null ? "ws :{$port}" : 'polling'],
            ['paths', $this->summarize($paths)],
            ['css', $this->rebuildsCss() ? 'rebuilding' : 'off'],
        ]);
        $this->console->start();
        $this->console->activity('watching for changes');

        // The polling watcher's shutdown path exit()s, so anything queued
        // behind it never runs — register first to always hand the
        // terminal back, and to take the server down with us (its
        // heartbeat is what keeps pages arming a client).
        register_shutdown_function(function () {
            $this->stopWatchConsole();
            ReloadServer::stop();
        });

        // Any browser that was showing pre-watch output should catch up
        // now rather than on whatever it happens to poll next.
        EdgeHot::signal();

        $this->startPollingWatcher(
            $paths,
            ['.git', 'node_modules', 'vendor', 'storage/framework', 'storage/logs'],
            fn (string $file) => $this->onChange($file),
            fn () => $this->onTick(),
        );

        return self::SUCCESS;
    }

    /** Whether view changes should trigger an `edge:css` rebuild. */
    protected function rebuildsCss(): bool
    {
        if ($this->option('no-css')) {
            return false;
        }

        // A built sheet is being SERVED (WebShell prefers it over the
        // CDN), so leaving it stale is the whole problem — rebuild by
        // default in that case, and only on request otherwise.
        return $this->option('css') || is_file(public_path('vendor/edge/app.css'));
    }

    protected function onChange(string $filePath): void
    {
        if (is_dir($filePath) || EdgeHot::ignores(basename($filePath))) {
            return;
        }

        $this->pendingSince = microtime(true);
        $this->pendingFile = $this->relative($filePath);
        $this->pendingCss = $this->pendingCss || str_ends_with($filePath, '.blade.php');
    }

    /** Runs between watcher polls: service the terminal, flush changes. */
    protected function onTick(): void
    {
        if ($this->console !== null) {
            $this->console->tick();

            while (($key = $this->console->readKey()) !== null) {
                $this->onKey($key);

                if ($this->console === null) {
                    return;
                }
            }
        }

        $this->flushPending();
    }

    /**
     * Publish the change once the burst has settled. CSS is rebuilt before
     * signalling so the sheet and the frame arrive together — though a tab
     * that spots the save on its own first (the fingerprint doesn't wait
     * for this process) may render once against the old sheet; the rebuilt
     * file moves the token again and the next frame is styled.
     */
    protected function flushPending(): void
    {
        if ($this->pendingSince === null || (microtime(true) - $this->pendingSince) < static::DEBOUNCE) {
            return;
        }

        $file = $this->pendingFile;
        $css = $this->pendingCss && $this->rebuildsCss();

        $this->pendingSince = null;
        $this->pendingFile = null;
        $this->pendingCss = false;

        if ($css) {
            $this->rebuildCss();
        }

        EdgeHot::signal($file);

        $this->changeCount++;
        $this->console?->activity(sprintf(
            '%s · %d %s',
            $file ?? 'changed',
            $this->changeCount,
            $this->changeCount === 1 ? 'change' : 'changes',
        ), 'green');
    }

    protected function rebuildCss(): void
    {
        $this->console?->activity('rebuilding stylesheet…', 'cyan');

        // Silenced: `edge:css` narrates its own build, which would fight
        // the footer for the terminal. Failures still get reported.
        if ($this->callSilent('edge:css') !== self::SUCCESS) {
            $this->console?->note('<fg=red>edge:css failed —</> <fg=gray>run</> <fg=cyan>php artisan edge:css</> <fg=gray>to see why.</>');
        }
    }

    protected function onKey(string $key): void
    {
        match (strtolower($key)) {
            // Every open tab re-renders on its next poll — the web
            // equivalent of the device watcher's reload key.
            'r' => $this->reloadBrowsers(),
            'c' => $this->console?->clearScrollback(),
            'q' => $this->onPollingWatcherShutdown(),
            default => null,
        };
    }

    protected function reloadBrowsers(): void
    {
        EdgeHot::signal('manual reload');
        $this->console?->activity('reloading browsers…', 'yellow');
    }

    /**
     * Called by ManagesPollingWatcher's shutdown path (Ctrl+C, SIGTERM,
     * the quit key) — hand the terminal back before it exit()s.
     */
    public function stopWatchConsole(): void
    {
        $console = $this->console;
        $this->console = null;

        $console?->stop();
    }

    protected function relative(string $path): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    /** `app, routes, config +3` — the footer has one line to spare. */
    protected function summarize(array $paths): string
    {
        $names = array_map(fn (string $path) => $this->relative($path), $paths);

        if (count($names) <= 3) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, 3)).' +'.(count($names) - 3);
    }
}
