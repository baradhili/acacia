# Runbooks

Operational runbooks for the Professional Services Accounting System.

## Available Runbooks

| Runbook | Description |
|---------|-------------|
| [Backup & Restore](backup-restore.md) | Database and file backup procedures, disaster recovery |

## Quick Reference

### Emergency Contacts

| Role | Name | Phone | Email |
|------|------|-------|-------|
| System Admin | | | |
| Database Admin | | | |
| DevOps Lead | | | |

### Critical Commands

```bash
# Emergency database backup
mysqldump -u root -p psa | gzip > emergency_backup.sql.gz

# Stop all services
docker compose down

# View recent errors
tail -100 storage/logs/laravel.log
```

### Backup Locations

| Type | Path |
|------|------|
| Database | `/backups/db/` |
| Files | `/backups/files/` |
| Config | `/backups/config/` |
| S3 | `s3://your-bucket/psa-backups/` |
