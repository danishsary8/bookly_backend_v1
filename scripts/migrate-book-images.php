<?php

declare(strict_types=1);

/**
 * One-time migration: move book cover images from hotlinked third-party URLs
 * to Cloudinary, and repoint books.book_img at the Cloudinary delivery URL.
 *
 * Cloudinary fetches each source image server-side (the remote URL is handed to
 * the upload API), so nothing is downloaded locally.
 *
 * Usage:
 *   php scripts/migrate-book-images.php --dry-run   # report only, no writes
 *   php scripts/migrate-book-images.php             # perform the migration
 *
 * Re-running is safe: rows already pointing at res.cloudinary.com are skipped.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config\DatabaseConnection;
use Cloudinary\Cloudinary;
use Dotenv\Dotenv;

$dryRun = in_array('--dry-run', $argv, true);

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

/** Write a line straight to the console, unbuffered. */
function out(string $line): void
{
    echo $line . PHP_EOL;
    flush();
}

// --- Credentials -----------------------------------------------------------

$required = ['CLOUDINARY_CLOUD_NAME', 'CLOUDINARY_API_KEY', 'CLOUDINARY_API_SECRET'];
$missing  = array_values(array_filter($required, static fn (string $k): bool => empty($_ENV[$k])));

if ($missing !== []) {
    out('ERROR: missing in .env: ' . implode(', ', $missing));
    exit(1);
}

$cloudinary = new Cloudinary([
    'cloud' => [
        'cloud_name' => $_ENV['CLOUDINARY_CLOUD_NAME'],
        'api_key'    => $_ENV['CLOUDINARY_API_KEY'],
        'api_secret' => $_ENV['CLOUDINARY_API_SECRET'],
    ],
    'url' => ['secure' => true],
]);

// --- Load the rows ---------------------------------------------------------

try {
    $pdo = DatabaseConnection::getInstance();
} catch (Throwable $e) {
    out('ERROR: ' . $e->getMessage());
    exit(1);
}

$books = $pdo
    ->query("SELECT id, title, book_img FROM books WHERE book_img IS NOT NULL AND book_img <> '' ORDER BY id")
    ->fetchAll();

$total = count($books);

out('Cloud:  ' . $_ENV['CLOUDINARY_CLOUD_NAME']);
out('Books:  ' . $total . ' row(s) with a book_img');
out('Mode:   ' . ($dryRun ? 'DRY RUN (no uploads, no writes)' : 'LIVE'));
out(str_repeat('-', 72));

if ($total === 0) {
    out('Nothing to migrate.');
    exit(0);
}

// --- Back up the current values before touching anything -------------------
// book_img is overwritten in place, so without this the original URLs are gone.

$backupPath = __DIR__ . '/migrate-book-images.backup.json';

if (!$dryRun) {
    $backup = json_encode([
        'created_at' => date('c'),
        'database'   => $_ENV['DB_NAME'] ?? null,
        'rows'       => $books,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (file_put_contents($backupPath, $backup) === false) {
        out('ERROR: could not write backup to ' . $backupPath . ' - aborting.');
        exit(1);
    }

    out('Backup: ' . $backupPath);
    out(str_repeat('-', 72));
}

// --- Migrate ---------------------------------------------------------------

$update = $pdo->prepare('UPDATE books SET book_img = :url WHERE id = :id');

$migrated = 0;
$skipped  = 0;
$failed   = 0;
$index    = 0;

foreach ($books as $book) {
    $index++;

    $id       = (int) $book['id'];
    $title    = (string) $book['title'];
    $source   = trim((string) $book['book_img']);
    $position = sprintf('%d/%d', $index, $total);

    // Already migrated - makes re-runs idempotent.
    if (stripos($source, 'res.cloudinary.com') !== false) {
        $skipped++;
        out(sprintf('SKIP    book %s: %s (already on Cloudinary)', $position, $title));
        continue;
    }

    // Cloudinary fetches the source over HTTP, so it needs an absolute URL.
    // Bare filenames (e.g. "dune.jpg") have no retrievable source.
    if (!preg_match('~^https?://~i', $source)) {
        $skipped++;
        out(sprintf('SKIP    book %s: %s (not a URL: "%s")', $position, $title, $source));
        continue;
    }

    // Transient upstream failures (rate limits, dropped connections) get a couple
    // of retries; a hard 404 fails immediately since retrying cannot help.
    $maxAttempts = 3;
    $attempt     = 0;
    $result      = null;
    $lastError   = null;

    while ($attempt < $maxAttempts) {
        $attempt++;

        try {
            $result = $cloudinary->uploadApi()->upload($source, [
                'folder'        => 'bookly/books',
                'public_id'     => 'book_' . $id,
                'overwrite'     => true,
                'resource_type' => 'image',
            ]);

            $lastError = null;
            break;
        } catch (Throwable $e) {
            $lastError = $e;

            if ($attempt >= $maxAttempts || !isTransientError($e->getMessage())) {
                break;
            }

            $delay = $attempt * 3;
            out(sprintf(
                'RETRY   book %s: %s (attempt %d of %d failed, retrying in %ds)',
                $position,
                $title,
                $attempt,
                $maxAttempts,
                $delay
            ));
            sleep($delay);
        }
    }

    if ($lastError !== null) {
        $failed++;
        out(sprintf('FAILED  book %s: %s', $position, $title));
        out('          source: ' . $source);
        out('          reason: ' . trim($lastError->getMessage()));
        out('          attempts: ' . $attempt);
        continue;
    }

    try {
        $secureUrl = (string) ($result['secure_url'] ?? '');

        if ($secureUrl === '') {
            throw new RuntimeException('upload succeeded but returned no secure_url');
        }

        // f_auto,q_auto are delivery-time transformations, so they belong in the
        // stored URL rather than baked into the uploaded asset.
        $deliveryUrl = withAutoFormatAndQuality($secureUrl);

        if ($dryRun) {
            $migrated++;
            out(sprintf('WOULD   book %s: %s', $position, $title));
            out('          -> ' . $deliveryUrl);
            continue;
        }

        $update->execute([':url' => $deliveryUrl, ':id' => $id]);

        $migrated++;
        out(sprintf('Migrated book %s: %s', $position, $title));
        out('          -> ' . $deliveryUrl);
    } catch (Throwable $e) {
        // A bad row must not stop the rest of the migration.
        $failed++;
        out(sprintf('FAILED  book %s: %s', $position, $title));
        out('          source: ' . $source);
        out('          reason: ' . trim($e->getMessage()));
    }
}

// --- Summary ---------------------------------------------------------------

out(str_repeat('-', 72));
out(sprintf(
    '%s  %d migrated, %d skipped, %d failed  (of %d)',
    $dryRun ? 'Dry run:' : 'Done:   ',
    $migrated,
    $skipped,
    $failed,
    $total
));

if (!$dryRun && $migrated > 0) {
    out('Original URLs saved in ' . $backupPath);
}

exit($failed > 0 ? 1 : 0);

/**
 * Is this upload failure worth retrying? Rate limits, dropped connections and
 * upstream 5xx are transient. A 404 means the source image is gone, so retrying
 * only wastes calls.
 */
function isTransientError(string $message): bool
{
    $transient = [
        '429',
        'too many requests',
        'broke connection',
        'connection reset',
        'timed out',
        'timeout',
        'temporarily',
        '500 internal',
        '502',
        '503',
        '504',
    ];

    $haystack = strtolower($message);

    foreach ($transient as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Insert f_auto,q_auto into a Cloudinary delivery URL, right after /upload/.
 * Returns the URL unchanged if it does not look like a standard delivery URL.
 */
function withAutoFormatAndQuality(string $secureUrl): string
{
    $marker = '/upload/';
    $at     = strpos($secureUrl, $marker);

    if ($at === false) {
        return $secureUrl;
    }

    $at += strlen($marker);

    return substr($secureUrl, 0, $at) . 'f_auto,q_auto/' . substr($secureUrl, $at);
}
