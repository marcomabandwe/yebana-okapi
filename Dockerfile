
FROM php:8.2-apache

# Installer les extensions PHP pour MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Activer les modules Apache
RUN a2enmod rewrite headers

# Copier tous les fichiers du backend
COPY . /var/www/html/

# Supprimer les BOM UTF-8 au debut des fichiers PHP
RUN find /var/www/html -type f -name "*.php" \
    -exec sed -i '1s/^\xEF\xBB\xBF//' {} +

# Afficher les premiers octets pour le diagnostic
RUN od -An -tx1 -N16 /var/www/html/api/public_stats.php

# Configurer les autorisations CORS
RUN printf '%s\n' \
    '<IfModule mod_headers.c>' \
    '    SetEnvIfNoCase Origin "^https?://localhost:(8081|19006)$" CORS_ORIGIN=$0' \
    '    Header always set Access-Control-Allow-Origin "%{CORS_ORIGIN}e" env=CORS_ORIGIN' \
    '    Header always set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"' \
    '    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, Accept"' \
    '    Header always merge Vary "Origin"' \
    '</IfModule>' \
    > /etc/apache2/conf-available/yebana-cors.conf

# Activer la configuration CORS
RUN a2enconf yebana-cors

# Port HTTP du serveur
EXPOSE 80
