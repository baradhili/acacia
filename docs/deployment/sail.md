# Laravel Sail Deployment

Laravel Sail provides a simple Docker-based development environment using Laravel's official Docker images.

## Requirements

- Docker Desktop (macOS/Windows) or Docker Engine (Linux)
- Docker Compose
- PHP 8.2+ (optional, for local CLI access)

## Quick Start

### 1. Install Laravel Sail

```bash
composer require laravel/sail --dev
php artisan sail:install
```

### 2. Configure Environment

Update `.env` file:

```env
SAIL_DB_CONNECTION=mysql
SAIL_DB_HOST=mysql
SAIL_DB_PORT=3306
SAIL_DB_DATABASE=psa
SAIL_DB_USERNAME=sail
SAIL_DB_PASSWORD=password
```

### 3. Start Containers

```bash
./vendor/bin/sail up
```

Or run in background:

```bash
./vendor/bin/sail up -d
```

### 4. Run Migrations

```bash
./vendor/bin/sail artisan migrate
```

### 5. Seed Database (Optional)

```bash
./vendor/bin/sail artisan db:seed
```

## Sail Commands Reference

| Command | Description |
|---------|-------------|
| `./vendor/bin/sail up` | Start all containers |
| `./vendor/bin/sail up -d` | Start containers in background |
| `./vendor/bin/sail down` | Stop all containers |
| `./vendor/bin/sail down -v` | Stop and remove volumes |
| `./vendor/bin/sail stop` | Stop containers (keep volumes) |
| `./vendor/bin/sail restart` | Restart all containers |
| `./vendor/bin/sail build` | Build/rebuild images |
| `./vendor/bin/sail logs` | View container logs |
| `./vendor/bin/sail logs -f` | Follow logs |
| `./vendor/bin/sail artisan` | Run artisan commands |
| `./vendor/bin/sail php` | Run PHP commands |
| `./vendor/bin/sail composer` | Run composer commands |
| `./vendor/bin/sail test` | Run tests |
| `./vendor/bin/sail shell` | Open shell in workspace |
| `./vendor/bin/sail root-shell` | Open root shell |

## Container Services

Sail includes these services by default:

| Service | Port | Description |
|---------|------|-------------|
| laravel.test | 80 | Main Laravel application |
| mysql | 3306 | MySQL 8.0 database |
| redis | 6379 | Redis cache/queue |
| mailhog | 1025/8025 | Local email testing |
| selenium | 4444 | Browser testing (optional) |

## Customizing Sail

### Add Additional Services

Edit `docker-compose.yml`:

```yaml
services:
  # ... existing services ...
  
  meilisearch:
    image: getmeili/meilisearch:latest
    environment:
      MEILISEARCH_KEY: 'masterKey'
    ports:
      - "7700:7700"
```

### Configure PHP Settings

Create `docker/8.2/php.ini` (or appropriate version folder):

```ini
upload_max_filesize=50M
post_max_size=50M
max_execution_time=300
memory_limit=512M
```

### Custom MySQL Configuration

Create `docker/mysql/my.cnf`:

```ini
[mysqld]
default-authentication-plugin=mysql_native_password
max_connections=200
```

## Development Workflow

### Running Tests

```bash
# Run all tests
./vendor/bin/sail test

# Run specific test file
./vendor/bin/sail test --filter=InvoiceTest

# Run with coverage
./vendor/bin/sail test --coverage
```

### Database Operations

```bash
# Open MySQL shell
./vendor/bin/sail mysql

# Run migrations
./vendor/bin/sail artisan migrate

# Refresh and seed
./vendor/bin/sail artisan migrate:fresh --seed

# Import database
./vendor/bin/sail mysql < backup.sql
```

### Queue Workers

```bash
# Start queue worker
./vendor/bin/sail artisan queue:work

# Start with Supervisor (production)
# See Docker deployment guide for production queue setup
```

### Scheduler

```bash
# Test scheduler locally
./vendor/bin/sail artisan schedule:work
```

## Troubleshooting

### Port Already in Use

```bash
# Check what's using port 80
lsof -i :80

# Change Sail port in docker-compose.yml
ports:
  - "8080:80"
```

### MySQL Connection Refused

```bash
# Check MySQL container is running
docker ps

# View MySQL logs
./vendor/bin/sail logs mysql

# Restart MySQL
./vendor/bin/sail restart mysql
```

### Permission Denied

```bash
# Fix storage permissions
./vendor/bin/sail exec laravel.test chown -R sail:sail /var/www/html/storage
./vendor/bin/sail exec laravel.test chmod -R 775 /var/www/html/storage
```

### Out of Disk Space

```bash
# Remove unused images
docker system prune -a

# Remove all volumes (WARNING: deletes data)
./vendor/bin/sail down -v
```

## Production Considerations

> **Warning:** Sail is designed for local development. For production, use the [Docker deployment guide](docker.md) or [Laravel Forge](forge.md).

For production-like testing with Sail:

1. Copy `.env.production` to `.env`
2. Set `APP_DEBUG=false`
3. Use MySQL volume for persistence:

```yaml
volumes:
  - sail-mysql:/var/lib/mysql
```

## Additional Resources

- [Laravel Sail Documentation](https://laravel.com/docs/sail)
- [Docker Documentation](https://docs.docker.com/)
- [Laravel DockerHub Images](https://hub.docker.com/u/laravel)
