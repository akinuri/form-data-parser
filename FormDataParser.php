<?php

/**
 * Parses raw multipart/form-data.
 */
final class FormDataParser {
    
    private function __construct() {}
    
    
    #region ==================== BOUNDARY
    
    public static function findBoundary(string $formData): ?string {
        $lines = \preg_split("/\r?\n/", $formData);
        $firstLine = \trim($lines[0]);
        \preg_match("/--(.+)/", $firstLine, $matches);
        $boundary = $matches[1] ?? null;
        return $boundary;
    }
    
    #endregion
    
    
    #region ==================== HEADER(S)
    
    /**
     * Parses Content-Disposition and Content-Type header values.
     */
    public static function parseHeaderValue(?string $headerValue, string $returnField = null) {
        $result = [];
        if (!empty($headerValue)) {
            $pieces = \explode(";", $headerValue);
            foreach ($pieces as $piece) {
                $assignment = \explode("=", $piece);
                $assignment = \array_map("trim", $assignment);
                if (\count($assignment) == 1) {
                    $result["mainValue"] = $assignment[0];
                } else {
                    $assignment[1] = \trim($assignment[1], "\"");
                    $result[ $assignment[0] ] = $assignment[1];
                }
            }
        }
        if ($returnField) {
            $result = $result[$returnField] ?? null;
        }
        return $result;
    }
    
    /**
     * Parses Content-Disposition and Content-Type headers (or headers with similar format).
     */
    public static function parseHeader(string $header): array {
        [$header, $value] = \explode(":", $header, 2);
        $result = [
            "header" => $header,
            "value" => self::parseHeaderValue($value),
        ];
        return $result;
    }
    
    #endregion
    
    
    #region ==================== PART(S)
    
    /**
     * Splits the form data into individual raw parts.
     */
    public static function getParts(string $formData, string $boundary = null): array {
        $boundary ??= self::findBoundary($formData);
        $parts = \explode("--" . $boundary, $formData);
        $parts = \array_filter($parts);
        \array_pop($parts);
        return $parts;
    }
    
    /**
     * Parses a raw part from the form data.
     */
    public static function parsePart(string $rawPart): array {
        $part = \trim($rawPart) . "\r\n\r\n";
        [$headers, $body] = \preg_split("/\r?\n\r?\n/", $part, 2);
        $headers = \preg_split("/\r?\n/", $headers);
        $headers = \array_map([self::class, "parseHeader"], $headers);
        foreach ($headers as $key => $header) {
            $headers[ $header["header"] ] = $header["value"];
            unset($headers[$key]);
        }
        $part = [
            "headers" => $headers,
            "body" => \trim($body),
        ];
        return $part;
    }
    
    #endregion
    
    
    #region ==================== FORM DATA
    
    /**
     * Parses raw form data.
     * 
     * The result is not consumer friendly, that is, it's not similar to $_POST or $_FILES.
     */
    public static function parseFormData(string $formData = null, string $boundary = null): array {
        $formData ??= \file_get_contents("php://input");
        $parts = self::getParts($formData, $boundary);
        $parts = \array_map([self::class, "parsePart"], $parts);
        return $parts;
    }
    
    #endregion
    
    
    #region ==================== INI
    
    /**
     * Parses a size string and returns it in bytes.
     */
    public static function parseSize(string $size): ?int {
        if (empty($size) || !\preg_match("/^\d*(?:\.\d+)?[BKM]$/i", $size)) {
            return null;
        }
        $amount = (int) substr($size, 0, -1);
        $unit   = $size[\strlen($size) - 1];
        // https://stackoverflow.com/a/25370978/2202732
        $bytes = \round($amount * \pow(1024, \stripos("BKM", $unit)));
        return $bytes;
    }
    
    #endregion
    
    
    #region ==================== FILES
    
    public static function processFile(array &$part, int $MAX_FILE_SIZE = null) {
        
        // https://www.php.net/manual/en/features.file-upload.post-method.php
        // https://www.php.net/manual/en/features.file-upload.errors.php#features.file-upload.errors
        
        $part["file"] = [
            "name"     => $part["headers"]["Content-Disposition"]["filename"],
            "type"     => $part["headers"]["Content-Type"]["mainValue"],
            "size"     => \strlen($part["body"]),
            "tmp_name" => null,
            "error"    => \UPLOAD_ERR_OK,
        ];
        $file = &$part["file"];
        
        $upload_max_filesize = \ini_get("upload_max_filesize");
        $upload_max_filesize = self::parseSize($upload_max_filesize);
        if ($file["size"] > $upload_max_filesize) {
            $file["error"] = \UPLOAD_ERR_INI_SIZE;
            return;
        }
        
        if (!is_null($MAX_FILE_SIZE) && $file["size"] > $MAX_FILE_SIZE) {
            $file["error"] = \UPLOAD_ERR_FORM_SIZE;
            return;
        }
        
        // if (false) {
            // Do we have a way to determine if the file was uploaded partially?
            // $file["error"] = \UPLOAD_ERR_PARTIAL;
            // return;
        // }
        
        if ($file["name"] === "" && $file["size"] == 0) {
            $file["error"] = \UPLOAD_ERR_NO_FILE;
            return;
        }
        
        // Are we handling this right?
        $upload_tmp_dir = \ini_get("upload_tmp_dir");
        if (empty($upload_tmp_dir)) {
            $file["error"] = \UPLOAD_ERR_NO_TMP_DIR;
            return;
        }
        
        $file["tmp_name"] = \tempnam($upload_tmp_dir, "upload");
        $handle = \fopen($file["tmp_name"], "w");
        if (!$handle) {
            $file["error"] = \UPLOAD_ERR_CANT_WRITE;
            return;
        }
        \fwrite($handle, $part["body"]);
        \fclose($handle);
        \register_shutdown_function(function () use ($file) {
            @unlink($file["tmp_name"]);
        });
        
        // if (false) {
            // How will we even know that an extension stopped the file upload?
            // $file["error"] = \UPLOAD_ERR_EXTENSION;
            // return;
        // }
        
    }
    
    /**
     * Creates temporary files from the files in the parsed form data.
     */
    public static function processFiles(array &$formData) {
        $fields = self::getFields($formData);
        $MAX_FILE_SIZE = $fields["MAX_FILE_SIZE"] ?? null;
        if ($MAX_FILE_SIZE) {
            $MAX_FILE_SIZE = (int) $MAX_FILE_SIZE;
        }
        foreach ($formData as &$part) {
            if (!empty($part["headers"]["Content-Disposition"]["filename"])) {
                self::processFile($part, $MAX_FILE_SIZE);
            }
        }
    }
    
    #endregion
    
    
    #region ==================== FIELDS & FILES
    
    /**
     * Returns the fields from the parsed form data.
     * Builds an array similar to $_POST from the parsed form data.
     */
    public static function getFields(array $formData): array {
        $pairs = [];
        foreach ($formData as $part) {
            if (empty($part["headers"]["Content-Disposition"]["filename"])) {
                $name  = $part["headers"]["Content-Disposition"]["name"];
                $value = $part["body"];
                $pair  = $name ."=". $value;
                $pairs[] = $pair;
            }
        }
        $pairs = \implode("&", $pairs);
        \parse_str($pairs, $fields);
        return $fields;
    }
    
    /**
     * Returns the files from the parsed form data.
     * Builds an array similar to $_FILES from the parsed form data.
     */
    public static function getFiles(array $formData) {
        $parts = \array_filter($formData, fn ($part) => !empty($part["file"]));
        $files = [];
        foreach ($parts as $part) {
            $fieldName = $part["headers"]["Content-Disposition"]["name"];
            // for now, let's keep it simple and support only first-level arrays
            if (\strpos($fieldName, "[]") !== false) {
                $fieldName = \str_replace("[]", "", $fieldName);
                $files[ $fieldName ][] = $part["file"];
            } else {
                $files[ $fieldName ] = $part["file"];
            }
        }
        return $files;
    }
    
    /**
     * Returns the fully parsed consumer friendly form data.
     */
    public static function getFieldsAndFiles() {
        $formData = self::parseFormData();
        self::processFiles($formData);
        $result = [
            "fields" => self::getFields($formData),
            "files"  => self::getFiles($formData),
        ];
        return $result;
    }
    
    #endregion
    
    
}