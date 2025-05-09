<?php

namespace LemonSqueezy\Laravel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LemonSqueezy\Laravel\Events\LicenseKeyCreated;
use LemonSqueezy\Laravel\Events\LicenseKeyUpdated;
use LemonSqueezy\Laravel\Events\OrderCreated;
use LemonSqueezy\Laravel\Events\OrderRefunded;
use LemonSqueezy\Laravel\Events\SubscriptionCancelled;
use LemonSqueezy\Laravel\Events\SubscriptionCreated;
use LemonSqueezy\Laravel\Events\SubscriptionExpired;
use LemonSqueezy\Laravel\Events\SubscriptionPaused;
use LemonSqueezy\Laravel\Events\SubscriptionPaymentFailed;
use LemonSqueezy\Laravel\Events\SubscriptionPaymentRecovered;
use LemonSqueezy\Laravel\Events\SubscriptionPaymentSuccess;
use LemonSqueezy\Laravel\Events\SubscriptionResumed;
use LemonSqueezy\Laravel\Events\SubscriptionUnpaused;
use LemonSqueezy\Laravel\Events\SubscriptionUpdated;
use LemonSqueezy\Laravel\Events\WebhookHandled;
use LemonSqueezy\Laravel\Events\WebhookReceived;
use LemonSqueezy\Laravel\Exceptions\InvalidCustomPayload;
use LemonSqueezy\Laravel\Exceptions\LicenseKeyNotFound;
use LemonSqueezy\Laravel\Exceptions\MalformedDataError;
use LemonSqueezy\Laravel\Http\Middleware\VerifyWebhookSignature;
use LemonSqueezy\Laravel\Http\Throwable\BadRequest;
use LemonSqueezy\Laravel\Http\Throwable\NotFound;
use LemonSqueezy\Laravel\LemonSqueezy;
use LemonSqueezy\Laravel\LicenseKey;
use LemonSqueezy\Laravel\Order;
use LemonSqueezy\Laravel\Subscription;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal Not supported by any backwards compatibility promise. Please use events to react to webhooks.
 */
final class WebhookController extends Controller
{
    public function __construct()
    {
        if (config('lemon-squeezy.signing_secret')) {
            $this->middleware(VerifyWebhookSignature::class);
        }
    }

    /**
     * Handle a Lemon Squeezy webhook call.
     */
    public function __invoke(Request $request): Response
    {
        $payload = $request->all();

        if (! isset($payload['meta']['event_name'])) {
            return new Response('Webhook received but no event name was found.');
        }

        $method = 'handle' . Str::studly($payload['meta']['event_name']);

        WebhookReceived::dispatch($payload);

        if (method_exists($this, $method)) {
            try {
                $this->{$method}($payload);
            } catch (BadRequest $e) {
                return new Response($e->getMessage(), 400);
            } catch (NotFound $e) {
                return new Response($e->getMessage(), 404);
            } catch (\Exception $e) {
                return new Response(sprintf('Internal server error: %s', $e->getMessage()), 500);
            }

            WebhookHandled::dispatch($payload);

            return new Response('Webhook was handled.');
        }

        return new Response('Webhook received but no handler found.');
    }

    public function handleOrderCreated(array $payload): void
    {
        $billable = $this->resolveBillable($payload);

        // Todo v2: Remove this check
        if (Schema::hasTable((new LemonSqueezy::$orderModel())->getTable())) {
            $attributes = $payload['data']['attributes'];

            $order = $billable->orders()->create([
                'lemon_squeezy_id' => $payload['data']['id'],
                'customer_id' => $attributes['customer_id'],
                'product_id' => (string) $attributes['first_order_item']['product_id'],
                'variant_id' => (string) $attributes['first_order_item']['variant_id'],
                'identifier' => $attributes['identifier'],
                'order_number' => $attributes['order_number'],
                'currency' => $attributes['currency'],
                'subtotal' => $attributes['subtotal'],
                'discount_total' => $attributes['discount_total'],
                'tax' => $attributes['tax'],
                'total' => $attributes['total'],
                'tax_name' => $attributes['tax_name'],
                'status' => $attributes['status'],
                'receipt_url' => $attributes['urls']['receipt'] ?? null,
                'refunded' => $attributes['refunded'],
                'refunded_at' => $attributes['refunded_at'] ? Carbon::make($attributes['refunded_at']) : null,
                'ordered_at' => Carbon::make($attributes['created_at']),
            ]);
        } else {
            $order = null;
        }

        OrderCreated::dispatch($billable, $order, $payload);
    }

    public function handleOrderRefunded(array $payload): void
    {
        $billable = $this->resolveBillable($payload);

        // Todo v2: Remove this check
        if (Schema::hasTable((new LemonSqueezy::$orderModel())->getTable())) {
            if (! $order = $this->findOrder($payload['data']['id'])) {
                return;
            }

            $order = $order->sync($payload['data']['attributes']);
        } else {
            $order = null;
        }

        OrderRefunded::dispatch($billable, $order, $payload);
    }

    public function handleSubscriptionCreated(array $payload): void
    {
        $custom = $payload['meta']['custom_data'] ?? null;
        $attributes = $payload['data']['attributes'];

        $billable = $this->resolveBillable($payload);

        $subscription = $billable->subscriptions()->create([
            'type' => $custom['subscription_type'] ?? Subscription::DEFAULT_TYPE,
            'lemon_squeezy_id' => $payload['data']['id'],
            'status' => $attributes['status'],
            'product_id' => (string) $attributes['product_id'],
            'variant_id' => (string) $attributes['variant_id'],
            'card_brand' => $attributes['card_brand'] ?? null,
            'card_last_four' => $attributes['card_last_four'] ?? null,
            'trial_ends_at' => $attributes['trial_ends_at'] ? Carbon::make($attributes['trial_ends_at']) : null,
            'renews_at' => $attributes['renews_at'] ? Carbon::make($attributes['renews_at']) : null,
            'ends_at' => $attributes['ends_at'] ? Carbon::make($attributes['ends_at']) : null,
        ]);

        // Terminate the billable's generic trial at the model level if it exists...
        if (! is_null($billable->customer->trial_ends_at)) {
            $billable->customer->update(['trial_ends_at' => null]);
        }

        // Set the billable's lemon squeezy id if it was on generic trial at the model level
        if (is_null($billable->customer->lemon_squeezy_id)) {
            $billable->customer->update(['lemon_squeezy_id' => $attributes['customer_id']]);
        }

        SubscriptionCreated::dispatch($billable, $subscription, $payload);
    }

    private function handleSubscriptionUpdated(array $payload): void
    {
        if (! $subscription = $this->findSubscription($payload['data']['id'])) {
            return;
        }

        $subscription = $subscription->sync($payload['data']['attributes']);

        if ($subscription->billable) {
            SubscriptionUpdated::dispatch($subscription->billable, $subscription, $payload);
        }
    }

    private function handleSubscriptionCancelled(array $payload): void
    {
        if (! $subscription = $this->findSubscription($payload['data']['id'])) {
            return;
        }

        $subscription = $subscription->sync($payload['data']['attributes']);

        if ($subscription->billable) {
            SubscriptionCancelled::dispatch($subscription->billable, $subscription, $payload);
        }
    }

    private function handleSubscriptionResumed(array $payload): void
    {
        if (! $subscription = $this->findSubscription($payload['data']['id'])) {
            return;
        }

        $subscription = $subscription->sync($payload['data']['attributes']);

        SubscriptionResumed::dispatch($subscription->billable, $subscription, $payload);
    }

    private function handleSubscriptionExpired(array $payload): void
    {
        if (! $subscription = $this->findSubscription($payload['data']['id'])) {
            return;
        }

        $subscription = $subscription->sync($payload['data']['attributes']);

        if ($subscription->billable) {
            SubscriptionExpired::dispatch($subscription->billable, $subscription, $payload);
        }
    }

    private function handleSubscriptionPaused(array $payload): void
    {
        if (! $subscription = $this->findSubscription($payload['data']['id'])) {
            return;
        }

        $subscription = $subscription->sync($payload['data']['attributes']);

        SubscriptionPaused::dispatch($subscription->billable, $subscription, $payload);
    }

    private function handleSubscriptionUnpaused(array $payload): void
    {
        if (! $subscription = $this->findSubscription($payload['data']['id'])) {
            return;
        }

        $subscription = $subscription->sync($payload['data']['attributes']);

        SubscriptionUnpaused::dispatch($subscription->billable, $subscription, $payload);
    }

    private function handleSubscriptionPaymentSuccess(array $payload): void
    {
        if (
            ($subscription = $this->findSubscription($payload['data']['attributes']['subscription_id'])) &&
            $subscription->billable
        ) {
            SubscriptionPaymentSuccess::dispatch($subscription->billable, $subscription, $payload);
        }
    }

    private function handleSubscriptionPaymentFailed(array $payload): void
    {
        if (
            ($subscription = $this->findSubscription($payload['data']['attributes']['subscription_id'])) &&
            $subscription->billable
        ) {
            SubscriptionPaymentFailed::dispatch($subscription->billable, $subscription, $payload);
        }
    }

    private function handleSubscriptionPaymentRecovered(array $payload): void
    {
        if (
            ($subscription = $this->findSubscription($payload['data']['attributes']['subscription_id'])) &&
            $subscription->billable
        ) {
            SubscriptionPaymentRecovered::dispatch($subscription->billable, $subscription, $payload);
        }
    }

    /**
     * @throws MalformedDataError
     * @throws InvalidCustomPayload
     */
    private function handleLicenseKeyCreated(array $payload): void
    {
        $licenseKey = LicenseKey::fromPayload($payload['data']);
        $billable = $this->resolveBillable($payload);

        LicenseKeyCreated::dispatch($billable, $licenseKey);
    }

    /**
     * @throws LicenseKeyNotFound
     */
    private function handleLicenseKeyUpdated(array $payload): void
    {
        $key = $payload['data']['attributes']['key'] ?? '';
        $licenseKey = LicenseKey::withKey($key)->first();

        if ($licenseKey === null) {
            throw LicenseKeyNotFound::withKey($key);
        }

        $licenseKey = $licenseKey->sync($payload['data']['attributes']);

        LicenseKeyUpdated::dispatch($licenseKey->billable(), $licenseKey);
    }

    /**
     * @return \LemonSqueezy\Laravel\Billable
     *
     * @throws InvalidCustomPayload
     */
    private function resolveBillable(array $payload)
    {
        $custom = $payload['meta']['custom_data'] ?? null;

        if (! isset($custom) || ! is_array($custom) || ! isset($custom['billable_id'], $custom['billable_type'])) {
            throw new InvalidCustomPayload();
        }

        return $this->findOrCreateCustomer(
            $custom['billable_id'],
            (string) $custom['billable_type'],
            (string) $payload['data']['attributes']['customer_id'],
        );
    }

    /**
     * @return \LemonSqueezy\Laravel\Billable
     */
    private function findOrCreateCustomer(int|string $billableId, string $billableType, string $customerId)
    {
        return LemonSqueezy::$customerModel::firstOrCreate([
            'billable_id' => $billableId,
            'billable_type' => $billableType,
        ], [
            'lemon_squeezy_id' => $customerId,
        ])->billable;
    }

    private function findSubscription(string $subscriptionId): ?Subscription
    {
        return LemonSqueezy::$subscriptionModel::firstWhere('lemon_squeezy_id', $subscriptionId);
    }

    private function findOrder(string $orderId): ?Order
    {
        return LemonSqueezy::$orderModel::firstWhere('lemon_squeezy_id', $orderId);
    }
}
