FROM dunglas/frankenphp:latest-php8.4

# Install system dependencies and PDO MySQL extension
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libwebp-dev \
    libzip-dev \
    unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Set working directory
WORKDIR /app

# Copy application source
COPY . .

# Ensure upload directories exist and have correct permissions
RUN mkdir -p uploads/verification \
    && chown -R www-data:www-data /app \
    && chmod -R 755 /app \
    && chmod -R 775 /app/uploads

# Expose the port Railway expects
EXPOSE 8080

# FrankenPHP serves the current directory by default;
# override the listen address to match Railway's PORT expectation
ENV SERVER_NAME=":8080"
