# The Game Library

A self-hosted web app for tracking a personal game collection. Add and edit games, sort by title or platform, search TheGamesDB when you provide an API key, or enter game details and upload cover art manually.

## Features

- Add, edit, and remove games from the web interface.
- Track platform, release year, status, description, and notes.
- Sort the library by title or platform and filter by title or status.
- Optionally search TheGamesDB for game details and cover art.
- Upload a personal JPEG, PNG, or WebP cover (up to 12 MB).
- Store the library and covers persistently when running in Docker.

## Requirements

- Docker Engine and the Docker Compose plugin.
- A TheGamesDB API key only if you want catalog search. Manual game entry and cover uploads work without one.

## Quick start with Docker Compose

1. Copy or clone this project onto the Linux server. The deployment needs `compose.yaml` and the `config/` directory; the application image is pulled from Docker Hub.
2. From the project directory, pull and start the app:

   ```bash
   docker compose pull
   docker compose up -d
   ```

3. Open the app from a device that can reach the server:

   ```text
   http://SERVER-IP:8080/
   ```

By default, Docker publishes the app on port 8080. To use a different port, set `GAME_LIBRARY_PORT` when starting the container:

```bash
docker compose pull
GAME_LIBRARY_PORT=8091 docker compose up -d
```

Then open `http://SERVER-IP:8091/`.

### Optional TheGamesDB search

The app starts without an API key. In that mode, users can create games by filling out the form and can upload cover images. The catalog search button will explain that search has not been configured.

To enable TheGamesDB search, provide your own key in `config/thegamesdb-key` on the server:

```bash
mkdir -p config
chmod 700 config
printf '%s\n' 'YOUR_THEGAMESDB_KEY' > config/thegamesdb-key
chmod 600 config/thegamesdb-key
docker compose restart game-library
```

Replace the example value with your key. Keep this file private and do not commit it or include it in a public deployment. The Compose setup mounts the `config/` directory read-only, and the Docker build excludes the key.

## Data, persistence, and backups

Docker stores the game list and cover images in the persistent `library-data` volume. The first time the container starts with a new volume, it copies the empty starter `games.json` into that volume. Rebuilding or replacing the container keeps the volume and its contents.

Useful commands:

```bash
docker compose logs -f game-library  # Follow app logs; Ctrl+C stops following logs
docker compose ps                    # Check container status
docker compose stop                  # Stop without deleting saved data
docker compose up -d                 # Start again
docker compose down                  # Remove the container and network, retain saved data
```

Back up the volume before migrating servers or making major changes. For example, from the project directory:

```bash
docker run --rm \
  -v game-library_library-data:/data:ro \
  -v "$PWD:/backup" \
  alpine tar -czf /backup/game-library-data-backup.tar.gz -C /data .
```

The exact volume name can differ if Compose uses a project name. Check it with `docker volume ls` and substitute the actual name for `game-library_library-data`. Store backups somewhere safe; they contain the library and uploaded cover images.

**Do not run `docker compose down -v` unless you intend to delete the persistent game library.**

## Security

The app currently has no user accounts or login. Anyone who can reach it can view, add, edit, or delete games. Keep it on a trusted home network, and do not expose its port directly to the public internet. If you need remote access, use a VPN or put it behind an authenticated reverse proxy.

## Troubleshooting

- **The page does not open:** Check `docker compose ps`, confirm the port is reachable from your network, and make sure another service is not already using it. Try another port with `GAME_LIBRARY_PORT=8091`.
- **The page loads but games do not:** Check the API and container logs with `docker compose logs --tail=100 game-library`.
- **Search is unavailable:** Manual entry still works. To use search, configure `config/thegamesdb-key` and restart the container.
- **Uploads fail:** The app accepts JPEG, PNG, and WebP images up to 12 MB. Check available disk space in Docker storage.
- **Changes disappeared:** Confirm the `library-data` volume is still attached. Do not remove it with `docker compose down -v`.

## Running without Docker

The project also includes a PHP backend for Apache hosting and an optional Python standard-library server:

- For Apache, use PHP 8.1+ with `mod_rewrite`; see the Apache section below.
- For the Python server, use Python 3.10+ and run `python3 server.py`. It binds to `127.0.0.1:8000` by default. Set `HOST=0.0.0.0` to allow other devices on the local network to connect.

Do not expose either non-Docker server publicly without adding authentication.

### Apache with PHP

Copy the project files to the Apache document root, retaining `games.json` and `covers/`. Enable `mod_rewrite` and `.htaccess` overrides, then ensure the PHP/Apache user can write `games.json` and `covers/`. Open `http://SERVER-IP/TheGameLibrary.html`.

Requirements: Apache 2.4, PHP 8.1+, and PHP's `fileinfo`, `openssl`, and URL stream support (`allow_url_fopen`). To enable TheGamesDB search, put your key in `.thegamesdb-key` beside the app files. The included `.htaccess` blocks direct HTTP access to the key and data files. Configure PHP upload limits to allow at least 13 MB for `upload_max_filesize` and `post_max_size`.

## License

No license has been selected yet. Add a license before redistributing or accepting contributions under an open-source license.
