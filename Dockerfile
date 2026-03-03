FROM php:8.2-apache

# Enable mod_rewrite so .htaccess routing works
RUN a2enmod rewrite

# Install PDO MySQL — required for all API database calls
RUN docker-php-ext-install pdo_mysql

# pdftotext for extracting agenda text from PDF attachments
# cron for scheduled jobs (reminders, digests, scraping, etc.)
RUN apt-get update && apt-get install -y poppler-utils cron && rm -rf /var/lib/apt/lists/*

# Allow .htaccess to override Apache config in the document root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Rate limiter middleware writes lock files here; must exist and be writable
RUN mkdir -p /tmp/access100_ratelimit \
    && chmod 777 /tmp/access100_ratelimit

# Install crontab for scheduled jobs
COPY crontab /etc/cron.d/access100
RUN chmod 0644 /etc/cron.d/access100 && crontab /etc/cron.d/access100

# Entrypoint: start cron, then hand off to Apache
COPY docker-entrypoint.sh /usr/local/bin/
ENTRYPOINT ["docker-entrypoint.sh"]

# public_html/ mounts to /var/www/html (the default DocumentRoot)
