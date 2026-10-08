<?php

namespace App\Services\Backups;

use App\Models\BackupOffsiteDisk;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use Spatie\Backup\Config\Config;
use Throwable;

/**
 * The GUI-configured offsite destination (Tao head 3 — separation):
 * the admin picks a remote driver (s3 or sftp) and credentials on
 * the Backups page, and this service turns the stored row into the
 * runtime `offsite` filesystems disk plus an entry in spatie's
 * destination list. Two invariants: the disk is only appended when
 * its adapter package is installed (an unavailable driver must
 * degrade to a warning, never a broken disk resolution), and spatie
 * snapshots config('backup') into a scoped binding at boot — so
 * BackupRunner rebinds via refreshSpatieConfig() before every run,
 * mirroring the test suite's resetSpatieConfig.
 */
class OffsiteDisk
{
    /** The filesystems disk name the saved row is published under. */
    public const DISK_NAME = 'offsite';

    /**
     * The driver catalogue for the Backups page: what to show, and
     * whether each adapter package is actually installed (the GUI
     * offers unavailable drivers disabled, with the composer line to
     * install them — the repo precedent for optional adapters).
     *
     * @return array<string, array{label: string, available: bool, install: string}>
     */
    public function drivers(): array
    {
        return [
            's3' => [
                'label' => __('backups.offsite_driver_s3'),
                'available' => $this->driverAvailable('s3'),
                'install' => 'composer require league/flysystem-aws-s3-v3',
            ],
            'sftp' => [
                'label' => __('backups.offsite_driver_sftp'),
                'available' => $this->driverAvailable('sftp'),
                'install' => 'composer require league/flysystem-sftp-v3',
            ],
        ];
    }

    public function driverAvailable(string $driver): bool
    {
        return match ($driver) {
            's3' => class_exists(AwsS3V3Adapter::class),
            'sftp' => class_exists(SftpAdapter::class),
            default => false,
        };
    }

    /**
     * The filesystems disk definition for a saved row, driver fields
     * mapped to what FilesystemManager expects (s3 path prefix via
     * `path`, sftp key material via `privateKey`).
     *
     * @return array<string, mixed>
     */
    public function definition(BackupOffsiteDisk $disk): array
    {
        $config = $disk->config ?? [];

        $definition = match ($disk->driver) {
            's3' => array_filter([
                'driver' => 's3',
                'key' => $config['key'] ?? null,
                'secret' => $config['secret'] ?? null,
                'region' => $config['region'] ?? null,
                'bucket' => $config['bucket'] ?? null,
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? null,
                'path' => $disk->root ?: null,
            ], fn ($value) => $value !== null),
            'sftp' => array_filter([
                'driver' => 'sftp',
                'host' => $config['host'] ?? null,
                'port' => $config['port'] ?? null,
                'username' => $config['username'] ?? null,
                'password' => $config['password'] ?? null,
                'privateKey' => $config['private_key'] ?? null,
                'root' => $disk->root ?: '/',
                'timeout' => 10,
            ], fn ($value) => $value !== null),
            default => [],
        };

        // A broken offsite disk must fail the backup run loudly, not
        // return a silently-empty listing — same posture as `backups`.
        $definition['throw'] = true;

        return $definition;
    }

    /**
     * Publish the saved offsite disk into the runtime config — the
     * `offsite` filesystems disk and spatie's destination list — when
     * it is enabled and its driver is installed; strip both otherwise
     * (idempotent, so a disable takes effect without a restart).
     */
    public function sync(): void
    {
        $row = BackupOffsiteDisk::query()->first();

        if (! $row?->enabled || ! $this->driverAvailable($row->driver)) {
            config([
                'backup.backup.destination.disks' => array_values(array_diff(
                    (array) config('backup.backup.destination.disks'),
                    [self::DISK_NAME],
                )),
            ]);

            return;
        }

        config([
            'filesystems.disks.'.self::DISK_NAME => $this->definition($row),
        ]);

        $destinations = (array) config('backup.backup.destination.disks');

        if (! in_array(self::DISK_NAME, $destinations)) {
            config(['backup.backup.destination.disks' => array_merge($destinations, [self::DISK_NAME])]);
        }
    }

    /**
     * Re-snapshot config('backup') into spatie's scoped Config
     * binding — spatie binds it at boot, so a GUI-enabled offsite
     * destination saved later would otherwise be invisible to the
     * next backup:run in the same process. Called by BackupRunner
     * right before it invokes spatie.
     */
    public function refreshSpatieConfig(): void
    {
        $this->sync();

        app()->forgetInstance(Config::class);
        app()->scoped(Config::class, fn () => Config::fromArray(config('backup')));
    }

    /**
     * Round-trip a probe file over a definition built from the row:
     * the write/read/delete proves credentials, reachability and the
     * root path in one shot. Never throws — the outcome is for the
     * admin screen and the enable gate.
     *
     * @return array{ok: bool, message: string}
     */
    public function probe(BackupOffsiteDisk $disk): array
    {
        if (! $this->driverAvailable($disk->driver)) {
            return ['ok' => false, 'message' => __('backups.offsite_driver_missing', [
                'driver' => $disk->driver,
                'install' => $this->drivers()[$disk->driver]['install'] ?? $disk->driver,
            ])];
        }

        try {
            $filesystem = Storage::build($this->definition($disk));
            $probe = 'offsite-probe-'.now()->format('YmdHis').'-'.bin2hex(random_bytes(3)).'.txt';
            $payload = 'acacia offsite connectivity probe';

            $filesystem->write($probe, $payload);
            $readBack = $filesystem->read($probe);
            $filesystem->delete($probe);

            if ($readBack !== $payload) {
                return ['ok' => false, 'message' => __('backups.offsite_probe_corrupt')];
            }

            return ['ok' => true, 'message' => __('backups.offsite_probe_passed')];
        } catch (Throwable $e) {
            // The admin configured this destination and needs the
            // reason; exception messages carry no secrets.
            return ['ok' => false, 'message' => __('backups.offsite_probe_failed', ['error' => Str::limit($e->getMessage(), 300)])];
        }
    }
}
