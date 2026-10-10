from __future__ import annotations

import json
import mimetypes
import os
import re
import threading
import uuid
from email.message import Message
from email.parser import BytesParser
from email.policy import default
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, quote, urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener, urlopen


ROOT = Path(__file__).resolve().parent
DATA_FILE = ROOT / "games.json"
KEY_FILE = ROOT / ".thegamesdb-key"
COVER_DIR = ROOT / "covers"
HTML_FILE = ROOT / "TheGameLibrary.html"
HOST = os.environ.get("HOST", "127.0.0.1")
PORT = int(os.environ.get("PORT", "8000"))
MAX_BODY_BYTES = 1024 * 1024
MAX_COVER_BYTES = 12 * 1024 * 1024
MAX_MULTIPART_BYTES = MAX_COVER_BYTES + 64 * 1024
VALID_STATUSES = {"Backlog", "Playing", "Completed", "On Hold"}
GAME_ID_PATTERN = re.compile(r"^[0-9a-f-]{36}$")
COVER_PATTERN = re.compile(r"^/covers/[0-9a-f-]{36}\.(?:jpg|png|webp)$")
IMAGE_PATH_PATTERN = re.compile(
    r"^/images/(?:original|medium|large|thumb)/boxart/front/[a-zA-Z0-9._-]+$"
)
STORAGE_LOCK = threading.RLock()

INITIAL_TITLES: list[str] = []


class APIError(Exception):
    def __init__(self, message: str, status: int = 400):
        super().__init__(message)
        self.status = status


class NoRedirectHandler(HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, new_url):
        _ = request, file, code, message, headers, new_url
        return None


def new_game(title: str) -> dict[str, str]:
    return {
        "id": str(uuid.uuid4()),
        "title": title,
        "platform": "PlayStation 5",
        "year": "",
        "status": "Backlog",
        "notes": "",
        "cover": "",
    }


def ensure_storage() -> None:
    COVER_DIR.mkdir(parents=True, exist_ok=True)
    try:
        with DATA_FILE.open("x", encoding="utf-8") as data_file:
            json.dump([new_game(title) for title in INITIAL_TITLES], data_file, indent=2)
            data_file.write("\n")
    except FileExistsError:
        pass


def read_games() -> list[dict]:
    with DATA_FILE.open(encoding="utf-8") as data_file:
        games = json.load(data_file)
    if not isinstance(games, list):
        raise ValueError("The games.json file must contain a JSON array.")
    return games


def write_games(games: list[dict]) -> None:
    temporary_file = DATA_FILE.with_name(f"{DATA_FILE.name}.{uuid.uuid4()}.tmp")
    try:
        with temporary_file.open("x", encoding="utf-8") as data_file:
            os.chmod(temporary_file, 0o600)
            json.dump(games, data_file, indent=2)
            data_file.write("\n")
        os.replace(temporary_file, DATA_FILE)
    finally:
        temporary_file.unlink(missing_ok=True)


def validated_game(payload, existing: dict | None = None) -> dict[str, str]:
    if not isinstance(payload, dict):
        raise APIError("A game object is required.")
    title = payload.get("title")
    if not isinstance(title, str) or not title.strip() or len(title.strip()) > 160:
        raise APIError("Enter a game title (up to 160 characters).")
    platform = payload.get("platform", "")
    if not isinstance(platform, str) or len(platform.strip()) > 80:
        raise APIError("Platform must be 80 characters or fewer.")
    raw_year = payload.get("year", "")
    year = "" if raw_year in ("", None) else str(raw_year)
    if year and (not re.fullmatch(r"\d{4}", year) or not 1950 <= int(year) <= 2100):
        raise APIError("Enter a release year from 1950 to 2100.")
    status = payload.get("status")
    if not isinstance(status, str) or status not in VALID_STATUSES:
        raise APIError("Choose a valid game status.")
    notes = payload.get("notes", "")
    if not isinstance(notes, str) or len(notes.strip()) > 2000:
        raise APIError("Notes must be 2000 characters or fewer.")
    description = payload.get("description", (existing or {}).get("description", ""))
    if not isinstance(description, str) or len(description.strip()) > 5000:
        raise APIError("Description must be 5000 characters or fewer.")
    cover = payload.get("cover", "")
    if not isinstance(cover, str) or (cover and not COVER_PATTERN.fullmatch(cover)):
        raise APIError("Cover must be a downloaded local image.")
    return {
        "id": existing["id"] if existing else str(uuid.uuid4()),
        "title": title.strip(),
        "platform": platform.strip(),
        "year": year,
        "status": status,
        "notes": notes.strip(),
        "description": description.strip(),
        "cover": cover,
    }


def read_request_json(request: BaseHTTPRequestHandler):
    try:
        length = int(request.headers.get("Content-Length", "0"))
    except ValueError as error:
        raise APIError("Invalid request body length.") from error
    if length < 0 or length > MAX_BODY_BYTES:
        raise APIError("Request body is too large.", 413)
    try:
        return json.loads(request.rfile.read(length))
    except (json.JSONDecodeError, UnicodeDecodeError) as error:
        raise APIError("Request body must be valid JSON.") from error


def image_extension(data: bytes, content_type: str) -> str:
    if content_type == "image/jpeg" and data.startswith(b"\xff\xd8\xff"):
        return "jpg"
    if content_type == "image/png" and data.startswith(b"\x89PNG\r\n\x1a\n"):
        return "png"
    if content_type == "image/webp" and data[:4] == b"RIFF" and data[8:12] == b"WEBP":
        return "webp"
    return ""


def canonical_platform(platform: str) -> str:
    normalized = platform.strip()
    lowered = normalized.casefold()
    playstation_match = re.fullmatch(r"(?:sony\s+)?playstation\s*(\d+)(?:\s+pro)?", lowered)
    if playstation_match:
        return f"PlayStation {playstation_match.group(1)}"
    if lowered in {"sony playstation", "playstation"}:
        return "PlayStation"
    if lowered in {"sony playstation portable", "playstation portable", "psp"}:
        return "PlayStation Portable (PSP)"
    if lowered in {"sony playstation vita", "playstation vita", "ps vita"}:
        return "PlayStation Vita"

    xbox_aliases = {
        "microsoft xbox": "Xbox",
        "microsoft xbox 360": "Xbox 360",
        "microsoft xbox one": "Xbox One",
        "microsoft xbox one s": "Xbox One S",
        "microsoft xbox one x": "Xbox One X",
        "microsoft xbox series s": "Xbox Series S",
        "microsoft xbox series x": "Xbox Series X",
    }
    if lowered in xbox_aliases:
        return xbox_aliases[lowered]
    if lowered.startswith("xbox"):
        return next(
            (value for key, value in xbox_aliases.items() if key.removeprefix("microsoft ") == lowered),
            normalized,
        )

    nintendo_platforms = {
        "nes": "Nintendo Entertainment System",
        "nintendo entertainment system": "Nintendo Entertainment System",
        "snes": "Super Nintendo Entertainment System",
        "super nintendo": "Super Nintendo Entertainment System",
        "super nintendo entertainment system": "Super Nintendo Entertainment System",
        "nintendo 64": "Nintendo 64",
        "nintendo gamecube": "Nintendo GameCube",
        "nintendo wii": "Wii",
        "wii": "Wii",
        "nintendo wii u": "Wii U",
        "wii u": "Wii U",
        "nintendo switch": "Nintendo Switch",
        "nintendo switch 2": "Nintendo Switch 2",
        "game boy": "Game Boy",
        "game boy color": "Game Boy Color",
        "game boy advance": "Game Boy Advance",
        "nintendo ds": "Nintendo DS",
        "nintendo 3ds": "Nintendo 3DS",
        "new nintendo 3ds": "New Nintendo 3DS",
        "virtual boy": "Virtual Boy",
    }
    return nintendo_platforms.get(lowered, normalized)


def save_cover_image(data: bytes, content_type: str) -> str:
    if len(data) > MAX_COVER_BYTES:
        raise APIError("The cover image is larger than 12 MB.", 413)
    extension = image_extension(data, content_type)
    if not extension:
        raise APIError("The image must be a valid JPEG, PNG, or WebP file.", 415)
    filename = f"{uuid.uuid4()}.{extension}"
    destination = COVER_DIR / filename
    with destination.open("xb") as cover_file:
        os.chmod(destination, 0o600)
        cover_file.write(data)
    return f"/covers/{filename}"


def read_cover_upload(request: BaseHTTPRequestHandler) -> tuple[bytes, str]:
    content_type = request.headers.get("Content-Type", "")
    header = Message()
    header["Content-Type"] = content_type
    boundary = header.get_param("boundary", header="Content-Type")
    if not content_type.lower().startswith("multipart/form-data") or not boundary:
        raise APIError("Upload the cover using a multipart form.")
    try:
        length = int(request.headers.get("Content-Length", "0"))
    except ValueError as error:
        raise APIError("Invalid request body length.") from error
    if length < 0 or length > MAX_MULTIPART_BYTES:
        raise APIError("The upload must be 12 MB or smaller.", 413)
    mime_headers = (
        f"MIME-Version: 1.0\r\nContent-Type: {content_type}\r\n\r\n"
    ).encode("ascii", errors="strict")
    try:
        multipart = BytesParser(policy=default).parsebytes(mime_headers + request.rfile.read(length))
    except (LookupError, UnicodeEncodeError, ValueError) as error:
        raise APIError("The uploaded form is invalid.") from error
    if not multipart.is_multipart():
        raise APIError("The uploaded form is invalid.")
    for part in multipart.iter_parts():
        if part.get_content_disposition() == "form-data" and part.get_param("name", header="Content-Disposition") == "cover":
            image_data = part.get_payload(decode=True)
            if not image_data:
                raise APIError("Choose an image file to upload.")
            return image_data, part.get_content_type().lower()
    raise APIError("Choose an image file to upload.")


def search_thegamesdb(query: str) -> list[dict]:
    try:
        api_key = KEY_FILE.read_text(encoding="utf-8").strip()
    except FileNotFoundError as error:
        raise APIError(
            "Game search is unavailable because no TheGamesDB API key is configured. You can still enter the game details manually.",
            503,
        ) from error
    if not api_key:
        raise APIError(
            "Game search is unavailable because no TheGamesDB API key is configured. You can still enter the game details manually.",
            503,
        )
    query_string = urlencode(
        {"apikey": api_key, "name": query, "include": "boxart,platform", "fields": "overview"}
    )
    url = f"https://api.thegamesdb.net/v1.1/Games/ByGameName?{query_string}"
    try:
        with urlopen(Request(url, headers={"User-Agent": "TheGameLibrary/1.0"}), timeout=15) as response:
            payload = json.loads(response.read(4 * 1024 * 1024))
    except HTTPError as error:
        if error.code == 403:
            raise APIError(
                "TheGamesDB rejected the API key or its usage limit has been reached.",
                502,
            ) from None
        raise APIError(f"TheGamesDB returned an error ({error.code}).", 502) from None
    except (URLError, TimeoutError, json.JSONDecodeError) as error:
        raise APIError(f"Could not complete the TheGamesDB search: {error.reason if isinstance(error, URLError) else 'request timed out or returned invalid data'}.", 502) from None

    game_data = payload.get("data") or {}
    platforms = (payload.get("include") or {}).get("platform", {}).get("data", {})
    boxart = (payload.get("include") or {}).get("boxart", {})
    base_urls = boxart.get("base_url") or {}
    results = []
    for game in (game_data.get("games") or [])[:20]:
        images = (boxart.get("data") or {}).get(str(game.get("id")), [])
        image = next(
            (
                item
                for item in images
                if item.get("type") == "boxart"
                and item.get("side") == "front"
                and isinstance(item.get("filename"), str)
            ),
            None,
        )
        filename = image["filename"].lstrip("/") if image else ""
        if filename and (not filename.startswith("boxart/front/") or ".." in filename):
            image = None
            filename = ""
        original_base = base_urls.get("original", "https://cdn.thegamesdb.net/images/original/")
        preview_base = base_urls.get("medium") or original_base
        release_date = game.get("release_date", "")
        platform_id = str(game.get("platform", ""))
        raw_platform = (platforms.get(platform_id) or {}).get("name", "")
        overview = game.get("overview", "")
        results.append(
            {
                "id": game.get("id"),
                "title": game.get("game_title") or "Untitled game",
                "platform": canonical_platform(raw_platform) if raw_platform else "",
                "year": release_date[:4] if isinstance(release_date, str) else "",
                "description": overview[:5000] if isinstance(overview, str) else "",
                "descriptionSource": canonical_platform(raw_platform) if isinstance(overview, str) and overview.strip() and raw_platform else "",
                "preview": (
                    f"/api/covers/preview?url={quote(f'{preview_base}{filename}', safe='')}"
                    if filename
                    else ""
                ),
                "imageUrl": f"{original_base}{filename}" if filename else "",
            }
        )
    descriptions_by_title = {
        result["title"].casefold(): result
        for result in results
        if result["description"]
    }
    for result in results:
        if result["description"]:
            continue
        sibling = descriptions_by_title.get(result["title"].casefold())
        if sibling:
            result["description"] = sibling["description"]
            result["descriptionSource"] = sibling["descriptionSource"]
    return results


def fetch_cover_image(image_url: str) -> tuple[bytes, str]:
    try:
        parsed = urlsplit(image_url)
    except ValueError as error:
        raise APIError("Choose a valid TheGamesDB cover.") from error
    if (
        parsed.scheme != "https"
        or parsed.netloc != "cdn.thegamesdb.net"
        or parsed.query
        or parsed.fragment
        or not IMAGE_PATH_PATTERN.fullmatch(parsed.path)
    ):
        raise APIError("Only TheGamesDB front-cover images can be downloaded.")
    opener = build_opener(NoRedirectHandler())
    try:
        response = opener.open(
            Request(image_url, headers={"User-Agent": "TheGameLibrary/1.0"}),
            timeout=20,
        )
    except HTTPError as error:
        raise APIError(f"The cover download failed ({error.code}).", 502) from None
    except URLError as error:
        raise APIError(f"Could not download the cover: {error.reason}.", 502) from None
    with response:
        content_type = response.headers.get_content_type().lower()
        content_length = response.headers.get("Content-Length")
        if content_length:
            try:
                if int(content_length) > MAX_COVER_BYTES:
                    raise APIError("The cover image is larger than 12 MB.", 413)
            except ValueError:
                pass
        chunks = []
        total = 0
        while chunk := response.read(64 * 1024):
            total += len(chunk)
            if total > MAX_COVER_BYTES:
                raise APIError("The cover image is larger than 12 MB.", 413)
            chunks.append(chunk)
    image_data = b"".join(chunks)
    extension = image_extension(image_data, content_type)
    if not extension:
        raise APIError("The downloaded file is not a supported JPEG, PNG, or WebP image.", 415)
    return image_data, extension


def download_cover(payload) -> str:
    image_url = payload.get("imageUrl") if isinstance(payload, dict) else None
    if not isinstance(image_url, str):
        raise APIError("Choose a valid TheGamesDB cover.")
    image_data, extension = fetch_cover_image(image_url)
    content_type = {"jpg": "image/jpeg", "png": "image/png", "webp": "image/webp"}[extension]
    return save_cover_image(image_data, content_type)


class GameLibraryHandler(BaseHTTPRequestHandler):
    server_version = "TheGameLibrary/1.0"

    def end_headers(self) -> None:
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header(
            "Content-Security-Policy",
            "default-src 'self'; img-src 'self' https://cdn.thegamesdb.net data:; "
            "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; "
            "connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        )
        super().end_headers()

    def send_json(self, status: int, payload) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self) -> None:
        url = urlsplit(self.path)
        if url.path == "/api/games":
            with STORAGE_LOCK:
                return self.send_json(200, {"games": read_games()})
        if url.path == "/api/search":
            query = parse_qs(url.query).get("q", [""])[0].strip()
            if not query or len(query) > 120:
                raise APIError("Enter a search title up to 120 characters long.")
            return self.send_json(200, {"results": search_thegamesdb(query)})
        if url.path == "/api/covers/preview":
            image_url = parse_qs(url.query).get("url", [""])[0]
            image_data, extension = fetch_cover_image(image_url)
            content_type = {"jpg": "image/jpeg", "png": "image/png", "webp": "image/webp"}[extension]
            self.send_response(200)
            self.send_header("Content-Type", content_type)
            self.send_header("Content-Length", str(len(image_data)))
            self.send_header("Cache-Control", "public, max-age=3600")
            self.end_headers()
            return self.wfile.write(image_data)
        if url.path in ("/", "/TheGameLibrary.html"):
            content = HTML_FILE.read_bytes()
            self.send_response(200)
            self.send_header("Content-Type", "text/html; charset=utf-8")
            self.send_header("Content-Length", str(len(content)))
            self.send_header("Cache-Control", "no-cache")
            self.end_headers()
            return self.wfile.write(content)
        cover_match = re.fullmatch(r"/covers/([0-9a-f-]{36}\.(?:jpg|png|webp))", url.path)
        if cover_match:
            return self.serve_cover(cover_match.group(1))
        self.send_json(404, {"error": "Not found."})

    def do_POST(self) -> None:
        route = urlsplit(self.path).path
        if route == "/api/covers/download":
            cover = download_cover(read_request_json(self))
            return self.send_json(201, {"cover": cover})
        if route == "/api/covers/upload":
            image_data, content_type = read_cover_upload(self)
            cover = save_cover_image(image_data, content_type)
            return self.send_json(201, {"cover": cover})
        if route == "/api/games":
            game = validated_game(read_request_json(self))
            with STORAGE_LOCK:
                games = read_games()
                games.append(game)
                write_games(games)
            return self.send_json(201, {"game": game})
        self.send_json(404, {"error": "API route not found."})

    def do_PUT(self) -> None:
        match = re.fullmatch(r"/api/games/([0-9a-f-]{36})", urlsplit(self.path).path)
        if not match:
            return self.send_json(404, {"error": "API route not found."})
        payload = read_request_json(self)
        with STORAGE_LOCK:
            games = read_games()
            index = next((i for i, game in enumerate(games) if game.get("id") == match.group(1)), None)
            if index is None:
                raise APIError("Game not found.", 404)
            game = validated_game(payload, games[index])
            games[index] = game
            write_games(games)
        self.send_json(200, {"game": game})

    def do_DELETE(self) -> None:
        match = re.fullmatch(r"/api/games/([0-9a-f-]{36})", urlsplit(self.path).path)
        if not match:
            return self.send_json(404, {"error": "API route not found."})
        with STORAGE_LOCK:
            games = read_games()
            index = next((i for i, game in enumerate(games) if game.get("id") == match.group(1)), None)
            if index is None:
                raise APIError("Game not found.", 404)
            game = games.pop(index)
            write_games(games)
        self.send_json(200, {"game": game})

    def serve_cover(self, filename: str) -> None:
        cover_path = COVER_DIR / filename
        content = cover_path.read_bytes()
        content_type = mimetypes.guess_type(filename)[0] or "application/octet-stream"
        self.send_response(200)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(content)))
        self.send_header("Cache-Control", "public, max-age=31536000, immutable")
        self.end_headers()
        self.wfile.write(content)

    def handle_one_request(self) -> None:
        try:
            super().handle_one_request()
        except APIError as error:
            self.send_json(error.status, {"error": str(error)})
        except FileNotFoundError:
            self.send_json(404, {"error": "File not found."})
        except (ValueError, json.JSONDecodeError):
            self.send_json(500, {"error": "The game library data is invalid."})
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True


def main() -> None:
    ensure_storage()
    server = ThreadingHTTPServer((HOST, PORT), GameLibraryHandler)
    print(f"The Game Library is running at http://{HOST}:{PORT}", flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down The Game Library.", flush=True)
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
