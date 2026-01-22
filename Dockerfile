FROM php:8.2-fpm-alpine

# Install nginx + envsubst + bash + php extensions
RUN apk add --no-cache nginx bash curl gettext \
  && docker-php-ext-install pdo pdo_mysql

# Create dirs nginx needs
RUN mkdir -p /run/nginx /var/log/nginx

WORKDIR /var/www/html
COPY . .

# Copy nginx template + start script
COPY nginx.conf.template /etc/nginx/http.d/default.conf.template
COPY start.sh /start.sh
RUN chmod +x /start.sh

# Render sets PORT (default 10000)
EXPOSE 10000

CMD ["/start.sh"]
