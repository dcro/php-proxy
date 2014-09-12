<?php

/**
 *
 * PHP PROXY
 *
 * ------------------------------------------------------------------------
 *
 * Copyright (c) 2014 Dan Cotora
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 * ------------------------------------------------------------------------
 *
 * The proxy endpoint URL should be specified in the GET or POST parameters as endpoint=http://...
 *
 * The proxy forwards the original request to the endpoint URL
 * and returns the response back to the original caller
 *
 * Currently only GET and POST requests are supported. On HEAD, PUT, or DELETE requests,
 * the proxy will return a "400 Bad Request" response
 *
 * If a request fails because of a CURL error (endpoint unavailable, network is down, etc.)
 * the proxy responds with a 502 Bad Gateway status
 *
 */

// Set error reporting level
error_reporting(E_ALL ^ E_NOTICE);

set_time_limit(0);

// ================================
// ===== FUNCTION DEFINITIONS =====
// ================================

/**
 * Get the original request headers
 *
 * Returns an array of headers
 */
function getRequestHeaders()
{
    // Initialize the headers list
    $headers = array();

    // Go trough all the $_SERVER values and extract the request headers
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === "HTTP_") {
            $key = str_replace('_', ' ', substr($key, 5));
            $key = str_replace(' ', '-', ucwords(strtolower($key)));
            $headers[$key] = $value;
        }
    }

    // Return the headers
    return $headers;
}

/**
 * Proxy a request to the specified URL (using CURL)
 *
 * $url     - The endpoint URL
 * $method  - The HTTP method
 * $headers - An array with the headers that should be sent with the request
 * $data    - The GET or POST data that should be included with the request
 *
 * Returns and array with the request & response headers and response body
 *
 * Throws an exception if a CURL error occurs
 */
function sendProxyRequest($url, $method, $headers, $data = array())
{

    // Transform the headers array from the key => value format in the one CURL expects
    $curlHeaders = array();
    foreach ($headers as $key => $value) {
        array_push($curlHeaders, $key . ': ' . $value);
    }

    // Initialize the curl request
    $ch = curl_init();

    // If this is a GET request, append the data to the URL
    if ($method === 'GET') {
        // Add the ? symbol at the end of the URL if the URL doesn't contain any other parameters
        if (strpos($url, '?') === false) {
            $url .= '?';
        } else {
            $url .= '&';
        }

        // Add the get parameters
        foreach($data as $key => $value) {
            $url .= rawurlencode($key) . '=' . rawurlencode($value) . '&';
        }

        // Trim the trailing extra & from the url
        $url = rtrim($url, '&');
    }

	// Set the endpoint URL
    curl_setopt($ch, CURLOPT_URL, $url);

    // Forward the specified headers
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

    // Configure CURL to return the transfer
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // Configure CURL to return the response headers
    curl_setopt($ch, CURLOPT_HEADER, 1);

    // Uncomment the following line to always force HTTP 1.0 and prevent chunked transfers
    // Force HTTP 1.0 to prevent chunked transfers
    // curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);

    // Configure CURL to return the original request headers so we can log them
    curl_setopt($ch, CURLINFO_HEADER_OUT, 1);

    // Configure the CURL connection timeout to 120 seconds
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 120);

    // If this is a POST request, include the post fields with the request
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    // Execute the CURL request
    $response = curl_exec($ch);

    // Get the original request headers
    $requestHeders = curl_getinfo($ch, CURLINFO_HEADER_OUT);

    // If an invalid response was received, throw an exception
    if ($response === false) {
        // Get the curl error message and error code
        $message = curl_error($ch);
        $code    = curl_errno($ch);

        // Close the curl handle
        curl_close($ch);

        // Throw the exception
        throw new Exception($message, $code);
    }

    // Close the curl handle
    curl_close($ch);

    // If the received response contains 100 Continue, remove it from the response
    if (0 === strpos($response, "HTTP/1.1 100 Continue\r\n\r\n")) {
        $response = substr($response, 25);
    }

    // Get the response headers and body
    list($responseHeaders, $responseBody) = explode("\r\n\r\n", $response, 2);

    // Trim extra new lines from the response headers
    $requestHeders = rtrim($requestHeders);

    // Return the response
    return array(
        'requestHeders'   => $requestHeders,
        'responseHeaders' => $responseHeaders,
        'responseBody'    => $responseBody
    );
}

// =====================
// ===== MAIN CODE =====
// =====================

// Get the HTTP request method
if (isset($_SERVER['REQUEST_METHOD'])) {
    $requestMethod = strtoupper($_SERVER['REQUEST_METHOD']);
} else {
    echo "Please run this script under a web server" . PHP_EOL;
    exit -1;
}

// Get the original requester's IP address
$senderIP = "Unknown";
if (isset($_SERVER["REMOTE_ADDR"])) {
    $senderIP = $_SERVER["REMOTE_ADDR"];
}

// If this isn't a GET or POST request return the appropriate response
if (!in_array($requestMethod, array('GET', 'POST'))) {
    // Return a 400 Bad Request response and exit
    header('HTTP/1.1 400 Bad Request');
    die();
}

// Get the endpoint URL
$url = $_GET['endpoint'] ? $_GET['endpoint'] : $_POST['endpoint'];

// If the URL is empty or is invalid, return the appropriate response
$isURLEmpty = empty($url);
$isURLValid = filter_var($url, FILTER_VALIDATE_URL) !== false;

if ($isURLEmpty || !$isURLValid) {
    // Return a 400 Bad Request response and exit
    header('HTTP/1.1 400 Bad Request');
    die();
}

// Get the original request headers
$requestHeders = getRequestHeaders();

// Unset the headers that should not be forwarded by the proxy
unset($requestHeders['Host']);
unset($requestHeders['Content-Length']);
unset($requestHeders['Connection']);

// Add the Content-Type header based on the original content type
if (isset($_SERVER['CONTENT_TYPE'])) {
    $requestHeders['Content-Type'] = $_SERVER['CONTENT_TYPE'];
}

// Add the X-Forwarded-For header
$requestHeders['X-Forwarded-For'] = isset($requestHeders['X-Forwarded-For']) ? $senderIP . ',' . $requestHeders['X-Forwarded-For'] : $senderIP;

// Initialize the data that should be forwarded
$data = array();

if ($requestMethod === 'GET') {
    // Get the original parameters sent via GET
    $data = $_GET;

    // Unset the parameters that are used internally by the proxy
    unset($data['endpoint']);

} elseif ($requestMethod === 'POST') {
    // If this is a multipart/form-data request, just use the parsed data
    // (PHP insists to parse this and it doesn't offer access to the raw POST data)
    if (preg_match('/^multipart\/form\-data/', $requestHeders['Content-Type'])) {
        $data = $_POST;

        // Set the correct content type (override the previous one)
        $requestHeders['Content-Type'] = 'multipart/form-data';

        // Unset the parameters that are used internally by the proxy
        unset($data['endpoint']);
    } else {
        // Use the raw POST data and keep the original content type
        $data = file_get_contents('php://input');
    }
}

try {
    // Proxy the request to the specified endpoint
    $result = sendProxyRequest($url, $requestMethod, $requestHeders, $data);

} catch (Exception $ex) {
    // Return a 502 Bad Gateway and exit
    header('HTTP/1.1 502 Bad Gateway');
    die();
}

// Send out the response headers, but skip any "Transfer-Encoding" headers
// Also identify any Content-Type & Content-Encoding headers in the response to determine if the content is binary or not
$responseHeaders = explode("\n", $result['responseHeaders']);
$contentType     = null;
$contentEncoding = null;

foreach ($responseHeaders as $header) {
    if (!preg_match('/^Transfer-Encoding/i', $header)) {
        header($header);
    }

    // Extract the Content-Type header's value
    if (preg_match('/^Content-Type:/i', $header)) {
        $contentType = strtolower(trim(substr($header, strpos($header, ':') + 1)));
        if ($pos = strpos($contentType, ';')) $contentType = substr($contentType, 0, $pos);
    }

    // Extract the Content-Encoding header's value
    if (preg_match('/^Content-Encoding:/i', $header)) {
        $contentEncoding = strtolower(trim(substr($header, strpos($header, ':') + 1)));
    }
}

// Send out the response body
echo $result['responseBody'];