<?php
require_once "protect.php";
require_once "functions.php";

requireLogin();

if (isAdmin()) {
    header('Location: /admin/index.php');
} else {
    header('Location: /');
}
exit;
