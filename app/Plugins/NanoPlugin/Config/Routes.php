<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('plugins/nano/paymentRequest', '\App\Plugins\NanoPlugin\Controllers\NanoController::getPaymentRequest');
$routes->post('plugins/nano/addPayment', '\App\Plugins\NanoPlugin\Controllers\NanoController::postAddPayment');
