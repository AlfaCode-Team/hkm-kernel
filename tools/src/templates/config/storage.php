<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Storage configuration — every value is env-driven so the disk/bucket can be
 * tuned per deployment without touching code.
 *
 * Resolution order at runtime:
 *   1. projects/<name>/config/storage.php  (project override — copy this file there)
 *   2. plugins/Storage/config/storage.php  (this file — framework default)
 *
 * Read it anywhere with the storage_config() helper:
 *   storage_config('driver');          // 'local' | 's3'
 *   storage_config('local.root');      // dotted access into a section
 *   storage_config('s3.bucket');
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Default Driver
    |--------------------------------------------------------------------------
    | Which StoragePort adapter the plugin binds: 'local' (disk) or 's3'
    | (AWS S3 / DigitalOcean Spaces / Cloudflare R2 / MinIO). A project that
    | wires StoragePort itself in withPorts() overrides this entirely.
    */
    'driver' => strtolower((string) (env('STORAGE_DRIVER') ?: 'local')),

    /*
    |--------------------------------------------------------------------------
    | Local Disk Driver
    |--------------------------------------------------------------------------
    | root       directory blobs are written under (required to enable). A RELATIVE
    |            STORAGE_ROOT (e.g. "userdata/storage") resolves against the active
    |            project root via Paths::project(); an absolute path is used as-is
    | url_base   public base URL prefix for temporaryUrl() (CDN/host)
    | url_secret HMAC secret used to sign + verify expiring temporary URLs
    */
    'local' => [
        'root'       => (static function (): string {
            $root = (string) (env('STORAGE_ROOT') ?: '');
            if ($root === '') {
                return '';
            }
            // Absolute paths (Unix "/…" or Windows "C:\…") are used verbatim;
            // a relative path is resolved under the active project root so
            // STORAGE_ROOT=userdata/storage Just Works per project.
            $isAbsolute = $root[0] === '/' || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $root);
            return $isAbsolute ? $root : Paths::project($root);
        })(),
        'url_base'   => (string) (env('STORAGE_URL_BASE') ?: ''),
        'url_secret' => (string) (env('STORAGE_URL_SECRET') ?: ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 / S3-Compatible Driver
    |--------------------------------------------------------------------------
    | bucket / region   the target bucket and its region (required to enable)
    | key / secret      static credentials — LEAVE EMPTY on EC2/ECS/EKS so the
    |                   AWS default provider chain (IAM roles) is used instead
    | endpoint          custom endpoint for non-AWS providers (Spaces/R2/MinIO)
    | use_path_style    true for MinIO / path-style endpoints
    */
    's3' => [
        'bucket'         => (string) (env('STORAGE_S3_BUCKET') ?: ''),
        'region'         => (string) (env('STORAGE_S3_REGION') ?: 'us-east-1'),
        'key'            => (string) (env('STORAGE_S3_KEY') ?: ''),
        'secret'         => (string) (env('STORAGE_S3_SECRET') ?: ''),
        'endpoint'       => env('STORAGE_S3_ENDPOINT') ?: null,
        'use_path_style' => filter_var(env('STORAGE_S3_PATH_STYLE') ?: 'false', FILTER_VALIDATE_BOOL),
    ],

];
