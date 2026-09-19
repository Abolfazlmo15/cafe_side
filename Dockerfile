# ─────────────────────────────────────────────
# Cafe Side · PHP 8.3 CLI on Wasmer Edge
# ─────────────────────────────────────────────
FROM php:8.3-cli-alpine

# Install extensions the app needs
RUN apk add --no-cache \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        oniguruma-dev \
        libzip-dev \
        unzip \
        curl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        mbstring \
        zip \
        opcache

# Work from the app root
WORKDIR /app

# Copy the whole project in (code + assets/images/ together)
COPY . .

# Expose assets under the public web root so URLs like
# https://your-domain.com/assets/images/Items/Latte.png resolve correctly.
RUN cp -r /app/assets /app/public/assets

# Wasmer Edge runs this command to start your app.
# PHP's built-in server on port 8080, with public/ as the web root.
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]