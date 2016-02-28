<?php
echo http_build_url("http://user@www.example.com/pub/index.php?a=b#files",
    array(
        "scheme" => "ftp",
        "host" => "ftp.example.com",
        "path" => "files/current/",
        "query" => "a=c"
    ),
    HTTP_URL_STRIP_AUTH | HTTP_URL_JOIN_PATH | HTTP_URL_JOIN_QUERY | HTTP_URL_STRIP_FRAGMENT
);
?>