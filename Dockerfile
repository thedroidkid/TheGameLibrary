FROM php:8.3-apache

RUN a2enmod rewrite

COPY docker/apache-library.conf /etc/apache2/conf-available/game-library.conf
RUN a2enconf game-library

WORKDIR /var/www/html
COPY TheGameLibrary.html api.php .htaccess ./
COPY games.json /opt/game-library-seed/games.json
COPY docker/entrypoint.sh /usr/local/bin/game-library-entrypoint

RUN chmod 755 /usr/local/bin/game-library-entrypoint

ENV GAME_LIBRARY_DATA_DIR=/data
ENV THEGAMESDB_KEY_FILE=/data/thegamesdb-key

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/game-library-entrypoint"]
