# Production Deployment Guide

This guide covers three deployment options for the Professional Services Accounting System.

## Quick Reference

| Method | Best For | Complexity |
|--------|----------|------------|
| [Laravel Forge](forge.md) | Traditional VPS (DigitalOcean, AWS, etc.) | Low |
| [Laravel Sail](sail.md) | Local development & testing | Very Low |
| [Docker](docker.md) | Containerized production | Medium |

---

## Prerequisites

Before deploying, ensure you have:

- [ ] PHP 8.2+ installed locally for testing
- [ ] Composer dependencies installed
- [ ] `.env` file configured with production values
- [ ] Database migrations tested
- [ ] SSL certificate ready (Let's Encrypt recommended)
- [ ] Domain DNS configured

## Environment Configuration

### Production `.env` Settings

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

LOG_CHANNEL=daily
LOG_LEVEL=error

SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

CACHE_DRIVER=redis
REDIS_CLIENT=phpredis

MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=tls

WISE_API_KEY=your_production_api_key
```

### Security Checklist

- [ ] Set `APP_DEBUG=false`
- [ ] Set `APP_ENV=production`
- [ ] Generate new `APP_KEY` if rotating
- [ ] Configure firewall (allow only 80, 443)
- [ ] Set up fail2ban for SSH protection
- [ ] Enable Let's Encrypt SSL
- [ ] Configure database backup schedule

---

## Post-Deployment

### Verify Installation

```bash
php artisan about
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Run Health Checks

```bash
php artisan health:check
# or manually verify:
curl -I https://your-domain.com
```

### Schedule Cron Jobs

Add to server crontab:

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

### Queue Worker (Supervisor)

```bash
[program:psa-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path-to-project/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/psa-worker.log
stopwaitsecs=3600
```

---

## Next Steps

- [Laravel Forge Deployment](forge.md)
- [Laravel Sail Deployment](sail.md)
- [Docker Deployment](docker.md)
- [Backup & Restore Runbook](../runbooks/backup-restore.md)
