# Docker Deployment

Production-ready Docker deployment for the Professional Services Accounting System.

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                      Load Balancer (SSL)                     │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                    Docker Compose Stack                     │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐   │
│  │   Nginx      │  │   PHP-FPM   │  │   Laravel       │   │
│  │   (Web)      │──│   App       │──│   Queue Worker │   │
│  └─────────────┘  └─────────────┘  └─────────────────┘   │
│                           │                   │            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────────┐   │
│  │   MySQL      │  │   Redis      │  │   Scheduler     │   │
│  │   (DB)       │  │   (Cache/Q) │  │   (Cron)        │   │
│  └─────────────┘  └─────────────┘  └─────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## Prerequisites

- Docker Engine 24.0+
- Docker Compose 2.20+
- Git

## Quick Start

### 1. Clone and Configure

```bash
git clone <repo-url> psa
cd psa
cp .env.example .env
```

### 2. Update `.env` for Production

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

LOG_CHANNEL=daily
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=psa
DB_USERNAME=psa_user
DB_PASSWORD=secure_password

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
```

### 3. Build and Start

```bash
docker compose up -d --build
```

### 4. Run Migrations

```bash
docker compose exec app php artisan migrate --force
```

### 5. Create Queue Worker & Scheduler Containers

The `docker-compose.yml` includes pre-configured services for queue workers and the scheduler.

## Docker Compose Configuration

### `docker-compose.yml`

```yaml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: psa-app
    restart: unless-stopped
    volumes:
      - ./storage:/var/www/html/storage
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - mysql
      - redis
    networks:
      - psa-network

  web:
    image: nginx:alpine
    container_name: psa-web
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./storage:/var/www/html/storage:ro
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app
    networks:
      - psa-network

  queue:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: psa-queue
    restart: unless-stopped
    command: php artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
    volumes:
      - ./storage:/var/www/html/storage
    depends_on:
      - mysql
      - redis
    networks:
      - psa-network

  scheduler:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: psa-scheduler
    restart: unless-stopped
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
    volumes:
      - ./storage:/var/www/html/storage
    depends_on:
      - mysql
      - redis
    networks:
      - psa-network

  mysql:
    image: mysql:8.0
    container_name: psa-mysql
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: psa
      MYSQL_USER: psa_user
      MYSQL_PASSWORD: secure_password
      MYSQL_ROOT_PASSWORD: root_secure_password
    volumes:
      - psa-mysql-data:/var/lib/mysql
      - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf:ro
    ports:
      - "3306:3306"
    networks:
      - psa-network

  redis:
    image: redis:alpine
    container_name: psa-redis
    restart: unless-stopped
    volumes:
      - psa-redis-data:/data
    ports:
      - "6379:6379"
    networks:
      - psa-network

volumes:
  psa-mysql-data:
  psa-redis-data:

networks:
  psa-network:
    driver: bridge
```

### `Dockerfile`

```dockerfile
FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    redis \
    supervisor

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage

# Copy supervisor configuration
COPY docker/supervisor.conf /etc/supervisor/conf.d/supervisor.conf

EXPOSE 9000

CMD ["php-fpm"]
```

### `docker/supervisor.conf`

```ini
[supervisorctl]
serverurl=unix:///var/run/supervisor.sock

[supervisor:nanny]
command=php /var/www/html/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=4
process_name=%(program_name)s_%(process_num)02d
```

### `nginx/default.conf`

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name localhost;
    root /var/www/html/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # SSL Configuration (uncomment for HTTPS)
    # listen 443 ssl http2;
    # listen [::]:443 ssl http2;
    # ssl_certificate /etc/nginx/ssl/cert.pem;
    # ssl_certificate_key /etc/nginx/ssl/key.pem;
}
```

### `docker/mysql/my.cnf`

```ini
[mysqld]
default-authentication-plugin=mysql_native_password
max_connections=200
innodb_buffer_pool_size=256M
```

## SSL Configuration

### Option 1: Let's Encrypt with Certbot

```bash
# Install Certbot
apt install certbot python3-certbot-nginx

# Generate certificate
certbot --nginx -d your-domain.com

# Auto-renew (already configured by default)
```

### Option 2: Reverse Proxy with SSL

Update `docker-compose.yml` to add a Traefik or Nginx proxy:

```yaml
services:
  traefik:
    image: traefik:v2.10
    container_name: psa-proxy
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock:ro
      - ./docker/traefik/traefik.yml:/etc/traefik/traefik.yml:ro
      - ./docker/traefik/acme.json:/etc/traefik/acme.json
    networks:
      - psa-network
```

## Deployment Commands

### Initial Deployment

```bash
# Clone repository
git clone <repo-url> psa && cd psa

# Configure environment
cp .env.example .env
# Edit .env with production values

# Build and start
docker compose up -d --build

# Run migrations
docker compose exec app php artisan migrate --force

# Create storage link
docker compose exec app php artisan storage:link

# Clear and optimize caches
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan config:cache
docker compose exec app php artisan route:cache
docker compose exec app php artisan view:cache
```

### Update Deployment

```bash
# Pull latest code
git pull origin phase-6

# Rebuild and restart
docker compose up -d --build

# Run migrations if needed
docker compose exec app php artisan migrate --force

# Clear caches
docker compose exec app php artisan optimize:clear
```

### Database Backup Before Update

```bash
# Create backup
docker compose exec mysql mysqldump -u psa_user -p psa > backup_$(date +%Y%m%d_%H%M%S).sql

# Or backup with Docker
docker compose exec -T mysql mysqldump -u psa_user -p psa > backup.sql
```

## Maintenance

### View Logs

```bash
# All containers
docker compose logs -f

# Specific service
docker compose logs -f app
docker compose logs -f queue

# Last 100 lines
docker compose logs --tail=100 app
```

### Restart Services

```bash
# Single service
docker compose restart app

# All services
docker compose restart
```

### Shell Access

```bash
# App container
docker compose exec app sh

# MySQL shell
docker compose exec mysql mysql -u psa_user -p psa
```

### Database Operations

```bash
# Run migrations
docker compose exec app php artisan migrate

# Rollback last migration
docker compose exec app php artisan migrate:rollback

# Fresh migrate with seeding
docker compose exec app php artisan migrate:fresh --seed
```

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker compose logs app

# Check Dockerfile syntax
docker compose config

# Rebuild without cache
docker compose build --no-cache
```

### Permission Denied Errors

```bash
# Fix storage permissions
docker compose exec app chown -R www-data:www-data /var/www/html/storage
docker compose exec app chmod -R 775 /var/www/html/storage/bootstrap/cache
```

### Database Connection Failed

```bash
# Check MySQL is running
docker compose ps mysql

# Check MySQL logs
docker compose logs mysql

# Test connection
docker compose exec app php artisan tinker --execute="DB::connection()->getPdo();"
```

### Queue Jobs Not Processing

```bash
# Check queue worker logs
docker compose logs queue

# Restart queue workers
docker compose restart queue

# Verify Redis connection
docker compose exec app php artisan tinker --execute="Redis::ping();"
```

### SSL Certificate Issues

```bash
# Check certificate
openssl s_client -connect your-domain.com:443 -showcerts

# Renew Let's Encrypt
certbot renew

# Or in Docker with Traefik, certificates auto-renew
```

## Security Checklist

- [ ] Set `APP_DEBUG=false`
- [ ] Use strong database passwords
- [ ] Configure firewall (ufw)
- [ ] Enable SSL/TLS
- [ ] Set up fail2ban
- [ ] Restrict container network access
- [ ] Regular security updates: `docker compose pull`
- [ ] Rotate secrets periodically

## Backup Strategy

See [Backup & Restore Runbook](../runbooks/backup-restore.md) for comprehensive backup procedures.

## Additional Resources

- [Laravel Docker Documentation](https://laravel.com/docs/docker)
- [Docker Compose Documentation](https://docs.docker.com/compose/)
- [Nginx Best Practices](https://www.nginx.com/resources/wiki/start/topics/tutorials/best_practices/)
