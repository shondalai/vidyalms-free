<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Upload;

/**
 * What a host answers after it has stored one upload.
 *
 * The shape is fixed and shared by every adapter, because the same React file
 * picker reads all of them: {path, name, size_bytes, mime} always, plus {url}
 * for a public purpose and null for a private one. It is deliberately the
 * shape a resource lesson's content already stores (path, name, size_bytes),
 * so the workbench appends the answer rather than translating it.
 *
 * "url" is null rather than a signed URL for a private file. A signed URL
 * expires in minutes, and this answer is put into a draft the author may not
 * save for an hour; the API mints one at read time, which is the only place
 * that knows when the link will be used.
 */
final class StoredUpload
{
    public function __construct(
        public readonly string $path,
        public readonly string $name,
        public readonly int $sizeBytes,
        public readonly string $mime,
        public readonly ?string $url = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'size_bytes' => $this->sizeBytes,
            'mime' => $this->mime,
            'url' => $this->url,
        ];
    }
}
