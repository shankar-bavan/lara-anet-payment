<?php
namespace Laravel\Cashier\AuthorizeNet;

use DateTime;
use Exception;
use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Laravel\CashierAuthorizeNet\Requester;
	
use net\authorize\api\contract\v1\ARBSubscriptionType,
	net\authorize\api\contract\v1\PaymentScheduleType;

use net\authorize\api\contract\v1\PaymentScheduleType\IntervalAType,
	net\authorize\api\controller\ARBCreateSubscriptionController;

use net\authorize\api\contract\v1\CustomerProfileIdType,
	net\authorize\api\contract\v1\ARBCreateSubscriptionRequest;

class SubscriptionBuilder
{
    /**
     * The user model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $user;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The quantity of the subscription.
     *
     * @var int
     */
    protected $quantity = 1;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * The metadata to apply to the subscription.
     *
     * @var array|null
     */
    protected $metadata;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $user
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($user, $name, $plan)
    {
        $this->user = $user;
        $this->name = $name;
        $this->plan = $plan;
        $this->requester = new Requester;
    }

    /**
     * Specify the quantity of the subscription.
     *
     * @param  int  $quantity
     * @return $this
     */
    public function quantity($quantity)
    {
        $this->quantity = $quantity;
        return $this;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;
        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;
        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string  $coupon
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;
        return $this;
    }

    /**
     * The metadata to apply to a new subscription.
     *
     * @param  array  $metadata
     * @return $this
     */
    public function withMetadata($metadata)
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Add a new Authorize subscription to the user.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Authorize subscription.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     */
    public function create()
    {	
        $user = $this->user;
		$interval = new IntervalAType;
		
		$config = Config::get('cashier');
		$plan	= array_get($config, $this->plan);
		
		$instance = new Requester;
        $request = $instance->prepare(new ARBCreateSubscriptionRequest);
		
		// Subscription Type Info
        $subscription = new ARBSubscriptionType;
        $subscription->setName(array_get($plan, 'name'));
		
        $this->trialDays($trialNumDays = array_get($plan, 'trial_days'));
        $paymentSchedule = new PaymentScheduleType;
		
        $paymentStartDay = new DateTime(Carbon::now('America/Denver')
			->addDays($trialNumDays));
        
		$tax_percentage = floatval(sprintf(
			'1.%d', $this->getTaxPercentageForPayload()?? 0));
		
		$interval->setLength(array_get($plan, 'interval.length'))
				 ->setUnit(array_get($plan, 'interval.unit'));
		
		$subscription->setPaymentSchedule($paymentSchedule
				->setInterval($interval)
				->setStartDate($paymentStartDay)
				->setTotalOccurrences(array_get($plan, 'total_occurances'))
				->setTrialOccurrences(array_get($plan, 'trial_occurances')));
		
        $subscription->setAmount(round( floatval(array_get($plan, 'amount')) * 
										$tax_percentage));
        
		$subscription->setTrialAmount(array_get($plan, 'trial_amount'));
        $profile = new CustomerProfileIdType;
		
		$request->setSubscription($subscription);
        $controller = new ARBCreateSubscriptionController($request);
        
        $subscription->setProfile($profile
			->setCustomerProfileId($user->authorize_id)
			->setCustomerPaymentProfileId($user->authorize_payment_id));
		
		$response = $controller->executeWithApiResponse($instance->env);
        $message = $response->getMessages();
		
		if ($response && strcmp($message->getResultCode(), "Ok") === 0)
		{
            $carbon = $this->trialDays?
				Carbon::now()->addDays($this->trialDays):
				null;
			
			$trialEndsAt = ($this->skipTrial)?
                null: $carbon;
			
			return $this->user->subscriptions()->create([
                'name' => $this->name,
                'authorize_id' => $response->getSubscriptionId(),
                'authorize_plan' => $this->plan,
                'authorize_payment_id' => $this->user->authorize_payment_id,
                'metadata' => json_encode([
                    'refId' => $requester->refId
                ]),
                'quantity' => $this->quantity,
                'trial_ends_at' => $trialEndsAt,
                'ends_at' => null,
            ]);
        }
		else {
			$excMesg = array_get($message->getMessage(), 0);
			
			throw new Exception(sprintf(
				'Payment Processor: %d: %s', $excMesg->getCode(), $excMesg->getText()), 1);
        }
    }

    /**
     * Get the trial ending date for the Authorize payload.
     *
     * @return int|null
     */
    protected function getTrialEndForPayload()
    {
        $trial_date = Carbon::now()
			->addDays($this->trialDays)
			->getTimestamp();
		
		return ($this->skipTrial)? 
            'now':
			$trial_date;
    }

    /**
     * Get the tax percentage for the Authorize payload.
     *
     * @return int|null
     */
    protected function getTaxPercentageForPayload()
    {
        return $this->user
					->taxPercentage();
    }
}
