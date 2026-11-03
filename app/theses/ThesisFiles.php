<?php
declare(strict_types=1); // keep typed values strict

namespace Scholar\Theses;

use PDO;
use RuntimeException;
use Scholar\AuditLog;

// stores thesis PDFs privately and finds them again for the RDPO-only viewer
// files go to storage/theses under a random name; the database row links that name to its thesis
// a newer upload marks the older file as replaced instead of deleting it
class ThesisFiles {
    private PDO $pdo;
    private string $storageDirectory;
    private int $maxBytes;

    // the usual way pages get this class: private storage/theses folder and the size limit from .env
    public static function create(PDO $pdo): ThesisFiles {
        $configuration = loadConfiguration();
        $storageDirectory = __DIR__ . '/../../storage/theses'; // two folders up from app/theses, outside public/
        return new ThesisFiles($pdo, $storageDirectory, $configuration['files']['max_bytes']);
    }

    public function __construct(PDO $pdo, string $storageDirectory, int $maxBytes) {
        $this->pdo = $pdo;
        $this->storageDirectory = rtrim($storageDirectory, '/\\');
        $this->maxBytes = $maxBytes;
    }

    // checks one file from $_FILES before anything is saved
    // returns '' when the file is acceptable, otherwise a message RDPO can act on
    // $upload is PHP's description of the upload: name, tmp_name (where PHP put it), size and error
    public function checkUpload($upload): string {
        // a modified form could send several files under one name, or none at all
        if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'], $upload['size'])
            || !is_int($upload['error']) || !is_string($upload['tmp_name']) || !is_string($upload['name'])) {
            return 'Choose one PDF file to upload.';
        }

        // PHP reports problems with a numbered code; turn the common ones into plain advice
        if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
            return 'Choose a PDF file before pressing Upload.';
        }
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'The file is larger than the ' . $this->maxMegabytes() . ' MB limit.';
        }
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            return 'The upload did not finish. Please try again.';
        }

        // is_uploaded_file() confirms the path really is a fresh upload from this request,
        // so a crafted request cannot trick us into "uploading" an existing file such as .env
        if (!is_uploaded_file($upload['tmp_name'])) {
            return 'The upload did not finish. Please try again.';
        }
        return $this->checkFile($upload['tmp_name'], $upload['name']);
    }

    // the content checks, separated so the unit test can run them on ordinary files
    public function checkFile(string $path, string $originalName): string {
        if (!str_ends_with(strtolower($originalName), '.pdf')) {
            return 'Only PDF files (.pdf) can be uploaded.';
        }
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return 'The file is empty.';
        }
        if ($size > $this->maxBytes) {
            return 'The file is larger than the ' . $this->maxMegabytes() . ' MB limit.';
        }

        // the extension and the browser's stated type are only labels, so look at the actual bytes:
        // every PDF begins with "%PDF-", and finfo (PHP's file-type detector) must agree that it is a PDF
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 'The file could not be read.';
        }
        $firstBytes = fread($handle, 5);
        fclose($handle);
        if ($firstBytes !== '%PDF-') {
            return 'This file is not a real PDF, even though its name ends in .pdf.';
        }
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        if ($detector->file($path) !== 'application/pdf') {
            return 'This file is not a real PDF, even though its name ends in .pdf.';
        }
        return '';
    }

    // saves an already-checked upload for a thesis and returns the new file's id
    // order of work: move the file into storage, then record it in the database inside a transaction;
    // if the database part fails, the moved file is deleted again so no unlisted file is left behind
    public function storeUpload(int $thesisId, array $upload, int $actorUserId): int {
        // a random 32-character name; the uploaded name is never used as a path
        $storageName = bin2hex(random_bytes(16)) . '.pdf';
        $targetPath = $this->storageDirectory . DIRECTORY_SEPARATOR . $storageName;

        // move_uploaded_file() only moves genuine uploads, a second safety check after is_uploaded_file()
        if (!move_uploaded_file($upload['tmp_name'], $targetPath)) {
            throw new RuntimeException('Uploaded PDF could not be moved into storage.');
        }
        return $this->recordStoredFile($thesisId, $storageName, $upload['name'], $actorUserId);
    }

    // ---- pending PDFs for autofill ----
    // on the New record form, RDPO picks the PDF before the record exists; the file waits in storage/theses/pending
    // until the record is saved, then attachPendingFile() moves it into place like a normal upload.
    // pending files are only ever reached through a random token kept in the RDPO session, and are removed after a day.

    // moves an already-checked upload into the pending folder and returns its random token
    public function holdPendingUpload(array $upload): string {
        $pendingDirectory = $this->pendingDirectory();
        if (!is_dir($pendingDirectory) && !mkdir($pendingDirectory, 0770)) {
            throw new RuntimeException('Pending PDF folder could not be created.');
        }
        $this->removeOldPendingFiles();

        $token = bin2hex(random_bytes(16)); // 32 random hex characters, like the stored names
        if (!move_uploaded_file($upload['tmp_name'], $pendingDirectory . DIRECTORY_SEPARATOR . $token . '.pdf')) {
            throw new RuntimeException('Uploaded PDF could not be held for autofill.');
        }
        return $token;
    }

    // the full path of a pending file, or null when the token is malformed or the file is gone (e.g. cleaned up)
    public function pendingPath(string $token): ?string {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $token)) {
            return null; // only our own token format, so a crafted value can never point elsewhere
        }
        $path = $this->pendingDirectory() . DIRECTORY_SEPARATOR . $token . '.pdf';
        if (!is_file($path)) {
            return null;
        }
        return $path;
    }

    // attaches a pending file to a newly created thesis; returns the new file id, or 0 when the pending file is gone
    public function attachPendingFile(int $thesisId, string $token, string $originalName, int $actorUserId): int {
        $pendingPath = $this->pendingPath($token);
        if ($pendingPath === null) {
            return 0;
        }
        $storageName = bin2hex(random_bytes(16)) . '.pdf';
        $targetPath = $this->storageDirectory . DIRECTORY_SEPARATOR . $storageName;
        // rename() moves the file within the same disk, so the bytes are not copied twice
        if (!rename($pendingPath, $targetPath)) {
            throw new RuntimeException('Pending PDF could not be moved into storage.');
        }
        return $this->recordStoredFile($thesisId, $storageName, $originalName, $actorUserId);
    }

    // removes a pending file that will not be used (for example when RDPO picks a different PDF)
    public function discardPendingFile(string $token): void {
        $pendingPath = $this->pendingPath($token);
        if ($pendingPath !== null) {
            unlink($pendingPath);
        }
    }

    private function pendingDirectory(): string {
        return $this->storageDirectory . DIRECTORY_SEPARATOR . 'pending';
    }

    // pending files older than one day belong to forms that were never saved; remove them so they do not pile up
    private function removeOldPendingFiles(): void {
        $oneDayAgo = time() - 86400;
        $pendingFiles = glob($this->pendingDirectory() . DIRECTORY_SEPARATOR . '*.pdf'); // false if the folder cannot be read
        if (!is_array($pendingFiles)) {
            return;
        }
        foreach ($pendingFiles as $pendingFile) {
            $isOurName = preg_match('/\A[a-f0-9]{32}\.pdf\z/', basename($pendingFile)) === 1;
            if ($isOurName && filemtime($pendingFile) < $oneDayAgo) {
                unlink($pendingFile);
            }
        }
    }

    // records a file that is already in storage/theses under $storageName, inside one transaction with its audit entry
    // if anything fails, the stored file is deleted again so storage never holds a file the database does not know
    private function recordStoredFile(int $thesisId, string $storageName, string $originalName, int $actorUserId): int {
        $targetPath = $this->storageDirectory . DIRECTORY_SEPARATOR . $storageName;
        try {
            $sizeBytes = filesize($targetPath);
            $fingerprint = hash_file('sha256', $targetPath); // like a checksum: changes if even one byte changes

            $this->pdo->beginTransaction();
            // lock the thesis row so two uploads for the same thesis are handled one after the other
            $statement = $this->pdo->prepare('SELECT id FROM theses WHERE id = :id FOR UPDATE');
            $statement->execute(['id' => $thesisId]);
            if (!$statement->fetch()) {
                throw new RuntimeException('Thesis disappeared during upload.');
            }

            // the previous current file (if any) is kept but marked as replaced
            $statement = $this->pdo->prepare('UPDATE thesis_files SET replaced_at = CURRENT_TIMESTAMP WHERE thesis_id = :id AND replaced_at IS NULL');
            $statement->execute(['id' => $thesisId]);
            $hadPreviousFile = $statement->rowCount() > 0;

            $statement = $this->pdo->prepare("
                INSERT INTO thesis_files (thesis_id, storage_name, original_filename, size_bytes, sha256, uploaded_by)
                VALUES (:thesis_id, :storage_name, :original_filename, :size_bytes, :sha256, :uploaded_by)
            ");
            $statement->execute([
                'thesis_id' => $thesisId,
                'storage_name' => $storageName,
                'original_filename' => self::displayName($originalName),
                'size_bytes' => $sizeBytes,
                'sha256' => $fingerprint,
                'uploaded_by' => $actorUserId,
            ]);
            $fileId = (int) $this->pdo->lastInsertId();

            $changedFields = [];
            if ($hadPreviousFile) {
                $changedFields = ['replaced previous PDF']; // a short note in the history, not file contents
            }
            AuditLog::record($this->pdo, $actorUserId, 'thesis.pdf_uploaded', 'thesis', $thesisId, $changedFields);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if (is_file($targetPath)) {
                unlink($targetPath); // the database does not know this file, so remove it to keep storage tidy
            }
            throw $exception;
        }
        return $fileId;
    }

    // the current (not replaced) PDF of a thesis with the uploader's username, or null when there is none
    public function currentFile(int $thesisId): ?array {
        $statement = $this->pdo->prepare("
            SELECT f.*, u.username AS uploaded_by_username
            FROM thesis_files f
            JOIN users u ON u.id = f.uploaded_by
            WHERE f.thesis_id = :thesis_id AND f.replaced_at IS NULL
            ORDER BY f.id DESC
            LIMIT 1
        ");
        $statement->execute(['thesis_id' => $thesisId]);
        $file = $statement->fetch();
        if (!$file) {
            return null;
        }
        return $file;
    }

    // the full path of a stored file, after double-checking that its name is one we generated
    // returns null when the name looks wrong or the file is missing from storage
    public function storedPath(array $file): ?string {
        // only names in our own format are accepted, so a damaged row can never point to "../.env" or similar
        if (!preg_match('/\A[a-f0-9]{32}\.pdf\z/', $file['storage_name'])) {
            return null;
        }
        $path = $this->storageDirectory . DIRECTORY_SEPARATOR . $file['storage_name'];
        if (!is_file($path)) {
            return null;
        }
        return $path;
    }

    public function maxMegabytes(): int {
        return intdiv($this->maxBytes, 1048576); // 1 MB = 1,048,576 bytes
    }

    // keeps only the last part of the uploaded name and removes characters that could confuse headers or the page
    // example: "C:\Users\me\Thesis Final.pdf" becomes "Thesis Final.pdf"
    public static function displayName(string $uploadedName): string {
        $name = basename(str_replace('\\', '/', $uploadedName)); // older browsers can send a whole Windows path
        $name = preg_replace('/[\x00-\x1F\x7F"]/u', '', $name); // no control characters or quotes
        if ($name === null || $name === '' || !mb_check_encoding($name, 'UTF-8')) {
            return 'thesis.pdf';
        }
        return mb_substr($name, 0, 255);
    }

    // turns 12345678 into "11.8 MB" for the record page; very small files show their exact byte count
    public static function readableSize(int $bytes): string {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 0) . ' KB';
        }
        return $bytes . ' bytes'; // otherwise a 400-byte file would read as "0 KB"
    }
}
