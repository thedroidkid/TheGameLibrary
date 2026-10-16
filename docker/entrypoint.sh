#!/bin/sh
set -eu

mkdir -p /data/covers

if [ ! -f /data/games.json ]; then
    cp /opt/game-library-seed/games.json /data/games.json
fi

chown -R www-data:www-data /data
chmod 660 /data/games.json
chmod 750 /data/covers

if [ -s /run/game-library-config/thegamesdb-key ] && [ ! -s /data/thegamesdb-key ]; then
    install -o www-data -g www-data -m 0400 /run/game-library-config/thegamesdb-key /data/thegamesdb-key
fi

exec apache2-foreground
