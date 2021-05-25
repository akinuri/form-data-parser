<?php

require "FormDataParser.php";

// Single source of truth?
$_INPUT = [];

$contentType = FormDataParser::parseHeaderValue($_SERVER["CONTENT_TYPE"] ?? null, "mainValue");
$requestBody = \file_get_contents("php://input");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $_INPUT = $_POST;
    if ($contentType == "application/json") {
        $json = \json_decode($requestBody, true);
        $_INPUT += $json;
    }
}
else if (in_array($_SERVER["REQUEST_METHOD"], ["PUT", "PATCH"])) {
            
    if (empty($contentType)) return;
    
    $fields = null;
    
    switch ($contentType) {
        
        case "application/x-www-form-urlencoded":
            \parse_str($requestBody, $fields);
            break;
        
        case "multipart/form-data":
            $formData = FormDataParser::getFieldsAndFiles();
            $fields = $formData["fields"];
            $_FILES = $formData["files"];
            break;
        
        case "application/json":
            $json = \json_decode($requestBody, true);
            if ($json) $fields = $json;
            break;
        
    }
    
    if (!\is_array($fields)) {
        $fields = (array) $fields;
    }
    
    $_INPUT = $fields;
    
}

print_r($_INPUT);
print_r($_FILES);
