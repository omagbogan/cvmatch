FROM php:8.2-apache

# Apache + PHP pour l'application web.
RUN apt-get update \
    && apt-get install -y --no-install-recommends zip unzip \
    && docker-php-ext-install pdo_mysql \
    && a2dismod mpm_event mpm_worker mpm_prefork || true \
    && rm -f /etc/apache2/mods-enabled/mpm*.load /etc/apache2/mods-enabled/mpm*.conf \
    && a2enmod mpm_prefork \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copier les sources et garder les permissions
COPY . /var/www/html/
RUN mkdir -p uploads/cvs logs && chown -R www-data:www-data /var/www/html/uploads /var/www/html/logs /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

CMD ["bash", "/usr/local/bin/docker-entrypoint.sh"]
