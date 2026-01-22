FROM php:8.2-fpm-alpine

# Install nginx + needed extensions
RUN apk add --no-cache nginx bash curl \
  && docker-php-ext-install pdo pdo_mysql

# Create dirs
RUN mkdir -p /run/nginx /var/log/nginx

# Copy app
WORKDIR /var/www/html
COPY . .

# Nginx config
COPY nginx.conf /etc/nginx/http.d/default.conf

# Start script
COPY start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 10000
CMD ["/start.sh"]
