<?php
define('DOCSYS', 1);
require __DIR__ . '/../app/bootstrap.php';

logout_user(); // destroys session

redirect('/docsys/public/');
