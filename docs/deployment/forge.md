# Laravel Forge Deployment

Laravel Forge is the recommended deployment method for traditional VPS hosting.

## Server Requirements

- Ubuntu 20.04+ (recommended)
- 2GB+ RAM
- Nginx
- PHP 8.2+
- MySQL 8.0+ or PostgreSQL 15+
- Redis
- Supervisor

## Step 1: Create Server

1. Log in to [Laravel Forge](https://forge.laravel.com)
2. Click **Create Server**
3. Select provider (DigitalOcean, AWS, Hetzner, etc.)
4. Choose server size and region
5. Wait for provisioning to complete

## Step 2: Install Application

### Option A: New Site from Repository

1. Click **Sites** → **Create Site**
2. Select **PHP Application**
3. Enter domain name
4. Under **Repository**, connect your GitHub/GitLab repo
5. Select branch: `phase-6`

### Option B: Existing Site

If site already exists:

1. Go to **Sites** → select your site
2. Click **Git** tab
3. Update branch if needed

## Step 3: Configure Environment

1. Click **Environment** tab
2. Update `.env` file with production values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=psa
DB_USERNAME=forge
DB_PASSWORD=your_secure_password

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=hello@your-domain.com
MAIL_FROM_NAME="${APP_NAME}"

WISE_API_KEY=your_production_key
```

3. Click **Update Environment**

## Step 4: Deploy

1. Click **Deploy Now** or push to `phase-6` branch
2. Watch deployment log for errors
3. Forge will automatically:
   - Run `composer install`
   - Run migrations
   - Clear caches
   - Restart queue workers

## Step 5: Configure SSL

1. Go to **SSL** tab
2. Click **Let's Encrypt**
3. Enter email and click **Get Certificate**

## Step 6: Configure Queue Worker

1. Go to **Workers** tab
2. Click **Create Worker**
3. Configure:
   - Command: `php artisan queue:work redis --sleep=3 --tries=3`
   - Processes: 4
   - Max Time: 3600

## Step 7: Configure Scheduler

1. Go to **Scheduler** tab
2. Enable the scheduler
3. Forge will add the cron job automatically

## Step 8: Configure Backups

1. Go to **Backups** tab
2. Click **Create Backup Script**
3. Configure:
   - Frequency: Daily
   - Retention: 7 days
   - S3 bucket (recommended)

## Troubleshooting

### Deployment Failed

```bash
# SSH into server
forge ssh

# Check logs
tail -f /home/forge/{domain}/storage/logs/laravel.log

# Run deployment manually
cd /home/forge/{domain}
php artisan migrate --force
```

### Queue Worker Not Running

```bash
# Check supervisor status
sudo supervisorctl status

# Restart workers
sudo supervisorctl restart all
```

### Permission Issues

```bash
# Fix storage permissions
sudo chown -R forge:forge /home/forge/{domain}/storage
sudo chmod -R 775 /home/forge/{domain}/storage
```

### Database Connection Failed

```bash
# Test database connection
mysql -u forge -p {database}

# Check .env configuration
forge ssh "cat ~/.forge/env/{site}"
```

## Forge Quick Commands

| Action | Command |
|--------|---------|
| SSH to server | Click **SSH** button in Forge UI |
| View logs | **Logs** tab |
| Quick deploy | **Deploy** button |
| Restart queue | **Workers** → **Restart All** |
| Clear cache | **Commands** → **php artisan optimize:clear** |

## Recommended Forge Settings

### PHP Version
Use PHP 8.2+ with the following extensions:
- pdo_mysql
- redis
- bcmath
- xml
- gd
- zip

### Nginx Configuration
Use default Forge Nginx template with these additions:

```nginx
# Increase upload limit
client_max_body_size 50M;

# Enable response caching
proxy_cache_valid 200 1h;
```

### Security Settings
- Enable **UFW** firewall
- Disable password authentication (use SSH keys)
- Set up **Fail2Ban** for SSH protection
