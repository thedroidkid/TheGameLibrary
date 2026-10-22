<?php
declare(strict_types=1);

const MAX_COVER_BYTES = 12582912;
const DEFAULT_ACCENT_COLOR = '#a9ef57';
const MAX_GENRES = 10;
const MAX_GENRE_LENGTH = 40;

$root = __DIR__;
$dataDir = getenv('GAME_LIBRARY_DATA_DIR') ?: $root;
$gamesFile = $dataDir . '/games.json';
$coversDir = $dataDir . '/covers';
$keyFile = getenv('THEGAMESDB_KEY_FILE') ?: $root . '/.thegamesdb-key';

final class ApiError extends RuntimeException
{
    public int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function send_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function new_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
        . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function normalized_title(string $title): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
}

function read_json_body(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length < 0 || $length > 1048576) {
        throw new ApiError('Request body is too large.', 413);
    }
    $body = file_get_contents('php://input');
    $payload = json_decode($body === false ? '' : $body, true);
    if (!is_array($payload)) {
        throw new ApiError('Request body must be a JSON object.');
    }
    return $payload;
}

function api_key_is_configured(string $keyFile): bool
{
    if (!is_file($keyFile)) {
        return false;
    }
    $apiKey = file_get_contents($keyFile);
    if ($apiKey === false) {
        throw new RuntimeException('Could not read the TheGamesDB API key file.');
    }
    return trim($apiKey) !== '';
}

function write_private_file(string $filePath, string $contents, string $temporaryPrefix, string $description): void
{
    $directory = dirname($filePath);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('The ' . $description . ' directory is not writable.');
    }
    $temporaryFile = tempnam($directory, $temporaryPrefix);
    if ($temporaryFile === false) {
        throw new RuntimeException('Could not create a temporary ' . $description . ' file.');
    }
    try {
        if (file_put_contents($temporaryFile, $contents, LOCK_EX) === false
            || !chmod($temporaryFile, 0600)
            || !rename($temporaryFile, $filePath)) {
            throw new RuntimeException('Could not save the ' . $description . '.');
        }
    } finally {
        if (is_file($temporaryFile) && !unlink($temporaryFile)) {
            error_log('The Game Library: could not remove a temporary ' . $description . ' file.');
        }
    }
}

function save_api_key(string $keyFile, string $apiKey): void
{
    write_private_file($keyFile, $apiKey . "\n", '.thegamesdb-key-', 'TheGamesDB API key');
}

function read_accent_color(string $colorFile): string
{
    if (!is_file($colorFile)) {
        return DEFAULT_ACCENT_COLOR;
    }
    $color = file_get_contents($colorFile);
    if ($color === false) {
        throw new RuntimeException('Could not read the accent color setting.');
    }
    $color = trim($color);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        throw new RuntimeException('The saved accent color setting is invalid.');
    }
    return strtolower($color);
}

function save_accent_color(string $colorFile, string $color): void
{
    write_private_file($colorFile, strtolower($color) . "\n", '.accent-color-', 'accent color setting');
}

function with_games_lock(string $lockFile, callable $callback): mixed
{
    $lock = fopen($lockFile, 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open the game library lock file.');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not lock the game library.');
        }
        return $callback();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function read_games_file(string $gamesFile): array
{
    if (!is_file($gamesFile)) {
        throw new RuntimeException('The game library file is missing.');
    }
    $json = file_get_contents($gamesFile);
    $games = json_decode($json === false ? '' : $json, true);
    if (!is_array($games) || !array_is_list($games)) {
        throw new RuntimeException('The game library data is invalid.');
    }
    return $games;
}

function write_games_file(string $gamesFile, array $games): void
{
    $json = json_encode($games, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($gamesFile, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not save the game library.');
    }
    chmod($gamesFile, 0660);
}

function canonical_platform(string $platform): string
{
    $trimmed = trim($platform);
    $lower = strtolower($trimmed);
    if (preg_match('/^(?:sony\s+)?playstation\s*(\d+)(?:\s+pro)?$/i', $trimmed, $match)) {
        return 'PlayStation ' . $match[1];
    }
    $aliases = [
        'sony playstation' => 'PlayStation',
        'playstation' => 'PlayStation',
        'sony playstation portable' => 'PlayStation Portable (PSP)',
        'playstation portable' => 'PlayStation Portable (PSP)',
        'psp' => 'PlayStation Portable (PSP)',
        'sony playstation vita' => 'PlayStation Vita',
        'playstation vita' => 'PlayStation Vita',
        'ps vita' => 'PlayStation Vita',
        'microsoft xbox' => 'Xbox',
        'microsoft xbox 360' => 'Xbox 360',
        'microsoft xbox one' => 'Xbox One',
        'microsoft xbox one s' => 'Xbox One S',
        'microsoft xbox one x' => 'Xbox One X',
        'microsoft xbox series s' => 'Xbox Series S',
        'microsoft xbox series x' => 'Xbox Series X',
        'nes' => 'Nintendo Entertainment System',
        'nintendo entertainment system' => 'Nintendo Entertainment System',
        'snes' => 'Super Nintendo Entertainment System',
        'super nintendo' => 'Super Nintendo Entertainment System',
        'super nintendo entertainment system' => 'Super Nintendo Entertainment System',
        'nintendo 64' => 'Nintendo 64',
        'nintendo gamecube' => 'Nintendo GameCube',
        'nintendo wii' => 'Wii',
        'wii' => 'Wii',
        'nintendo wii u' => 'Wii U',
        'wii u' => 'Wii U',
        'nintendo switch' => 'Nintendo Switch',
        'nintendo switch 2' => 'Nintendo Switch 2',
        'game boy' => 'Game Boy',
        'game boy color' => 'Game Boy Color',
        'game boy advance' => 'Game Boy Advance',
        'nintendo ds' => 'Nintendo DS',
        'nintendo 3ds' => 'Nintendo 3DS',
        'new nintendo 3ds' => 'New Nintendo 3DS',
        'virtual boy' => 'Virtual Boy',
    ];
    return $aliases[$lower] ?? $trimmed;
}

function validate_image(string $data, string $contentType): string
{
    if (strlen($data) > MAX_COVER_BYTES) {
        throw new ApiError('The cover image is larger than 12 MB.', 413);
    }
    $formats = [
        'image/jpeg' => ['jpg', "\xFF\xD8\xFF"],
        'image/png' => ['png', "\x89PNG\r\n\x1A\n"],
    ];
    if (isset($formats[$contentType]) && str_starts_with($data, $formats[$contentType][1])) {
        return $formats[$contentType][0];
    }
    if ($contentType === 'image/webp' && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
        return 'webp';
    }
    throw new ApiError('The image must be a valid JPEG, PNG, or WebP file.', 415);
}

function save_cover(string $coversDir, string $data, string $contentType): string
{
    $extension = validate_image($data, $contentType);
    if (!is_dir($coversDir) && !mkdir($coversDir, 0750, true) && !is_dir($coversDir)) {
        throw new RuntimeException('Could not create the covers directory.');
    }
    $filename = new_uuid() . '.' . $extension;
    if (file_put_contents($coversDir . '/' . $filename, $data, LOCK_EX) === false) {
        throw new RuntimeException('Could not save the cover image.');
    }
    chmod($coversDir . '/' . $filename, 0644);
    return '/covers/' . $filename;
}

function remote_get(string $url, int $timeout = 20): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "User-Agent: TheGameLibrary/1.0\r\n",
        ],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = 0;
    $contentType = '';
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/i', $header, $match)) {
            $status = (int)$match[1];
        } elseif (stripos($header, 'Content-Type:') === 0) {
            $contentType = strtolower(trim(explode(';', substr($header, 13), 2)[0]));
        }
    }
    if ($body === false || $status < 200 || $status >= 300) {
        throw new ApiError('The remote request failed' . ($status ? ' (' . $status . ')' : '') . '.', 502);
    }
    return [$body, $contentType, $status];
}

function fetch_cover(string $imageUrl): array
{
    $parts = parse_url($imageUrl);
    if (!is_array($parts)) {
        throw new ApiError('Choose a valid TheGamesDB cover.');
    }
    $path = $parts['path'] ?? '';
    if (($parts['scheme'] ?? '') !== 'https'
        || ($parts['host'] ?? '') !== 'cdn.thegamesdb.net'
        || isset($parts['query']) || isset($parts['fragment'])
        || !preg_match('~^/images/(?:original|medium|large|thumb)/boxart/front/[a-zA-Z0-9._-]+$~', $path)) {
        throw new ApiError('Only TheGamesDB front-cover images can be downloaded.');
    }
    [$data, $contentType] = remote_get($imageUrl);
    if (strlen($data) > MAX_COVER_BYTES) {
        throw new ApiError('The cover image is larger than 12 MB.', 413);
    }
    $extension = validate_image($data, $contentType);
    return [$data, $extension];
}

function fetch_genre_names(string $apiKey, string $cacheFile): array
{
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
    }
    try {
        [$json] = remote_get('https://api.thegamesdb.net/v1/Genres?' . http_build_query(['apikey' => $apiKey]), 15);
    } catch (ApiError) {
        return [];
    }
    $genres = json_decode($json, true)['data']['genres'] ?? [];
    $names = [];
    foreach (is_array($genres) ? $genres : [] as $key => $genre) {
        if (is_array($genre) && is_string($genre['name'] ?? null)) {
            $names[(string)$key] = $genre['name'];
        }
    }
    if ($names !== []) {
        @file_put_contents($cacheFile, json_encode($names), LOCK_EX);
    }
    return $names;
}

function search_games(string $keyFile, string $query, string $genreCacheFile): array
{
    if (!is_file($keyFile)) {
        throw new ApiError('Game search is unavailable because no TheGamesDB API key is configured. You can still enter the game details manually.', 503);
    }
    $apiKey = trim((string)file_get_contents($keyFile));
    if ($apiKey === '') {
        throw new ApiError('Game search is unavailable because no TheGamesDB API key is configured. You can still enter the game details manually.', 503);
    }
    $url = 'https://api.thegamesdb.net/v1.1/Games/ByGameName?' . http_build_query([
        'apikey' => $apiKey,
        'name' => $query,
        'include' => 'boxart,platform',
        'fields' => 'overview,genres',
    ]);
    try {
        [$json] = remote_get($url, 15);
    } catch (ApiError $error) {
        if (str_contains($error->getMessage(), '(403)')) {
            throw new ApiError('TheGamesDB rejected the API key or its usage limit has been reached.', 502);
        }
        throw new ApiError('Could not complete the TheGamesDB search: ' . $error->getMessage(), 502);
    }
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        throw new ApiError('TheGamesDB returned invalid data.', 502);
    }
    $data = $payload['data']['games'] ?? [];
    $platforms = $payload['include']['platform']['data'] ?? [];
    $boxart = $payload['include']['boxart'] ?? [];
    $baseUrls = $boxart['base_url'] ?? [];
    $gamesFound = array_slice(is_array($data) ? $data : [], 0, 20);
    $genreNames = [];
    foreach ($gamesFound as $game) {
        if (!empty($game['genres'])) {
            $genreNames = fetch_genre_names($apiKey, $genreCacheFile);
            break;
        }
    }
    $results = [];
    foreach ($gamesFound as $game) {
        $images = $boxart['data'][(string)($game['id'] ?? '')] ?? [];
        $filename = '';
        foreach (is_array($images) ? $images : [] as $image) {
            $candidate = ltrim((string)($image['filename'] ?? ''), '/');
            if (($image['type'] ?? '') === 'boxart' && ($image['side'] ?? '') === 'front'
                && str_starts_with($candidate, 'boxart/front/') && !str_contains($candidate, '..')) {
                $filename = $candidate;
                break;
            }
        }
        $originalBase = $baseUrls['original'] ?? 'https://cdn.thegamesdb.net/images/original/';
        $previewBase = $baseUrls['medium'] ?? $originalBase;
        $rawPlatform = $platforms[(string)($game['platform'] ?? '')]['name'] ?? '';
        $overview = (string)($game['overview'] ?? '');
        $releaseDate = (string)($game['release_date'] ?? '');
        $genres = [];
        foreach (is_array($game['genres'] ?? null) ? $game['genres'] : [] as $genreId) {
            if (isset($genreNames[(string)$genreId])) {
                $genres[] = $genreNames[(string)$genreId];
            }
        }
        $results[] = [
            'id' => $game['id'] ?? null,
            'title' => $game['game_title'] ?? 'Untitled game',
            'platform' => $rawPlatform !== '' ? canonical_platform((string)$rawPlatform) : '',
            'year' => preg_match('/^\d{4}/', $releaseDate, $year) ? $year[0] : '',
            'description' => substr($overview, 0, 5000),
            'descriptionSource' => $overview !== '' && $rawPlatform !== '' ? canonical_platform((string)$rawPlatform) : '',
            'genres' => array_slice($genres, 0, MAX_GENRES),
            'preview' => $filename !== '' ? '/api/covers/preview?url=' . rawurlencode($previewBase . $filename) : '',
            'imageUrl' => $filename !== '' ? $originalBase . $filename : '',
        ];
    }
    $descriptions = [];
    $genresByTitle = [];
    foreach ($results as $result) {
        if ($result['description'] !== '') {
            $descriptions[normalized_title($result['title'])] = $result;
        }
        if ($result['genres'] !== []) {
            $genresByTitle[normalized_title($result['title'])] = $result['genres'];
        }
    }
    foreach ($results as &$result) {
        if ($result['genres'] === []) {
            $result['genres'] = $genresByTitle[normalized_title($result['title'])] ?? [];
        }
        if ($result['description'] === '') {
            $sibling = $descriptions[normalized_title($result['title'])] ?? null;
            if ($sibling !== null) {
                $result['description'] = $sibling['description'];
                $result['descriptionSource'] = $sibling['descriptionSource'];
            }
        }
    }
    unset($result);
    return $results;
}

function validate_genres(mixed $value): array
{
    if (is_string($value)) {
        $value = preg_split('/[|,]/', $value);
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new ApiError('Genres must be a list of names.');
    }
    $genres = [];
    $seen = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            throw new ApiError('Genres must be a list of names.');
        }
        $name = trim((string)preg_replace('/\s+/u', ' ', $item));
        $key = normalized_title($name);
        if ($name === '' || isset($seen[$key])) {
            continue;
        }
        if (mb_strlen($name, 'UTF-8') > MAX_GENRE_LENGTH) {
            throw new ApiError('Each genre must be ' . MAX_GENRE_LENGTH . ' characters or fewer.');
        }
        $seen[$key] = true;
        $genres[] = $name;
    }
    if (count($genres) > MAX_GENRES) {
        throw new ApiError('Enter up to ' . MAX_GENRES . ' genres.');
    }
    return $genres;
}

function validate_game(array $payload, ?array $existing = null): array
{
    $title = $payload['title'] ?? null;
    if (!is_string($title) || trim($title) === '' || strlen(trim($title)) > 160) {
        throw new ApiError('Enter a game title (up to 160 characters).');
    }
    $platform = $payload['platform'] ?? '';
    if (!is_string($platform) || trim($platform) === '' || strlen(trim($platform)) > 80) {
        throw new ApiError('Choose a platform (up to 80 characters).');
    }
    $year = (string)($payload['year'] ?? '');
    if ($year !== '' && (!preg_match('/^\d{4}$/', $year) || (int)$year < 1950 || (int)$year > 2100)) {
        throw new ApiError('Enter a release year from 1950 to 2100.');
    }
    $status = $payload['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['Backlog', 'Playing', 'Completed', 'On Hold'], true)) {
        throw new ApiError('Choose a valid game status.');
    }
    $notes = $payload['notes'] ?? '';
    if (!is_string($notes) || strlen(trim($notes)) > 2000) {
        throw new ApiError('Notes must be 2000 characters or fewer.');
    }
    $description = $payload['description'] ?? ($existing['description'] ?? '');
    if (!is_string($description) || strlen(trim($description)) > 5000) {
        throw new ApiError('Description must be 5000 characters or fewer.');
    }
    $genres = validate_genres($payload['genres'] ?? ($existing['genres'] ?? []));
    $cover = $payload['cover'] ?? '';
    if (!is_string($cover) || ($cover !== '' && !preg_match('~^/covers/[0-9a-f-]{36}\.(?:jpg|png|webp)$~', $cover))) {
        throw new ApiError('Cover must be a downloaded local image.');
    }
    return [
        'id' => $existing['id'] ?? new_uuid(),
        'title' => trim($title),
        'platform' => trim($platform),
        'year' => $year,
        'status' => $status,
        'genres' => $genres,
        'notes' => trim($notes),
        'description' => trim($description),
        'cover' => $cover,
    ];
}

try {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    $route = trim((string)($_GET['route'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $route === 'games') {
        $games = with_games_lock($coversDir . '/.games.lock', fn() => read_games_file($gamesFile));
        send_json(200, ['games' => $games]);
    }
    if ($route === 'settings/thegamesdb-key') {
        if ($method === 'GET') {
            send_json(200, ['configured' => api_key_is_configured($keyFile)]);
        }
        if ($method === 'PUT') {
            $payload = read_json_body();
            $apiKey = $payload['key'] ?? null;
            if (!is_string($apiKey)) {
                throw new ApiError('Enter a valid TheGamesDB API key.');
            }
            $apiKey = trim($apiKey);
            if ($apiKey === '' || strlen($apiKey) > 512 || preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
                throw new ApiError('Enter a TheGamesDB API key of 1 to 512 characters.');
            }
            save_api_key($keyFile, $apiKey);
            send_json(200, ['configured' => true]);
        }
        if ($method === 'DELETE') {
            if (file_exists($keyFile) && !unlink($keyFile)) {
                throw new RuntimeException('Could not remove the TheGamesDB API key.');
            }
            send_json(200, ['configured' => false]);
        }
    }
    if ($route === 'settings/accent-color') {
        $colorFile = $dataDir . '/accent-color';
        if ($method === 'GET') {
            send_json(200, ['color' => read_accent_color($colorFile)]);
        }
        if ($method === 'PUT') {
            $payload = read_json_body();
            $color = $payload['color'] ?? null;
            if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                throw new ApiError('Enter a HEX color in #RRGGBB format.');
            }
            save_accent_color($colorFile, $color);
            send_json(200, ['color' => strtolower($color)]);
        }
        if ($method === 'DELETE') {
            if (file_exists($colorFile) && !unlink($colorFile)) {
                throw new RuntimeException('Could not reset the accent color setting.');
            }
            send_json(200, ['color' => DEFAULT_ACCENT_COLOR]);
        }
    }
    if ($method === 'GET' && $route === 'search') {
        $query = trim((string)($_GET['q'] ?? ''));
        if ($query === '' || strlen($query) > 120) {
            throw new ApiError('Enter a search title up to 120 characters long.');
        }
        send_json(200, ['results' => search_games($keyFile, $query, $dataDir . '/genres-cache.json')]);
    }
    if ($method === 'GET' && $route === 'covers/preview') {
        [$data, $extension] = fetch_cover((string)($_GET['url'] ?? ''));
        header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension]);
        header('Cache-Control: public, max-age=3600');
        echo $data;
        exit;
    }
    if ($method === 'POST' && $route === 'covers/download') {
        $payload = read_json_body();
        [$data, $extension] = fetch_cover((string)($payload['imageUrl'] ?? ''));
        $contentType = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$extension];
        send_json(201, ['cover' => save_cover($coversDir, $data, $contentType)]);
    }
    if ($method === 'POST' && $route === 'covers/upload') {
        $upload = $_FILES['cover'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiError('Choose an image file to upload.');
        }
        if (($upload['size'] ?? 0) > MAX_COVER_BYTES) {
            throw new ApiError('The cover image is larger than 12 MB.', 413);
        }
        $data = file_get_contents((string)$upload['tmp_name']);
        $contentType = (new finfo(FILEINFO_MIME_TYPE))->file((string)$upload['tmp_name']);
        if ($data === false || !is_string($contentType)) {
            throw new ApiError('Could not read the uploaded image.');
        }
        send_json(201, ['cover' => save_cover($coversDir, $data, $contentType)]);
    }
    if ($method === 'POST' && $route === 'games/import') {
        $payload = read_json_body();
        $requestedGames = $payload['games'] ?? null;
        if (!is_array($requestedGames) || !array_is_list($requestedGames) || $requestedGames === []) {
            throw new ApiError('Choose a text file containing at least one game title.');
        }
        $validatedGames = [];
        foreach ($requestedGames as $requestedGame) {
            if (!is_array($requestedGame)) {
                throw new ApiError('Each imported game must include a title and platform.');
            }
            $validatedGames[] = validate_game($requestedGame);
        }
        $result = with_games_lock($coversDir . '/.games.lock', function () use ($gamesFile, $validatedGames): array {
            $games = read_games_file($gamesFile);
            $seen = [];
            foreach ($games as $game) {
                $key = json_encode([normalized_title(trim($game['title'])), normalized_title(trim($game['platform']))]);
                $seen[$key] = true;
            }
            $importedGames = [];
            $skippedGames = [];
            foreach ($validatedGames as $game) {
                $key = json_encode([normalized_title($game['title']), normalized_title($game['platform'])]);
                if (isset($seen[$key])) {
                    $skippedGames[] = $game;
                } else {
                    $seen[$key] = true;
                    $importedGames[] = $game;
                }
            }
            if ($importedGames !== []) {
                write_games_file($gamesFile, array_merge($games, $importedGames));
            }
            return ['games' => $importedGames, 'skipped' => $skippedGames];
        });
        send_json(201, $result);
    }
    if ($method === 'POST' && $route === 'games/bulk-delete') {
        $ids = read_json_body()['ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 10000) {
            throw new ApiError('Choose at least one game to delete.');
        }
        foreach ($ids as $id) {
            if (!is_string($id) || !preg_match('~^[0-9a-f-]{36}$~', $id)) {
                throw new ApiError('Choose at least one game to delete.');
            }
        }
        $idSet = array_flip($ids);
        $deleted = with_games_lock($coversDir . '/.games.lock', function () use ($gamesFile, $idSet): int {
            $games = read_games_file($gamesFile);
            $remaining = array_values(array_filter($games, fn($game) => !isset($idSet[$game['id'] ?? ''])));
            $count = count($games) - count($remaining);
            if ($count > 0) {
                write_games_file($gamesFile, $remaining);
            }
            return $count;
        });
        send_json(200, ['deleted' => $deleted]);
    }
    if ($route === 'games' && $method === 'POST') {
        $game = validate_game(read_json_body());
        with_games_lock($coversDir . '/.games.lock', function () use ($gamesFile, $game): void {
            $games = read_games_file($gamesFile);
            $games[] = $game;
            write_games_file($gamesFile, $games);
        });
        send_json(201, ['game' => $game]);
    }
    if (preg_match('~^games/([0-9a-f-]{36})$~', $route, $match)) {
        $id = $match[1];
        if ($method === 'PUT') {
            $payload = read_json_body();
            $game = with_games_lock($coversDir . '/.games.lock', function () use ($gamesFile, $id, $payload): array {
                $games = read_games_file($gamesFile);
                foreach ($games as $index => $existing) {
                    if (($existing['id'] ?? '') === $id) {
                        $updated = validate_game($payload, $existing);
                        $games[$index] = $updated;
                        write_games_file($gamesFile, $games);
                        return $updated;
                    }
                }
                throw new ApiError('Game not found.', 404);
            });
            send_json(200, ['game' => $game]);
        }
        if ($method === 'DELETE') {
            $removed = with_games_lock($coversDir . '/.games.lock', function () use ($gamesFile, $id): array {
                $games = read_games_file($gamesFile);
                foreach ($games as $index => $game) {
                    if (($game['id'] ?? '') === $id) {
                        array_splice($games, $index, 1);
                        write_games_file($gamesFile, $games);
                        return $game;
                    }
                }
                throw new ApiError('Game not found.', 404);
            });
            send_json(200, ['game' => $removed]);
        }
    }
    send_json(404, ['error' => 'API route not found.']);
} catch (ApiError $error) {
    send_json($error->status, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('The Game Library: ' . $error->getMessage());
    send_json(500, ['error' => 'The server could not complete the request. Check the PHP error log.']);
}
