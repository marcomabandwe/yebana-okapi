
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli

RUN a2enmod rewrite headers

COPY . /var/www/html/

# Supprimer les BOM UTF-8 au début des fichiers PHP
RUN find /var/www/html -type f -name "*.php" \
    -exec sed -i '1s/^\xEF\xBB\xBF//' {} +

# Autoriser les origines Web locales connues
RUN printf '%s\n' \
    '<IfModule mod_headers.c>' \
    '    SetEnvIfNoCase Origin "^https?://localhost:(8081|19006)$" CORS_ORIGIN=$0' \
    '    Header always set Access-Control-Allow-Origin "%{CORS_ORIGIN}e" env=CORS_ORIGIN' \
    '    Header always merge Vary "Origin"' \
    '</IfModule>' \
    > /etc/apache2/conf-available/yebana-cors.conf

RUN a2enconf yebana-cors

EXPOSE 80
