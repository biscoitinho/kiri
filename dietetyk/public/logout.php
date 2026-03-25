<?php
require_once __DIR__ . '/../app/core/Bootstrap.php';
Auth::logout();
redirect('/login');
