<?php
require_once __DIR__ . '/master.php';

if (!function_exists('handle_locations')) {
    function handle_locations($action) { return handle_locations($action); }
}
if (!function_exists('handle_customers')) {
    function handle_customers($action) { return handle_customers($action); }
}
if (!function_exists('handle_users')) {
    function handle_users($action) { return handle_users($action); }
}