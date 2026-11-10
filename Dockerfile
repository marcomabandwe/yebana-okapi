FROM php:8.2-apache

# Installer les extensions MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Activer les modules Apache
RUN a2enmod rewrite headers

# Copier le backend
COPY . /var/www/html/

# Supprimer les BOM UTF-8 et les espaces
# avant l'ouverture PHP
RUN php -r '$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("/var/www/html")); foreach ($dir as $file) { if (!$file->isFile() || strtolower($file->getExtension()) !== "php") continue; $path = $file->getPathname(); $content = file_get_contents($path); $clean = preg_replace("/^\xEF\xBB\xBF/", "", $content); if (preg_match("/^\s*<\?php/", $clean)) { $clean = ltrim($clean); } if ($clean !== $content) { file_put_contents($path, $clean); echo "Corrige: $path\n"; } }'

# Configurer CORS pour le Web local
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

# Definir le nom du serveur Apache
RUN printf '%s\n' 'ServerName localhost' \
    > /etc/apache2/conf-available/servername.conf

RUN a2enconf servername

# Verifier les premiers octets du fichier PHP
RUN od -An -tx1 -N16 /var/www/html/api/public_stats.php

EXPOSE 80
