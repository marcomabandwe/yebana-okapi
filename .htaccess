
FROM php:8.2-apache

# Installer les extensions PHP necessaires
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Activer les modules Apache
RUN a2enmod rewrite headers

# Copier le backend dans Apache
COPY . /var/www/html/

# Supprimer les BOM UTF-8 et les lignes vides
# avant l'ouverture PHP dans les fichiers concernes
RUN python3 - <<'PY'
from pathlib import Path

root = Path('/var/www/html')

for path in root.rglob('*.php'):
    content = path.read_bytes()

    cleaned = content

    if cleaned.startswith(b'\xef\xbb\xbf'):
        cleaned = cleaned[3:]

    if cleaned.lstrip(b' \t\r\n').startswith(b'<?php'):
        cleaned = cleaned.lstrip(b' \t\r\n')

    if cleaned != content:
        path.write_bytes(cleaned)
        print('Fichier corrige:', path)
PY

# Configurer Apache pour les requetes CORS
RUN printf '%s\n' \
    '<IfModule mod_headers.c>' \
    '    SetEnvIfNoCase Origin "^https?://localhost:(8081|19006)$" CORS_ORIGIN=$0' \
    '    Header always set Access-Control-Allow-Origin "%{CORS_ORIGIN}e" env=CORS_ORIGIN' \
    '    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"' \
    '    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, Accept"' \
    '    Header always merge Vary "Origin"' \
    '</IfModule>' \
    > /etc/apache2/conf-available/yebana-cors.conf

# Activer la configuration Apache
RUN a2enconf yebana-cors

# Eviter les avertissements de nom du serveur
RUN echo "ServerName localhost" \
    > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Verifier les premiers octets du fichier
RUN od -An -tx1 -N16 /var/www/html/api/public_stats.php

EXPOSE 80
