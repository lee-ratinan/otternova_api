<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Uploaded images, as saved by /v1/staff/image-upload and /v1/client/image-upload; see App\Controllers\Images
// The initials avatar shown when auth_master.avatar_path is empty; see App\Libraries\InitialsAvatar
$routes->get('image/initials-avatar/(:segment)', 'Images::initials/$1');
$routes->get('image/(:segment)/(:segment)', 'Images::show/$1/$2');

// Version 1 of the API: every endpoint the apps call lives under /v1
$routes->group('v1', static function (RouteCollection $routes) {
    // Sign-in for the Admin (staff) and Client apps; see App\Controllers\Auth
    $routes->post('login', 'Auth::login');
    $routes->post('verify-otp', 'Auth::verifyOtp');
    $routes->post('change-expired-password', 'Auth::changeExpiredPassword');
    $routes->post('logout', 'Auth::logout', ['filter' => 'jwt']);

    // Client app sign-up: no sign-in needed; see App\Controllers\Auth
    $routes->post('create-client-account', 'Auth::createClientAccount');
    $routes->post('activate-client-account', 'Auth::activateClientAccount');
    $routes->post('client-invitation', 'Auth::clientInvitation');

    // Forgotten password, for either app: no sign-in needed; see App\Controllers\Auth
    $routes->post('request-password-reset', 'Auth::requestPasswordReset');
    $routes->post('check-password-reset', 'Auth::checkPasswordReset');
    $routes->post('reset-password', 'Auth::resetPassword');

    // Public contact form: no sign-in needed; see App\Controllers\Contact and App\Libraries\ContactFormGuard
    $routes->get('contact-form-token', 'Contact::formToken');
    $routes->post('contact', 'Contact::submit');

    // Admin app: staff accounts only (a valid token with typ S)
    $routes->group('staff', ['filter' => 'jwt:S'], static function (RouteCollection $routes) {
        $routes->get('me', 'Auth::me');
        $routes->post('change-password', 'Auth::changePassword');
        $routes->post('accounts', 'Accounts::list');
        $routes->post('account', 'Accounts::show');
        $routes->post('account/update', 'Accounts::save');
        $routes->post('account/delete', 'Accounts::delete');
        $routes->post('departments', 'Accounts::departments');
        $routes->post('staff-members', 'Accounts::staffMembers');
        $routes->post('business-types', 'Businesses::listTypes');
        $routes->post('business-type/create', 'Businesses::createType');
        $routes->post('business-type/edit', 'Businesses::editType');
        $routes->post('image-upload', 'Images::upload');
        $routes->post('image-delete', 'Images::delete');
    });

    // Admin app's internal ledger: staff of the Executive Office and Finance only; see App\Controllers\Ledger
    $routes->group('staff/ledger', ['filter' => ['jwt:S', 'department:EXE,FIN']], static function (RouteCollection $routes) {
        $routes->post('entries', 'Ledger::entries');
        $routes->post('entry', 'Ledger::entry');
        $routes->post('entry/update', 'Ledger::saveEntry');
        $routes->post('receipt-upload', 'Ledger::uploadReceipt');
        $routes->post('receipt-delete', 'Ledger::deleteReceipt');
        $routes->get('receipt/(:segment)', 'Ledger::receipt/$1');
        $routes->post('accounts', 'Ledger::accounts');
        $routes->post('account/update', 'Ledger::saveAccount');
        $routes->post('categories', 'Ledger::categories');
        $routes->post('category/update', 'Ledger::saveCategory');
        $routes->post('counterparties', 'Ledger::counterparties');
        $routes->post('counterparty/update', 'Ledger::saveCounterparty');
    });

    // Admin app's plans, prices, payment methods and discount codes: Executive Office and Finance only; see App\Controllers\Plans
    $routes->group('staff/plans', ['filter' => ['jwt:S', 'department:EXE,FIN']], static function (RouteCollection $routes) {
        $routes->post('plans', 'Plans::plans');
        $routes->post('plan/create', 'Plans::createPlan');
        $routes->post('plan/edit', 'Plans::editPlan');
        $routes->post('versions', 'Plans::versions');
        $routes->post('version/create', 'Plans::createVersion');
        $routes->post('version/cancel', 'Plans::cancelVersion');
        $routes->post('payment-methods', 'Plans::paymentMethods');
        $routes->post('payment-method/create', 'Plans::createPaymentMethod');
        $routes->post('payment-method/edit', 'Plans::editPaymentMethod');
        $routes->post('discounts', 'Plans::discounts');
        $routes->post('discount', 'Plans::discount');
        $routes->post('discount/update', 'Plans::saveDiscount');
    });

    // Admin app's view of every subscription and payment: Executive Office and Finance only; see App\Controllers\SubscriptionAdmin
    $routes->group('staff/subscriptions', ['filter' => ['jwt:S', 'department:EXE,FIN']], static function (RouteCollection $routes) {
        $routes->post('list', 'SubscriptionAdmin::list');
        $routes->post('subscription', 'SubscriptionAdmin::subscription');
        $routes->post('subscription/status', 'SubscriptionAdmin::setStatus');
        $routes->post('invoices', 'SubscriptionAdmin::invoices');
        $routes->post('invoice', 'SubscriptionAdmin::invoice');
        $routes->post('payments', 'SubscriptionAdmin::payments');
        $routes->post('payment/approve', 'SubscriptionAdmin::approvePayment');
        $routes->post('payment/reject', 'SubscriptionAdmin::rejectPayment');
        $routes->get('slip/(:segment)', 'SubscriptionAdmin::slip/$1');
    });

    // Admin app's activity log: the Executive Office only, as it holds every table's changes; see App\Controllers\ActivityLogs
    $routes->group('staff/logs', ['filter' => ['jwt:S', 'department:EXE']], static function (RouteCollection $routes) {
        $routes->post('activities', 'ActivityLogs::activities');
        $routes->post('tables', 'ActivityLogs::tables');
    });

    // Client app's subscription of a business: its active owners only; see App\Controllers\Subscriptions
    $routes->group('client/subscription', ['filter' => 'jwt:C'], static function (RouteCollection $routes) {
        $routes->post('current', 'Subscriptions::current');
        $routes->post('plans', 'Subscriptions::plans');
        $routes->post('discounts', 'Subscriptions::discounts');
        $routes->post('payment-methods', 'Subscriptions::paymentMethods');
        $routes->post('subscribe', 'Subscriptions::subscribe');
        $routes->post('apply-discount', 'Subscriptions::applyDiscount');
        $routes->post('pay', 'Subscriptions::pay');
        $routes->post('cancel', 'Subscriptions::cancel');
        $routes->post('resume', 'Subscriptions::resume');
        $routes->post('change-plan', 'Subscriptions::changePlan');
        $routes->post('invoices', 'Subscriptions::invoices');
    });

    // Client app: client accounts only (a valid token with typ C)
    $routes->group('client', ['filter' => 'jwt:C'], static function (RouteCollection $routes) {
        $routes->get('me', 'Auth::me');
        $routes->post('change-password', 'Auth::changePassword');
        $routes->post('account', 'Accounts::showOwn');
        $routes->post('account/update', 'Accounts::saveOwn');
        $routes->post('business-types', 'Businesses::listActiveTypes');
        $routes->post('business/create', 'Businesses::create');
        $routes->post('business/edit', 'Businesses::edit');
        $routes->post('businesses', 'Businesses::listOwn');
        $routes->post('business/make-default', 'Businesses::makeDefault');
        $routes->post('business/switch', 'Businesses::switchTo');
        // the stores of a business: its active owners and managers only; see App\Controllers\Stores
        $routes->post('stores', 'Stores::list');
        $routes->post('store/create', 'Stores::create');
        $routes->post('store/edit', 'Stores::edit');
        // the people of a business and their accounts: its active owners only; see App\Controllers\Employees
        $routes->post('employees', 'Employees::list');
        $routes->post('employee', 'Employees::show');
        $routes->post('employee/edit', 'Employees::edit');
        $routes->post('employee/terminate', 'Employees::terminate');
        $routes->post('employee/rehire', 'Employees::rehire');
        $routes->post('employee/find', 'Employees::find');
        $routes->post('employee/add', 'Employees::add');
        $routes->post('employee/invite', 'Employees::invite');
        $routes->post('image-upload', 'Images::upload');
        $routes->post('image-delete', 'Images::delete');
        // the images of an artifact: the active owners and managers of its business only; see App\Controllers\ArtifactImages
        $routes->post('artifact/image-upload', 'ArtifactImages::upload');
        $routes->post('artifact/image-delete', 'ArtifactImages::delete');
        $routes->post('artifact/image-reorder', 'ArtifactImages::reorder');
        // the products and services of a business and what they are made of: its active owners and managers only;
        // see App\Controllers\Artifacts, ArtifactCategories and ArtifactAttributes
        $routes->post('artifacts', 'Artifacts::list');
        $routes->post('artifact', 'Artifacts::show');
        $routes->post('artifact/create', 'Artifacts::create');
        $routes->post('artifact/edit', 'Artifacts::edit');
        $routes->post('artifact-categories', 'ArtifactCategories::list');
        $routes->post('artifact-category/create', 'ArtifactCategories::create');
        $routes->post('artifact-category/edit', 'ArtifactCategories::edit');
        $routes->post('artifact-attributes', 'ArtifactAttributes::list');
        $routes->post('artifact-attribute/create', 'ArtifactAttributes::create');
        $routes->post('artifact-attribute/edit', 'ArtifactAttributes::edit');
        // the reviews of a business's artifacts: any of its active clients; see App\Controllers\ArtifactReviews
        $routes->post('artifact-reviews', 'ArtifactReviews::list');
        $routes->post('artifact-review/approve', 'ArtifactReviews::approve');
        $routes->post('artifact-review/hide', 'ArtifactReviews::hide');
    });
});
