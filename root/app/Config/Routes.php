<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
// The container's health check; see App\Controllers\Health
$routes->get('health', 'Health::index');

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

    // The Terms of Service and Privacy Policy in effect: no sign-in needed; see App\Controllers\Legal
    $routes->match(['GET', 'POST'], 'legal/current', 'Legal::current');
    $routes->match(['GET', 'POST'], 'legal/document', 'Legal::document'); // the website's page of one document and its versions

    // Forgotten password, for either app: no sign-in needed; see App\Controllers\Auth
    $routes->post('request-password-reset', 'Auth::requestPasswordReset');
    $routes->post('check-password-reset', 'Auth::checkPasswordReset');
    $routes->post('reset-password', 'Auth::resetPassword');

    // Public contact form: no sign-in needed; see App\Controllers\Contact and App\Libraries\ContactFormGuard
    $routes->get('contact-form-token', 'Contact::formToken');
    $routes->post('contact', 'Contact::submit');

    // Visitor tracker of the website and the marketplace (public/js/tracker.js): no sign-in needed; see
    // App\Controllers\Tracker and App\Libraries\TrackerGuard
    $routes->post('track', 'Tracker::collect');

    // The public pages of a business on the marketplace (otternova_mart): no sign-in needed, cached; see
    // App\Controllers\Mart and App\Libraries\MartCache
    $routes->group('mart', static function (RouteCollection $routes) {
        $routes->get('site', 'Mart::site');
        $routes->get('search', 'Mart::search'); // businesses by name or tag, across the marketplace
        $routes->get('webpage', 'Mart::webpage');
        $routes->get('design', 'Mart::design');
        $routes->get('artifacts', 'Mart::artifacts');
        $routes->get('artifact', 'Mart::artifact');
        $routes->get('slots', 'Mart::slots');
        $routes->get('sessions', 'Mart::sessions');
        $routes->get('staff', 'Mart::staff');
        $routes->post('quote', 'Mart::quote');
        $routes->post('order', 'Mart::placeOrder');
        $routes->get('order', 'Mart::order');
        $routes->post('order/report-payment', 'Mart::reportPayment');
        $routes->post('order/lookup', 'Mart::lookupOrder');
        $routes->post('order-history/request', 'Mart::requestHistory');
        $routes->post('order-history/open', 'Mart::openHistory');
        $routes->post('order-history', 'Mart::history');
        $routes->post('order-history/close', 'Mart::closeHistory');
        // the contact form of a business: a message to it, read in the Client app; see App\Controllers\MartMessages
        $routes->get('message-token', 'MartMessages::token');
        $routes->post('message', 'MartMessages::submit');
    });

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

    // Admin app's inbox of the public contact form's messages: any staff member; see App\Controllers\Inbox
    // Each app's own notifications, the signed-in account's only: staff and clients alike; see App\Controllers\Notifications
    foreach (['staff/notifications' => 'jwt:S', 'client/notifications' => 'jwt:C'] as $prefix => $filter) {
        $routes->group($prefix, ['filter' => $filter], static function (RouteCollection $routes) {
            $routes->post('latest', 'Notifications::latest');
            $routes->post('list', 'Notifications::list');
            $routes->post('read', 'Notifications::read');
            $routes->post('read-all', 'Notifications::readAll');
            $routes->post('delete', 'Notifications::delete');
            $routes->post('delete-read', 'Notifications::deleteRead');
        });
    }

    // Admin app's traffic of the website and the marketplace, from the tracker: any staff member; see App\Controllers\Traffic
    $routes->group('staff/traffic', ['filter' => 'jwt:S'], static function (RouteCollection $routes) {
        $routes->post('overview', 'Traffic::staffOverview');
        $routes->post('breakdown', 'Traffic::staffBreakdown');
        $routes->post('visits', 'Traffic::staffVisits');
        $routes->post('businesses', 'Traffic::staffBusinesses');
    });

    $routes->group('staff/inbox', ['filter' => 'jwt:S'], static function (RouteCollection $routes) {
        $routes->post('messages', 'Inbox::messages');
        $routes->post('message', 'Inbox::message');
        $routes->post('message/status', 'Inbox::setStatus');
        $routes->post('message/category', 'Inbox::setCategory');
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
        $routes->post('entities', 'Ledger::entities');
        $routes->post('entity/update', 'Ledger::saveEntity');
        $routes->post('report/trial-balance', 'Ledger::trialBalance');
        $routes->post('report/profit-loss', 'Ledger::profitLoss');
        $routes->post('report/balance-sheet', 'Ledger::balanceSheet');
        $routes->post('report/general-ledger', 'Ledger::generalLedger');
        $routes->post('report/vat', 'Ledger::vatReport');
        $routes->post('report/wht', 'Ledger::whtReport');
        $routes->post('report/revenue-register', 'Ledger::revenueRegister');
        $routes->post('report/expense-register', 'Ledger::expenseRegister');
        $routes->post('report/vat-threshold', 'Ledger::vatThreshold');
        $routes->post('tax-codes', 'Ledger::taxCodes');
        $routes->post('tax-code/update', 'Ledger::saveTaxCode');
        $routes->post('posting-rules', 'Ledger::postingRules');
        $routes->post('posting-rule/update', 'Ledger::savePostingRule');
        $routes->post('posting/status', 'Ledger::postingStatus');
        $routes->post('posting/sync', 'Ledger::syncPosting');
        $routes->post('periods', 'Ledger::periods');
        $routes->post('period/close', 'Ledger::closePeriod');
        $routes->post('period/reopen', 'Ledger::reopenPeriod');
        $routes->post('period/snapshot', 'Ledger::periodSnapshot');
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

    // Admin app's view of every business: any staff member, the tax ID to the Executive Office and Finance only; see App\Controllers\BusinessAdmin
    $routes->group('staff/businesses', ['filter' => 'jwt:S'], static function (RouteCollection $routes) {
        $routes->post('list', 'BusinessAdmin::list');
        $routes->post('business', 'BusinessAdmin::business');
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
        $routes->post('withholding', 'SubscriptionAdmin::withholding');
        $routes->post('withholding/review', 'SubscriptionAdmin::reviewWithholding');
        $routes->post('certificates', 'SubscriptionAdmin::certificates');
        $routes->post('certificate/accept', 'SubscriptionAdmin::acceptCertificate');
        $routes->post('certificate/reject', 'SubscriptionAdmin::rejectCertificate');
        $routes->get('certificate-file/(:segment)', 'SubscriptionAdmin::certificateFile/$1');
    });

    // Admin app's org chart, the staff's reporting lines: the Executive Office and Human Resources only; see App\Controllers\Accounts
    $routes->group('staff', ['filter' => ['jwt:S', 'department:EXE,HRD']], static function (RouteCollection $routes) {
        $routes->post('org-chart', 'Accounts::orgChart');
    });

    // Admin app's Finance dashboard: Executive Office and Finance only; see App\Controllers\Dashboards
    $routes->group('staff/dashboard', ['filter' => ['jwt:S', 'department:EXE,FIN']], static function (RouteCollection $routes) {
        $routes->post('finance', 'Dashboards::finance');
    });
    // ... and the Executive Office's, which holds the activity log: the Executive Office only
    $routes->group('staff/dashboard', ['filter' => ['jwt:S', 'department:EXE']], static function (RouteCollection $routes) {
        $routes->post('executive', 'Dashboards::executive');
    });
    // ... and Engineering's, with the API's error log: Engineering and the Executive Office
    $routes->group('staff/dashboard', ['filter' => ['jwt:S', 'department:EXE,ENG']], static function (RouteCollection $routes) {
        $routes->post('engineering', 'Dashboards::engineering');
    });
    // ... and Client Experience's, with the inbox's response times: Client Experience and the Executive Office
    $routes->group('staff/dashboard', ['filter' => ['jwt:S', 'department:EXE,CXD']], static function (RouteCollection $routes) {
        $routes->post('client-experience', 'Dashboards::clientExperience');
    });

    // Admin app's billing documents, as HTML pages: Executive Office and Finance only; see App\Controllers\Documents
    $routes->group('staff/documents', ['filter' => ['jwt:S', 'department:EXE,FIN']], static function (RouteCollection $routes) {
        $routes->get('invoice/(:segment)', 'Documents::staffInvoice/$1');
        $routes->get('receipt/(:segment)', 'Documents::staffReceipt/$1');
    });

    // Admin app's Terms of Service and Privacy Policy: the versions and who accepted them for the Executive Office and
    // Client Experience; publishing or cancelling a version for the Executive Office only; see App\Controllers\LegalAdmin
    $routes->group('staff/legal', ['filter' => ['jwt:S', 'department:EXE,CXD']], static function (RouteCollection $routes) {
        $routes->post('versions', 'LegalAdmin::versions');
        $routes->post('version', 'LegalAdmin::version');
        $routes->post('acceptances', 'LegalAdmin::acceptances');
    });
    $routes->group('staff/legal', ['filter' => ['jwt:S', 'department:EXE']], static function (RouteCollection $routes) {
        $routes->post('version/create', 'LegalAdmin::create');
        $routes->post('version/cancel', 'LegalAdmin::cancel');
    });

    // Admin app's activity log: the Executive Office, as it holds every table's changes, and Engineering to look into problems; see App\Controllers\ActivityLogs
    $routes->group('staff/logs', ['filter' => ['jwt:S', 'department:EXE,ENG']], static function (RouteCollection $routes) {
        $routes->post('activities', 'ActivityLogs::activities');
        $routes->post('tables', 'ActivityLogs::tables');
    });

    // Admin app's email log: the Executive Office, Client Experience for customers' questions, and Engineering to look into problems; see App\Controllers\EmailLogs
    $routes->group('staff/logs', ['filter' => ['jwt:S', 'department:EXE,CXD,ENG']], static function (RouteCollection $routes) {
        $routes->post('emails', 'EmailLogs::emails');
        $routes->post('email-types', 'EmailLogs::types');
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
        $routes->post('withholding', 'Subscriptions::withholding');
        $routes->post('withholding/withdraw', 'Subscriptions::withdrawWithholding');
        $routes->post('certificate', 'Subscriptions::certificate');
    });

    // Client app's billing documents of a business, as HTML pages: its active owners only; see App\Controllers\Documents
    $routes->group('client/documents', ['filter' => 'jwt:C'], static function (RouteCollection $routes) {
        $routes->get('invoice/(:segment)', 'Documents::clientInvoice/$1');
        $routes->get('receipt/(:segment)', 'Documents::clientReceipt/$1');
    });

    // Client app: client accounts only (a valid token with typ C)
    $routes->group('client', ['filter' => 'jwt:C'], static function (RouteCollection $routes) {
        $routes->get('me', 'Auth::me');
        $routes->post('change-password', 'Auth::changePassword');
        // the Terms of Service and Privacy Policy versions the client has still to accept; see App\Controllers\Legal
        $routes->post('legal/pending', 'Legal::pending');
        $routes->post('legal/accept', 'Legal::accept');
        $routes->post('account', 'Accounts::showOwn');
        $routes->post('account/update', 'Accounts::saveOwn');
        $routes->post('business-types', 'Businesses::listActiveTypes');
        $routes->post('business/create', 'Businesses::create');
        $routes->post('business/edit', 'Businesses::edit');
        $routes->post('businesses', 'Businesses::listOwn');
        $routes->post('business/make-default', 'Businesses::makeDefault');
        $routes->post('business/open', 'Businesses::open'); // on otternova_mart or not: its owners only
        $routes->post('business/switch', 'Businesses::switchTo');
        // the messages customers send a business through its contact form on the mart, and the header's unread
        // badge: any of its active clients; replying from the platform is reserved; see App\Controllers\Messages
        $routes->post('messages', 'Messages::list');
        $routes->post('messages/unread-count', 'Messages::unreadCount');
        $routes->post('message', 'Messages::show');
        $routes->post('message/status', 'Messages::setStatus');
        $routes->post('message/comment', 'Messages::addComment');
        $routes->post('message/comment/delete', 'Messages::deleteComment');
        $routes->post('message/reply', 'Messages::reply');
        // the stores of a business: its active owners and managers only; see App\Controllers\Stores
        $routes->post('stores', 'Stores::list');
        $routes->post('store/create', 'Stores::create');
        $routes->post('store/edit', 'Stores::edit');
        // how the customers of a business can pay it, at every store or at one: its active owners and managers only;
        // see App\Controllers\PaymentMethods
        $routes->post('payment-methods', 'PaymentMethods::list');
        $routes->post('payment-method/create', 'PaymentMethods::create');
        $routes->post('payment-method/edit', 'PaymentMethods::edit');
        // how the customers of a business can get its products and what that costs, at every store or at one: its
        // active owners and managers only; see App\Controllers\ShippingMethods
        $routes->post('shipping-methods', 'ShippingMethods::list');
        $routes->post('shipping-method/create', 'ShippingMethods::create');
        $routes->post('shipping-method/edit', 'ShippingMethods::edit');
        // the discount codes a business gives its customers on the mart: its active owners and managers only;
        // see App\Controllers\Discounts
        $routes->post('discounts', 'Discounts::list');
        $routes->post('discount/create', 'Discounts::create');
        $routes->post('discount/edit', 'Discounts::edit');
        // the orders customers placed with a business: their statuses, payments and refunds; any of its active clients,
        // refunds its owners and managers only; see App\Controllers\SalesOrders
        $routes->post('orders', 'SalesOrders::list');
        $routes->post('order', 'SalesOrders::show');
        $routes->post('order/status', 'SalesOrders::saveStatus');
        $routes->post('order/payment/create', 'SalesOrders::createPayment');
        $routes->post('order/payment/settle', 'SalesOrders::settlePayment');
        $routes->post('order/reschedule/slots', 'SalesOrders::rescheduleSlots');
        $routes->post('order/reschedule', 'SalesOrders::reschedule');
        $routes->post('order/cancel', 'SalesOrders::cancel');
        $routes->get('order/payment-slip/(:segment)', 'SalesOrders::paymentSlip/$1');
        $routes->post('order/no-show', 'SalesOrders::noShow');
        $routes->post('order/refund-close', 'SalesOrders::closeRefund');
        $routes->post('order/receipt', 'SalesOrders::receipt');
        // orders taken at the counter of a store (walk-ins, call-ins): any of the business's active clients; see
        // App\Controllers\CounterOrders
        $routes->post('counter/stores', 'CounterOrders::stores');
        $routes->post('counter/catalog', 'CounterOrders::catalog');
        $routes->post('counter/slots', 'CounterOrders::slots');
        $routes->post('counter/sessions', 'CounterOrders::sessions');
        $routes->post('counter/quote', 'CounterOrders::quote');
        $routes->post('counter/order', 'CounterOrders::create');
        // the opening hours of a store: the active owners and managers of its business only; see App\Controllers\StoreHours
        $routes->post('store/hours', 'StoreHours::show');
        $routes->post('store/hours/save', 'StoreHours::save');
        $routes->post('store/hours-exception/save', 'StoreHours::saveException');
        $routes->post('store/hours-exception/delete', 'StoreHours::deleteException');
        // the resources of a business (rooms, equipment) and which of them its services need: its active owners and
        // managers only; see App\Controllers\Resources
        $routes->post('resource-types', 'Resources::types');
        $routes->post('resource-type/create', 'Resources::createType');
        $routes->post('resource-type/edit', 'Resources::editType');
        $routes->post('resources', 'Resources::list');
        $routes->post('resource/create', 'Resources::create');
        $routes->post('resource/edit', 'Resources::edit');
        $routes->post('artifact/resources', 'Resources::needs');
        $routes->post('artifact/resources/save', 'Resources::saveNeeds');
        // the sessions of a business's group services, their slots and what each slot holds: its active owners and
        // managers only; see App\Controllers\ServiceSessions
        $routes->post('sessions', 'ServiceSessions::list');
        $routes->post('session', 'ServiceSessions::show');
        $routes->post('session/options', 'ServiceSessions::options');
        $routes->post('session/create', 'ServiceSessions::create');
        $routes->post('session/edit', 'ServiceSessions::edit');
        // what a business has booked on a day, and one session of it: any of its active clients; see App\Controllers\Calendar
        $routes->post('calendar', 'Calendar::day');
        $routes->post('calendar/session', 'Calendar::session');
        // what the orders of a business placed in a period come to: its active owners only; see App\Controllers\Revenue
        $routes->post('revenue', 'Revenue::report');
        // what the people of a business earn in a pay period, salary and commission: its active owners only; see App\Controllers\Payroll
        $routes->post('payroll', 'Payroll::report');
        $routes->post('payroll/person', 'Payroll::person');
        // where the people of a business work and what they are paid: its active owners and managers only; see App\Controllers\Staff
        $routes->post('staff', 'Staff::list');
        $routes->post('staff/show', 'Staff::show');
        $routes->post('staff/stores/save', 'Staff::saveStores');
        $routes->post('staff/services/save', 'Staff::saveServices');
        $routes->post('staff/salary/add', 'Staff::addSalary');
        $routes->post('staff/salary/edit', 'Staff::editSalary');
        $routes->post('staff/hours', 'Staff::hours');
        $routes->post('staff/hours/save', 'Staff::saveHours');
        $routes->post('staff/hours-exception/save', 'Staff::saveHoursException');
        $routes->post('staff/hours-exception/delete', 'Staff::deleteHoursException');
        // what a business records about its people: the attributes it defines, their values, the languages its people
        // speak and the customers they do not serve; its active owners and managers only; see App\Controllers\StaffProfiles
        $routes->post('staff-attributes', 'StaffProfiles::attributes');
        $routes->post('staff-attribute/create', 'StaffProfiles::createAttribute');
        $routes->post('staff-attribute/edit', 'StaffProfiles::editAttribute');
        $routes->post('staff-attribute/delete', 'StaffProfiles::deleteAttribute');
        $routes->post('staff/profile', 'StaffProfiles::profile');
        $routes->post('staff/languages/save', 'StaffProfiles::saveLanguages');
        $routes->post('staff/attributes/save', 'StaffProfiles::saveAttributes');
        $routes->post('staff/restrictions/save', 'StaffProfiles::saveRestrictions');
        $routes->post('staff/visibility/save', 'StaffProfiles::saveVisibility');
        $routes->post('commission-policy', 'Staff::policy');
        $routes->post('commission-policy/save', 'Staff::savePolicy');
        // how the order numbers of a business look: its active owners and managers only; see App\Controllers\OrderNumbers
        $routes->post('order-numbers', 'OrderNumbers::show');
        $routes->post('order-numbers/save', 'OrderNumbers::save');
        // the traffic of a business's marketplace pages, from the tracker: its active owners and managers only;
        // see App\Controllers\Traffic
        $routes->post('traffic/overview', 'Traffic::clientOverview');
        $routes->post('traffic/breakdown', 'Traffic::clientBreakdown');
        $routes->post('traffic/visits', 'Traffic::clientVisits');
        // how a business's web pages look: its active owners and managers only; see App\Controllers\Websites
        $routes->post('website', 'Websites::show');
        $routes->post('website/save', 'Websites::save');
        $routes->post('website/logo-upload', 'Websites::logoUpload');
        $routes->post('website/logo-delete', 'Websites::logoDelete');
        // the social media accounts of a business: its active owners and managers only; see App\Controllers\SocialLinks
        $routes->post('social-links', 'SocialLinks::show');
        $routes->post('social-links/save', 'SocialLinks::save');
        // the tags of a business, for searching businesses: its active owners and managers only; see App\Controllers\Tags
        $routes->post('tags', 'Tags::show');
        $routes->post('tags/save', 'Tags::save');
        $routes->post('tags/suggest', 'Tags::suggest');
        // the pages a business designs itself, their sections and images: its active owners and managers only;
        // see App\Controllers\Webpages
        $routes->post('webpages', 'Webpages::list');
        $routes->post('webpage', 'Webpages::show');
        $routes->post('webpage/create', 'Webpages::create');
        $routes->post('webpage/edit', 'Webpages::edit');
        $routes->post('webpage/delete', 'Webpages::delete');
        $routes->post('webpage/preview', 'Webpages::preview');
        $routes->post('webpage/reorder', 'Webpages::reorder');
        $routes->post('webpage/section/save', 'Webpages::saveSection');
        $routes->post('webpage/section/delete', 'Webpages::deleteSection');
        // rewrites every text of the page with the AI, within the plan's allowance
        $routes->post('webpage/refine-texts', 'Webpages::refineTexts');
        $routes->post('webpage/image-upload', 'Webpages::uploadImage');
        $routes->post('webpage/image-delete', 'Webpages::deleteImage');
        // the people of a business and their accounts: its active owners only; see App\Controllers\Employees
        $routes->post('employees', 'Employees::list');
        $routes->post('employee', 'Employees::show');
        $routes->post('employee/edit', 'Employees::edit');
        $routes->post('employee/terminate', 'Employees::terminate');
        $routes->post('employee/rehire', 'Employees::rehire');
        $routes->post('employee/find', 'Employees::find');
        $routes->post('employee/add', 'Employees::add');
        $routes->post('employee/invite', 'Employees::invite');
        // the public profiles of the people of a business: anyone active in it; see App\Controllers\Colleagues
        $routes->post('colleagues', 'Colleagues::list');
        $routes->post('colleague', 'Colleagues::show');
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
        // rewrites the description in other languages with the AI, within the plan's monthly allowance
        $routes->post('artifact/refine-description', 'Artifacts::refineDescription');
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
        // the customers of a business, read only, and the notes its people write about them: any of its active clients
        // (a note deleted by its writer or an owner or manager); see App\Controllers\Customers
        $routes->post('customers', 'Customers::list');
        $routes->post('customer', 'Customers::show');
        $routes->post('customer-note/create', 'Customers::createNote');
        $routes->post('customer-note/delete', 'Customers::deleteNote');
    });
});
