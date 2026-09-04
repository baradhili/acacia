<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup destination
    |--------------------------------------------------------------------------
    |
    | `backup:create` writes gzipped database dumps to {path}/db and a
    | tar.gz of the public storage disk (uploads, logos, profile
    | photos) to {path}/files. The default keeps everything inside
    | storage/app; point BACKUP_PATH at dedicated storage — ideally an
    | external volume — so backups survive losing the app disk. See
    | docs/runbooks/backup-restore.md for restore procedures.
    */

    'path' => env('BACKUP_PATH', storage_path('app/backups')),

];
