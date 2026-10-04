<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

class InquiryUploads
{
    public static function type(UploadedFile $file): ?array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $plain = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
        if (isset($plain[$ext]) && $mime === $plain[$ext]) {
            if ($ext !== 'pdf' && ! @getimagesize($file->getRealPath())) {
                return null;
            }

            return ['extension' => $ext, 'mime' => $mime];
        }
        if ($ext === 'csv' && in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true)) {
            $chunk = file_get_contents($file->getRealPath());

            return $chunk !== false && ! str_contains($chunk, "\0") && preg_match('//u', $chunk) ? ['extension' => 'csv', 'mime' => 'text/csv'] : null;
        }
        if (in_array($ext, ['docx', 'xlsx'], true)) {
            try {
                $zip = new \PharData($file->getRealPath(), 0, null, \Phar::ZIP);
                if (! $zip->isFileFormat(\Phar::ZIP) || ! in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) {
                    return null;
                }
                $entry = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
                if (isset($zip['[Content_Types].xml'], $zip[$entry])) {
                    if ($zip['[Content_Types].xml']->getSize() > 1048576) {
                        return null;
                    }
                    $types = $zip['[Content_Types].xml']->getContent();
                    if (strlen($types) > 1048576 || stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) {
                        return null;
                    }
                    foreach (new \RecursiveIteratorIterator($zip) as $part) {
                        if (preg_match('/(?:vbaProject|activeX|embeddings)\\b/i', $part->getPathname())) {
                            return null;
                        }
                    }

                    return ['extension' => $ext, 'mime' => $ext === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
                }
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN ._()-]/u', '_', $name) ?: 'document';

        return mb_substr($name, 0, 180);
    }
}
