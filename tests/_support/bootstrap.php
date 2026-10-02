<?php

require __DIR__ . '/../../vendor/codeigniter4/framework/system/Test/bootstrap.php';

// Testy nie czytają mcs_ustawienia (SQLite :memory: nie ma tabeli) — tylko .env / domyślne.
\App\Libraries\OpcjeMcs::ustawModel(null);
