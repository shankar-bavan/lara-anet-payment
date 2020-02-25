# Laravel Cashier-Authorize

## Introduction

This package provides a compatible, expressive and fluent interface to [Authorize.net's](https://authorize.net) native subscription billing services. It handles (mostly) all of the boilerplate subscription billing code you would otherwise have to manage yourself. In addition to basic subscription management, this package can also handle cancellation grace periods.

## Basic Setup

Please read the following for the basic setup.

## Composer Setup 
`composer require https://github.com/shankar-bavan/lara-anet-payment`

#### .env
ADN_ENV=
ADN_LOG=authorize.log

ADN_API_LOGIN_ID=
ADN_TRANSACTION_KEY=
ADN_SECRET_KEY=Simon

ADN_ENV should be one of: sandbox, production

#### Migrations

You will need to make migrations that include the following:

```php
Schema::table('users', function ($table) {
    $table->string('authorize_id')->nullable();
    $table->string('authorize_payment_id')->nullable();
    $table->string('card_brand')->nullable();
    $table->string('card_last_four')->nullable();
});
```

```php
Schema::create('subscriptions', function ($table) {
    $table->increments('id');
    $table->integer('user_id');
    $table->string('name');
    $table->string('authorize_id');
    $table->string('authorize_payment_id');
    $table->text('metadata');
    $table->string('authorize_plan');
    $table->integer('quantity');
    $table->timestamp('trial_ends_at')->nullable();
    $table->timestamp('ends_at')->nullable();
    $table->timestamps();
});
```

#### Publish

You will need to publish the assets of this package.

`php artisan vendor:publish --provider="Laravel\CashierAuthorizeNet\CashierServiceProvider"`

## Configuration

The following files need to be updated, as demonstrated below. `config/services.php` needs to be updated to match your User account's class name.

#### config/cashier.php
```php
'monthly-10-1' => [
    'name' => 'main',
    'interval' => [
        'length' => 1, // number of instances for billing
        'unit' => 'months' //months, days, years
    ],
    'total_occurances' => 9999, // 9999 means without end date
    'trial_occurances' => 0,
    'amount' => 9.99,
    'trial_amount' => 0,
    'trial_days' => 0,
    'trial_delay' => 0, // days you wish to delay the start of billing
]
```

#### config/services.php
```php
'authorize' => [
    'model'  => App\User::class,
],
```

You can also set this value with the following `.env` variable: ADN_MODEL

## Basic Usage

There are several differences with Authorize.net vs. other services (e.g. Stripe); firstly, Authorize.net is slighly slower and a more restricted subscription provider. You cannot perform certain things (i.e. swap subscriptions, change the quantity of a subscription) without first canceling and creating a new subscription.


#### `Laravel\CashierAuthorize\Billable` interface
```php
interface Billable
{
	/**
	 * Charge a customer once.
	 *
	 * @param  int  $amount
	 * @param  array  $options
	 * @param  string $transactionType
	 *
	 * @return Charge
	 * @throws Error\Card
	 */
	public function charge(float $amount, array $options = [], string $transactionType = "authCaptureTransaction");

	/**
	 * Determines if the customer currently has a card on file.
	 *
	 * @return bool
	 */
	public function hasCardOnFile();

	/**
	 * Invoice the customer for the given amount.
	 *
	 * @param  string  $description
	 * @param  int  $amount
	 * @param  array  $options
	 * @return bool
	 *
	 * @throws \Stripe\Error\Card
	 */
	public function invoiceFor($description, $amount, array $options = []);

	/**
	 * Begin creating a new subscription.
	 *
	 * @param  string  $subscription
	 * @param  string  $plan
	 * @return \Laravel\CashierAuthorizeNet\SubscriptionBuilder
	 */
	public function newSubscription($subscription, $plan);

	/**
	 * Determine if the user is on trial.
	 *
	 * @param  string  $subscription
	 * @param  string|null  $plan
	 * @return bool
	 */
	public function onTrial(string $subscription = 'default', $plan = null);

	/**
	 * Determine if the user is on a "generic" trial at the user level.
	 *
	 * @return bool
	 */
	public function onGenericTrial();

	/**
	 * Determine if the user has a given subscription.
	 *
	 * @param  string  $subscription
	 * @param  string|null  $plan
	 * @return bool
	 */
	public function subscribed($subscription = 'default', $plan = null);

	/**
	 * Get a subscription instance by name.
	 *
	 * @param  string  $subscription
	 * @return \Laravel\Cashier\Subscription|null
	 */
	public function subscription($subscription = 'default');
	
	/**
	 * Get all of the subscriptions for the user.
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function subscriptions();
	
	/**
	 * Get the entity's upcoming invoice.
	 *
	 * @return \Laravel\Cashier\Invoice|null
	 */
	public function upcomingInvoice();
	
	/**
	 * Find an invoice by ID.
	 *
	 * @param  string  $id
	 * @return \Laravel\Cashier\Invoice|null
	 */
	public function findInvoice($invoiceId);
	
	/**
	 * Find an invoice or throw a 404 error.
	 *
	 * @param  string  $id
	 * @return \Laravel\Cashier\Invoice
	 */
	public function findInvoiceOrFail($id);
	
	/**
	 * Create an invoice download Response.
	 *
	 * @param  string  $id
	 * @param  array   $data
	 * @param  string  $storagePath
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function downloadInvoice($id, array $data, $storagePath = null);
	
	/**
	 * Get a subcription from Authorize
	 *
	 * @param  string $subscriptionId
	 * @return array
	 */
	public function getSubscriptionFromAuthorize($subscriptionId);

	/**
	 * Get a collection of the entity's invoices.
	 *
	 * @param  string  $plan
	 * @return \Illuminate\Support\Collection
	 */
	public function invoices($plan);
	
	/**
	 * Update customer's credit card.
	 *
	 * @param  string  $token
	 * @return void
	 */
	public function updateCard($card);
	
	/**
	 * Determine if the user is actively subscribed to one of the given plans.
	 *
	 * @param  array|string  $plans
	 * @param  string  $subscription
	 * @return bool
	 */
	public function subscribedToPlan($plans, $subscription = 'default');
	
	/**
	 * Determine if the entity is on the given plan.
	 *
	 * @param  string  $plan
	 * @return bool
	 */
	public function onPlan($plan);
	
	/**
	 * Determine if the entity has a Stripe customer ID.
	 *
	 * @return bool
	 */
	public function hasAuthorizeId() { return !!$this->authorize_id; }

	/**
	 * Create a Stripe customer for the given user.
	 *
	 * @param  array $creditCardDetails
	 * @return StripeCustomer
	 */
	public function createAsAuthorizeCustomer(Array $location, array $cardDetails);
	
	/**
	 * Delete an Authorize.net Profile
	 *
	 * @return
	 */
	public function deleteAuthorizeProfile();
	
	/**
	 * Get the Stripe supported currency used by the entity.
	 *
	 * @return string
	 */
	public function preferredCurrency();

	/**
	 * Get the tax percentage to apply to the subscription.
	 *
	 * @return int
	 */
	public function taxPercentage();

	/**
	 * Detect the brand cause Authorize wont give that to us
	 *
	 * @param  string $card Card number
	 * @return string
	 */
	public function cardBrandDetector($card);
}
```


#### Transaction Details

Enabling the API
To enable the Transaction Details API:
1) Log on to the Merchant Interface at https://account.authorize.net .
2) Select Settings under Account in the main menu on the left.
3) Click the Transaction Details API link in the Security Settings section. The Transaction Details API screen opens.
4) If you have not already enabled the Transaction Details API, enter the answer to your Secret Question, then click Enable Transaction Details API.
5) When you have successfully enabled the Transaction Details API, the Settings page displays.

### CRON job

You need to enable the following CRON job to check the status of your user's subscriptions. This can run as often as you like, and will check to confirm that your user's subscription is active. If the status is changed to cancelled or suspended - the system will disable their subscription locally. Your team will need to resolve the payment issue with Authorize.net and then move forward.

```php
protected $commands = [
    \Laravel\CashierAuthorizeNet\Console\SubscriptionUpdates::class,
];
```

```php
$schedule->command('subscription:update')->hourly();
```

#### Limitations
Another limitation is time related. Due to the fact that Authorize.net uses a SOAP structure for its APIs, there needs to be a time delay between adding a customer with a credit card to their system and then adding a subscription to that user. This could be done easily in your app by having the user enter their credit card information, and then allowing a confirmation of the subscription they wish to purchase as another action. This time can be as little as a second, but all tests thus far with immediate adding of subscriptions fails to work, so please be mindful of this limitation when designing your app.

## License

Laravel Cashier-Authorize is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)
# New Document
