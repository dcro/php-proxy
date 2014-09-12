PHP Proxy
=========

A simple PHP proxy script that can be used to proxy HTTP requests to external web services

The proxy endpoint URL should be specified in the `GET` or `POST` parameters as `endpoint=http://...`

The proxy forwards the original request to the endpoint URL and returns the response back to the original caller.

Currently only GET and POST requests are supported. On HEAD, PUT, or DELETE requests, the proxy will return a `400 Bad Request` response.

If a request fails because of a CURL error (endpoint unavailable, network is down, etc.) the proxy responds with a `502 Bad Gateway` status.

Check out the [test HTML file (test.html)](test.html) to test the proxy script.
