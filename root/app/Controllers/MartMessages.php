<?php

namespace App\Controllers;

use App\Libraries\ApiResponse;
use App\Libraries\BusinessMessageGuard;
use App\Libraries\ContactFormGuard;
use App\Models\BusinessMasterModel;
use App\Models\BusinessMessageModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;
use Config\BusinessMessageForm;
use Throwable;

/**
 * The contact form of a business on otternova_mart: no sign-in needed. A customer sends the business a message with
 * their name, an email address or a telephone number (at least one, as the business answers off the platform), and
 * the message; it goes to business_message (App\Models\BusinessMessageModel), read in the Client app (Messages).
 * The spam and bot checks are App\Libraries\BusinessMessageGuard's. otternova_mart calls these from its server, so it
 * passes on the customer's address in X-Forwarded-For; its server must be in PROXY_IPS (Config\App) for the counts
 * per IP address to be the customer's.
 * Only a business open on the mart takes messages: a closed one answers 409 with Mart.shopClosed, as for orders.
 */
class MartMessages extends BaseController
{
    use ContactInputTrait;

    /**
     * GET /mart/message-token?business_slug=
     * A single-use token for one message to the business. The page gets it when it shows the form and sends it back
     * as form_token; it works from Config\BusinessMessageForm::$tokenMinAge seconds after this call until $tokenMaxAge,
     * for this business only.
     * { form_token, expires_in }
     * @return ResponseInterface
     */
    public function token(): ResponseInterface
    {
        $slug = $this->request->getGet('business_slug');

        return $this->forOpenBusiness('v1/mart/message-token', is_string($slug) ? $slug : null, function (array $business): ResponseInterface {
            return ApiResponse::success([
                'form_token' => service('businessMessageGuard')->forBusiness((int) $business['id'])->issueToken(),
                'expires_in' => config(BusinessMessageForm::class)->tokenMaxAge,
            ]);
        });
    }

    /**
     * POST /mart/message { business_slug, name, email?, phone?, message, locale, form_token, website? }
     * Stores a message to the business. website is the honeypot: the form hides it, so it stays empty.
     * A honeypot hit, or the same message sent again, gets the same 201 as a stored message, so a bot learns nothing.
     * 400 for a form token that is not this business's, used, too new or stale (Messages.formExpired: reload); 422 with
     * errors by field; 429 for too many messages from the sender (Messages.tooManyMessages) or to the business today
     * (Messages.businessBusy).
     * @return ResponseInterface
     */
    public function submit(): ResponseInterface
    {
        try {
            $input = $this->request->getJSON(true);
        } catch (Throwable) {
            $input = null;
        }
        if (! is_array($input)) {
            return ApiResponse::error(lang('Messages.invalid'), 400);
        }
        $slug = $input['business_slug'] ?? null;

        return $this->forOpenBusiness('v1/mart/message', is_string($slug) ? $slug : null, function (array $business) use ($input): ResponseInterface {
            return $this->receive((int) $business['id'], $input);
        });
    }

    /**
     * The checks and the storing of submit(), for an open business
     * @param int   $businessId
     * @param array $input
     * @return ResponseInterface
     * @throws Throwable When the message cannot be stored
     */
    private function receive(int $businessId, array $input): ResponseInterface
    {
        $config = config(BusinessMessageForm::class);
        $guard  = service('businessMessageGuard')->forBusiness($businessId);

        $honeypot = $input[$config->honeypotField] ?? '';
        if (! is_string($honeypot) || trim($honeypot) !== '') {
            return $this->received();
        }

        $token = $input['form_token'] ?? null;
        if ($guard->checkToken($token) !== ContactFormGuard::TOKEN_OK) {
            return ApiResponse::error(lang('Messages.formExpired'), 400);
        }

        [$row, $errors] = $this->validated($input, $config);
        if ($errors !== []) {
            return ApiResponse::error(lang('Messages.invalid'), 422, $errors);
        }

        $ip      = $this->request->getIPAddress();
        $blocked = $guard->isSenderBlocked($row['email_address'], $row['phone_number'], $ip);
        if ($blocked !== null) {
            return ApiResponse::error(lang($blocked === BusinessMessageGuard::BLOCKED_BUSINESS ? 'Messages.businessBusy' : 'Messages.tooManyMessages'), 429);
        }
        $guard->recordSender($row['email_address'], $row['phone_number'], $ip);
        $guard->consumeToken($token);

        $sender = BusinessMessageGuard::senderKey($row['email_address'], $row['phone_number']);
        if ($guard->isDuplicate($sender, $row['message_text'])) {
            return $this->received();
        }

        $reason = $guard->spamReason($row['sender_name'], '', $row['message_text']);
        $agent  = trim((string) $this->request->getHeaderLine('User-Agent'));
        model(BusinessMessageModel::class)->receive($businessId, $row + [
            'message_status' => $reason === null ? BusinessMessageModel::STATUS_UNREAD : BusinessMessageModel::STATUS_SPAM,
            'spam_reason'    => $reason,
            'ip_address'     => $ip,
            'user_agent'     => $agent === '' ? null : mb_substr($agent, 0, 255, 'UTF-8'),
        ]);
        $guard->remember($sender, $row['message_text']);

        return $this->received();
    }

    /**
     * The fields of the form, trimmed and checked: an email address or a telephone number, or both
     * @param array               $input
     * @param BusinessMessageForm $config
     * @return array{0: array<string, string|null>, 1: array<string, string>} The row to store, and field => error
     */
    private function validated(array $input, BusinessMessageForm $config): array
    {
        [$sender, $errors] = $this->senderFields($input, $config, 'Messages', false);
        if ($sender['email'] === '' && $sender['phone'] === '') {
            $errors['contact'] = lang('Messages.contactRequired');
        }
        [$message, $error] = $this->messageField($input, $config, 'Messages');
        if ($error !== null) {
            $errors['message'] = $error;
        }
        $locale = is_string($input['locale'] ?? null) ? $input['locale'] : '';
        if (! in_array($locale, config(App::class)->supportedLocales, true)) {
            $errors['locale'] = lang('Messages.localeInvalid');
        }

        return [[
            'sender_name'   => $sender['name'],
            'email_address' => $sender['email'] === '' ? null : $sender['email'],
            'phone_number'  => $sender['phone'] === '' ? null : $sender['phone'],
            'message_text'  => $message,
            'locale'        => $locale,
        ], $errors];
    }

    /**
     * Finds the business of the slug, then answers with $answer when it is open on the mart; a failure is logged and
     * answered 500
     * @param string                            $endpoint For the error log
     * @param string|null                       $slug
     * @param callable(array): ResponseInterface $answer   Given business_master's id and is_business_open
     * @return ResponseInterface
     */
    private function forOpenBusiness(string $endpoint, ?string $slug, callable $answer): ResponseInterface
    {
        if ($slug === null || trim($slug) === '') {
            return ApiResponse::error(lang('Mart.slugRequired'), 400);
        }
        try {
            $businessId = service('martCache')->businessIdOf(trim($slug));
            $business   = $businessId === null ? null
                : model(BusinessMasterModel::class)->select('id, is_business_open')->find($businessId);
            if ($business === null) {
                return ApiResponse::error(lang('Mart.businessNotFound'), 404);
            }
            if ($business['is_business_open'] !== BusinessMasterModel::OPEN) {
                return ApiResponse::error(lang('Mart.shopClosed'), 409);
            }

            return $answer($business);
        } catch (Throwable $e) {
            log_message('error', $endpoint . ': {message}', ['message' => $e->getMessage()]);

            return ApiResponse::error(lang('Messages.saveFailed'), 500);
        }
    }

    /**
     * @return ResponseInterface
     */
    private function received(): ResponseInterface
    {
        return ApiResponse::success(null, 201, lang('Messages.received'));
    }
}
