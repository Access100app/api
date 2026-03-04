FROM php:8.2-apache

# Enable mod_rewrite (routing) and mod_headers (security headers)
RUN a2enmod rewrite headers

# Hide PHP version from response headers
RUN echo 'expose_php = Off' > /usr/local/etc/php/conf.d/security.ini

# Install PDO MySQL — required for all API database calls
RUN docker-php-ext-install pdo_mysql

# pdftotext for extracting agenda text from PDF attachments
# cron for scheduled jobs (reminders, digests, scraping, etc.)
RUN apt-get update && apt-get install -y poppler-utils cron && rm -rf /var/lib/apt/lists/*

# Allow .htaccess to override Apache config in the document root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Rate limiter middleware writes lock files here; must exist and be writable
RUN mkdir -p /tmp/access100_ratelimit \
    && chown www-data:www-data /tmp/access100_ratelimit \
    && chmod 750 /tmp/access100_ratelimit

# Logrotate for cron log files (prevent unbounded growth)
RUN printf '/var/log/access100-*.log {\n  daily\n  rotate 14\n  compress\n  missingok\n  notifempty\n  copytruncate\n}\n' \
    > /etc/logrotate.d/access100

# Install crontab for scheduled jobs (/etc/cron.d/ format with user field)
COPY crontab /etc/cron.d/access100
RUN chmod 0644 /etc/cron.d/access100

# Pre-create log files owned by www-data so cron jobs can write to them
RUN touch /var/log/access100-scrape.log \
         /var/log/access100-scrape-nco.log \
         /var/log/access100-scrape-hnl-boards.log \
         /var/log/access100-scrape-maui.log \
         /var/log/access100-notify.log \
         /var/log/access100-digest.log \
         /var/log/access100-weekly.log \
         /var/log/access100-reminder.log \
         /var/log/access100-classify.log \
         /var/log/access100-cleanup.log \
    && chown www-data:www-data /var/log/access100-*.log

# Entrypoint: start cron, then hand off to Apache
COPY docker-entrypoint.sh /usr/local/bin/
ENTRYPOINT ["docker-entrypoint.sh"]

# public_html/ mounts to /var/www/html (the default DocumentRoot)
