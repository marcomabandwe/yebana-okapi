FROM php:8.2-apache

# Extensions PHP pour MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Modules Apache
RUN a2enmod rewrite headers

# Copier les fichiers du backend
COPY . /var/www/html/

# Supprimer les BOM et les espaces avant <?php
RUN python3 -c "from pathlib import Path; root=Path('/var/www/html'); files=list(root.rglob('*.php')); [(p.write_bytes(c.lstrip(b' \t\r\n')) if c.lstrip(b' \t\r\n').startswith(b'<?php') and c.lstrip(b' \t\r\n') != c else None) for p in files for c in [p.read_bytes().removeprefix(bytes.fromhex('efbbbf'))]]"

# Configurer CORS dans Apache
RUN printf '%s\n' \
    '<IfModule mod_headers.c>' \
    '    SetEnvIfNoCase Origin "^https?://localhost:(8081|19006)$" CORS_ORIGIN=$0' \
    '    Header always set Access-Control-Allow-Origin "%{CORS_ORIGIN}e" env=CORS_ORIGIN' \
    '    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"' \
    '    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, Accept"' \
    '    Header always merge Vary "Origin"' \
    '</IfModule>' \
    > /etc/apache2/conf-available/yebana-cors.conf

RUN a2enconf yebana-cors

# Configurer le nom du serveur
RUN echo "ServerName localhost" \
    > /etc/apache2/conf-available/servername.conf

RUN a2enconf servername

# Verifier les premiers octets
RUN od -An -tx1 -N16 /var/www/html/api/public_stats.php

EXPOSE 80
