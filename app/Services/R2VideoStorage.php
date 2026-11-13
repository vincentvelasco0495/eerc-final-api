<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;

class R2VideoStorage
{
    public const PART_BYTES = 16 * 1024 * 1024;

    public function configured(): bool
    {
        return $this->accountId() !== ''
            && $this->accessKeyId() !== ''
            && $this->secret() !== ''
            && $this->bucket() !== '';
    }

    public function isStoredPath(string $path): bool
    {
        return str_starts_with($path, 'r2:');
    }

    public function keyFromStoredPath(string $path): string
    {
        return ltrim(substr($path, 3), '/');
    }

    /**
     * @return array{uploadId: string, key: string}
     */
    public function createMultipartUpload(string $key, string $mime): array
    {
        $result = $this->client()->createMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ContentType' => $mime !== '' ? $mime : 'video/mp4',
        ]);

        $uploadId = trim((string) ($result['UploadId'] ?? ''));
        if ($uploadId === '') {
            abort(502, 'Cloudflare R2 did not start the upload.');
        }

        return ['uploadId' => $uploadId, 'key' => $key];
    }

    public function presignPart(string $key, string $uploadId, int $partNumber): string
    {
        $command = $this->client()->getCommand('UploadPart', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $this->client()->createPresignedRequest($command, '+2 hours')->getUri();
    }

    /**
     * @return list<array{PartNumber: int, ETag: string}>
     */
    public function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = 0;

        do {
            $params = [
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
            ];
            if ($marker > 0) {
                $params['PartNumberMarker'] = $marker;
            }
            $result = $this->client()->listParts($params);
            foreach ($result['Parts'] ?? [] as $part) {
                $number = (int) ($part['PartNumber'] ?? 0);
                $etag = trim((string) ($part['ETag'] ?? ''));
                if ($number > 0 && $etag !== '') {
                    $parts[] = ['PartNumber' => $number, 'ETag' => $etag];
                }
            }
            $truncated = (bool) ($result['IsTruncated'] ?? false);
            $marker = (int) ($result['NextPartNumberMarker'] ?? 0);
        } while ($truncated && $marker > 0);

        usort($parts, static fn (array $a, array $b) => $a['PartNumber'] <=> $b['PartNumber']);

        return $parts;
    }

    /**
     * @param  list<array{PartNumber: int, ETag: string}>  $parts
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        $this->client()->completeMultipartUpload([
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    public function abortMultipartUpload(?string $key, ?string $uploadId): void
    {
        $key = trim((string) $key);
        $uploadId = trim((string) $uploadId);
        if ($key === '' || $uploadId === '' || ! $this->configured()) {
            return;
        }

        try {
            $this->client()->abortMultipartUpload([
                'Bucket' => $this->bucket(),
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('R2 multipart abort failed.', [
                'status' => $exception->getCode(),
            ]);
        }
    }

    public function presignGet(string $key, int $ttlSeconds, string $mime = 'video/mp4'): string
    {
        $command = $this->client()->getCommand('GetObject', [
            'Bucket' => $this->bucket(),
            'Key' => $key,
            'ResponseContentType' => $mime !== '' ? $mime : 'video/mp4',
        ]);
        $ttl = max(60, $ttlSeconds);

        return (string) $this->client()->createPresignedRequest($command, "+{$ttl} seconds")->getUri();
    }

    public function deleteObject(?string $key): void
    {
        $key = trim((string) $key);
        if ($key === '' || ! $this->configured()) {
            return;
        }

        try {
            $this->client()->deleteObject([
                'Bucket' => $this->bucket(),
                'Key' => $key,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('R2 object delete failed.', [
                'status' => $exception->getCode(),
            ]);
        }
    }

    public function configureCors(array $origins): void
    {
        $allowed = array_values(array_unique(array_filter(array_map(
            static fn ($origin) => rtrim(trim((string) $origin), '/'),
            $origins
        ))));
        if ($allowed === []) {
            abort(422, 'No browser origins are configured for R2 uploads.');
        }

        $this->client()->putBucketCors([
            'Bucket' => $this->bucket(),
            'CORSConfiguration' => [
                'CORSRules' => [[
                    'AllowedOrigins' => $allowed,
                    'AllowedMethods' => ['GET', 'PUT', 'HEAD'],
                    'AllowedHeaders' => ['*'],
                    'ExposeHeaders' => ['ETag', 'Content-Length', 'Content-Type'],
                    'MaxAgeSeconds' => 3600,
                ]],
            ],
        ]);
    }

    protected function client(): S3Client
    {
        if (! $this->configured()) {
            abort(409, 'Cloudflare R2 is not configured.');
        }
        if (! class_exists(S3Client::class)) {
            abort(500, 'The AWS SDK is not installed.');
        }

        return new S3Client([
            'version' => 'latest',
            'region' => 'auto',
            'endpoint' => $this->endpoint(),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $this->accessKeyId(),
                'secret' => $this->secret(),
            ],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    protected function endpoint(): string
    {
        $configured = trim((string) config('lms.r2.endpoint', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return 'https://'.$this->accountId().'.r2.cloudflarestorage.com';
    }

    protected function accountId(): string
    {
        return trim((string) config('lms.r2.account_id', ''));
    }

    protected function accessKeyId(): string
    {
        return trim((string) config('lms.r2.access_key_id', ''));
    }

    protected function secret(): string
    {
        return trim((string) config('lms.r2.secret_access_key', ''));
    }

    protected function bucket(): string
    {
        return trim((string) config('lms.r2.bucket', ''));
    }
}
