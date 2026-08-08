<?php

namespace Native\Mobile\Edge\Web\Protocol;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Temporary file uploads for the EDGE web target (Livewire-design blueprint).
 *
 * POST {EdgeEndpoint::uploadPath()} (multipart, `web` middleware so CSRF
 * applies) stores each file under storage/app/edge-tmp/ with a random name
 * (client extension preserved) and returns a signed descriptor per file:
 *
 *     { path, name, mime, size, signature }
 *
 * `signature` is an HMAC-SHA256 of `path` under the app key. A component
 * (or the future camera driver) that wants to consume an uploaded file
 * server-side MUST present both values to {@see validatePath()}, which
 * returns the absolute path only when the signature verifies — clients can
 * never point a component at an arbitrary filesystem path, only at files
 * this endpoint itself wrote.
 *
 * Housekeeping mirrors Livewire's TemporaryUploadedFile cleanup: every
 * successful upload opportunistically deletes edge-tmp files older than
 * 24 hours, so the directory is self-pruning without a scheduler.
 */
class EdgeUpload
{
    /** Directory under storage/app that holds temporary uploads. */
    public const DIRECTORY = 'edge-tmp';

    /** Seconds after which a temporary upload is eligible for cleanup. */
    public const TTL = 86400;

    /**
     * Handle a multipart upload. Accepts `files[]` (array) or a single
     * `file` field. Responds with the first file's descriptor at the top
     * level plus a `files` array covering every stored file.
     */
    public function store(Request $request): JsonResponse
    {
        $files = $request->file('files') ?? $request->file('file') ?? [];
        $files = is_array($files) ? array_values($files) : [$files];

        $maxFiles = (int) config('nativephp.edge_uploads.max_files', 10);
        $maxBytes = (int) config('nativephp.edge_uploads.max_bytes', 12 * 1024 * 1024);

        if ($files === []) {
            return response()->json(['message' => 'No file provided.'], 422);
        }

        if (count($files) > $maxFiles) {
            return response()->json(['message' => "Too many files (max {$maxFiles})."], 422);
        }

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return response()->json(['message' => 'Invalid upload.'], 422);
            }

            if ($file->getSize() === false || $file->getSize() > $maxBytes) {
                return response()->json(['message' => 'File exceeds the maximum size of '.$maxBytes.' bytes.'], 413);
            }
        }

        static::cleanupOldUploads();

        $descriptors = array_map(fn (UploadedFile $file) => static::storeFile($file), $files);

        return response()->json([...$descriptors[0], 'files' => $descriptors]);
    }

    /**
     * Verify a {path, signature} pair issued by this endpoint and resolve
     * it to an absolute filesystem path. Returns null for a bad signature,
     * a malformed path, or a file that no longer exists (cleaned up).
     */
    public static function validatePath(string $path, string $signature): ?string
    {
        // Shape check first: only names this controller generates. Defense
        // in depth on top of the HMAC — traversal can never verify anyway,
        // since signatures are only ever minted over generated names.
        if (! preg_match('#^'.static::DIRECTORY.'/[A-Za-z0-9]+(\.[A-Za-z0-9]{1,10})?$#', $path)) {
            return null;
        }

        if (! hash_equals(static::sign($path), $signature)) {
            return null;
        }

        $absolute = storage_path('app/'.$path);

        return is_file($absolute) ? $absolute : null;
    }

    /** HMAC-SHA256 of a relative upload path under the app key. */
    public static function sign(string $path): string
    {
        return hash_hmac('sha256', $path, static::key());
    }

    // ── Local-file preview URLs (renderer support) ──────

    /**
     * Signed URL for serving a LOCAL file to the browser. This is what
     * lets `<native:image :src="$absolutePath">` behave identically on
     * both targets: native renderers load absolute file paths directly,
     * and WebRenderer rewrites them to this URL (same file, served over
     * HTTP with an HMAC nobody can forge).
     *
     * Only files under storage/app are signable — the natural home for
     * both edge-tmp uploads and app-stored files, and a hard boundary so
     * a screen that renders a user-influenced src can never be tricked
     * into exposing something like the app's .env over HTTP.
     */
    public static function fileUrl(string $absolute): ?string
    {
        $real = realpath($absolute);

        if ($real === false || ! static::withinStorageApp($real)) {
            return null;
        }

        return EdgeEndpoint::prefix().'/file?p='.rawurlencode($real).'&s='.hash_hmac('sha256', 'file:'.$real, static::key());
    }

    /** Verify a {p, s} pair from the file route; absolute path or null. */
    public static function validateFileUrl(string $absolute, string $signature): ?string
    {
        $real = realpath($absolute);

        if ($real === false || ! static::withinStorageApp($real) || ! is_file($real)) {
            return null;
        }

        return hash_equals(hash_hmac('sha256', 'file:'.$real, static::key()), $signature) ? $real : null;
    }

    protected static function withinStorageApp(string $real): bool
    {
        $root = realpath(storage_path('app'));

        return $root !== false && str_starts_with($real, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * Normalize file paths inside a client-dispatched native-event
     * payload so listeners receive the same thing they'd get on device:
     * a real, readable filesystem path.
     *
     * Applies to the top-level payload and to each entry of a `files`
     * list. The rule is strict, because native-event payloads are
     * client-authored on web: a `path` key is ONLY honored when its
     * sibling `signature` verifies against this endpoint's HMAC — then
     * it's rewritten to the absolute temp path (signature consumed).
     * Any other `path` (missing/invalid signature, cleaned-up file) is
     * nulled, never passed through — a browser must not be able to point
     * a listener at an arbitrary server path.
     */
    public static function resolvePayloadPaths(array $payload): array
    {
        $payload = static::resolveEntryPath($payload);

        if (isset($payload['files']) && is_array($payload['files'])) {
            $payload['files'] = array_map(
                fn ($entry) => is_array($entry) ? static::resolveEntryPath($entry) : $entry,
                $payload['files'],
            );
        }

        return $payload;
    }

    /** One {path, signature} pair → verified absolute path or null. */
    protected static function resolveEntryPath(array $entry): array
    {
        if (! array_key_exists('path', $entry)) {
            return $entry;
        }

        $path = $entry['path'];
        $signature = $entry['signature'] ?? null;
        unset($entry['signature']);

        $entry['path'] = (is_string($path) && is_string($signature))
            ? static::validatePath($path, $signature)
            : null;

        return $entry;
    }

    /** Store one file under a random name, preserving a safe extension. */
    protected static function storeFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());

        if (! preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
            $extension = 'bin';
        }

        $name = Str::random(32).'.'.$extension;
        $directory = storage_path('app/'.static::DIRECTORY);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $file->move($directory, $name);

        $path = static::DIRECTORY.'/'.$name;

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => mime_content_type($directory.'/'.$name) ?: 'application/octet-stream',
            'size' => filesize($directory.'/'.$name) ?: 0,
            'signature' => static::sign($path),
        ];
    }

    /** Delete temporary uploads older than the TTL (opportunistic). */
    protected static function cleanupOldUploads(): void
    {
        $directory = storage_path('app/'.static::DIRECTORY);

        if (! is_dir($directory)) {
            return;
        }

        $cutoff = time() - static::TTL;

        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file) && (filemtime($file) ?: PHP_INT_MAX) < $cutoff) {
                @unlink($file);
            }
        }
    }

    /** The app key, materialized like Laravel's encrypter does. */
    protected static function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new \RuntimeException('EDGE temporary uploads require an application key (config app.key).');
        }

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return $key;
    }
}
