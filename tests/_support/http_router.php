<?php

// Router dla `php -S` w KlientHttpTest.
switch (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) {
    case '/ok':
        header('Retry-After: 7');
        echo 'hello';
        break;

    case '/wolno':
        header('Content-Length: 10');
        echo 'x';
        flush();
        sleep(4);
        echo 'yyyyyyyyy';
        break;

    case '/echo':
        echo $_SERVER['REQUEST_METHOD'] . ':' . file_get_contents('php://input') . ':' . ($_SERVER['PHP_AUTH_USER'] ?? '');
        break;

    default:
        http_response_code(404);
        echo 'brak';
}
