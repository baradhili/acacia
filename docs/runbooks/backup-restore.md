# Backup & Restore Runbook

This runbook documents the backup and restore procedures for the Professional Services Accounting System.

## Overview

### What Gets Backed Up

| Component | Location | Frequency | Retention |
|-----------|----------|-----------|-----------|
| Database | MySQL/PostgreSQL | Daily + before major changes | 30 days |
| Files | `storage/app/public/uploads/` | Daily | 30 days |
| Configuration | `.env` (encrypted) | Weekly | 90 days |
| Application | Git repository | N/A (version controlled) | N/A |

### Backup Types

- **Full Backup**: Complete database + files
- **Database Only**: SQL dump of all tables
- **Files Only**: Uploaded documents and attachments
- **Point-in-Time**: Binary log based (MySQL)

---

## Manual Backup Procedures

### 1. Database Backup (MySQL)

#### Standard Dump

```bash
# Local backup
mysqldump -u root -p psa > backup_$(date +%Y%m%d_%H%M%S).sql

# With compression
mysqldump -u root -p psa | gzip > backup_$(date +%Y%m%d_%H%M%S).sql.gz

# Remote backup (from local machine)
ssh user@server "mysqldump -u root -p psa" > backup.sql
```

#### Docker Environment

```bash
# Backup MySQL container
docker compose exec -T mysql mysqldump -u psa_user -p psa > backup.sql

# With compression
docker compose exec -T mysql mysqldump -u psa_user -p psa | gzip > backup.sql.gz
```

#### Laravel Forge

```bash
# Use Forge's built-in backup or manual
forge ssh "mysqldump -u forge -p psa | gzip" > backup.sql.gz
```

### 2. File Backup

```bash
# Backup uploads directory
tar -czvf storage_backup_$(date +%Y%m%d).tar.gz storage/app/public/uploads/

# Sync to remote location
rsync -avz storage/app/public/uploads/ user@backup-server:/path/to/backups/uploads/

# S3 backup
aws s3 sync storage/app/public/uploads/ s3://your-bucket/backups/uploads/ \
    --storage-class STANDARD_IA
```

### 3. Configuration Backup

```bash
# Backup .env file (store securely, never in git)
cp .env .env.backup.$(date +%Y%m%d)

# Backup nginx configuration
sudo cp /etc/nginx/sites-available/your-site /path/to/backups/nginx/

# Backup SSL certificates
sudo tar -czvf ssl_backup.tar.gz /etc/letsencrypt/live/ /etc/letsencrypt/archive/
```

---

## Automated Backup Setup

### Option 1: Cron Job (Traditional Server)

```bash
# Edit crontab
crontab -e

# Add backup job (daily at 2 AM)
0 2 * * * /path/to/backup.sh >> /var/log/backup.log 2>&1

# Add MySQL backup
0 2 * * * mysqldump -u root -p'password' psa | gzip > /backups/db/psa_$(date +\%Y\%m\%d).sql.gz

# Add file backup
5 2 * * * tar -czvf /backups/files/psa_storage_$(date +\%Y\%m\%d).tar.gz /var/www/psa/storage/app/public/uploads/
```

### Option 2: Laravel Scheduler

Create a custom backup command:

```bash
php artisan make:command BackupDatabase
```

```php
// app/Console/Commands/BackupDatabase.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--keep=7 : Number of backups to keep}';
    protected $description = 'Backup the database';

    public function handle()
    {
        $filename = 'database_backup_' . date('Y-m-d_His') . '.sql';
        $path = storage_path('backups/' . $filename);

        // Ensure directory exists
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $this->info('Creating database backup...');

        $command = sprintf(
            'mysqldump -u%s -p%s %s > %s 2>/dev/null',
            config('database.connections.mysql.username'),
            config('database.connections.mysql.password'),
            config('database.connections.mysql.database'),
            $path
        );

        exec($command);

        $this->info("Backup created: {$filename}");

        // Clean old backups
        $keep = (int) $this->option('keep');
        $this->cleanup($path, $keep);

        // Upload to S3 (optional)
        if (config('filesystems.disks.s3')) {
            Storage::disk('s3')->put('backups/' . $filename, file_get_contents($path));
            $this->info('Uploaded to S3');
        }

        return Command::SUCCESS;
    }

    protected function cleanup($currentBackup, $keep)
    {
        $backups = glob(storage_path('backups/*.sql'));
        sort($backups);

        while (count($backups) > $keep) {
            $old = array_shift($backups);
            unlink($old);
            $this->warn("Removed old backup: {$old}");
        }
    }
}
```

Register in `app/Console/Kernel.php`:

```php
$schedule->command('backup:database --keep=7')->daily();
```

### Option 3: Docker Volume Backup

```bash
#!/bin/bash
# backup.sh

BACKUP_DIR="/backups"
DATE=$(date +%Y%m%d_%H%M%S)

# Create backup directory
mkdir -p $BACKUP_DIR/{db,files,config}

# Backup MySQL data volume
docker run --rm \
    -v psa-mysql-data:/var/lib/mysql \
    -v $BACKUP_DIR/db:/backup \
    alpine tar -czvf /backup/mysql_$DATE.tar.gz -C /var/lib/mysql .

# Backup uploads
tar -czvf $BACKUP_DIR/files/uploads_$DATE.tar.gz storage/app/public/uploads/

# Backup .env
cp .env $BACKUP_DIR/config/.env.$DATE

# Cleanup old backups (keep 30 days)
find $BACKUP_DIR -mtime +30 -delete

# Upload to S3
aws s3 sync $BACKUP_DIR s3://your-bucket/psa-backups/ --delete

echo "Backup completed: $DATE"
```

### Option 4: Laravel Forge (S3 Integration)

1. Go to **Servers** → **Backups**
2. Configure S3 bucket
3. Set retention policy
4. Forge handles backup scheduling automatically

---

## Restore Procedures

### 1. Database Restore

#### Standard Restore

```bash
# Restore from uncompressed backup
mysql -u root -p psa < backup_20250101_120000.sql

# Restore from compressed backup
gunzip < backup_20250101_120000.sql.gz | mysql -u root -p psa

# Restore specific table
mysql -u root -p psa -e "DROP TABLE invoices;"
mysql -u root -p psa < backup_20250101_120000.sql
```

#### Docker Restore

```bash
# Restore database
cat backup.sql | docker compose exec -T mysql mysql -u psa_user -p psa

# Restore from compressed backup
gunzip < backup.sql.gz | docker compose exec -T mysql mysql -u psa_user -p psa
```

#### Point-in-Time Restore (MySQL)

```bash
# Enable binary logging (in my.cnf)
[mysqld]
log-bin=mysql-bin
binlog-format=ROW

# Restore to specific point in time
mysqlbinlog --stop-datetime="2025-01-15 10:00:00" mysql-bin.000001 | mysql -u root -p psa
```

### 2. Files Restore

```bash
# Restore uploads directory
tar -xzvf storage_backup_20250101.tar.gz -C /

# Restore specific file
tar -xzvf storage_backup_20250101.tar.gz ./storage/app/public/uploads/important.pdf

# Restore from S3
aws s3 sync s3://your-bucket/backups/uploads/ storage/app/public/uploads/
```

### 3. Full Restore from Backup

```bash
#!/bin/bash
# full_restore.sh

BACKUP_DATE=$1  # e.g., 20250115

if [ -z "$BACKUP_DATE" ]; then
    echo "Usage: $0 YYYYMMDD"
    exit 1
fi

# Stop services
docker compose down

# Restore MySQL
gunzip < /backups/db/mysql_$BACKUP_DATE.tar.gz | docker volume rm psa-mysql-data 2>/dev/null
docker volume create psa-mysql-data
docker run --rm \
    -v psa-mysql-data:/var/lib/mysql \
    -v /backups/db:/backup \
    alpine tar -xzvf /backup/mysql_$BACKUP_DATE.tar.gz -C /var/lib/mysql

# Restore files
tar -xzvf /backups/files/uploads_$BACKUP_DATE.tar.gz

# Restore config
cp /backups/config/.env.$BACKUP_DATE .env

# Start services
docker compose up -d

# Verify
docker compose exec app php artisan migrate:status
```

---

## Disaster Recovery

### Scenario 1: Server Failure

1. Provision new server
2. Install Docker/Nginx + PHP + MySQL
3. Clone repository
4. Restore latest backup
5. Update DNS
6. Verify functionality

### Scenario 2: Database Corruption

```bash
# Stop MySQL
sudo systemctl stop mysql

# Remove corrupted data
sudo rm -rf /var/lib/mysql/*

# Reinitialize
sudo mysqld --initialize --user=mysql

# Start MySQL
sudo systemctl start mysql

# Restore from backup
mysql -u root -p < latest_backup.sql
```

### Scenario 3: Ransomware Attack

1. **IMMEDIATELY** isolate the server (disconnect from network)
2. Identify affected systems
3. Restore from last known good backup (before infection date)
4. Investigate vulnerability
5. Apply security patches
6. Bring online with enhanced monitoring

### Scenario 4: Accidental Data Deletion

```bash
# If soft delete used, check for recoverable data
php artisan tinker
>>> \App\Models\Invoice::withTrashed()->whereNotNull('deleted_at')->restore();

# If permanent delete, restore from backup
mysql -u root -p psa < backup_before_delete.sql
```

---

## Verification & Testing

### Verify Backup Integrity

```bash
# Test MySQL backup
mysql -u root -p -e "SELECT 1" psa

# Check backup file size (shouldn't be empty)
ls -lh backup.sql

# Test compressed backup
gunzip -t backup.sql.gz && echo "Valid gzip"

# Verify data in backup
grep -c "CREATE TABLE" backup.sql
grep -c "INSERT INTO" backup.sql
```

### Restore Testing Schedule

| Test | Frequency | Responsible |
|------|-----------|-------------|
| Backup file integrity | Weekly | Automated |
| Full restore to test environment | Monthly | DevOps |
| Point-in-time recovery drill | Quarterly | Team Lead |
| Disaster recovery simulation | Annually | Full Team |

### Restore Test Procedure

```bash
# 1. Create isolated test environment
docker compose -f docker-compose.test.yml up -d

# 2. Restore backup to test environment
cat backup.sql | docker compose -f docker-compose.test.yml exec -T mysql mysql -u test -p test

# 3. Verify application works
curl -I http://localhost:8080

# 4. Check data integrity
docker compose exec app php artisan tinker --execute="echo \App\Models\Invoice::count();"

# 5. Document results
echo "Restore test completed: $(date)" >> /var/log/restore_tests.log
```

---

## Backup Storage Best Practices

### 3-2-1 Backup Rule

- **3** copies of data
- **2** different media types
- **1** offsite backup

### Recommended Storage

| Backup Type | Primary Location | Secondary Location |
|-------------|------------------|-------------------|
| Daily DB | Local `/backups` | S3 Standard-IA |
| Weekly DB | S3 Standard | Glacier (90 days) |
| Files | S3 Standard | Secondary S3 bucket |
| Config | S3 Standard | Encrypted USB |

### Encryption

```bash
# Encrypt sensitive backups
gpg --encrypt --recipient backup@example.com backup.sql

# Decrypt and restore
gpg --decrypt backup.sql.gpg | mysql -u root -p psa
```

---

## Troubleshooting

### Backup Fails: Disk Space

```bash
# Check disk space
df -h

# Clean old backups
find /backups -mtime +7 -delete

# Or compress existing backups
gzip /backups/*.sql
```

### Backup Fails: Permission Denied

```bash
# Fix MySQL dump permissions
sudo chown mysql:mysql /var/lib/mysql
sudo chmod 700 /var/lib/mysql

# For Docker
docker compose exec mysql chown -R mysql:mysql /var/lib/mysql
```

### Backup Fails: Lock Timeout

```bash
# MySQL dump with lock timeout
mysqldump -u root -p --lock-tables=false psa > backup.sql

# Or use single-transaction
mysqldump -u root -p --single-transaction psa > backup.sql
```

### Restore Fails: Disk Space

```bash
# Check space before restore
df -h

# Clean up
docker system prune -a
apt autoremove
```

---

## Emergency Contacts

| Role | Name | Phone | Email |
|------|------|-------|-------|
| System Admin | | | |
| Database Admin | | | |
| DevOps Lead | | | |
| Escalation | | | |

---

## Documentation History

| Date | Version | Changes | Author |
|------|---------|---------|--------|
| 2025-01-15 | 1.0 | Initial version | |
