<?php

require "FormDataParser.php";

// Single source of truth?
$_INPUT = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $_INPUT += $_POST;
}
else if (in_array($_SERVER["REQUEST_METHOD"], ["PUT", "PATCH", "DELETE"])) {
    
    $contentType = $_SERVER["CONTENT_TYPE"] ?? null;
    
    if (!empty($contentType)) {
        
        $contentType = FormDataParser::parseHeaderValue($contentType)["mainValue"];
        
        $requestBody = \file_get_contents("php://input");
        
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
    
        $_INPUT += $fields;
    }
    
}

print_r($_INPUT);
print_r($_FILES);
