<?php
/**
 * Handles receipt-image / PDF uploads for payment proofs.
 * Files are stored OUTSIDE the web-served path (see .htaccess) and only
 * streamed back to admins via the admin_get_receipt endpoint.
 */
class UploadException extends \RuntimeException {}

class Uploads
{
    public function __construct(private array $cfg) {}

    /**
     * Validate and store a single uploaded file from $_FILES.
     * Returns the stored path (relative to the uploads dir) or null if no file.
     *
     * @param array|null $file one entry from $_FILES
     */
    public function storeReceipt(?array $file, string $orderRef): ?string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null; // no file provided (allowed — crypto txid may be used instead)
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new UploadException('File upload failed (error code ' . $file['error'] . ').');
        }
        if (($file['size'] ?? 0) > (int) $this->cfg['max_bytes']) {
            throw new UploadException('Receipt is too large (max '
                . round($this->cfg['max_bytes'] / 1048576, 1) . ' MB).');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new UploadException('Invalid upload.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: '';
        if (!in_array($mime, $this->cfg['allowed_mime'], true)) {
            throw new UploadException('Unsupported file type. Allowed: '
                . implode(', ', $this->cfg['allowed_mime']) . '.');
        }

        $dir = rtrim($this->cfg['dir'], '/\\');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UploadException('Upload directory is not writable.');
        }

        $ext = $this->extensionFor($mime);
        $safeRef  = preg_replace('/[^A-Za-z0-9_-]/', '', $orderRef);
        $filename = $safeRef . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest     = $dir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new UploadException('Could not save the uploaded file.');
        }

        return $filename; // store just the name; dir comes from config
    }

    /** Absolute path for a stored receipt filename. */
    public function absolutePath(string $filename): string
    {
        return rtrim($this->cfg['dir'], '/\\') . DIRECTORY_SEPARATOR . basename($filename);
    }

    /**
     * Validate and store a document file (admin upload).
     * Returns [filename, mime, size] or throws UploadException.
     */
    public function storeDocument(array $file, string $orderRef): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new UploadException('File upload failed (error code ' . $file['error'] . ').');
        }
        if (($file['size'] ?? 0) > (int) $this->cfg['max_bytes']) {
            throw new UploadException('File is too large (max ' . round($this->cfg['max_bytes'] / 1048576, 1) . ' MB).');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new UploadException('Invalid upload.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']) ?: '';
        $allowed = array_merge($this->cfg['allowed_mime'], ['application/pdf']);
        if (!in_array($mime, $allowed, true)) {
            throw new UploadException('Unsupported file type.');
        }

        $docsDir = dirname(rtrim($this->cfg['dir'], '/\\')) . DIRECTORY_SEPARATOR . 'documents';
        if (!is_dir($docsDir) && !mkdir($docsDir, 0775, true) && !is_dir($docsDir)) {
            throw new UploadException('Documents directory is not writable.');
        }

        $ext      = $this->extensionFor($mime);
        $safeRef  = preg_replace('/[^A-Za-z0-9_-]/', '', $orderRef);
        $filename = $safeRef . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest     = $docsDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new UploadException('Could not save the uploaded file.');
        }

        return ['filename' => $filename, 'path' => $dest, 'mime' => $mime, 'size' => (int) $file['size']];
    }

    /** Absolute path for a stored document filename. */
    public function documentPath(string $filename): string
    {
        $docsDir = dirname(rtrim($this->cfg['dir'], '/\\')) . DIRECTORY_SEPARATOR . 'documents';
        return $docsDir . DIRECTORY_SEPARATOR . basename($filename);
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
            default           => 'bin',
        };
    }
}
