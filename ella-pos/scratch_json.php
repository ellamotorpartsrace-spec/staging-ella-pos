<?php
$hOnly = 'stopwatch-unfeeling-calamari.ngrok-free.dev';
$isLocal = in_array($hOnly, ['localhost', '127.0.0.1', '::1']) || str_ends_with($hOnly, '.test') || str_contains($hOnly, 'ngrok');
var_dump($isLocal);
