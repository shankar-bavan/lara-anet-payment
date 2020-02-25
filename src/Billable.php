<?php
namespace Laravel\Cashier\AuthorizeNet;

use Exception;
use Carbon\Carbon;
use InvalidArgumentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Laravel\CashierAuthorizeNet\Requester;

use CardDetect\Detector as CardType;

use net\authorize\api\contract\v1\CreditCardType,
	net\authorize\api\contract\v1\PaymentType,
	net\authorize\api\contract\v1\OrderType,
	net\authorize\api\contract\v1\PaymentProfileType,
	net\authorize\api\contract\v1\CustomerProfileType,
	net\authorize\api\contract\v1\CustomerAddressType,
	net\authorize\api\contract\v1\TransactionRequestType,
	net\authorize\api\contract\v1\CustomerProfilePaymentType,
	net\authorize\api\contract\v1\CustomerPaymentProfileType,
	net\authorize\api\contract\v1\CustomerPaymentProfileExType;

use net\authorize\api\contract\v1\CreateCustomerProfileRequest,
	net\authorize\api\contract\v1\DeleteCustomerProfileRequest,
	net\authorize\api\contract\v1\UpdateCustomerPaymentProfileRequest,
	net\authorize\api\contract\v1\CreateTransactionRequest,
	net\authorize\api\contract\v1\GetTransactionDetailsRequest,
	net\authorize\api\contract\v1\ARBGetSubscriptionRequest;

use net\authorize\api\controller\CreateCustomerProfileController,
	net\authorize\api\controller\DeleteCustomerProfileController,
	net\authorize\api\controller\UpdateCustomerPaymentProfileController,
	net\authorize\api\controller\CreateTransactionController,
	net\authorize\api\controller\GetTransactionDetailsController,
	net\authorize\api\controller\GetCustomerProfileController,
	net\authorize\api\controller\ARBGetSubscriptionController;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait Billable
{
	private $configName = '';
	
	/**
	 * Make a "one off" charge on the customer for the given amount.
	 *
	 * @param  int  $amount
	 * @param  array  $options
	 * @return \Stripe\Charge
	 *
	 * @throws \Stripe\Error\Card
	 */
	public function charge($amount, array $options = []) 
	{	
		$options		= array_merge([ 'currency' => $this->preferredCurrency() ], 
										$options);

		$request		= (new Requester())
							->prepare(new CreateTransactionRequest);
		
		$transaction	= new TransactionRequestType;
		$profile		= new PaymentProfileType;
		
		$order_type		= new OrderType;
		
		$profileToCharge->setCustomerProfileId($this->authorize_id);
		$profile->setPaymentProfileId($this->authorize_payment_id);
		
		$profileToCharge->setPaymentProfile($profile);
		$order_type->setDescription(array_get($options, 'description'));
		
		$transaction
			->setTransactionType("authCaptureTransaction")
			->setAmount(round(floatval($amount) * floatval(sprintf('1.%d', $this->taxPercentage())), 2));
		
		$transaction
			->setCurrencyCode(array_get($options, 'currency'))
			->setOrder($order_type);
		
		$transaction->setProfile(new CustomerProfilePaymentType);
		$request->setTransactionRequest($transaction);
		
		$netResponse = (new CreateTransactionController($request))
			->executeWithApiResponse($requester->env);
		
		switch ($netResponse) {
		case null:
			throw new Exception("ERROR: NO RESPONSE", 1);
		
		default:
			$response = $netResponse
					->getTransactionResponse();
			
			$result = [
				'authCode' => $tresponse->getAuthCode(), 
				'transId' => $tresponse->getTransId()
			];
			
			switch ($response->getResponseCode()) {
			case "1":
				return $result;
			
			case "1":
				return false;
			
			case "4":
				throw new Exception("ERROR: HELD FOR REVIEW", 1);
			}
		}

		return false;
	}

	/**
	 * Determines if the customer currently has a card on file.
	 *
	 * @return bool
	 */
	public function hasCardOnFile() { return !!$this->card_brand; }

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
	public function invoiceFor($description, $amount, array $options = []) {
		$attributes = [
			'currency' => $this->preferredCurrency(),
			'description' => $description
		];
		
		if ((!! $this->authorize_id) === false) {
			throw new InvalidArgumentException('User is not a customer. See the createAsAuthorizeCustomer method.');
		}
		
		return $this->charge($amount, array_merge($attributes, $options));
	}

	/**
	 * Begin creating a new subscription.
	 *
	 * @param  string  $subscription
	 * @param  string  $plan
	 * @return \Laravel\CashierAuthorizeNet\SubscriptionBuilder
	 */
	public function newSubscription($subscription, $plan) {
		return new SubscriptionBuilder($this, $subscription, $plan);
	}

	/**
	 * Determine if the user is on trial.
	 *
	 * @param  string  $subscription
	 * @param  string|null  $plan
	 * @return bool
	 */
	public function onTrial(string $subscription = 'default', $plan = null) {
		switch (func_num_args()) {
		case 0:
			return $this->onGenericTrial();
		
		default:
			$subscription = $this->subscription($subscription);
			break;
		}

		return is_null($plan) ?
			$subscription->onTrial():
			$subscription->onTrial() && strcmp($subscription->authorize_plan, $plan) === 0;
	}

	/**
	 * Determine if the user is on a "generic" trial at the user level.
	 *
	 * @return bool
	 */
	public function onGenericTrial() {
		return $this->trial_ends_at && Carbon::now()
			->lt($this->trial_ends_at);
	}

	/**
	 * Determine if the user has a given subscription.
	 *
	 * @param  string  $subscription
	 * @param  string|null  $plan
	 * @return bool
	 */
	public function subscribed($subscription = 'default', $plan = null) {
		$subscription = $this->subscription($subscription);
		$result = true;

		switch ($subscription) {
		case null:
			$result = false;
			break;
			
		default:
			switch ($plan)
			{
			case null:
				$result = $subscription->valid();
				break;
			
			default:
				$result = $subscription->valid() && (strcmp(
					$subscription->authorize_plan, $plan) === 0);
				
				break;
			}
		}
		
		return $result;
	}

	/**
	 * Get a subscription instance by name.
	 *
	 * @param  string  $subscription
	 * @return \Laravel\Cashier\Subscription|null
	 */
	public function subscription($subscription = 'default') {
		$subscriptions = $this->subscriptions;

		$sorted = $subscriptions->sortByDesc(function ($value)
		{
			$timestamp = $value->created_at;
			return $timestamp->getTimestamp();
		});
		
		return $sorted->first(function ($key, $value) use ($subscription) {
			return $value->name === $subscription;
		});
	}

	/**
	 * Get all of the subscriptions for the user.
	 *
	 * @return \Illuminate\Database\Eloquent\Collection
	 */
	public function subscriptions() {
		return $this->hasMany(Subscription::class , 'user_id')
			->orderBy('created_at', 'desc');
	}

	/**
	 * Get the entity's upcoming invoice.
	 *
	 * @return \Laravel\Cashier\Invoice|null
	 */
	public function upcomingInvoice() {
		$subscriptions		= $this->subscriptions();
		$subscription		= $subscriptions->first();
		
		$startDate			= $subscription->created_at;
		$now				= Carbon::now();
		
		$authorizePlan		= $subscription->authorize_plan;
		$config				= Config::get('cashier-authorize');
		
		$date				= Carbon::createFromDate(
								$now->year,
								$now->month,
								$startDate->day);
		
		$billingDate		= $thisMonthsBillingDate->lte($now)?
								$date:
								$date->addMonths(1);
		
		$taxBasePercent 	= array_get($config, sprintf( '%s.amount', $authorizePlan ), 0.00);
		$taxRealPercent		= floatval(sprintf('0.%d', $this->taxPercentage()));
		
		$parameters = [
			'date' => $billingDate->timestamp,
			'subscription' => $subscription,
			'tax_percent' => $this->taxPercentage(),
			'tax' => floatval($taxBasePercent * $taxRealPercent)
		];
		
		return new Invoice($this, $parameters);
	}

	/**
	 * Find an invoice by ID.
	 *
	 * @param  string  $id
	 * @return \Laravel\Cashier\Invoice|null
	 */
	public function findInvoice($invoiceId) {
		$instance		= new Requester;
		
		$request 		= $instance->prepare(new GetTransactionDetailsRequest);
		$request->setTransId($invoiceId);
		
		$environment	= $instance->env;
		$controller		= new GetTransactionDetailsController($request);
		
		$authRspnse		= $controller->executeWithApiResponse($environment);
		$transaction	= $authRspnse->getTransaction();
		
		$parameters		= [
			'id'			=> $transaction->getTransId(), 
			'amount'		=> $transaction->getAuthAmount(),
			'status'		=> $transaction->getTransactionStatus(),
			'response'		=> $authRspnse
		];
		
		switch ($authRspnse) {
		case null:
			$message	= $authRspnse->getMessages();		
			$exception	= $message->getMessage();
			
			throw new Exception(sprintf(
				'Payment Processor: %d: %s', $exception->getCode(), $exception->getMessage()));
		
		default:
			$success = strcmp($authRspnse->getCode(), 'Ok') === 0;
			return $success? $parameters: [];
		}}
	
	/**
	 * Find an invoice or throw a 404 error.
	 *
	 * @param  string  $id
	 * @return \Laravel\Cashier\Invoice
	 */
	public function findInvoiceOrFail($id) {
		return $this->findInvoice($id)?? abort(404);
	}
	
	/**
	 * Create an invoice download Response.
	 *
	 * @param  string  $id
	 * @param  array   $data
	 * @param  string  $storagePath
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function downloadInvoice($id, array $data, $storagePath = null) { 
		$invoices = $this->findInvoiceOrFail($id);
		
		return array_get($invoices, $id)
			->download($data, $storagePath);
	}
	
	/**
	 * Get a subcription from Authorize
	 *
	 * @param  string $subscriptionId
	 * @return array
	 */
	public function getSubscriptionFromAuthorize($subscriptionId) {
		$instance		= new Requester;
		$request		= $instance->prepare(new ARBGetSubscriptionRequest);
		
		$environment	= $instance->env;
		$request->setSubscriptionId($subscriptionId);
		
		$controller		= new ARBGetSubscriptionController($request);
		$response		= $controller->executeWithApiResponse($environment);
		
		$subscription	= $response->getSubscription();
		$profileInfo	= $subscription->getProfile();
		
		switch ($response) {
		case null:
			throw new Exception("Unhandled Payment Gateway Response", 1);
			
		default:
			$messages	= $response->getMessages();
			$successful	= strcmp($messages->getResultCode(), "Ok") === 0;
			
			if ($successful) {
				return [
					'name'			=> $subscription->getName(),
					'amount'		=> $subscription->getAmount(),
					'status'		=> $subscription->getStatus(),
					'description'	=> $profileInfo->getDescription(),
					'customer'		=> $profileInfo->getCustomerProfileId()
				];
			}
			else {
				$exception	= $messages->getMessage();
				
				throw new Exception(sprintf(
					'Payment Processor: %d: %s', $exception->getCode(), $exception->getMessage()));
			}
			
			break;
		}
	}

	/**
	 * Get a collection of the entity's invoices.
	 *
	 * @param  string  $plan
	 * @return \Illuminate\Support\Collection
	 */
	public function invoices($plan) {
		$subscription	= $this->subscriptions($plan)->first();
		$startDate		= $subscription->created_at;
		
		$authorizeId	= $subscription->authorize_id;
		$authorizePlan	= $subscription->authorize_plan;
		
		$config			= Config::get('cashier-authorize');
		$endDate		= Carbon::now();
		
		$difference		= $startDate->diffInMonths($endDate);
		$subscription	= $this->getSubscriptionFromAuthorize($authorizeId);

		$collection	  = [];
		
		if ($difference >= 1)
		{
			$attribute = sprintf('%s.amount', $authorizePlan);

			foreach (range(1, $difference) as $invoiceNumber)
			{
				$timestamp = $startDate->addMonths($invoiceNumber)
						->timestamp;
				
				$taxPercent		=	sprintf('0.%d', $this->taxPercentage());
				
				$effTaxRate		=	$this->taxPercentage();
				$cmpTaxRate		=	floatval(array_get($config, $attribute, 0.00)) *
									floatval($taxPercent);
				
				array_push($invoices, new Invoice($this, [
					'date'			=> $timestamp,
					'subscription'	=> $subscription,
					'tax_percent'	=> $efTaxRate,
					'tax'			=> $cmpTaxRate
				]));
			}
		}

		return collect($collection);
	}

	/**
	 * Update customer's credit card.
	 *
	 * @param  string  $token
	 * @return void
	 */
	public function updateCard($card) {
		$instance		= new Requester;
		
		$creditCard		= new CreditCardType;
		$paymentCard	= new PaymentType;
		
		$billing		= new CustomerAddressType;
		$payment		= new CustomerPaymentProfileExType;
		
		$request		= $instance->prepare(new UpdateCustomerPaymentProfileRequest);
		$request->setCustomerProfileId($this->authorize_id);
		
		$creditCard->setCardNumber(array_get($card, 'number'));
		$creditCard->setExpirationDate(array_get($card, 'experation'));
		
		$paymentCard->setCreditCard($creditCard);

		$billing->setFirstName($this->first)
				->setLastName($this->last)
				->setAddress($this->address)
				->setCity($this->city)
				->setState($this->state)
				->setZip($this->zip)
				->setCountry($this->country);
		
		$payment->setCustomerPaymentProfileId($this->authorize_payment_id)
				->setBillTo($billing)
				->setPayment($paymentCreditCard);
		
		$request->setPaymentProfile($payment);
		
		$controller	= new UpdateCustomerPaymentProfileController($request);
		$authRspnse	= $controller->executeWithApiResponse($requester->env);
		
		switch ($authRspnse)
		{
		case null:
			$message = $authRspnse->getMessages();		
			$excMesg = $message->getMessage();
			
			throw new Exception(sprintf(
				'Payment Processor: %d: %s', $excMesg->getCode(), $excMesg->getMessage()), 1);
			
		default:
			$success = strcmp($authRspnse->getCode(), 'Ok') === 0;
			
			if ($success) {
				$this->card_type = $this->cardBrandDetector(array_get($card, 'number'));
				$this->last_four = substr(array_get($card, 'number'), -4);
			}
			break;
		}
		
		return $this->save();
	}

	/**
	 * Determine if the user is actively subscribed to one of the given plans.
	 *
	 * @param  array|string  $plans
	 * @param  string  $subscription
	 * @return bool
	 */
	public function subscribedToPlan($plans, $subscription = 'default') {	
		$subscription	= $this->subscription($subscription);
		$result			= !!$subscription;
		
		if ( $result && $subscription->valid())
		{
			foreach ((array)$plans as $plan)
			{	
				$authorize_plan = $subscription->authorize_plan;
				$result = strcmp($authorize_plan, $plan) === 0;
				
				if ($result)
				{
					break;
				}
			}
		}

		return $result;
	}

	/**
	 * Determine if the entity is on the given plan.
	 *
	 * @param  string  $plan
	 * @return bool
	 */
	public function onPlan($plan) {	
		$filter = function( $key, $record ) use ($plan) {
			$condition = !!strcmp($record->authorize_plan, $plan) === 0;
			return $condition & $record->valid();
		};
		
		return $this->subscriptions->first( $filter );
	}

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
	public function createAsAuthorizeCustomer(Array $location, array $cardDetails) {
		$instance	= new Requester;
		$request	= $instance->prepare(new CreateCustomerProfileRequest);
		
		
		
		/*
		$creditCard = new CreditCardType;
		$creditCard->setCardNumber(array_get($cardDetails, 'number'));
		$creditCard->setExpirationDate(array_get($cardDetails, 'experation'));
		
		$paymentCreditCard = new PaymentType;
		$paymentCreditCard->setCreditCard($creditCard);
		
		$billing = new CustomerAddressType;
		$billing->setFirstName($this->first);
		
		$billing->setLastName($this->last);
		$billing->setAddress(array_get($location, 'address'));
		
		$billing->setCity(array_get($location, 'city'));
		$billing->setState(array_get($location, 'state'));
		
		$billing->setZip(array_get($location, 'zip'));
		$billing->setCountry(array_get($location, 'country', 'US'));
		
		$payment = new CustomerPaymentProfileType;
		$payment->setCustomerType('individual');
		
		$payment->setBillTo($billing);
		$payment->setPayment($paymentCreditCard);
		
		$profile = new CustomerProfileType();
		$profile->setMerchantCustomerId(sprintf('M_%08d', $this->account_id));
		
		$profile->setEmail($this->email);
		$profile->setPaymentProfiles([ $payment ]);
		
		$request->setProfile($profile);
		$environment = $request->env;
		
		$controller	= new CreateCustomerProfileController($request);
		$authRspnse = $controller->executeWithApiResponse($environment);
		
		switch ($authRspnse)
		{
		case null:
			$message = $authRspnse->getMessages();		
			$excMesg = $message->getMessage();
			
			Log::error($errorMessage);
			throw new Exception(sprintf(
				'Payment Processor: %d: %s', $excMesg->getCode(), $excMesg->getMessage()), 1);
		
		default:
			if (strcmp($authRspnse->getCode(), 'Ok') === 0)
			{
				$this->gateway_id		= $response->getCustomerProfileId();
				$this->payment_id		= array_get($response->getCustomerPaymentProfileIdList() , 0);
				
				$this->card_type		= $this->cardBrandDetector(array_get($cardDetails, 'number'));
				$cardNumber				= array_get($cardDetails, 'number');
				
				$this->last_four		= substr($cardNumber, strlen($cardNumber) - 4);
				$this->save();
			}
			
			break;
		}
		*/
		
		return $this;
	}

	/**
	 * Delete an Authorize.net Profile
	 *
	 * @return
	 */
	public function deleteAuthorizeProfile() {
		$request = new Requester();
		$payment = $request->prepare(new DeleteCustomerProfileRequest);
		
		$environment = $request->env;
		$payment->setCustomerProfileId($this->authorize_id);

		$controller = new DeleteCustomerProfileController($request);
		$pmntResult = $controller->executeWithApiResponse($environment);
		
		$messages   = $pmntResult->getMessages();
		$message	= array_get($messages, 0);

		if (is_null($pmntResult) === false)
		{
			return strcmp($messages->getResultCode() , "Ok") === 0;
		}
		else
		{
			$response = sprintf("Payment Gateway Reply: %s %s %s", $message->getCode() , $message->getText());
			
			Log::error($response);
			throw new Exception($response);
		}
	}

	/**
	 * Get the Stripe supported currency used by the entity.
	 *
	 * @return string
	 */
	public function preferredCurrency() { return Cashier::usesCurrency(); }

	/**
	 * Get the tax percentage to apply to the subscription.
	 *
	 * @return int
	 */
	public function taxPercentage() { return 0; }

	/**
	 * Detect the brand cause Authorize wont give that to us
	 *
	 * @param  string $card Card number
	 * @return string
	 */
	public function cardBrandDetector($card) {
		$cardType = new CardType;
		return $cardType->detect($card);
	}
}
