# The Game Library

A self-hosted web app for tracking a personal game collection. Add and edit games, sort by title or platform, search TheGamesDB when you provide an API key, or enter game details and upload cover art manually.

## Features

- Add, edit, and remove games from the web interface.
- Click a game's title or cover to view its full details and edit it from that view.
- Track platform, release year, status, description, and notes.
- Sort the library by title or platform and filter by title, status, or platform.
- Export the full game library to a spreadsheet-friendly CSV file.
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

By default, Compose uses the `latest` image, which is updated from the `main` branch. To pin a release instead, set `GAME_LIBRARY_VERSION` to its image tag when pulling and starting the container:

```bash
GAME_LIBRARY_VERSION=v1.3 docker compose pull
GAME_LIBRARY_VERSION=v1.3 docker compose up -d
```

You can also set `GAME_LIBRARY_VERSION` in a local `.env` file so every Compose command uses the same version. To publish a new version, create and push a Git tag beginning with `v` (for example, `v1.3`). The Docker workflow publishes an image with that exact tag; it does not move the `latest` tag, which continues to track `main`.

By default, Docker publishes the app on port 8080. To use a different port, set `GAME_LIBRARY_PORT` when starting the container:

```bash
docker compose pull
GAME_LIBRARY_PORT=8091 docker compose up -d
```

Then open `http://SERVER-IP:8091/`.

### Optional TheGamesDB search

The app starts without an API key. In the app, open **Settings**, enter a TheGamesDB API key, and save it. The key is stored in the persistent Docker data volume and takes effect immediately; it is not returned to the browser. In the same settings dialog, users can choose an accent color or enter a HEX value; the selected color is shared by everyone and stored in that volume.

For existing deployments, a key in `config/thegamesdb-key` is imported into the data volume when no key has been saved there yet. Keep this file private and do not commit it or include it in a public deployment.

## Data, persistence, and backups

Docker stores the game list, cover images, TheGamesDB API key, and accent color in the persistent `library-data` volume. The first time the container starts with a new volume, it copies the empty starter `games.json` into that volume. Rebuilding or replacing the container keeps the volume and its contents.

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

The exact volume name can differ if Compose uses a project name. Check it with `docker volume ls` and substitute the actual name for `game-library_library-data`. Store backups somewhere safe; they contain the library, uploaded cover images, API key, and appearance settings.

**Do not run `docker compose down -v` unless you intend to delete the persistent game library.**

## Security

The app currently has no user accounts or login. Anyone who can reach it can view, add, edit, or delete games, and replace or remove the shared TheGamesDB API key. Keep it on a trusted home network, and do not expose its port directly to the public internet. If you need remote access, use a VPN or put it behind an authenticated reverse proxy.

## Troubleshooting

- **The page does not open:** Check `docker compose ps`, confirm the port is reachable from your network, and make sure another service is not already using it. Try another port with `GAME_LIBRARY_PORT=8091`.
- **The page loads but games do not:** Check the API and container logs with `docker compose logs --tail=100 game-library`.
- **Search is unavailable:** Manual entry still works. To use search, add a TheGamesDB API key in the app's Settings.
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
